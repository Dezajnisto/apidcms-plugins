<?php
/**
 * Плагин «Личный кабинет» — AccountController
 *
 * Обрабатывает: /login, /register, /profile, /logout
 * Работает через FrontController (доступ к Twig, DB, render)
 */

namespace Plugins\Account;

class Controller
{
    /**
     * Страница входа
     */
    public static function login($fc)
    {
        // Куда вернуть пользователя после входа (?return=/subscribe/pro).
        // Раньше параметр игнорировался и человек всегда попадал в /profile,
        // поэтому переход «купить тариф → войти» терял цель.
        $returnTo = self::safeReturn($_REQUEST['return'] ?? '');

        // Если уже авторизован — на профиль (или туда, куда шли)
        if (!empty($_SESSION['user_id'])) {
            header('Location: ' . ($returnTo ?: '/profile'));
            exit;
        }

        $error = null;
        $success = null;
        $email = '';

        // Сообщения после подтверждения почты / смены пароля
        if (!empty($_GET['confirmed'])) {
            $success = 'Почта подтверждена. Аккаунт активен.';
        } elseif (!empty($_GET['reset'])) {
            $success = 'Пароль изменён. Войдите с новым паролем.';
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $remember = !empty($_POST['remember']);

            if (empty($email) || empty($password)) {
                $error = 'Заполните все поля';
            } else {
                $db = self::getDb($fc);
                $user = $db->query(
                    "SELECT * FROM plugin_account_users WHERE email = ?",
                    [$email]
                )->fetch();

                if ($user && ($user['status'] ?? '') === 'pending') {
                    $error = 'Аккаунт ещё не подтверждён. Перейдите по ссылке из письма.';
                } elseif ($user && ($user['status'] ?? '') === 'active'
                    && password_verify($password, $user['password_hash'])) {
                    self::startUserSession($user, $remember, $db);
                    header('Location: ' . ($returnTo ?: '/profile'));
                    exit;
                } else {
                    $error = 'Неверный email или пароль';
                }
            }
        }

        // Рендерим шаблон логина
        $template = self::getTemplate($fc, 'login');
        self::renderPlugin($fc, $template, [
            'title' => 'Вход',
            'error' => $error,
            'success' => $success,
            'email' => $email,
            'return_url' => $returnTo
        ]);
    }

