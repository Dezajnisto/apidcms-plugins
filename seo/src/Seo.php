<?php
/**
 * Фронтенд плагина: редиректы, sitemap.xml, robots.txt и правка <head>.
 *
 * Тема сайта ничего не знает о плагине, поэтому работа идёт в двух точках:
 * до роутинга (машиночитаемые файлы и 301) и на готовом HTML (мета-теги).
 * Если тема хочет управлять тегами сама — есть функция seo_meta().
 */

namespace Plugins\Seo;

class Seo
{
    private const MARKER = '<!--seo-plugin-->';

    private static ?Settings $settings = null;
    private static ?\Core\Database $db = null;
    private static ?Inventory $inventory = null;

    public static function configure(\Core\Database $db): void
    {
        self::$db = $db;
        self::$settings = new Settings();
        self::$inventory = new Inventory($db);
    }

    public static function settings(): Settings
    {
        if (self::$settings === null) {
            self::$settings = new Settings();
        }
        return self::$settings;
    }

    private static function inventory(): Inventory
    {
        if (self::$inventory === null) {
            $db = self::$db ?? (\Core\PluginManager::getInstance()->getDatabase());
            if (!$db) {
                throw new \RuntimeException('seo: база недоступна');
            }
            self::$inventory = new Inventory($db);
        }
        return self::$inventory;
    }

    /**
     * Точка входа на фронте до роутинга ядра.
     */
    public static function handleRequest(string $path): void
    {
        $path = Inventory::normalizePath($path);

        try {
            $redirect = self::redirectFor($path);
            if ($redirect !== null) {
                header('Location: ' . $redirect, true, 301);
                exit;
            }

            if (self::isSitemapPath($path) && self::settings()->bool('sitemap_enabled', true)) {
                if (self::themeOwnsSitemap()) {
                    return;
                }
                header('Content-Type: application/xml; charset=utf-8');
                header('X-Robots-Tag: noindex');
                echo Sitemap::build(self::inventory(), self::settings());
                exit;
            }

            if (self::isRobotsPath($path)) {
                $mode = (string) self::settings()->get('robots_mode', 'auto');
                if ($mode === 'off') {
                    return;
                }
                if ($mode === 'auto' && self::themeOwnsRobots()) {
                    return;
                }
                header('Content-Type: text/plain; charset=utf-8');
                echo Robots::build(self::settings());
                exit;
            }
        } catch (\Throwable $e) {
            // SEO не должен ронять сайт: любая ошибка = обычная страница
            error_log('seo: ' . $e->getMessage());
        }
    }

    /** 301 из таблицы редиректов. */
    private static function redirectFor(string $path): ?string
    {
        try {
            $db = self::$db ?? \Core\PluginManager::getInstance()->getDatabase();
            if (!$db) {
                return null;
            }
            $row = $db->query(
                'SELECT id, to_path, code FROM plugin_seo_redirect WHERE from_path = ?',
                [$path]
            )->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return null;
        }

        if (!$row) {
            return null;
        }

        $to = (string) $row['to_path'];
        if ($to === $path) {
            return null;
        }

        try {
            $db->query('UPDATE plugin_seo_redirect SET hits = hits + 1 WHERE id = ?', [(int) $row['id']]);
        } catch (\Throwable $e) {
            // счётчик не критичен
        }

        return $to;
    }

    private static function isSitemapPath(string $path): bool
    {
        return in_array($path, ['/sitemap.xml', '/sitemap_index.xml'], true);
    }

    private static function isRobotsPath(string $path): bool
    {
        return $path === '/robots.txt';
    }

    /**
     * Своя страница sitemap.xml в навигации — значит владелец сайта уже
     * настроил карту, и плагин не должен её перекрывать.
     */
    private static function themeOwnsSitemap(): bool
    {
        return self::navOwns('sitemap.xml') || self::navOwns('sitemap');
    }

    private static function themeOwnsRobots(): bool
    {
        return self::navOwns('robots.txt') || self::navOwns('robots');
    }

