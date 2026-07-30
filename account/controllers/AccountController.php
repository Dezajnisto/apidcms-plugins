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
        // Если уже авторизован — на профиль
        if (!empty($_SESSION['user_id'])) {
            header('Location: /profile');
            exit;
        }

        $error = null;
        $email = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $remember = !empty($_POST['remember']);

            if (empty($email) || empty($password)) {
                $error = 'Заполните все поля';
            } else {
                $db = self::getDb($fc);
                $user = $db->query(
                    "SELECT * FROM plugin_account_users WHERE email = ? AND status = 'active'",
                    [$email]
                )->fetch();

                if ($user && password_verify($password, $user['password_hash'])) {
                    self::startUserSession($user, $remember, $db);
                    header('Location: /profile');
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
            'email' => $email
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
                    "SELECT id FROM plugin_account_users WHERE email = ?",
                    [$formData['email']]
                )->fetch();

                if ($exists) {
                    $error = 'Пользователь с таким email уже существует';
                } else {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $userId = $db->insert('plugin_account_users', [
                        'email' => $formData['email'],
                        'password_hash' => $hash,
                        'name' => $formData['name'],
                        'phone' => $formData['phone'],
                        'status' => 'active'
                    ]);

                    // Авторизуем сразу
                    $user = $db->query("SELECT * FROM plugin_account_users WHERE id = ?", [$userId])->fetch();
                    self::startUserSession($user, false, $db);

                    header('Location: /profile');
                    exit;
                }
            }
        }

        self::renderPlugin($fc, '@account/register.html.twig', [
            'title' => 'Регистрация',
            'error' => $error,
            'form_data' => $formData
        ]);
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

        // Read redirect params from subscription/payment flows
        $redirectError = $_GET['error'] ?? null;
        $redirectSuccess = $_GET['success'] ?? null;
        $redirectSubscribed = $_GET['subscribed'] ?? null;
        $redirectPending = $_GET['pending'] ?? null;

        if ($redirectError) {
            $errorMap = [
                'payment_failed' => 'Не удалось создать платёж. Попробуйте позже или обратитесь в поддержку.',
                'payment_canceled' => 'Платёж отменён.',
                'no_payment_gateway' => 'Платёжный шлюз не настроен.',
                'trial_used' => 'Вы уже использовали пробный период.',
            ];
            $error = $errorMap[$redirectError] ?? $redirectError;
        }
        if ($redirectSuccess) {
            $success = $redirectSuccess;
        }
        if ($redirectSubscribed) {
            $success = 'Подписка успешно оформлена!';
        }
        if ($redirectPending) {
            $success = 'Платёж ожидает подтверждения. Подписка активируется автоматически.';
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $name = trim($_POST['name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');

            $db->update('plugin_account_users', [
                'name' => $name,
                'phone' => $phone
            ], 'id = ?', [$_SESSION['user_id']]);

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
                    $db->update('plugin_account_users', ['password_hash' => $newHash], 'id = ?', [$_SESSION['user_id']]);
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
     * Отправить письмо с magic-link
     */
    private static function sendResetEmail($fc, $user, $token, $ttl)
    {
        $db = self::getDb($fc);

        // Read email settings from core
        try {
            $driverResult = $db->query(
                "SELECT setting_value FROM system_settings WHERE setting_key = 'email_driver'"
            )->fetch();
            $driver = $driverResult['setting_value'] ?? 'mail';

            $fromEmailResult = $db->query(
                "SELECT setting_value FROM system_settings WHERE setting_key = 'email_from_email'"
            )->fetch();
            $fromEmail = $fromEmailResult['setting_value'] ?? '';

            $fromNameResult = $db->query(
                "SELECT setting_value FROM system_settings WHERE setting_key = 'email_from_name'"
            )->fetch();
            $fromName = $fromNameResult['setting_value'] ?? '';

            $siteTitleResult = $db->query(
                "SELECT setting_value FROM system_settings WHERE setting_key = 'site_title'"
            )->fetch();
            $siteTitle = $siteTitleResult['setting_value'] ?? ($_SERVER['HTTP_HOST'] ?? 'Site');

            // Build EmailSender config
            $config = [
                'driver' => $driver,
                'from' => [
                    'email' => $fromEmail,
                    'name' => $fromName
                ]
            ];

            // Add API/SMTP settings based on driver
            if ($driver === 'api') {
                $apiKeyResult = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'email_api_key'")->fetch();
                $apiEndpointResult = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'email_api_endpoint'")->fetch();
                $config['api'] = [
                    'key' => $apiKeyResult['setting_value'] ?? '',
                    'endpoint' => $apiEndpointResult['setting_value'] ?? ''
                ];
            } elseif ($driver === 'smtp') {
                $smtpHost = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'email_smtp_host'")->fetch();
                $smtpPort = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'email_smtp_port'")->fetch();
                $smtpUser = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'email_smtp_username'")->fetch();
                $smtpPass = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'email_smtp_password'")->fetch();
                $smtpEnc = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'email_smtp_encryption'")->fetch();
                $config['smtp'] = [
                    'host' => $smtpHost['setting_value'] ?? '',
                    'port' => $smtpPort['setting_value'] ?? '587',
                    'username' => $smtpUser['setting_value'] ?? '',
                    'password' => $smtpPass['setting_value'] ?? '',
                    'encryption' => $smtpEnc['setting_value'] ?? 'tls'
                ];
            }

            // Render email template
            $resetUrl = ($_SERVER['HTTPS'] ?? 'off' === 'on' ? 'https' : 'http')
                . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                . '/account/reset?token=' . $token;

            $ttlText = $ttl >= 86400 ? round($ttl / 86400) . ' дн.' : round($ttl / 3600) . ' ч.';

            // Get Twig from FrontController to render email
            $ref = new \ReflectionClass($fc);
            $twigProp = $ref->getProperty('twig');
            $twigProp->setAccessible(true);
            $twig = $twigProp->getValue($fc);

            $htmlBody = $twig->render('@account/email_reset.html.twig', [
                'site_title' => $siteTitle,
                'reset_url' => $resetUrl,
                'ttl_text' => $ttlText
            ]);

            $emailSender = new \Core\EmailSender($config);
            $emailSender->send($user['email'], 'Вход на сайт ' . $siteTitle, $htmlBody, true);

        } catch (\Exception $e) {
            error_log("Account plugin: failed to send reset email: " . $e->getMessage());
            // Fail silently — user still sees "check your email" page
        }
    }

}
