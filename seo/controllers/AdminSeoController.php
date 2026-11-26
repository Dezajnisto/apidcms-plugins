<?php
/**
 * Админка плагина SEO.
 *
 * Три экрана: обзор сайта (оценки и проблемы), редактор одной страницы
 * (поля + чек-лист + превью сниппета), журнал 404 и редиректы.
 * Оценки хранятся в базе: обзор не должен пересчитывать весь сайт на каждый
 * заход — на каталоге из тысяч записей это секунды ожидания.
 */

namespace Plugins\Seo;

class AdminSeoController extends \Admin\BaseController
{
    private Inventory $inventory;
    private Analyzer $analyzer;
    private Store $store;
    private Settings $settings;

    public function __construct($app)
    {
        parent::__construct($app);
        $this->settings = new Settings();
        $this->inventory = new Inventory($this->db);
        $this->analyzer = new Analyzer($this->settings);
        $this->store = new Store($this->db);
    }

    // ===== Обзор =====

    public function index(): void
    {
        $filter = (string) ($_GET['filter'] ?? 'all');
        $search = trim((string) ($_GET['q'] ?? ''));

        $docs = $this->inventory->all(false);
        $meta = $this->store->all();

        $rows = [];
        $counts = [
            'all' => 0,
            'problems' => 0,
            'unchecked' => 0,
            'good' => 0,
            'warn' => 0,
            'bad' => 0,
        ];
        $scoreSum = 0;
        $scoreCount = 0;

        foreach ($docs as $doc) {
            $row = $meta[$doc['key']] ?? [];
            $score = isset($row['score']) && $row['score'] !== null ? (int) $row['score'] : null;

            $counts['all']++;
            if ($score === null) {
                $counts['unchecked']++;
            } else {
                $scoreSum += $score;
                $scoreCount++;
                if ($score >= Analyzer::SCORE_GOOD) {
                    $counts['good']++;
                } elseif ($score >= Analyzer::SCORE_OK) {
                    $counts['warn']++;
                } else {
                    $counts['bad']++;
                }
                if ($score < Analyzer::SCORE_OK) {
                    $counts['problems']++;
                }
            }

            // Что реально уходит на сайт и откуда взялось. «Пусто» в колонке
            // описания при заполненной колонке content — ложь: посетитель
            // видит текст из контента, а не пустой тег.
            $effective = Meta::effective($doc, $row, $this->settings);

            $rows[] = [
                'key' => $doc['key'],
                'url' => $doc['url'],
                'title' => $doc['title'],
                'type' => $doc['type'],
                'table' => $doc['table'],
                'id' => $doc['id'],
                'score' => $score,
                'level' => $score === null ? 'none' : Analyzer::level($score),
                'checked_at' => $row['checked_at'] ?? null,
                'focus_keyword' => $row['focus_keyword'] ?? '',
                'description' => (string) ($effective['description'] ?? ''),
                'description_own' => trim((string) ($row['description'] ?? '')) !== '',
                'title_own' => trim((string) ($row['title'] ?? '')) !== '',
            ];
        }

        // Фильтр и поиск — по уже собранным строкам, без повторных запросов
        $filtered = array_values(array_filter($rows, function ($row) use ($filter, $search) {
            if ($filter === 'problems' && !($row['score'] !== null && $row['score'] < Analyzer::SCORE_OK)) {
                return false;
            }
            if ($filter === 'unchecked' && $row['score'] !== null) {
                return false;
            }
            if ($filter === 'good' && !($row['score'] !== null && $row['score'] >= Analyzer::SCORE_GOOD)) {
                return false;
            }
            if ($search !== '') {
                $haystack = mb_strtolower($row['url'] . ' ' . $row['title'] . ' ' . $row['focus_keyword']);
                if (mb_strpos($haystack, mb_strtolower($search)) === false) {
                    return false;
                }
            }
            return true;
        }));

        usort($filtered, function ($a, $b) {
            $sa = $a['score'] === null ? 101 : $a['score'];
            $sb = $b['score'] === null ? 101 : $b['score'];
            return $sa <=> $sb;
        });

        $this->render('@seo/index.html.twig', [
            'title' => 'SEO',
            'rows' => array_slice($filtered, 0, 200),
            'total_filtered' => count($filtered),
            'counts' => $counts,
            'avg' => $scoreCount > 0 ? (int) round($scoreSum / $scoreCount) : null,
            'filter' => $filter,
            'search' => $search,
            'ai_ready' => (new Ai($this->db))->ready(),
            'settings_obj' => new Settings(),
            'redirects_count' => $this->countRows('plugin_seo_redirect'),
            'notfound_count' => $this->countRows('plugin_seo_404'),
        ]);
    }