    private static function navOwns(string $url): bool
    {
        try {
            $db = self::$db ?? \Core\PluginManager::getInstance()->getDatabase();
            if (!$db) {
                return false;
            }
            $row = $db->query(
                "SELECT 1 FROM navigation WHERE LOWER(url) IN (?, ?) LIMIT 1",
                [$url, '/' . $url]
            )->fetch(\PDO::FETCH_ASSOC);
            return (bool) $row;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Правка готового HTML: заголовок, description, robots, canonical,
     * Open Graph и JSON-LD. Дубли из темы убираются, чтобы в <head> не было
     * двух description — это самая частая ошибка после установки плагина.
     */
    public static function injectHead(string $html): string
    {
        if ($html === '') {
            return $html;
        }

        if (http_response_code() === 404) {
            self::log404();
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return $html;
        }
        if (stripos($html, '</head>') === false) {
            return $html;
        }
        if (strpos($html, self::MARKER) !== false) {
            return $html; // тема сама вызвала seo_meta()
        }
        if (!self::settings()->bool('meta_enabled', true)) {
            return $html;
        }

        try {
            $path = self::currentPath();
            $doc = self::inventory()->byPath($path);
            $blocks = self::headBlocksFor($doc, $path, $html);
        } catch (\Throwable $e) {
            error_log('seo: meta не собраны — ' . $e->getMessage());
            return $html;
        }

        // У страницы, которой нет в контенте (404, служебный экран), теги
        // темы не трогаем: своего заголовка у нас для неё нет, а снимать
        // чужой — значит оставить её вообще без <title>.
        $clean = $doc === null ? $html : self::stripExisting($html);
        $inserted = preg_replace('#</head>#i', $blocks . self::MARKER . '</head>', $clean, 1);

        return $inserted ?? $html;
    }

    /**
     * Блок тегов для конкретной страницы. Пустой документ (404, служебная
     * страница) получает только noindex — иначе поисковик проиндексирует
     * страницу ошибки.
     *
     * @return string
     */
    public static function headBlocks(string $html = ''): string
    {
        $path = self::currentPath();
        return self::headBlocksFor(self::inventory()->byPath($path), $path, $html);
    }

    /** Путь текущего запроса — без параметров, в едином виде. */
    private static function currentPath(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        return Inventory::normalizePath((string) $path);
    }

    /**
     * @param array<string,mixed>|null $doc
     */
    private static function headBlocksFor(?array $doc, string $path, string $html): string
    {
        $settings = self::settings();
        $siteName = self::siteName();

        if ($doc === null) {
            $is404 = http_response_code() === 404;
            $robots = ($is404 && $settings->bool('noindex_404', true)) ? 'noindex,follow' : 'index,follow';
            return "\n    " . '<meta name="robots" content="' . $robots . '">'
                . (self::alternates($path) !== '' ? "\n    " . self::alternates($path) : '') . "\n";
        }

        $row = self::row($doc);
        $e = Meta::effective($doc, $row, $settings);

        // Канонический адрес берём из запроса: только там есть языковой
        // префикс и нет параметров, которые вообще не должны попадать в
        // canonical. Ручное значение из SEO-поля остаётся главным.
        if (trim((string) ($row['canonical'] ?? '')) === '') {
            $e['canonical'] = Meta::absolute($path);
            $e['og_url'] = $e['canonical'];
        }

        // Дополняем тем, что тема уже вывела: плагин не должен обеднять страницу
        [$themeTitle, $themeDescription, $themeImage] = self::readExisting($html);
        if (trim((string) $e['title']) === '' && $themeTitle !== '') {
            $e['title'] = $themeTitle;
            $e['display_title'] = Meta::applyTemplate($themeTitle, (string) $settings->get('title_suffix', ''));
        }
        if (trim((string) $e['description']) === '' && $themeDescription !== '') {
            $e['description'] = $themeDescription;
        }
        if (trim((string) $e['image']) === '' && $themeImage !== '') {
            $e['image'] = $themeImage;
        }

        $blocks = Meta::head($e, $doc, $settings, $siteName);
        return $blocks . (self::alternates($path) !== '' ? '    ' . self::alternates($path) . "\n" : '');
    }

    /** HTML текущего ответа — для seo_meta() из шаблона. */
    private static ?string $lastHtml = null;

    public static function rememberHtml(string $html): void
    {
        self::$lastHtml = $html;
    }

    /** @return array<string,mixed> */
    private static function row(array $doc): array
    {
        $store = new Store(self::$db ?? \Core\PluginManager::getInstance()->getDatabase());
        return $store->get((string) $doc['type'], (string) $doc['table'], (int) $doc['id']);
    }

    /**
     * Заголовок и описание, которые уже есть в HTML темы.
     *
     * @return array{0:string,1:string,2:string}
     */
    private static function readExisting(string $html): array
    {
        $title = '';
        $description = '';
        $image = '';

        if (preg_match('#<title\b[^>]*>(.*?)</title>#is', $html, $m)) {
            $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if (preg_match('#<meta\s+[^>]*name=["\']description["\'][^>]*content=["\'](.*?)["\']#is', $html, $m)) {
            $description = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        } elseif (preg_match('#<meta\s+[^>]*content=["\'](.*?)["\'][^>]*name=["\']description["\']#is', $html, $m)) {
            $description = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if (preg_match('#<meta\s+[^>]*property=["\']og:image["\'][^>]*content=["\'](.*?)["\']#is', $html, $m)) {
            $image = trim($m[1]);
        }

        return [$title, $description, $image];
    }

    /** Убрать теги, которые плагин выводит сам. */
    private static function stripExisting(string $html): string
    {
        $patterns = [
            '#<title\b[^>]*>.*?</title>\s*#is',
            '#<meta\s+[^>]*name=["\']?(description|robots|keywords)["\']?[^>]*>\s*#is',
            '#<link\s+[^>]*rel=["\']?canonical["\']?[^>]*>\s*#is',
            '#<meta\s+[^>]*(property|name)=["\']?(og:[a-z:_-]+|twitter:[a-z:_-]+)["\']?[^>]*>\s*#is',
            '#<link\s+[^>]*rel=["\']?alternate["\']?[^>]*hreflang[^>]*>\s*#is',
            '#<script\s+type=["\']application/ld\+json["\'][^>]*>.*?</script>\s*#is',
            '#<!--seo-plugin-->#',
        ];

        $result = $html;
        foreach ($patterns as $pattern) {
            $result = preg_replace($pattern, '', $result) ?? $result;
        }
        return $result;
    }

    /** Как называется сайт: og:site_name и JSON-LD. */
    public static function siteName(): string
    {
        try {
            $db = self::$db ?? \Core\PluginManager::getInstance()->getDatabase();
            if (!$db) {
                return '';
            }
            $row = $db->query(
                "SELECT setting_value FROM system_settings WHERE setting_key IN ('site_title','site_name') ORDER BY CASE setting_key WHEN 'site_name' THEN 0 ELSE 1 END LIMIT 1"
            )->fetch(\PDO::FETCH_ASSOC);
            return $row ? trim((string) $row['setting_value']) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * hreflang для мультиязычных сайтов: /en/... , /de/... и x-default.
     * Включается только когда в настройках сайта есть дополнительные языки.
     */
    public static function alternates(string $path): string
    {
        try {
            $db = self::$db ?? \Core\PluginManager::getInstance()->getDatabase();
            if (!$db) {
                return '';
            }
            $rows = $db->query(
                "SELECT setting_key, setting_value FROM system_settings
                  WHERE setting_key IN ('site_language','site_languages')"
            )->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return '';
        }

        $base = 'ru';
        $extra = [];
        foreach ($rows as $row) {
            if ($row['setting_key'] === 'site_language') {
                $base = trim((string) $row['setting_value']) ?: 'ru';
            } else {
                $decoded = json_decode((string) $row['setting_value'], true);
                if (is_array($decoded)) {
                    $extra = array_values(array_filter(array_map('strval', $decoded)));
                }
            }
        }

        if ($extra === []) {
            return '';
        }

        $path = Inventory::normalizePath($path);
        // Убираем префикс языка, если он уже есть в адресе
        foreach ($extra as $lang) {
            if (strpos($path, '/' . $lang . '/') === 0 || $path === '/' . $lang) {
                $path = substr($path, strlen($lang) + 1) ?: '/';
                break;
            }
        }
        if ($base !== 'ru' && (strpos($path, '/' . $base . '/') === 0 || $path === '/' . $base)) {
            $path = substr($path, strlen($base) + 1) ?: '/';
        }

        $lines = [];
        $basePath = $path === '/' ? '/' : $path;
        $lines[] = '<link rel="alternate" hreflang="' . htmlspecialchars($base, ENT_QUOTES) . '" href="'
            . htmlspecialchars(Meta::absolute($basePath), ENT_QUOTES) . '">';

        foreach ($extra as $lang) {
            $lines[] = '<link rel="alternate" hreflang="' . htmlspecialchars($lang, ENT_QUOTES) . '" href="'
                . htmlspecialchars(Meta::absolute('/' . $lang . ($basePath === '/' ? '' : $basePath)), ENT_QUOTES) . '">';
        }
        $lines[] = '<link rel="alternate" hreflang="x-default" href="'
            . htmlspecialchars(Meta::absolute($basePath), ENT_QUOTES) . '">';

        return implode("\n    ", $lines);
    }

    /** Лог 404: что посетители ищут и не находят. */
    public static function log404(): void
    {
        if (!self::settings()->bool('log_404', true)) {
            return;
        }

        try {
            $db = self::$db ?? \Core\PluginManager::getInstance()->getDatabase();
            if (!$db) {
                return;
            }

            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
            $path = Inventory::normalizePath((string) $path);

            // Служебные и технические адреса в отчёт не нужны
            if (preg_match('#^/(admin|storage|core|config|plugins|tmp|api|mcp)(/|$)#i', $path)) {
                return;
            }
            if (preg_match('#\.(php|map|js|css|png|jpe?g|gif|webp|svg|ico|woff2?|ttf|txt|xml)$#i', $path)
                && !preg_match('#\.html$#i', $path)) {
                return;
            }

            $referer = substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 255);
            $db->query(
                "INSERT INTO plugin_seo_404 (path, referer) VALUES (?, ?)
                 ON CONFLICT(path) DO UPDATE SET hits = hits + 1, last_seen = CURRENT_TIMESTAMP",
                [$path, $referer !== '' ? $referer : null]
            );
        } catch (\Throwable $e) {
            // лог — не повод для ошибки
        }
    }

    /**
     * Значения для <?= seo_meta() ?>. Тема, которая хочет контролировать
     * вывод, вызывает функцию в своём <head> и получает готовый блок вместе
     * с маркером — тогда плагин второй раз ничего не вставит.
     */
    public static function metaForTemplate(): string
    {
        if (!self::settings()->bool('meta_enabled', true)) {
            return '';
        }

        try {
            return self::headBlocks((string) (self::$lastHtml ?? '')) . self::MARKER;
        } catch (\Throwable $e) {
            return '';
        }
    }
}
