<?php
/**
 * Инвентарь контента: что вообще есть на сайте и по каким адресам.
 *
 * CMS хранит контент в двух местах — системные страницы (pages) и записи
 * пользовательских таблиц, которые выводятся через раздел навигации
 * (/blog, /catalog). Адрес записи плагин не может «спросить» у ядра, поэтому
 * собирает его сам по тем же правилам: раздел + slug записи.
 */

namespace Plugins\Seo;

class Inventory
{
    private \Core\Database $db;

    /** Максимум записей на таблицу: защита от гигантских каталогов. */
    private int $perTableLimit = 3000;

    /** Максимум символов контента, которые вообще читаем. */
    private int $contentLimit = 200000;

    public function __construct(\Core\Database $db)
    {
        $this->db = $db;
    }

    /**
     * Все документы сайта без загрузки контента — для отчёта.
     *
     * @return array<int, array<string,mixed>>
     */
    public function all(bool $withContent = true): array
    {
        $docs = [];

        foreach ($this->navItems() as $nav) {
            if ($nav['page_type'] === 'page' && !empty($nav['page_id'])) {
                continue; // страница попадёт в список отдельно, с контентом
            }
            // Раздел — это самостоятельный документ со своим адресом, какой бы
            // тип у него ни был: landing, about, price, reviews, form, dynamic.
            // Исключения: raw и external (машиночитаемые файлы и чужие
            // страницы), поиск и AI-чат (у них нет своего контента).
            if (in_array($nav['page_type'], ['raw', 'external', 'search', 'ai'], true)) {
                continue;
            }
            $docs[] = $this->navDoc($nav, $withContent);
        }

        // Страницы
        try {
            $pages = $this->db->query(
                "SELECT * FROM pages WHERE status IS NULL OR status = '' OR status = 'active'"
            )->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $pages = [];
        }

        $navByPage = [];
        foreach ($this->navItems() as $nav) {
            if (!empty($nav['page_id'])) {
                $navByPage[(int) $nav['page_id']] = $nav;
            }
        }

        foreach ($pages as $page) {
            $docs[] = $this->pageDoc($page, $navByPage[(int) $page['id']] ?? null, $withContent);
        }

        // Записи таблиц разделов.
        //
        // Только page_type = dynamic: именно этот тип в ядре даёт адреса
        // вида /раздел/запись (FrontController::handleDynamicContent).
        // Таблицы блоков (landing, about, price, faq, reviews, form) — это
        // контент одной страницы: у них нет своих URL, и без фильтра плагин
        // выдумывал адреса вроде /glavnaya/35, которые отдают 404.
        $tables = $this->db->getTables();
        foreach ($this->navItems() as $nav) {
            if ((string) ($nav['page_type'] ?? '') !== 'dynamic') {
                continue;
            }
            $table = trim((string) ($nav['source_table'] ?? ''));
            if ($table === '' || !in_array($table, $tables, true)) {
                continue;
            }
            foreach ($this->records($table, $nav, $withContent) as $doc) {
                $docs[] = $doc;
            }
        }

        // Дедупликация: страница может встретиться и в навигации, и в pages
        $unique = [];
        foreach ($docs as $doc) {
            $unique[$doc['key']] = $doc;
        }

        return array_values($unique);
    }

