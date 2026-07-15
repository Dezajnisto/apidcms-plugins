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
                    "SELECT * FROM users WHERE email = ? AND status = 'active'",
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
                    "SELECT id FROM users WHERE email = ?",
                    [$formData['email']]
                )->fetch();

                if ($exists) {
                    $error = 'Пользователь с таким email уже существует';
                } else {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $userId = $db->insert('users', [
                        'email' => $formData['email'],
                        'password_hash' => $hash,
                        'name' => $formData['name'],
                        'phone' => $formData['phone'],
                        'status' => 'active'
                    ]);

                    // Авторизуем сразу
                    $user = $db->query("SELECT * FROM users WHERE id = ?", [$userId])->fetch();
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
        $user = $db->query("SELECT * FROM users WHERE id = ?", [$_SESSION['user_id']])->fetch();

        if (!$user) {
            // Пользователь удалён — разлогиниваем
            self::logout($fc);
            return;
        }

        $error = null;
        $success = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $name = trim($_POST['name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');

            $db->update('users', [
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
            $db->query("DELETE FROM user_tokens WHERE token = ?", [$_COOKIE['remember_token']]);
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
            "SELECT * FROM user_tokens WHERE token = ? AND type = 'remember' AND expires_at > datetime('now')",
            [$token]
        )->fetch();

        if (!$tokenRow) {
            setcookie('remember_token', '', time() - 3600, '/');
            return;
        }

        $user = $db->query("SELECT * FROM users WHERE id = ? AND status = 'active'", [$tokenRow['user_id']])->fetch();
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
            $db->insert('user_tokens', [
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
}
