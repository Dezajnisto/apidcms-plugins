<?php
/**
 * Плагин «Веб-чат» — init.php
 *
 * Хуки:
 *   db.migrate             — таблицы обращений и сообщений
 *   front.router.before    — /chat/widget.js|css и /chat/api/*
 *   front.output           — вставка виджета на страницы сайта
 *   admin.routes           — /admin/chats и действия
 *   admin.menu             — пункт меню с числом непрочитанных
 *   admin.twig.init        — шаблоны плагина в админке
 *   admin.current_section  — подсветка пункта меню
 */

use Core\PluginManager;

$pm = PluginManager::getInstance();
$pluginDir = __DIR__;

// === Автозагрузка классов плагина ===

spl_autoload_register(function ($class) use ($pluginDir) {
    $prefix = 'Plugins\\Webchat\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $candidates = [
        $pluginDir . '/src/' . str_replace('\\', '/', $relative) . '.php',
        $pluginDir . '/controllers/' . str_replace('\\', '/', $relative) . '.php',
    ];

    foreach ($candidates as $file) {
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});

// === Миграции ===

$pm->addAction('db.migrate', function ($db) use ($pluginDir) {
    $migration = $pluginDir . '/migrations/001_init.sql';
    if (!is_file($migration)) {
        return;
    }

    $sql = (string) file_get_contents($migration);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        try {
            $db->exec($statement);
        } catch (\Throwable $e) {
            error_log('webchat: миграция не применилась — ' . $e->getMessage());
        }
    }

    // Секрет вебхука нужен до первого события: иначе админ не сможет
    // подписать вебхук кнопкой «Проверить подключение».
    try {
        $settings = new \Plugins\Webchat\Settings();
        if ((string) $settings->get('webhook_secret', '') === '') {
            $settings->save('webhook_secret', bin2hex(random_bytes(24)));
        }
    } catch (\Throwable $e) {
        error_log('webchat: секрет вебхука не создан — ' . $e->getMessage());
    }
}, 10, 'webchat');

// === Публичные маршруты: скрипт виджета, API, вебхуки ===

$pm->addAction('front.router.before', function ($path, $frontController) {
    try {
        \Plugins\Webchat\FrontChatController::handle((string) $path);
    } catch (\Throwable $e) {
        error_log('webchat: ' . $e->getMessage());
    }
}, 20, 'webchat');

// === Вставка виджета в готовую страницу ===

$pm->addFilter('front.output', function ($html) use ($pluginDir) {
    if (!is_string($html) || $html === '') {
        return $html;
    }

    // Только настоящие страницы и только GET: в ответы API и на POST
    // вставка не нужна.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return $html;
    }
    if (stripos($html, '</body>') === false) {
        return $html;
    }

    if (strpos($html, 'apidcms-chat-widget') !== false) {
        return $html; // уже вставлен
    }

    // Версия в URL — кеш-бастер: скрипт отдаётся с длинным max-age, и без
    // этого новая версия виджета доезжала бы до посетителей через сутки.
    $stamp = (string) @filemtime($pluginDir . '/assets/widget.js')
        . (string) @filemtime($pluginDir . '/assets/widget.css');
    $version = substr(md5($stamp), 0, 8);

    $snippet = '<script src="/chat/widget?v=' . $version . '" defer></script>';

    $replaced = preg_replace(
        '#</body>#i',
        '<!-- apidcms-chat-widget -->' . $snippet . '</body>',
        $html,
        1
    );

    return $replaced ?? $html;
}, 10, 'webchat');

// === Админка: маршруты ===

$pm->addAction('admin.routes', function ($app) use ($pluginDir) {
    // Файл контроллера нужен до class_exists(): автозагрузчик плагина
    // зарегистрирован в init.php и уже активен.
    $app->addRoute('/chats', 'Plugins\\Webchat\\AdminChatsController', 'index');
    $app->addRoute('/chats/check', 'Plugins\\Webchat\\AdminChatsController', 'check');
    $app->addRoute('/chats/{id}', 'Plugins\\Webchat\\AdminChatsController', 'thread');
    $app->addRoute('/chats/{id}/reply', 'Plugins\\Webchat\\AdminChatsController', 'reply');
    $app->addRoute('/chats/{id}/status', 'Plugins\\Webchat\\AdminChatsController', 'status');
    $app->addRoute('/chats/{id}/delete', 'Plugins\\Webchat\\AdminChatsController', 'remove');
}, 10, 'webchat');

// === Админка: пункт меню ===

$pm->addFilter('admin.menu', function ($items) {
    $unread = 0;
    try {
        $db = PluginManager::getInstance()->getDatabase();
        if ($db) {
            $chat = new \Plugins\Webchat\Chat($db, new \Plugins\Webchat\Settings());
            $unread = $chat->unreadCount();
        }
    } catch (\Throwable $e) {
        $unread = 0;
    }

    $items[] = [
        'title' => 'Чаты',
        'url' => '/admin/chats',
        'icon' => 'fas fa-comments',
        'section' => 'chats',
        'badge' => $unread,
        'order' => 60,
    ];

    return $items;
}, 10, 'webchat');

// === Админка: шаблоны плагина ===

$pm->addAction('admin.twig.init', function ($controller, $twig) use ($pluginDir) {
    $loader = $twig->getLoader();
    if ($loader instanceof \Twig\Loader\FilesystemLoader) {
        $loader->addPath($pluginDir . '/views', 'webchat');
    }
}, 10, 'webchat');

// === Админка: подсветка своего пункта меню ===

$pm->addFilter('admin.current_section', function ($section, $path) {
    if (strpos((string) $path, '/chats') !== false) {
        return 'chats';
    }
    return $section;
}, 10, 'webchat');