    // ===== Редактор страницы =====

    public function edit(): void
    {
        $key = (string) ($_GET['key'] ?? '');
        $parsed = Store::parseKey($key);
        if (!$parsed) {
            $this->setFlash('error', 'Неизвестная страница');
            $this->redirect('/seo');
            return;
        }

        [$type, $table, $id] = $parsed;
        $doc = $this->inventory->find($type, $table, $id);
        if ($doc === null) {
            $this->setFlash('error', 'Страница не найдена в контенте — возможно, она удалена');
            $this->redirect('/seo');
            return;
        }

        $row = $this->store->get($type, $table, $id);

        // Дубли заголовков на сайте: считается по всем документам без
        // контента — загружать HTML всех страниц ради двух счётчиков нельзя.
        $titles = [];
        $descriptions = [];
        $allMeta = $this->store->all();
        foreach ($this->inventory->all(false) as $light) {
            $e = Meta::effective($light, $allMeta[$light['key']] ?? [], new Settings());
            $t = mb_strtolower(trim((string) $e['title']));
            if ($t !== '') {
                $titles[$t] = ($titles[$t] ?? 0) + 1;
            }
            $d = mb_strtolower(trim((string) $e['description']));
            if ($d !== '') {
                $descriptions[$d] = ($descriptions[$d] ?? 0) + 1;
            }
        }
        $this->analyzer->setSiteCounts($titles, $descriptions);

        $result = $this->analyzer->analyze($doc, $row);

        $this->render('@seo/edit.html.twig', [
            'title' => 'SEO: ' . ($doc['title'] !== '' ? $doc['title'] : $doc['url']),
            'key' => $key,
            'doc' => $doc,
            'row' => $row,
            'result' => $result,
            'stats' => $result['stats'],
            'ai_ready' => (new Ai($this->db))->ready(),
            'has_content' => Analyzer::plain((string) $doc['content']) !== '',
        ]);
    }

    public function save(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/seo');
            return;
        }

        $key = (string) ($_POST['key'] ?? '');
        $parsed = Store::parseKey($key);
        if (!$parsed) {
            $this->setFlash('error', 'Неизвестная страница');
            $this->redirect('/seo');
            return;
        }

        [$type, $table, $id] = $parsed;

        $data = [];
        foreach (['title', 'description', 'focus_keyword', 'canonical', 'og_title', 'og_description', 'og_image'] as $field) {
            $data[$field] = (string) ($_POST[$field] ?? '');
        }
        $data['robots'] = (string) ($_POST['robots'] ?? '');

        if (!empty($_POST['reset'])) {
            // Кнопка «вернуть как в контенте» — снять SEO-поля целиком
            $this->store->forget($type, $table, $id);
            Sitemap::invalidate();
            $this->setFlash('success', 'SEO-поля сброшены, работают значения из контента');
            $this->redirect('/seo/edit?key=' . urlencode($key));
            return;
        }

        $this->store->save($type, $table, $id, $data);

        $doc = $this->inventory->find($type, $table, $id);
        if ($doc !== null) {
            $fresh = $this->store->get($type, $table, $id);
            $result = $this->analyzer->analyze($doc, $fresh);
            $this->store->saveScore($type, $table, $id, $result['score']);
        }