    /**
     * Страница регистрации
     */
    public static function register($fc)
    {
        if (!empty($_SESSION['user_id'])) {
            header('Location: /profile');
            exit;
        }

        $error = null;
        $returnTo = self::safeReturn($_REQUEST['return'] ?? '');
        $formData = ['email' => '', 'name' => '', 'phone' => ''];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $formData['email'] = trim($_POST['email'] ?? '');
            $formData['name'] = trim($_POST['name'] ?? '');
            $formData['phone'] = trim($_POST['phone'] ?? '');
            $password = $_POST['password'] ?? '';
            $password2 = $_POST['password2'] ?? '';

            // Валидация
            if (empty($formData['email']) || empty($password)) {
                $error = 'Email и пароль обязательны';
            } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
                $error = 'Некорректный email';
            } elseif (strlen($password) < 6) {
                $error = 'Пароль должен быть не менее 6 символов';
            } elseif ($password !== $password2) {
                $error = 'Пароли не совпадают';
            } else {
                $db = self::getDb($fc);

                // Проверяем, нет ли уже такого email
                $exists = $db->query(
                    "SELECT id, status FROM plugin_account_users WHERE email = ?",
                    [$formData['email']]
                )->fetch();

                if ($exists) {
                    $error = 'Пользователь с таким email уже существует';
                } else {
                    $needConfirm = self::confirmRequired($db);
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $userId = $db->insert('plugin_account_users', [
                        'email' => $formData['email'],
                        'password_hash' => $hash,
                        'name' => $formData['name'],
                        'phone' => $formData['phone'],
                        'status' => $needConfirm ? 'pending' : 'active'
                    ]);

                    $user = $db->query("SELECT * FROM plugin_account_users WHERE id = ?", [$userId])->fetch();

                    if ($needConfirm) {
                        // Ссылка подтверждения живёт сутки: почта может полежать
                        // в спаме, а токен на час человек уже не найдёт.
                        $ttl = 86400;
                        $token = bin2hex(random_bytes(32));
                        $db->insert('plugin_account_tokens', [
                            'user_id' => $userId,
                            'token' => $token,
                            'type' => 'email_confirm',
                            'expires_at' => date('Y-m-d H:i:s', time() + $ttl)
                        ]);
                        self::sendConfirmEmail($fc, $user, $token, $ttl, $returnTo);

                        self::renderPlugin($fc, '@account/registered_check_email.html.twig', [
                            'title' => 'Подтвердите почту',
                            'email' => $formData['email']
                        ]);
                        return;
                    }

                    // Подтверждение выключено — авторизуем сразу
                    self::startUserSession($user, false, $db);
                    $pm = \Core\PluginManager::getInstance();
                    $pm->doAction('account.registered', $user, $fc);

                    header('Location: ' . ($returnTo ?: '/profile'));
                    exit;
                }
            }
        }

        self::renderPlugin($fc, '@account/register.html.twig', [
            'title' => 'Регистрация',
            'error' => $error,
            'form_data' => $formData,
            'return_url' => $returnTo
        ]);
    }

    /**
     * Подтверждение почты по ссылке из письма.
     *
     * Пока статус pending, входа нет: пользователь видит «подтвердите почту».
     * После подтверждения сразу авторизуем — второй раз вводить пароль глупо,
     * человек только что перешёл по ссылке из своего же письма.
     */
    public static function confirm($fc)
    {
        $token = trim($_GET['token'] ?? '');
        $db = self::getDb($fc);

        $row = $token
            ? $db->query(
                "SELECT * FROM plugin_account_tokens WHERE token = ? AND type = 'email_confirm' AND expires_at > datetime('now')",
                [$token]
            )->fetch()
            : null;

        if (!$row) {
            self::renderPlugin($fc, '@account/confirm_error.html.twig', [
                'title' => 'Ссылка недействительна'
            ]);
            return;
        }

        $user = $db->query(
            "SELECT * FROM plugin_account_users WHERE id = ?",
            [$row['user_id']]
        )->fetch();

        if (!$user) {
            self::renderPlugin($fc, '@account/confirm_error.html.twig', [
                'title' => 'Ссылка недействительна'
            ]);
            return;
        }

        // ВАЖНО: сигнатура Core\Database::update() — ($table, $id, $data).
        // Старый вызов ($table, $data, $where, $params) молча собирал пустой SET
        // и падал с «near WHERE: syntax error» — из-за него не работали
        // смена пароля и сохранение профиля.
        $db->update('plugin_account_users', $user['id'], ['status' => 'active']);
        $db->query("DELETE FROM plugin_account_tokens WHERE id = ?", [$row['id']]);
        $user['status'] = 'active';

        self::startUserSession($user, false, $db);

        // Хук: на подтверждении аккаунта можно выдать бесплатный тариф,
        // записать событие, отправить письмо «добро пожаловать».
        $pm = \Core\PluginManager::getInstance();
        $pm->doAction('account.confirmed', $user, $fc);

        $returnTo = self::safeReturn($_GET['return'] ?? '');
        header('Location: ' . ($returnTo ?: '/profile?confirmed=1'));
        exit;
    }

    /**
     * Профиль пользователя
     */
    public static function profile($fc)
    {
        if (empty($_SESSION['user_id'])) {
            header('Location: /login');
            exit;
        }

        $db = self::getDb($fc);
        $user = $db->query("SELECT * FROM plugin_account_users WHERE id = ?", [$_SESSION['user_id']])->fetch();

        if (!$user) {
            // Пользователь удалён — разлогиниваем
            self::logout($fc);
            return;
        }

        $error = null;
        $success = null;

        // Read redirect params from subscription/payment flows (one-time flash)
        $redirectError = $_GET['error'] ?? null;
        $redirectSuccess = $_GET['success'] ?? null;
        $redirectSubscribed = $_GET['subscribed'] ?? null;
        $redirectPending = $_GET['pending'] ?? null;
        $redirectConfirmed = $_GET['confirmed'] ?? null;
        $redirectDevices = $_GET['devices'] ?? null;

        if ($redirectError || $redirectSuccess || $redirectSubscribed || $redirectPending
            || $redirectConfirmed || $redirectDevices) {
            // Store as session flash, then redirect to clean URL
            if ($redirectError) {
                $errorMap = [
                    'payment_failed' => 'Не удалось создать платёж. Попробуйте позже или обратитесь в поддержку.',
                    'payment_canceled' => 'Платёж отменён.',
                    'no_payment_gateway' => 'Платёжный шлюз не настроен.',
                    'trial_used' => 'Вы уже использовали пробный период.',
                ];
                $_SESSION['profile_flash_error'] = $errorMap[$redirectError] ?? $redirectError;
            }
            if ($redirectSuccess) {
                $_SESSION['profile_flash_success'] = $redirectSuccess;
            }
            if ($redirectSubscribed) {
                $_SESSION['profile_flash_success'] = 'Подписка успешно оформлена!';
            }
            if ($redirectPending) {
                $_SESSION['profile_flash_success'] = 'Платёж ожидает подтверждения. Подписка активируется автоматически.';
            }
            if ($redirectConfirmed) {
                $_SESSION['profile_flash_success'] = 'Почта подтверждена — аккаунт активен.';
            }
            if ($redirectDevices) {
                $_SESSION['profile_flash_success'] = 'Устройство отвязано.';
            }
            header('Location: /profile');
            exit;
        }

        // Read one-time flash from session
        $error = $_SESSION['profile_flash_error'] ?? null;
        $success = $_SESSION['profile_flash_success'] ?? null;
        unset($_SESSION['profile_flash_error'], $_SESSION['profile_flash_success']);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $name = trim($_POST['name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');

            $db->update('plugin_account_users', $_SESSION['user_id'], [
                'name' => $name,
                'phone' => $phone
            ]);

            $success = 'Профиль обновлён';
            $user['name'] = $name;
            $user['phone'] = $phone;
            $_SESSION['user_name'] = $name;
        }

        self::renderPlugin($fc, '@account/profile.html.twig', [
            'title' => 'Профиль',
            'user' => $user,
            'error' => $error,
            'success' => $success
        ]);
    }

    /**
     * Выход
     */
    public static function logout($fc)
    {
        // Удаляем remember-me токен
        if (!empty($_COOKIE['remember_token'])) {
            $db = self::getDb($fc);
            $db->query("DELETE FROM plugin_account_tokens WHERE token = ?", [$_COOKIE['remember_token']]);
            setcookie('remember_token', '', time() - 3600, '/');
        }

        unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['user_email']);
        session_destroy();

        header('Location: /');
        exit;
    }

    /**
     * Авторизация по remember-me токену
     */
    public static function loginByToken($fc, $token)
    {
        $db = self::getDb($fc);
        $tokenRow = $db->query(
            "SELECT * FROM plugin_account_tokens WHERE token = ? AND type = 'remember' AND expires_at > datetime('now')",
            [$token]
        )->fetch();

        if (!$tokenRow) {
            setcookie('remember_token', '', time() - 3600, '/');
            return;
        }

        $user = $db->query("SELECT * FROM plugin_account_users WHERE id = ? AND status = 'active'", [$tokenRow['user_id']])->fetch();
        if (!$user) {
            setcookie('remember_token', '', time() - 3600, '/');
            return;
        }

        self::startUserSession($user, true, $db);
    }

    // ======== Вспомогательные методы ========

    /**
     * Получить объект БД из FrontController
     */
    private static function getDb($fc)
    {
        $ref = new \ReflectionClass($fc);
        $prop = $ref->getProperty('database');
        $prop->setAccessible(true);
        return $prop->getValue($fc);
    }

    /**
     * Получить путь к шаблону плагина
     */
    private static function getTemplate($fc, $name)
    {
        return '@account/' . $name . '.html.twig';
    }

    /**
     * Рендерить шаблон плагина
     */
    private static function renderPlugin($fc, $template, $data)
    {
        // Добавляем данные текущего пользователя
        $data['is_logged_in'] = !empty($_SESSION['user_id']);
        if (!isset($data['return_url'])) {
            $data['return_url'] = '';
        }
        $data['current_user'] = !empty($_SESSION['user_id']) ? [
            'id' => $_SESSION['user_id'],
            'name' => $_SESSION['user_name'] ?? '',
            'email' => $_SESSION['user_email'] ?? ''
        ] : null;

        // Вызываем render через рефлексию (приватный метод)
        $ref = new \ReflectionClass($fc);
        $method = $ref->getMethod('render');
        $method->setAccessible(true);
        $method->invoke($fc, $template, $data);
    }

    /**
     * Начать сессию пользователя
     */
    private static function startUserSession($user, $remember, $db)
    {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'] ?: $user['email'];
        $_SESSION['user_email'] = $user['email'];

        if ($remember) {
            $token = bin2hex(random_bytes(32));
            $db->insert('plugin_account_tokens', [
                'user_id' => $user['id'],
                'token' => $token,
                'type' => 'remember',
                'expires_at' => date('Y-m-d H:i:s', strtotime('+30 days'))
            ]);
            setcookie('remember_token', $token, [
                'expires' => time() + 30 * 86400,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }
    }

    /**
     * Страница «Забыли пароль» — отправка magic-link
     */
    public static function forgot($fc)
    {
        if (!empty($_SESSION['user_id'])) {
            header('Location: /profile');
            exit;
        }

        $error = null;
        $email = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $email = trim($_POST['email'] ?? '');

            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Укажите корректный email';
            } else {
                $db = self::getDb($fc);

                // Check rate limit: existing valid token for this email
                $existing = $db->query(
                    "SELECT t.id FROM plugin_account_tokens t
                     JOIN plugin_account_users u ON t.user_id = u.id
                     WHERE u.email = ? AND t.type = 'password_reset'
                     AND t.expires_at > datetime('now')",
                    [$email]
                )->fetch();

                if ($existing) {
                    // Already sent — show same generic message
                    return self::renderForgotSent($fc);
                }

                $user = $db->query(
                    "SELECT * FROM plugin_account_users WHERE email = ? AND status = 'active'",
                    [$email]
                )->fetch();

                if ($user) {
                    $ttl = self::getResetTokenTtl($db);
                    $token = bin2hex(random_bytes(32));
                    $expiresAt = date('Y-m-d H:i:s', time() + $ttl);

                    $db->insert('plugin_account_tokens', [
                        'user_id' => $user['id'],
                        'token' => $token,
                        'type' => 'password_reset',
                        'expires_at' => $expiresAt
                    ]);

                    self::sendResetEmail($fc, $user, $token, $ttl);
                }

                return self::renderForgotSent($fc);
            }
        }

        // GET — show form
        self::renderPlugin($fc, '@account/forgot.html.twig', [
            'title' => 'Восстановление доступа',
            'error' => $error,
            'email' => $email
        ]);
    }

    /**
     * Показать страницу «Проверьте почту»
     */
    private static function renderForgotSent($fc)
    {
        $db = self::getDb($fc);
        $ttl = self::getResetTokenTtl($db);
        $ttlText = $ttl >= 86400 ? round($ttl / 86400) . ' дн.' : round($ttl / 3600) . ' ч.';

        self::renderPlugin($fc, '@account/forgot_sent.html.twig', [
            'title' => 'Проверьте почту',
            'ttl_text' => $ttlText
        ]);
    }

    /**
     * Magic-link: проверка токена и вход
     */
    public static function reset($fc)
    {
        $token = $_GET['token'] ?? '';

        if (empty($token)) {
            header('Location: /login');
            exit;
        }

        $db = self::getDb($fc);

        $tokenRow = $db->query(
            "SELECT * FROM plugin_account_tokens WHERE token = ? AND type = 'password_reset' AND expires_at > datetime('now')",
            [$token]
        )->fetch();

        if (!$tokenRow) {
            // Token invalid or expired
            self::renderPlugin($fc, '@account/forgot.html.twig', [
                'title' => 'Восстановление доступа',
                'error' => 'Ссылка недействительна или истекла. Запросите новую.',
                'email' => ''
            ]);
            return;
        }

        $user = $db->query(
            "SELECT * FROM plugin_account_users WHERE id = ? AND status = 'active'",
            [$tokenRow['user_id']]
        )->fetch();

        if (!$user) {
            header('Location: /login');
            exit;
        }

        // Delete the used token
        $db->query("DELETE FROM plugin_account_tokens WHERE id = ?", [$tokenRow['id']]);

        // Log user in
        self::startUserSession($user, false, $db);

        header('Location: /profile');
        exit;
    }

    /**
     * Смена пароля (для авторизованных)
     */
    public static function changePassword($fc)
    {
        if (empty($_SESSION['user_id'])) {
            header('Location: /login');
            exit;
        }

        $error = null;
        $success = null;
        $db = self::getDb($fc);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $newPassword2 = $_POST['new_password2'] ?? '';

            // Validation
            if (empty($currentPassword) || empty($newPassword)) {
                $error = 'Заполните все поля';
            } elseif (strlen($newPassword) < 6) {
                $error = 'Новый пароль должен быть не менее 6 символов';
            } elseif ($newPassword !== $newPassword2) {
                $error = 'Новые пароли не совпадают';
            } else {
                $user = $db->query(
                    "SELECT * FROM plugin_account_users WHERE id = ?",
                    [$_SESSION['user_id']]
                )->fetch();

                if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
                    $error = 'Неверный текущий пароль';
                } else {
                    $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
                    $db->update('plugin_account_users', $_SESSION['user_id'], ['password_hash' => $newHash]);
                    $success = 'Пароль успешно изменён';
                }
            }
        }

        self::renderPlugin($fc, '@account/change_password.html.twig', [
            'title' => 'Смена пароля',
            'error' => $error,
            'success' => $success
        ]);
    }

    /**
     * Требуется ли подтверждение почты (настройка плагина require_email_confirm).
     * Значение живёт в plugin_settings (ядро 2.7+), по умолчанию — false,
     * чтобы поведение чужих сайтов не менялось от обновления плагина.
     */
    private static function confirmRequired($db)
    {
        try {
            $row = $db->query(
                "SELECT setting_value FROM plugin_settings WHERE plugin = 'account' AND setting_key = 'require_email_confirm'",
                []
            )->fetch();
        } catch (\Exception $e) {
            return false;
        }
        if (!$row) {
            return false;
        }
        $value = strtolower(trim((string) $row['setting_value']));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Безопасный адрес возврата: только локальный путь.
     * Без этого ?return=https://evil.example превращался бы в открытый редирект.
     */
    private static function safeReturn($value)
    {
        $value = trim((string) $value);
        if ($value === '' || $value[0] !== '/' || strpos($value, '//') === 0) {
            return '';
        }
        // Отсекаем переводы строк и пробелы, чтобы нельзя было подсунуть заголовки
        if (preg_match('/[\r\n\t ]/', $value)) {
            return '';
        }
        return $value;
    }

    /**
     * Получить TTL токена сброса из настроек плагина
     */
    private static function getResetTokenTtl($db)
    {
        $ttl = 3600; // default 1 hour
        try {
            $result = $db->query(
                "SELECT setting_value FROM system_settings WHERE setting_key = 'plugin_account_reset_token_ttl'"
            )->fetch();
            if ($result && is_numeric($result['setting_value'])) {
                $ttl = max(300, min(604800, (int)$result['setting_value'])); // 5 min .. 7 days
            }
        } catch (\Exception $e) {
            // Use default
        }
        return $ttl;
    }

    /**
     * Отправить письмо с magic-link для входа/сброса
     */
    private static function sendResetEmail($fc, $user, $token, $ttl)
    {
        $ttlText = self::ttlText($ttl);
        self::sendTemplateMail($fc, $user['email'], '@account/email_reset.html.twig', [
            'reset_url' => self::absoluteUrl('/account/reset?token=' . $token),
            'ttl_text' => $ttlText
        ], 'Вход на сайт ' . self::siteTitle(self::getDb($fc)));
    }

    /**
     * Отправить письмо «подтвердите почту»
     */
    public static function sendConfirmEmail($fc, $user, $token, $ttl, $returnTo = '')
    {
        $url = '/account/confirm?token=' . $token;
        if ($returnTo !== '') {
            $url .= '&return=' . urlencode($returnTo);
        }
        self::sendTemplateMail($fc, $user['email'], '@account/email_confirm.html.twig', [
            'confirm_url' => self::absoluteUrl($url),
            'ttl_text' => self::ttlText($ttl)
        ], 'Подтвердите почту — ' . self::siteTitle(self::getDb($fc)));
    }

    /**
     * Собрать EmailSender из system_settings (общий код для писем плагина).
     * Драйвер api = HTTP-API провайдера (у нас smtp.bz), smtp = пока mail().
     */
    private static function mailer($db)
    {
        $get = function ($key, $default = '') use ($db) {
            $row = $db->query(
                "SELECT setting_value FROM system_settings WHERE setting_key = ?",
                [$key]
            )->fetch();
            $value = $row['setting_value'] ?? null;
            return ($value === null || $value === '') ? $default : $value;
        };

        $driver = $get('email_driver', 'mail');
        $config = [
            'driver' => $driver,
            'from' => [
                'email' => $get('email_from_email'),
                'name' => $get('email_from_name', self::siteTitle($db))
            ]
        ];

        if ($driver === 'api') {
            $config['api'] = [
                'key' => $get('email_api_key'),
                'endpoint' => $get('email_api_endpoint')
            ];
        } elseif ($driver === 'smtp') {
            $config['smtp'] = [
                'host' => $get('email_smtp_host'),
                'port' => $get('email_smtp_port', '587'),
                'username' => $get('email_smtp_username'),
                'password' => $get('email_smtp_password'),
                'encryption' => $get('email_smtp_encryption', 'tls')
            ];
        }

        return new \Core\EmailSender($config);
    }

    /**
     * Отрендерить шаблон письма и отправить его. Ошибки — в лог, пользователю
     * не показываем: «письмо не ушло» не должно превращаться в 500.
     */
    private static function sendTemplateMail($fc, $to, $template, $vars, $subject)
    {
        $db = self::getDb($fc);
        try {
            $ref = new \ReflectionClass($fc);
            $twigProp = $ref->getProperty('twig');
            $twigProp->setAccessible(true);
            $twig = $twigProp->getValue($fc);

            $vars['site_title'] = self::siteTitle($db);
            $htmlBody = $twig->render($template, $vars);

            self::mailer($db)->send($to, $subject, $htmlBody, true);
        } catch (\Exception $e) {
            error_log("Account plugin: failed to send '{$template}' to {$to}: " . $e->getMessage());
        }
    }

    private static function siteTitle($db)
    {
        try {
            $row = $db->query(
                "SELECT setting_value FROM system_settings WHERE setting_key = 'site_title'"
            )->fetch();
            if ($row && !empty($row['setting_value'])) {
                return $row['setting_value'];
            }
        } catch (\Exception $e) {
            // ignore
        }
        return $_SERVER['HTTP_HOST'] ?? 'Site';
    }

    private static function absoluteUrl($path)
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $path;
    }

    private static function ttlText($ttl)
    {
        if ($ttl >= 86400) {
            return round($ttl / 86400) . ' дн.';
        }
        return round($ttl / 3600) . ' ч.';
    }

}