    /**
     * Документ с контентом: то, что уходит в анализатор и в редактор SEO-полей.
     *
     * @return array<string,mixed>|null
     */
    public function find(string $type, string $table, int $id): ?array
    {
        if ($type === 'page') {
            try {
                $page = $this->db->query("SELECT * FROM pages WHERE id = ?", [$id])->fetch(\PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
                return null;
            }
            if (!$page) {
                return null;
            }
            $nav = null;
            foreach ($this->navItems() as $item) {
                if ((int) ($item['page_id'] ?? 0) === $id) {
                    $nav = $item;
                    break;
                }
            }
            return $this->pageDoc($page, $nav);
        }

        if ($type === 'landing') {
            foreach ($this->navItems() as $nav) {
                if ((int) $nav['id'] === $id) {
                    return $this->navDoc($nav);
                }
            }
            return null;
        }

        if ($type === 'record') {
            if (!in_array($table, $this->db->getTables(), true)) {
                return null;
            }
            $quoted = '"' . str_replace('"', '', $table) . '"';
            try {
                $row = $this->db->query("SELECT * FROM {$quoted} WHERE id = ?", [$id])->fetch(\PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
                return null;
            }
            if (!$row) {
                return null;
            }
            $nav = $this->navForTable($table);
            return $this->recordDoc($table, $row, $nav);
        }

        return null;
    }

    /**
     * Документ по адресу запроса: нужен на фронте, чтобы понять, чью
     * SEO-запись выводить.
     *
     * @return array<string,mixed>|null
     */
    public function byPath(string $path): ?array
    {
        $path = self::normalizePath($path);

        // Языковой префикс (/en/oferta) не часть контента: ядро вырезает его
        // до роутинга, а канонический адрес должен остаться с префиксом —
        // его подставит Seo::headBlocks из реального адреса запроса.
        $segments = array_values(array_filter(explode('/', $path), fn($p) => $p !== ''));
        if ($segments !== [] && in_array($segments[0], $this->extraLanguages(), true)) {
            array_shift($segments);
        }

        if ($segments === []) {
            // Главная. Сначала навигация: у части сайтов раздел «/» — это
            // landing без привязанной страницы (page_id пустой), и тогда
            // документ — сам раздел, а не запись в pages. Затем pages:
            // slug бывает 'home', пустым и даже '/'. Все три варианта
            // встречаются на живых сайтах.
            foreach ($this->navItems() as $nav) {
                if (!in_array(self::normalizePath((string) $nav['url']), ['/', '/home'], true)) {
                    continue;
                }
                if (!empty($nav['page_id'])) {
                    $page = $this->find('page', 'pages', (int) $nav['page_id']);
                    if ($page !== null) {
                        return $page;
                    }
                }
                return $this->navDoc($nav);
            }

            try {
                $page = $this->db->query(
                    "SELECT * FROM pages WHERE slug IN ('home', '', '/') LIMIT 1"
                )->fetch(\PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
                $page = null;
            }
            return $page ? $this->pageDoc($page, null) : null;
        }

        $first = $segments[0];
        foreach ($this->navItems() as $nav) {
            $navPath = ltrim(self::normalizePath((string) $nav['url']), '/');
            if ($navPath === '' || $navPath !== $first) {
                continue;
            }

            if (count($segments) === 1) {
                // Сам раздел
                if (!empty($nav['page_id'])) {
                    return $this->find('page', 'pages', (int) $nav['page_id']);
                }
                return $this->navDoc($nav);
            }

            // Только dynamic даёт /раздел/запись: у landing-таблиц (блоки
            // страницы) своих адресов нет, и такой запрос ядро отдаёт как 404.
            if ((string) ($nav['page_type'] ?? '') !== 'dynamic') {
                continue;
            }
            $table = trim((string) ($nav['source_table'] ?? ''));
            if ($table === '') {
                continue;
            }
            $slug = $segments[1];
            $row = $this->recordBySlug($table, $slug);
            return $row ? $this->recordDoc($table, $row, $nav) : null;
        }

        // Страница по slug без навигации
        try {
            $page = $this->db->query(
                "SELECT * FROM pages WHERE slug = ? LIMIT 1",
                [$first]
            )->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $page = null;
        }

        return $page ? $this->pageDoc($page, null) : null;
    }

    /**
     * Документы для карты сайта: без контента и картинок, только адрес и дата.
     *
     * @return array<int, array<string,mixed>>
     */
    public function sitemapDocs(): array
    {
        $docs = [];
        $navs = $this->navItems();

        $navByPage = [];
        $navByTable = [];
        foreach ($navs as $nav) {
            if (!empty($nav['page_id'])) {
                $navByPage[(int) $nav['page_id']] = $nav;
            }
            $table = trim((string) ($nav['source_table'] ?? ''));
            if ($table !== '' && !isset($navByTable[$table]) && (string) ($nav['page_type'] ?? '') === 'dynamic') {
                $navByTable[$table] = $nav;
            }
        }

        // Разделы (списки/лендинги), у которых нет своей страницы.
        // Поиск и AI-чат в карту не идут: у них нет своего контента, а адреса
        // с запросом робот и так не обходит.
        foreach ($navs as $nav) {
            if (!empty($nav['page_id'])) {
                continue;
            }
            if (in_array($nav['page_type'], ['external', 'raw', 'search', 'ai'], true)) {
                continue;
            }
            $docs[] = [
                'key' => Store::makeKey('landing', '', (int) $nav['id']),
                'url' => self::normalizePath((string) $nav['url']),
                'lastmod' => (string) ($nav['updated_at'] ?? ''),
                'type' => 'landing',
                'table' => '',
                'id' => (int) $nav['id'],
            ];
        }

        // Страницы
        try {
            $pages = $this->db->query(
                "SELECT id, slug, updated_at FROM pages
                  WHERE status IS NULL OR status = '' OR status = 'active'"
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $pages = [];
        }

        foreach ($pages as $page) {
            $slug = (string) ($page['slug'] ?? '');
            $nav = $navByPage[(int) $page['id']] ?? null;
            $url = $nav ? (string) $nav['url'] : $slug;
            if (in_array($slug, ['home', ''], true)) {
                $url = '/';
            }
            $docs[] = [
                'key' => Store::makeKey('page', 'pages', (int) $page['id']),
                'url' => self::normalizePath($url),
                'lastmod' => (string) ($page['updated_at'] ?? ''),
                'type' => 'page',
                'table' => 'pages',
                'id' => (int) $page['id'],
            ];
        }

        // Записи таблиц разделов
        $tables = $this->db->getTables();
        foreach ($navByTable as $table => $nav) {
            if (!in_array($table, $tables, true)) {
                continue;
            }
            $columns = $this->columns($table);
            if ($columns === []) {
                continue;
            }

            $select = ['id'];
            $select[] = in_array('slug', $columns, true) ? 'slug' : 'id AS slug';
            $select[] = in_array('updated_at', $columns, true) ? 'updated_at' : 'id AS updated_at';
            $where = in_array('status', $columns, true)
                ? "WHERE status IS NULL OR status = '' OR status = 'active'"
                : '';
            $quoted = '"' . str_replace('"', '', $table) . '"';

            try {
                $rows = $this->db->query(
                    "SELECT " . implode(', ', $select) . " FROM {$quoted} {$where} LIMIT " . $this->perTableLimit
                )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            } catch (\Throwable $e) {
                continue;
            }

            $base = (string) $nav['url'];
            foreach ($rows as $row) {
                $slug = trim((string) ($row['slug'] ?? ''));
                if ($slug === '') {
                    continue;
                }
                $docs[] = [
                    'key' => Store::makeKey('record', $table, (int) $row['id']),
                    'url' => self::normalizePath($base . '/' . $slug),
                    'lastmod' => (string) ($row['updated_at'] ?? ''),
                    'type' => 'record',
                    'table' => $table,
                    'id' => (int) $row['id'],
                ];
            }
        }

        $unique = [];
        foreach ($docs as $doc) {
            $unique[$doc['url']] = $doc;
        }

        return array_values($unique);
    }

    /**
     * Дополнительные языки сайта (site_languages). Нужны, чтобы отличать
     * префикс локали от первого сегмента настоящего адреса.
     *
     * @return array<int,string>
     */
    public function extraLanguages(): array
    {
        try {
            $row = $this->db->query(
                "SELECT setting_value FROM system_settings WHERE setting_key = 'site_languages'"
            )->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }

        $value = trim((string) ($row['setting_value'] ?? ''));
        if ($value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            $decoded = array_map('trim', explode(',', $value));
        }

        return array_values(array_filter(array_map('strval', $decoded)));
    }

    /** @var array<int, array<string,mixed>>|null кэш на один запрос */
    private ?array $navCache = null;

    /**
     * Текущая локаль запроса: префикс из адреса, иначе базовый язык сайта.
     * Нужна, чтобы развернуть мультиязычные поля вида {"ru":"…","en":"…"}.
     */
    public function currentLocale(): string
    {
        $base = 'ru';
        try {
            $row = $this->db->query(
                "SELECT setting_value FROM system_settings WHERE setting_key = 'site_language'"
            )->fetch(\PDO::FETCH_ASSOC);
            $base = trim((string) ($row['setting_value'] ?? '')) ?: 'ru';
        } catch (\Throwable $e) {
            $base = 'ru';
        }

        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $first = explode('/', trim($path, '/'))[0] ?? '';
        if ($first !== '' && in_array($first, $this->extraLanguages(), true)) {
            return $first;
        }

        return $base;
    }

    /**
     * Развернуть мультиязычное поле. В базе title/description могут лежать
     * как {"ru":"…","en":"…"} — без разворота это уехало бы в <title> сырым
     * JSON (встречается на живом сайте).
     */
    private function t($value): string
    {
        $value = (string) $value;
        if ($value === '' || $value[0] !== '{') {
            return $value;
        }
        if (!class_exists('\Core\I18n')) {
            return $value;
        }

        try {
            return (string) \Core\I18n::resolve($value, $this->currentLocale());
        } catch (\Throwable $e) {
            return $value;
        }
    }

    /** @return array<int, array<string,mixed>> */
    private function navItems(): array
    {
        if ($this->navCache !== null) {
            return $this->navCache;
        }

        try {
            $rows = $this->db->query(
                "SELECT * FROM navigation WHERE status IS NULL OR status = '' OR status = 'active' ORDER BY menu_order ASC"
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $rows = [];
        }

        $this->navCache = $rows;
        return $rows;
    }

    private function navForTable(string $table): ?array
    {
        foreach ($this->navItems() as $nav) {
            if (trim((string) ($nav['source_table'] ?? '')) === $table) {
                return $nav;
            }
        }
        return null;
    }

    /** @return array<int, array<string,mixed>> */
    private function records(string $table, array $nav, bool $withContent = true): array
    {
        $columns = $this->columns($table);
        if ($columns === []) {
            return [];
        }
        $quoted = '"' . str_replace('"', '', $table) . '"';
        $where = in_array('status', $columns, true)
            ? "WHERE status IS NULL OR status = '' OR status = 'active'"
            : '';

        try {
            $rows = $this->db->query(
                "SELECT * FROM {$quoted} {$where} LIMIT " . $this->perTableLimit
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }

        $docs = [];
        foreach ($rows as $row) {
            $docs[] = $this->recordDoc($table, $row, $nav, $withContent);
        }
        return $docs;
    }

    /** @return array<string,mixed>|null */
    private function recordBySlug(string $table, string $slug)
    {
        $columns = $this->columns($table);
        if ($columns === []) {
            return null;
        }
        $quoted = '"' . str_replace('"', '', $table) . '"';

        try {
            if (in_array('slug', $columns, true)) {
                return $this->db->query(
                    "SELECT * FROM {$quoted} WHERE slug = ? LIMIT 1",
                    [$slug]
                )->fetch(\PDO::FETCH_ASSOC) ?: null;
            }
            if (preg_match('/^\d+$/', $slug)) {
                return $this->db->query(
                    "SELECT * FROM {$quoted} WHERE id = ? LIMIT 1",
                    [(int) $slug]
                )->fetch(\PDO::FETCH_ASSOC) ?: null;
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    /** @return array<int,string> */
    private function columns(string $table): array
    {
        try {
            return array_map(
                fn($col) => (string) $col['name'],
                $this->db->getTableStructure($table) ?: []
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @return array<string,mixed> */
    private function pageDoc(array $page, ?array $nav, bool $withContent = true): array
    {
        $slug = (string) ($page['slug'] ?? '');
        $url = $nav ? (string) $nav['url'] : $slug;
        if (in_array($slug, ['home', ''], true)) {
            $url = '/';
        }

        return [
            'key' => Store::makeKey('page', 'pages', (int) $page['id']),
            'type' => 'page',
            'table' => 'pages',
            'id' => (int) $page['id'],
            'url' => self::normalizePath($url),
            'title' => trim($this->t($page['title'] ?? '')),
            'slug' => $slug,
            'description' => trim($this->t($page['meta_description'] ?? '')),
            'image' => trim((string) ($page['featured_image'] ?? '')),
            'content' => $withContent ? $this->cut($this->t($page['content'] ?? '')) : '',
            'updated_at' => (string) ($page['updated_at'] ?? ($page['created_at'] ?? '')),
            'template' => (string) ($page['template'] ?? ''),
        ];
    }

    /** @return array<string,mixed> */
    private function navDoc(array $nav, bool $withContent = true): array
    {
        return [
            'key' => Store::makeKey('landing', '', (int) $nav['id']),
            'type' => 'landing',
            'table' => '',
            'id' => (int) $nav['id'],
            'url' => self::normalizePath((string) $nav['url']),
            'title' => trim($this->t($nav['title'] ?? '')),
            'slug' => ltrim(self::normalizePath((string) $nav['url']), '/'),
            'description' => trim($this->t($nav['description'] ?? '')),
            'image' => '',
            // Раздел-лендинг набирает текст из строк своей таблицы
            // (source_table), иначе анализатор видит пустую страницу
            // там, где на самом деле есть контент.
            'content' => $withContent ? $this->landingContent((string) ($nav['source_table'] ?? '')) : '',
            'updated_at' => (string) ($nav['updated_at'] ?? ''),
            'template' => (string) ($nav['template'] ?? ''),
        ];
    }

    /**
     * Текст лендинга: собираем текстовые колонки всех строк source_table.
     * Ограничено, чтобы не вытащить в память каталог целиком.
     */
    private function landingContent(string $table): string
    {
        $table = trim($table);
        if ($table === '' || !in_array($table, $this->db->getTables(), true)) {
            return '';
        }

        $columns = $this->columns($table);
        $textColumns = array_values(array_intersect(
            $columns,
            ['title', 'name', 'h1', 'heading', 'subtitle', 'description', 'content', 'body', 'text', 'excerpt', 'summary', 'intro', 'lead', 'note']
        ));
        if ($textColumns === []) {
            return '';
        }

        $quoted = '"' . str_replace('"', '', $table) . '"';
        $where = in_array('status', $columns, true)
            ? "WHERE status IS NULL OR status = '' OR status = 'active'"
            : '';

        try {
            $rows = $this->db->query(
                "SELECT " . implode(', ', array_map(fn($c) => '"' . $c . '"', $textColumns))
                . " FROM {$quoted} {$where} LIMIT 200"
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return '';
        }

        $html = '';
        foreach ($rows as $row) {
            foreach ($textColumns as $column) {
                $value = trim((string) ($row[$column] ?? ''));
                if ($value !== '') {
                    $html .= '<div>' . $value . '</div>';
                }
            }
        }

        return $this->cut($html);
    }

    /** @return array<string,mixed> */
    private function recordDoc(string $table, array $row, ?array $nav, bool $withContent = true): array
    {
        $columns = array_keys($row);
        $slug = '';
        foreach (['slug', 'alias', 'url'] as $candidate) {
            if (in_array($candidate, $columns, true) && trim((string) $row[$candidate]) !== '') {
                $slug = trim((string) $row[$candidate]);
                break;
            }
        }
        if ($slug === '') {
            $slug = (string) ($row['id'] ?? '');
        }

        $title = '';
        foreach (['title', 'name', 'h1', 'heading', 'question', 'label'] as $candidate) {
            if (in_array($candidate, $columns, true) && trim((string) $row[$candidate]) !== '') {
                $title = trim($this->t($row[$candidate]));
                break;
            }
        }

        $image = '';
        foreach (['featured_image', 'image', 'cover', 'photo', 'thumbnail', 'picture'] as $candidate) {
            if (in_array($candidate, $columns, true) && trim((string) $row[$candidate]) !== '') {
                $image = trim((string) $row[$candidate]);
                break;
            }
        }

        // Описание для мета-тега: у записей тема выводит его из своей колонки,
        // поэтому анализатор должен видеть то же значение, что и посетитель.
        $description = '';
        foreach (['description', 'excerpt', 'summary', 'annotation', 'lead', 'intro', 'short_description'] as $candidate) {
            if (in_array($candidate, $columns, true) && trim((string) $row[$candidate]) !== '') {
                $description = mb_substr(Analyzer::plain((string) $row[$candidate]), 0, 300);
                break;
            }
        }

        $content = '';
        foreach (['content', 'body', 'text', 'description', 'about', 'annotation', 'excerpt', 'summary', 'intro', 'lead', 'note'] as $candidate) {
            if (in_array($candidate, $columns, true) && trim((string) $row[$candidate]) !== '') {
                $content .= '<div>' . (string) $row[$candidate] . '</div>';
            }
        }

        $baseUrl = $nav ? (string) $nav['url'] : $table;

        return [
            'key' => Store::makeKey('record', $table, (int) ($row['id'] ?? 0)),
            'type' => 'record',
            'table' => $table,
            'id' => (int) ($row['id'] ?? 0),
            'url' => self::normalizePath($baseUrl . '/' . $slug),
            'title' => $title,
            'slug' => $slug,
            'description' => $description,
            'image' => $image,
            'content' => $withContent ? $this->cut($content) : '',
            'updated_at' => (string) ($row['updated_at'] ?? ($row['created_at'] ?? ($row['date'] ?? ''))),
            'template' => (string) ($nav['template'] ?? ''),
        ];
    }

    private function cut(string $html): string
    {
        if (strlen($html) > $this->contentLimit) {
            return substr($html, 0, $this->contentLimit);
        }
        return $html;
    }

    /** Единый вид адреса: с ведущим слешем, без хвостового. */
    public static function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || $path === '/') {
            return '/';
        }
        $path = '/' . ltrim($path, '/');
        return rtrim($path, '/') ?: '/';
    }
}
