<?php
/**
 * Плагин «Личный кабинет» — init.php
 *
 * Регистрирует хуки: core.init, front.router.before, db.migrate, admin.menu, twig.init
 * Добавляет Twig-функции: is_logged_in(), current_user()
 */

use Core\PluginManager;
use Twig\TwigFunction;

$pm = PluginManager::getInstance();
$pluginDir = __DIR__;

// === Миграции (создание таблиц) ===

$pm->addAction('db.migrate', function ($db) use ($pluginDir) {
    $migrationFile = $pluginDir . '/migrations/001_create_users.sql';
    if (!file_exists($migrationFile)) return;

    $sql = file_get_contents($migrationFile);
    $statements = array_filter(array_map('trim', explode(';', $sql)));

    foreach ($statements as $stmt) {
        if (!empty($stmt)) {
            try {
                $db->exec($stmt);
            } catch (\Exception $e) {
                error_log("Account plugin migration error: " . $e->getMessage());
            }
        }
    }

    // Schema verification: ensure all expected columns exist
    $expectedColumns = [
        'plugin_account_users' => [
            'id' => 'INTEGER',
            'email' => 'TEXT',
            'password_hash' => 'TEXT',
            'name' => 'TEXT',
            'phone' => 'TEXT',
            'avatar' => 'TEXT',
            'status' => 'TEXT',
            'created_at' => 'DATETIME'
        ],
        'plugin_account_tokens' => [
            'id' => 'INTEGER',
            'user_id' => 'INTEGER',
            'token' => 'TEXT',
            'type' => 'TEXT',
            'expires_at' => 'DATETIME',
            'created_at' => 'DATETIME'
        ]
    ];

    foreach ($expectedColumns as $table => $columns) {
        try {
            $existing = $db->query("PRAGMA table_info(\"{$table}\")")->fetchAll();
            $existingNames = array_column($existing, 'name');

            foreach ($columns as $colName => $colType) {
                if (!in_array($colName, $existingNames)) {
                    // Use safe default: TEXT for everything added post-hoc
                    $db->exec("ALTER TABLE \"{$table}\" ADD COLUMN \"{$colName}\" {$colType} DEFAULT ''");
                    error_log("Account plugin: added missing column {$table}.{$colName}");
                }
            }
        } catch (\Exception $e) {
            error_log("Account plugin schema verification error for {$table}: " . $e->getMessage());
        }
    }
}, 10, 'account');

// === Twig: добавляем путь к шаблонам и функции ===

$pm->addAction('twig.init', function ($fc, $twig) use ($pluginDir) {
    // Добавляем путь к views плагина в Twig-лоадер
    $loader = $twig->getLoader();
    if ($loader instanceof \Twig\Loader\FilesystemLoader) {
        $loader->addPath($pluginDir . '/views', 'account');
    }

    // Функция is_logged_in()
    $twig->addFunction(new TwigFunction('is_logged_in', function () {
        return !empty($_SESSION['user_id']);
    }));

    // Функция current_user()
    $twig->addFunction(new TwigFunction('current_user', function () {
        if (empty($_SESSION['user_id'])) return null;
        return [
            'id' => $_SESSION['user_id'],
            'name' => $_SESSION['user_name'] ?? '',
            'email' => $_SESSION['user_email'] ?? ''
        ];
    }));
}, 10, 'account');

// === Middleware: проверка сессии при каждом запросе ===

$pm->addAction('core.init', function () {
    // Запускаем сессию для проверки авторизации
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Проверяем remember-me куку
    if (empty($_SESSION['user_id']) && !empty($_COOKIE['remember_token'])) {
        $_SESSION['pending_remember_token'] = $_COOKIE['remember_token'];
    }
}, 10, 'account');

// === Обработка маршрутов аккаунта ===

$pm->addAction('front.router.before', function ($path, $frontController) use ($pluginDir) {
    // Проверяем remember-me токен (теперь DB доступна)
    if (!empty($_SESSION['pending_remember_token'])) {
        $token = $_SESSION['pending_remember_token'];
        unset($_SESSION['pending_remember_token']);
        require_once $pluginDir . '/controllers/AccountController.php';
        \Plugins\Account\Controller::loginByToken($frontController, $token);
    }

    // Подключаем контроллер для маршрутизации
    require_once $pluginDir . '/controllers/AccountController.php';

    switch ($path) {
        case 'login':
            \Plugins\Account\Controller::login($frontController);
            exit;
        case 'register':
            \Plugins\Account\Controller::register($frontController);
            exit;
        case 'profile':
            \Plugins\Account\Controller::profile($frontController);
            exit;
        case 'logout':
            \Plugins\Account\Controller::logout($frontController);
            exit;
        case 'account/forgot':
            \Plugins\Account\Controller::forgot($frontController);
            exit;
        case 'account/reset':
            \Plugins\Account\Controller::reset($frontController);
            exit;
        case 'account/change-password':
            \Plugins\Account\Controller::changePassword($frontController);
            exit;
    }
}, 20, 'account');

// === Админка: пункт меню ===

$pm->addFilter('admin.menu', function ($menuItems) {
    $menuItems[] = [
        'title' => '👤 Пользователи',
        'url' => '/admin/table/users',
        'section' => 'users',
        'order' => 80
    ];
    return $menuItems;
}, 10, 'account');
