<?php
/**
 * Плагин «Сообщество»: форум, комментарии к страницам, сообщества.
 *
 * Идея взята у rainlab/forum-plugin для OctoberCMS: тема — обычная запись
 * с полем embed_code. Код заполнен — тема живёт под страницей сайта и работает
 * как комментарии; код пуст — обычная тема форума. Один набор таблиц и один
 * код обслуживают три сценария (форум, комментарии, сообщества).
 *
 * Хуки:
 *   db.migrate            — таблицы + каналы по умолчанию
 *   twig.init             — шаблоны плагина в теме сайта, функции
 *   front.router.before   — /forum/..., /community/..., /community/reply, /community/upload
 *   front.render          — блок комментариев под текстом страницы
 *   front.output          — стили и скрипт
 *   admin.routes          — очередь модерации
 *   admin.menu            — пункт меню с числом сообщений на проверке
 *   admin.twig.init       — шаблоны админки
 *   admin.current_section — подсветка пункта меню
 */

use Core\PluginManager;
use Plugins\Community\Csrf;
use Plugins\Community\Endpoint;
use Plugins\Community\Forum;
use Plugins\Community\Render;
use Plugins\Community\Settings;
use Plugins\Community\Store;
use Twig\TwigFunction;

$pm = PluginManager::getInstance();
$pluginDir = __DIR__;

/**
 * Есть ли колонка из «ALTER TABLE x ADD COLUMN y ...».
 * Нужно, чтобы повторный запуск миграции не писал ошибку в лог.
 */
function self_column_exists($db, string $statement): bool
{
    if (!preg_match('/ALTER\s+TABLE\s+["\'`]?([a-z0-9_]+)["\'`]?\s+ADD\s+COLUMN\s+["\'`]?([a-z0-9_]+)/i', $statement, $m)) {
        return false;
    }

    try {
        foreach ($db->query("PRAGMA table_info(\"{$m[1]}\")")->fetchAll() as $column) {
            if (strcasecmp((string) ($column['name'] ?? ''), $m[2]) === 0) {
                return true;
            }
        }
    } catch (\Throwable $e) {
        return false;
    }

    return false;
}

// === Автозагрузка классов плагина ===

spl_autoload_register(function ($class) use ($pluginDir) {
    $prefix = 'Plugins\\Community\\';
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
    // Все миграции по порядку номеров. Повторный запуск безопасен: в 001 стоят
    // IF NOT EXISTS, а 002 добавляет колонки — их отсутствие проверяем сами,
    // потому что SQLite не умеет ADD COLUMN IF NOT EXISTS.
    $files = glob($pluginDir . '/migrations/*.sql') ?: [];
    sort($files);

    foreach ($files as $migration) {
        $sql = (string) file_get_contents($migration);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            // ALTER TABLE ... ADD COLUMN повторно падает — это нормально и значит,
            // что колонка уже есть. Такой шум в лог не пишем.
            if (stripos($statement, 'ADD COLUMN') !== false && self_column_exists($db, $statement)) {
                continue;
            }

            try {
                $db->exec($statement);
            } catch (\Throwable $e) {
                if (stripos($statement, 'ADD COLUMN') === false) {
                    error_log('community: миграция ' . basename($migration) . ' — ' . $e->getMessage());
                }
            }
        }
    }

    // Каналы по умолчанию: пустой форум выглядит сломанным, а владельцу
    // проще переименовать готовый раздел, чем создавать с нуля.
    try {
        (new Store($db, new Settings()))->ensureDefaultChannels();
    } catch (\Throwable $e) {
        error_log('community: каналы по умолчанию — ' . $e->getMessage());
    }
}, 10, 'community');

// === Twig: шаблоны и функции ===

$pm->addAction('twig.init', function ($fc, $twig) use ($pluginDir) {
    $loader = $twig->getLoader();
    if ($loader instanceof \Twig\Loader\FilesystemLoader) {
        $loader->addPath($pluginDir . '/views', 'community');
    }

    // FrontController держит Twig приватно — запоминаем ссылку, чтобы страницы
    // плагина рендерились в теме сайта (наследуя base.html.twig).
    Render::bind($fc, $twig);

    $twig->addFunction(new TwigFunction('community_url', function (string $path = '') {
        $prefix = (new Settings())->forumPrefix();
        $path = trim($path, '/');
        return '/' . $prefix . ($path === '' ? '' : '/' . $path);
    }));

    $twig->addFunction(new TwigFunction('community_csrf', function () {
        return Csrf::field();
    }));

    // Склонение числительных: 1 участник, 2 участника, 5 участников.
    // Без него в интерфейсе появляется «1 участников» и «2 тем».
    $twig->addFunction(new TwigFunction('plural', function (int $n, string $one, string $few, string $many) {
        $n = abs($n) % 100;
        if ($n >= 11 && $n <= 14) {
            return $many;
        }
        switch ($n % 10) {
            case 1:  return $one;
            case 2:
            case 3:
            case 4:  return $few;
            default: return $many;
        }
    }));

    // Ручная вставка блока комментариев в шаблон темы, если контент страницы
    // печатается не как page.content (запасной путь для нестандартных тем).
    $twig->addFunction(new TwigFunction('community_comments', function ($page) {
        if (empty($page['id'])) {
            return '';
        }
        return (new \Plugins\Community\Comments())->block(
            'page:' . (int) $page['id'],
            (string) ($page['title'] ?? 'Страница')
        );
    }, ['is_safe' => ['html']]));
}, 10, 'community');