        Sitemap::invalidate();
        $this->setFlash('success', 'Сохранено, оценка пересчитана');
        $this->redirect('/seo/edit?key=' . urlencode($key));
    }

    /**
     * Генерация заголовка и описания нейросетью. JSON, вызывается из
     * редактора без перезагрузки страницы.
     */
    public function generate(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $key = (string) ($_POST['key'] ?? '');
            $parsed = Store::parseKey($key);
            if (!$parsed) {
                throw new \Exception('Неизвестная страница');
            }
            [$type, $table, $id] = $parsed;

            $doc = $this->inventory->find($type, $table, $id);
            if ($doc === null) {
                throw new \Exception('Страница не найдена в контенте');
            }

            $ai = new Ai($this->db);
            if (!$ai->ready()) {
                throw new \Exception('Не задан ключ DeepSeek: встроенный ассистент → Настройки → AI');
            }

            $keyword = trim((string) ($_POST['focus_keyword'] ?? ''));

            // Какое именно поле просят: заголовок, описание, фразы или всё.
            // Отдельные промты короче и точнее — модель не отвлекается на
            // три задачи сразу, а ответ влезает в 200-300 токенов.
            $field = (string) ($_POST['field'] ?? 'all');
            if (!in_array($field, ['all', 'title', 'description', 'keyword'], true)) {
                $field = 'all';
            }

            $generated = $ai->generate($doc, $keyword, $field);

            echo json_encode(['ok' => true, 'data' => $generated], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            http_response_code(200); // ошибка — прикладная, не HTTP
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    /**
     * Пересчёт оценок порциями: браузер вызывает endpoint с offset, пока
     * не получит done. Так на большом каталоге не упираемся в max_execution_time.
     */
    public function scan(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $offset = max(0, (int) ($_GET['offset'] ?? 0));
            $limit = min(50, max(1, (int) ($_GET['limit'] ?? 25)));

            $light = $this->inventory->all(false);
            $total = count($light);
            $slice = array_slice($light, $offset, $limit);

            // Дубли считаем один раз, по метаданным и заголовкам документов
            $titles = [];
            $descriptions = [];
            $allMeta = $this->store->all();
            foreach ($light as $doc) {
                $e = Meta::effective($doc, $allMeta[$doc['key']] ?? [], new Settings());
                $t = mb_strtolower(trim((string) $e['title']));
                if ($t !== '') {
                    $titles[$t] = ($titles[$t] ?? 0) + 1;
                }
                $d = mb_strtolower(trim((string) $e['description']));
                if ($d !== '') {
                    $descriptions[$d] = ($descriptions[$d] ?? 0) + 1;
                }
            }
            $this->analyzer->setSiteCounts($titles, $descriptions);

            $scored = 0;
            foreach ($slice as $meta) {
                // Контент грузим только для тех страниц, которые сейчас проверяем
                $doc = $this->inventory->find($meta['type'], $meta['table'], $meta['id']);
                if ($doc === null) {
                    $scored++;
                    continue;
                }
                $row = $allMeta[$doc['key']] ?? [];
                $result = $this->analyzer->analyze($doc, $row);
                $this->store->saveScore($doc['type'], $doc['table'], $doc['id'], $result['score']);
                $scored++;
            }

            $next = $offset + $scored;
            $finished = $next >= $total || $scored === 0;

            // Последняя порция: убираем строки, которых больше нет в контенте.
            // Иначе после смены состава документов в отчёте навсегда остаются
            // призраки — записи таблиц, которые перестали быть страницами.
            $purged = $finished ? $this->purgeStale($light) : 0;

            echo json_encode([
                'ok' => true,
                'total' => $total,
                'done' => $next,
                'finished' => $finished,
                'purged' => $purged,
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    // ===== Редиректы =====

    public function redirects(): void
    {
        $rows = [];
        try {
            $rows = $this->db->query(
                "SELECT * FROM plugin_seo_redirect ORDER BY hits DESC, id DESC"
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $rows = [];
        }

        $this->render('@seo/redirects.html.twig', [
            'title' => 'Редиректы',
            'rows' => $rows,
            'prefill_from' => (string) ($_GET['from'] ?? ''),
        ]);
    }

    public function saveRedirect(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/seo/redirects');
            return;
        }

        $from = Inventory::normalizePath((string) ($_POST['from_path'] ?? ''));
        $to = trim((string) ($_POST['to_path'] ?? ''));

        if ($from === '/' || $to === '') {
            $this->setFlash('error', 'Укажите старый и новый адрес');
            $this->redirect('/seo/redirects');
            return;
        }
        if (preg_match('#^https?://#i', $to) === 0) {
            $to = Inventory::normalizePath($to);
        }
        if ($from === $to) {
            $this->setFlash('error', 'Адрес совпадает с новым — редирект замкнётся сам на себя');
            $this->redirect('/seo/redirects');
            return;
        }

        try {
            $this->db->query(
                "INSERT INTO plugin_seo_redirect (from_path, to_path) VALUES (?, ?)
                 ON CONFLICT(from_path) DO UPDATE SET to_path = excluded.to_path",
                [$from, $to]
            );
            $this->setFlash('success', 'Редирект сохранён: ' . $from . ' → ' . $to);
        } catch (\Throwable $e) {
            $this->setFlash('error', 'Не сохранилось: ' . $e->getMessage());
        }

        $this->redirect('/seo/redirects');
    }

    public function deleteRedirect($id = ''): void
    {
        try {
            $this->db->query('DELETE FROM plugin_seo_redirect WHERE id = ?', [(int) $id]);
            $this->setFlash('success', 'Редирект удалён');
        } catch (\Throwable $e) {
            $this->setFlash('error', 'Не удалилось: ' . $e->getMessage());
        }
        $this->redirect('/seo/redirects');
    }

    // ===== Журнал 404 =====

    public function notFound(): void
    {
        $rows = [];
        try {
            $rows = $this->db->query(
                "SELECT * FROM plugin_seo_404 ORDER BY hits DESC, last_seen DESC LIMIT 300"
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $rows = [];
        }

        // Ближайший существующий адрес для каждого промаха — подсказка,
        // куда ставить редирект. Сравнение грубое, но полезное.
        $urls = [];
        foreach ($this->inventory->sitemapDocs() as $doc) {
            $urls[] = $doc['url'];
        }

        foreach ($rows as &$row) {
            $row['suggest'] = self::closest((string) $row['path'], $urls);
        }
        unset($row);

        $this->render('@seo/notfound.html.twig', [
            'title' => 'Ошибки 404',
            'rows' => $rows,
        ]);
    }

    public function deleteNotFound($id = ''): void
    {
        try {
            $this->db->query('DELETE FROM plugin_seo_404 WHERE id = ?', [(int) $id]);
            $this->setFlash('success', 'Запись удалена');
        } catch (\Throwable $e) {
            $this->setFlash('error', 'Не удалилось: ' . $e->getMessage());
        }
        $this->redirect('/seo/notfound');
    }

    // ===== Sitemap =====

    public function rebuildSitemap(): void
    {
        Sitemap::invalidate();
        $this->setFlash('success', 'Карта сайта пересобрана: /sitemap.xml');
        $this->redirect('/seo');
    }

    // ===== Служебное =====

    /**
     * Удалить SEO-строки сущностей, которых больше нет в контенте.
     *
     * @param array<int, array<string,mixed>> $docs текущий состав документов
     */
    private function purgeStale(array $docs): int
    {
        try {
            $this->db->exec('CREATE TEMP TABLE IF NOT EXISTS seo_live_keys (
                entity_type TEXT, entity_table TEXT, entity_id INTEGER
            )');
            $this->db->exec('DELETE FROM seo_live_keys');

            foreach ($docs as $doc) {
                $this->db->query(
                    'INSERT INTO seo_live_keys (entity_type, entity_table, entity_id) VALUES (?, ?, ?)',
                    [(string) $doc['type'], (string) $doc['table'], (int) $doc['id']]
                );
            }

            $before = $this->countRows('plugin_seo_meta');
            $this->db->exec(
                'DELETE FROM plugin_seo_meta WHERE NOT EXISTS (
                    SELECT 1 FROM seo_live_keys k
                     WHERE k.entity_type = plugin_seo_meta.entity_type
                       AND k.entity_table = plugin_seo_meta.entity_table
                       AND k.entity_id = plugin_seo_meta.entity_id
                )'
            );

            return max(0, $before - $this->countRows('plugin_seo_meta'));
        } catch (\Throwable $e) {
            error_log('seo: очистка устаревших строк не удалась — ' . $e->getMessage());
            return 0;
        }
    }

    private function countRows(string $table): int
    {
        try {
            $row = $this->db->query("SELECT COUNT(*) AS c FROM {$table}")->fetch(\PDO::FETCH_ASSOC);
            return (int) ($row['c'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Самый похожий адрес из существующих: сначала по последнему сегменту,
     * потом по расстоянию Левенштейна.
     *
     * @param array<int,string> $urls
     */
    public static function closest(string $path, array $urls): ?string
    {
        $needle = trim($path, '/');
        if ($needle === '' || $urls === []) {
            return null;
        }

        $needleTail = '';
        $parts = explode('/', $needle);
        $needleTail = end($parts) ?: '';

        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach ($urls as $url) {
            $candidate = trim($url, '/');
            $candidateTail = '';
            $parts = explode('/', $candidate);
            $candidateTail = end($parts) ?: '';

            $distance = levenshtein(
                mb_strtolower(mb_substr($needleTail, 0, 100)),
                mb_strtolower(mb_substr($candidateTail, 0, 100))
            );
            if ($candidateTail === $needleTail && $needleTail !== '') {
                $distance = 0;
            } else {
                $distance += 2; // предпочитаем совпадение хвоста адреса
            }

            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $url;
            }
        }

        // Слишком далёкое совпадение бесполезно как подсказка
        $limit = max(3, (int) round(mb_strlen($needleTail) * 0.6));
        return $bestDistance <= $limit ? $best : null;
    }
}
