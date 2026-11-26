<?php
/**
 * Плагин «SEO» — init.php
 *
 * Хуки:
 *   db.migrate             — таблицы SEO-полей, редиректов и журнала 404
 *   front.router.before    — 301-редиректы, /sitemap.xml, /robots.txt
 *   front.output           — мета-теги в готовую страницу, журнал 404
 *   twig.init              — функция seo_meta() для тем, которым нужен контроль
 *   admin.routes           — /admin/seo и его действия
 *   admin.menu             — пункт меню с числом проблемных страниц
 *   admin.twig.init        — шаблоны плагина в админке
 *   admin.current_section  — подсветка пункта меню
 */

use Core\PluginManager;

$pm = PluginManager::getInstance();
$pluginDir = __DIR__;

// === Автозагрузка классов плагина ===

spl_autoload_register(function ($class) use ($pluginDir) {
    $prefix = 'Plugins\\Seo\\';
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
    $files = glob($pluginDir . '/migrations/*.sql') ?: [];
    sort($files);

    foreach ($files as $file) {
        $sql = (string) file_get_contents($file);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            try {
                $db->exec($statement);
            } catch (\Throwable $e) {
                error_log('seo: миграция ' . basename($file) . ' не применилась — ' . $e->getMessage());
            }
        }
    }
}, 10, 'seo');

// Плагин мог быть включён без прогона db.migrate (свежая копия plugins/seo
// на уже работающем сайте). Один дешёвый запрос при загрузке: если таблицы
// нет — создаём, иначе SEO молча не работает.
try {
    $db = $pm->getDatabase();
    if ($db) {
        $exists = $db->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'plugin_seo_meta'"
        )->fetch();
        if (!$exists) {
            $pm->doAction('db.migrate', $db);
        }
        \Plugins\Seo\Seo::configure($db);
    }
} catch (\Throwable $e) {
    error_log('seo: инициализация не удалась — ' . $e->getMessage());
}

// === Фронтенд: редиректы и машиночитаемые файлы ===

$pm->addAction('front.router.before', function ($path, $frontController) {
    \Plugins\Seo\Seo::handleRequest((string) $path);
}, 10, 'seo');

// === Фронтенд: мета-теги в готовой странице ===

$pm->addFilter('front.output', function ($html, $frontController = null) {
    if (!is_string($html) || $html === '') {
        return $html;
    }
    return \Plugins\Seo\Seo::injectHead($html);
}, 10, 'seo');

// === Тема сама управляет тегами: {{ seo_meta() }} в <head> ===

$pm->addAction('twig.init', function ($frontController, $twig) {
    try {
        $twig->addFunction(new \Twig\TwigFunction('seo_meta', function () {
            return \Plugins\Seo\Seo::metaForTemplate();
        }, ['is_safe' => ['html']]));

        // Адрес к карте сайта и подтверждение индексации в шаблоне — редко,
        // но полезно: тема может строить свой sitemap-индекс.
        $twig->addFunction(new \Twig\TwigFunction('seo_sitemap_url', function () {
            return \Plugins\Seo\Meta::absolute('/sitemap.xml');
        }));
    } catch (\Throwable $e) {
        error_log('seo: twig-функции не добавлены — ' . $e->getMessage());
    }
}, 10, 'seo');

// === Админка: маршруты ===

$pm->addAction('admin.routes', function ($app) {
    $app->addRoute('/seo', 'Plugins\\Seo\\AdminSeoController', 'index');
    $app->addRoute('/seo/edit', 'Plugins\\Seo\\AdminSeoController', 'edit');
    $app->addRoute('/seo/save', 'Plugins\\Seo\\AdminSeoController', 'save');
    $app->addRoute('/seo/generate', 'Plugins\\Seo\\AdminSeoController', 'generate');
    $app->addRoute('/seo/scan', 'Plugins\\Seo\\AdminSeoController', 'scan');
    $app->addRoute('/seo/redirects', 'Plugins\\Seo\\AdminSeoController', 'redirects');
    $app->addRoute('/seo/redirects/save', 'Plugins\\Seo\\AdminSeoController', 'saveRedirect');
    $app->addRoute('/seo/redirects/delete/{id}', 'Plugins\\Seo\\AdminSeoController', 'deleteRedirect');
    $app->addRoute('/seo/notfound', 'Plugins\\Seo\\AdminSeoController', 'notFound');
    $app->addRoute('/seo/notfound/delete/{id}', 'Plugins\\Seo\\AdminSeoController', 'deleteNotFound');
    $app->addRoute('/seo/sitemap/rebuild', 'Plugins\\Seo\\AdminSeoController', 'rebuildSitemap');
}, 10, 'seo');

// === Админка: пункт меню ===

$pm->addFilter('admin.menu', function ($items) {
    $bad = 0;
    try {
        $db = PluginManager::getInstance()->getDatabase();
        if ($db) {
            $row = $db->query(
                "SELECT COUNT(*) AS c FROM plugin_seo_meta WHERE score IS NOT NULL AND score < 60"
            )->fetch(\PDO::FETCH_ASSOC);
            $bad = (int) ($row['c'] ?? 0);
        }
    } catch (\Throwable $e) {
        $bad = 0;
    }

    $items[] = [
        'title' => 'SEO',
        'url' => '/admin/seo',
        'icon' => 'fas fa-magnifying-glass-chart',
        'section' => 'seo',
        'badge' => $bad,
        'order' => 55,
    ];

    return $items;
}, 10, 'seo');

// === Админка: шаблоны плагина ===

$pm->addAction('admin.twig.init', function ($controller, $twig) use ($pluginDir) {
    $loader = $twig->getLoader();
    if ($loader instanceof \Twig\Loader\FilesystemLoader) {
        $loader->addPath($pluginDir . '/views', 'seo');
    }
}, 10, 'seo');

// === Админка: подсветка своего пункта меню ===

$pm->addFilter('admin.current_section', function ($section, $path) {
    if (strpos((string) $path, '/seo') !== false) {
        return 'seo';
    }
    return $section;
}, 10, 'seo');