// === Публичные маршруты ===

$pm->addAction('front.router.before', function ($path, $fc) {
    // Обработчик, который уже отдал ответ, обязан завершить запрос: иначе
    // FrontController дорисует свою 404 поверх и перетрёт статус.
    if (Endpoint::handle($path)) {
        exit;
    }

    // Сообщества раньше форума: у них свои адреса (/community/<slug>),
    // а темы внутри сообщества обрабатывает уже Forum.
    if ((new \Plugins\Community\Communities())->handle($path)) {
        exit;
    }

    if ((new Forum())->handle($path)) {
        exit;
    }
}, 20, 'community');

// === Комментарии под текстом страницы ===

$pm->addFilter('front.render', function ($data, $template) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return $data;
    }

    $comments = new \Plugins\Community\Comments();

    if (!$comments->enabled() || $comments->excluded()) {
        return $data;
    }

    $target = \Plugins\Community\Comments::target($data, (string) $template);
    if ($target === null) {
        return $data;
    }

    $html = $comments->block($target['code'], $target['subject']);
    if ($html === '') {
        return $data;
    }

    // Блок запоминаем: на front.output проверим, доехал ли он до страницы.
    \Plugins\Community\Comments::remember($html);

    if ($comments->position() === 'end') {
        return $data; // всё равно вставим в конец страницы
    }

    // Дописываем к контенту, а не в конец страницы: тогда блок оказывается
    // под статьёй, а не после подвала, и шаблоны тем править не нужно.
    // Ключи page/post/item — всё, что ядро передаёт при рендере одной записи.
    foreach (['page', 'post', 'item'] as $key) {
        if (isset($data[$key]['id']) && is_string($data[$key]['content'] ?? null)) {
            $data[$key]['content'] .= $html;
            break;
        }
    }

    // Если шаблон печатает контент не как <ключ>.content (карточка каталога
    // грузит текст отдельным запросом), блок на страницу не попадёт —
    // тогда его поставит front.output по маркеру.
    return $data;
}, 10, 'community');

// === Стили и скрипт ===

$pm->addFilter('front.output', function ($html) use ($pluginDir) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return $html;
    }

    // Комментарии: если блок отрисован, но шаблон темы его не напечатал
    // (карточка каталога, товар, любая своя вёрстка без page.content),
    // ставим его в конец <main>. Так работает без правки шаблонов.
    $block = \Plugins\Community\Comments::rendered();
    if ($block !== null && strpos($html, \Plugins\Community\Comments::MARKER) === false) {
        $position = (new \Plugins\Community\Settings())->get('comments_position', 'auto');

        if ($position !== 'content') {
            if (stripos($html, '</main>') !== false) {
                $html = preg_replace('#</main>#i', $block . '</main>', $html, 1) ?? $html;
            } elseif (stripos($html, '</body>') !== false) {
                $html = preg_replace('#</body>#i', $block . '</body>', $html, 1) ?? $html;
            }
        }
    }

    if (strpos($html, 'apidcms-community') !== false) {
        return $html;
    }

    $stamp = (string) @filemtime($pluginDir . '/assets/community.css')
        . (string) @filemtime($pluginDir . '/assets/community.js');
    $version = substr(md5($stamp), 0, 8);

    $snippet = '<link rel="stylesheet" href="/community/css?v=' . $version . '">'
        . '<script src="/community/js?v=' . $version . '" defer></script>'
        . '<!-- apidcms-community -->';

    if (stripos($html, '</head>') !== false) {
        return preg_replace('#</head>#i', $snippet . '</head>', $html, 1) ?? $html;
    }

    return $html;
}, 10, 'community');

// === Админка ===

$pm->addAction('admin.routes', function ($app) {
    $app->addRoute('/community', 'Plugins\\Community\\AdminCommunityController', 'index');
    $app->addRoute('/community/moderate', 'Plugins\\Community\\AdminCommunityController', 'moderate');
    $app->addRoute('/community/application', 'Plugins\\Community\\AdminCommunityController', 'application');
    $app->addRoute('/community/channel', 'Plugins\\Community\\AdminCommunityController', 'channel');
}, 10, 'community');

$pm->addFilter('admin.menu', function ($items) {
    $pending = 0;
    try {
        $db = PluginManager::getInstance()->getDatabase();
        if ($db) {
            $pending = (new Store($db, new Settings()))->pendingCount();
        }
    } catch (\Throwable $e) {
        $pending = 0;
    }

    $items[] = [
        'title' => '💬 Сообщество' . ($pending > 0 ? ' (' . $pending . ')' : ''),
        'url' => '/community',
        'section' => 'community',
        'order' => 75,
    ];

    return $items;
}, 10, 'community');

$pm->addAction('admin.twig.init', function ($controller, $twig) use ($pluginDir) {
    $loader = $twig->getLoader();
    if ($loader instanceof \Twig\Loader\FilesystemLoader) {
        $loader->addPath($pluginDir . '/views', 'community');
    }
}, 10, 'community');

$pm->addFilter('admin.current_section', function ($section, $path) {
    if (strpos((string) $path, '/community') === 0) {
        return 'community';
    }
    return $section;
}, 10, 'community');
