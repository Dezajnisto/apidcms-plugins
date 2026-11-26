<?php
/**
 * Проверка страницы по чек-листу.
 *
 * Анализатор полностью офлайновый: он не ходит в интернет и не спрашивает
 * нейросеть, а читает то, что уже лежит в базе — контент, заголовок, SEO-поля.
 * Поэтому проверка стоит один запрос к БД и не зависит от ключей API.
 * Нейросеть подключается отдельно и только для подсказок.
 */

namespace Plugins\Seo;

class Analyzer
{
    public const TITLE_MIN = 30;
    public const TITLE_MAX = 65;
    public const DESC_MIN = 70;
    public const DESC_MAX = 170;
    public const CONTENT_MIN_WORDS = 300;

    /** Границы оценки: зелёный / жёлтый / красный. Один источник правды. */
    public const SCORE_GOOD = 80;
    public const SCORE_OK = 60;

    private Settings $settings;

    /** @var array<string,int> сколько раз заголовок встречается на сайте */
    private array $titleCounts = [];

    /** @var array<string,int> сколько раз description встречается на сайте */
    private array $descCounts = [];

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Счётчики дублей по всему сайту: нормализованный текст => количество.
     *
     * @param array<string,int> $titles
     * @param array<string,int> $descriptions
     */
    public function setSiteCounts(array $titles, array $descriptions): void
    {
        $this->titleCounts = $titles;
        $this->descCounts = $descriptions;
    }

    /**
     * Проверить документ.
     *
     * @param array<string,mixed> $doc документ из Inventory
     * @param array<string,mixed> $row SEO-поля из Store
     * @return array{score:int, level:string, checks:array, stats:array, meta:array}
     */
    public function analyze(array $doc, array $row): array
    {
        $e = Meta::effective($doc, $row, $this->settings);

        $html = (string) ($doc['content'] ?? '');
        $text = self::plain($html);
        $words = self::words($text);
        $wordCount = count($words);
        $keyword = trim((string) ($e['focus_keyword'] ?? ''));
        $url = (string) ($doc['url'] ?? '/');

        $checks = [];

        // ===== Заголовок =====
        $title = (string) $e['title'];
        $titleLength = mb_strlen($title);

        $checks[] = $this->check('title_present', 'title', 'Заголовок страницы есть', 3,
            $title !== '' ? 'ok' : 'fail',
            $title !== '' ? '«' . self::short($title, 70) . '»' : 'Заголовок не задан ни в контенте, ни в SEO-полях',
            'Заголовок — главный сигнал: без него поисковик покажет адрес страницы.',
            $title !== '');

        $checks[] = $this->check('title_length', 'title', 'Длина заголовка ' . self::TITLE_MIN . '–' . self::TITLE_MAX . ' символов', 3,
            $titleLength === 0 ? 'na' : ($titleLength < self::TITLE_MIN || $titleLength > self::TITLE_MAX ? 'warn' : 'ok'),
            $titleLength === 0 ? 'Нет заголовка' : $titleLength . ' символов',
            $titleLength > self::TITLE_MAX
                ? 'В выдаче видно около ' . self::TITLE_MAX . ' символов, остальное обрежется.'
                : 'Короткий заголовок хуже цепляет: добавьте уточнение или ключевую фразу.',
            $titleLength > 0);

        $titleKey = self::normalize($title);
        $titleDupes = $titleKey !== '' ? ($this->titleCounts[$titleKey] ?? 1) : 1;
        $checks[] = $this->check('title_unique', 'title', 'Заголовок не повторяется на сайте', 2,
            $titleDupes > 1 ? 'warn' : 'ok',
            $titleDupes > 1 ? 'Такой же заголовок ещё у ' . ($titleDupes - 1) . ' страниц' : 'Уникальный',
            'Одинаковые заголовки поисковик считает дублями и выбирает один — остальные теряют позиции.',
            $title !== '');

        // ===== Description =====
        $description = (string) $e['description'];
        $descLength = mb_strlen($description);

        $checks[] = $this->check('description_present', 'description', 'Описание для поиска задано', 3,
            $description !== '' ? 'ok' : 'fail',
            $description !== '' ? $descLength . ' символов' : 'Пусто: поисковик соберёт сниппет сам из случайного куска текста',
            'Описание не влияет на позиции напрямую, но влияет на кликабельность.',
            true);

        $checks[] = $this->check('description_length', 'description', 'Длина описания ' . self::DESC_MIN . '–' . self::DESC_MAX . ' символов', 2,
            $descLength === 0 ? 'na' : ($descLength < self::DESC_MIN || $descLength > self::DESC_MAX ? 'warn' : 'ok'),
            $descLength === 0 ? 'Нет описания' : $descLength . ' символов',
            $descLength > self::DESC_MAX
                ? 'Длинное описание обрежется на ' . self::DESC_MAX . ' символах — смысл должен уместиться в начало.'
                : 'Короткое описание не использует место в выдаче.',
            $descLength > 0);

        $descKey = self::normalize($description);
        $descDupes = $descKey !== '' ? ($this->descCounts[$descKey] ?? 1) : 1;
        $checks[] = $this->check('description_unique', 'description', 'Описание не повторяется', 1,
            $descDupes > 1 ? 'warn' : 'ok',
            $descDupes > 1 ? 'Повторяется ещё на ' . ($descDupes - 1) . ' страницах' : 'Уникальное',
            'Одинаковые описания — признак шаблонного текста.',
            $description !== '');

        // ===== Контент =====
        $h1 = self::matchAll('#<h1\b[^>]*>(.*?)</h1>#is', $html);
        $isRecord = ($doc['type'] ?? '') === 'record';
        $recordTitle = (string) ($doc['title'] ?? '');

        // В apidcms H1 выводит шаблон темы из заголовка записи или страницы,
        // в колонке content его обычно нет и быть не должно. Поэтому пустой
        // content — не проблема, а вот два H1 в тексте — проблема структуры.
        if (count($h1) === 0 && $recordTitle !== '') {
            $h1Status = 'ok';
            $h1Message = 'H1 выводит тема из заголовка страницы';
        } else {
            $h1Status = count($h1) === 1 ? 'ok' : (count($h1) === 0 ? 'fail' : 'warn');
            $h1Message = 'H1 в тексте: ' . count($h1);
        }

        $checks[] = $this->check('h1_count', 'content', 'Ровно один H1', 1,
            $h1Status,
            $h1Message,
            count($h1) > 1
                ? 'Несколько H1 путают структуру: оставьте один, остальные сделайте H2.'
                : 'H1 задаёт главную тему страницы.',
            true);

        $headings = self::matchAll('#<h([2-4])\b[^>]*>#i', $html);
        $checks[] = $this->check('headings', 'content', 'Подзаголовки H2–H4', 1,
            $wordCount === 0 || $isRecord ? 'na' : (count($headings) > 0 ? 'ok' : 'warn'),
            $wordCount === 0 ? 'Нет текста' : 'Подзаголовков: ' . count($headings),
            'Подзаголовки разбивают текст на блоки — по ним поисковик понимает структуру.',
            $wordCount > 0 && !$isRecord);

        $checks[] = $this->check('content_length', 'content', 'Объём текста от ' . self::CONTENT_MIN_WORDS . ' слов', 4,
            $wordCount === 0 ? 'na' : ($wordCount < self::CONTENT_MIN_WORDS ? ($wordCount < 100 ? 'fail' : 'warn') : 'ok'),
            $wordCount === 0 ? 'Нет текста' : $wordCount . ' слов',
            'Короткая страница отвечает на запрос хуже, чем подробная. Исключение — служебные страницы.',
            $wordCount > 0);

        // ===== Ключевая фраза =====
        $keywordChecks = $this->keywordChecks($keyword, $e, $text, $words, $url);
        foreach ($keywordChecks as $check) {
            $checks[] = $check;
        }

        // ===== Ссылки и картинки =====
        $imgs = self::matchAll('#<img\b[^>]*>#i', $html);
        $withoutAlt = 0;
        foreach ($imgs as $img) {
            if (!preg_match('#\balt\s*=\s*(["\'])(.*?)\1#is', $img, $m) || trim($m[2]) === '') {
                $withoutAlt++;
            }
        }
        $checks[] = $this->check('images_alt', 'images', 'У картинок есть alt', 1,
            count($imgs) === 0 ? 'na' : ($withoutAlt === 0 ? 'ok' : ($withoutAlt === count($imgs) ? 'fail' : 'warn')),
            count($imgs) === 0 ? 'Картинок нет' : 'Без alt: ' . $withoutAlt . ' из ' . count($imgs),
            'Alt читают поиск по картинкам и скринридеры.',
            count($imgs) > 0);

        $links = self::matchAll('#<a\b[^>]*href\s*=\s*(["\'])(.*?)\1#is', $html);
        $internal = 0;
        foreach ($links as $link) {
            $href = trim($link);
            if ($href === '' || $href[0] === '#' || strpos($href, 'mailto:') === 0 || strpos($href, 'tel:') === 0) {
                continue;
            }
            if ($href[0] === '/' || strpos($href, Meta::baseUrl()) === 0) {
                $internal++;
            }
        }
        // У записи ссылки обычно в шаблоне (навигация, похожие записи), а не
        // в тексте — проверять по колонке бессмысленно.
        $checks[] = $this->check('internal_links', 'links', 'Есть внутренние ссылки', 1,
            $wordCount === 0 || $isRecord ? 'na' : ($internal > 0 ? 'ok' : 'warn'),
            $wordCount === 0 ? 'Нет текста' : 'Внутренних ссылок: ' . $internal,
            'Ссылки на другие страницы сайта распределяют вес и помогают обходу.',
            $wordCount > 0 && !$isRecord);

        // ===== Адрес =====
        $slug = (string) ($doc['slug'] ?? '');
        $checks[] = $this->check('slug_quality', 'url', 'Адрес читаемый (латиница, дефисы)', 1,
            $this->slugStatus($slug),
            $slug === '' ? 'Адрес пустой' : '/' . ltrim($url, '/'),
            'Кириллица в адресе превращается в %D0%9F…, а /?p=123 ничего не говорит ни человеку, ни поиску.',
            $slug !== '');

        $depth = count(array_filter(explode('/', trim($url, '/')), fn($s) => $s !== ''));
        $checks[] = $this->check('url_depth', 'url', 'Не глубже трёх уровней', 1,
            $depth > 3 ? 'warn' : 'ok',
            'Уровней: ' . $depth,
            'Глубокие адреса хуже обходятся и хуже выглядят в выдаче.',
            true);

        // ===== Соцсети и индексация =====
        $checks[] = $this->check('og_image', 'social', 'Картинка для соцсетей', 1,
            (string) $e['image'] !== '' ? 'ok' : 'warn',
            (string) $e['image'] !== '' ? self::short((string) $e['image'], 60) : 'Нет картинки и нет значения по умолчанию',
            'Без картинки ссылка в мессенджере выглядит серой плашкой.',
            true);

        $noindex = strpos((string) $e['robots'], 'noindex') !== false;
        $checks[] = $this->check('robots_index', 'indexing', 'Страница открыта для индексации', 1,
            $noindex ? 'warn' : 'ok',
            $noindex ? 'Стоит noindex — страница не попадёт в поиск' : 'Индексируется',
            'Иногда noindex нужен (служебные страницы). Если это не намеренно — снимите.',
            true);

        $checks[] = $this->check('canonical', 'indexing', 'Канонический адрес', 0,
            'ok',
            (string) $e['canonical'],
            'Канонический адрес защищает от дублей при параметрах в ссылке.',
            true);

        // ===== Оценка =====
        $earned = 0.0;
        $possible = 0.0;
        foreach ($checks as $check) {
            if ($check['status'] === 'na') {
                continue;
            }
            $possible += $check['weight'];
            $earned += $check['status'] === 'ok' ? $check['weight'] : ($check['status'] === 'warn' ? $check['weight'] * 0.5 : 0);
        }

        $score = $possible > 0 ? (int) round(100 * $earned / $possible) : 0;
        $level = self::level($score);

        $stats = [
            'words' => $wordCount,
            'h1' => count($h1),
            'images' => count($imgs),
            'images_without_alt' => $withoutAlt,
            'links_internal' => $internal,
            'url_depth' => $depth,
            'keyword_density' => $keyword !== ''
                ? round(self::density($text, $keyword) * 100, 2)
                : null,
        ];

        return [
            'score' => $score,
            'level' => $level,
            'checks' => $checks,
            'stats' => $stats,
            'meta' => $e,
            'problems' => count(array_filter($checks, fn($c) => in_array($c['status'], ['warn', 'fail'], true))),
        ];
    }

    /**
     * Проверки ключевой фразы. Без фразы они не считаются (na) и не портят
     * оценку — но и не помогают.
     *
     * @return array<int, array<string,mixed>>
     */
    private function keywordChecks(string $keyword, array $e, string $text, array $words, string $url): array
    {
        $hasKeyword = $keyword !== '';
        $wordCount = count($words);
        $checks = [];

        // Без фразы остальные проверки ключа не считаются — но сам выбор
        // фразы это шаг работы, поэтому он входит в оценку.
        $checks[] = $this->check('keyword_present', 'keyword', 'Ключевая фраза выбрана', 3,
            $hasKeyword ? 'ok' : 'fail',
            $hasKeyword ? '«' . $keyword . '»' : 'Не выбрана',
            'Без ключевой фразы нельзя ответить на главный вопрос: под какой запрос сделана страница.',
            true);

        $inTitle = $hasKeyword && mb_stripos((string) $e['title'], $keyword) !== false;
        $checks[] = $this->check('keyword_in_title', 'keyword', 'Ключевая фраза в заголовке', 2,
            $hasKeyword ? ($inTitle ? 'ok' : 'fail') : 'na',
            $hasKeyword ? ($inTitle ? 'Есть' : 'Нет фразы «' . $keyword . '» в заголовке') : 'Ключевая фраза не выбрана',
            'Фраза в заголовке — самый весомый сигнал релевантности.',
            $hasKeyword);

        $inDesc = $hasKeyword && mb_stripos((string) $e['description'], $keyword) !== false;
        $checks[] = $this->check('keyword_in_description', 'keyword', 'Фраза в описании', 1,
            $hasKeyword ? ($inDesc ? 'ok' : 'warn') : 'na',
            $hasKeyword ? ($inDesc ? 'Есть' : 'Нет') : 'Ключевая фраза не выбрана',
            'В описании фраза подсвечивается в выдаче — это плюс к клику.',
            $hasKeyword);

        $inContent = $hasKeyword && mb_stripos($text, $keyword) !== false;
        $checks[] = $this->check('keyword_in_content', 'keyword', 'Фраза в тексте', 2,
            $hasKeyword && $wordCount > 0 ? ($inContent ? 'ok' : 'fail') : 'na',
            $hasKeyword && $wordCount > 0 ? ($inContent ? 'Есть' : 'Текст не содержит фразу') : 'Нет текста или фразы',
            'Фраза должна встречаться в тексте, иначе страница не отвечает на запрос.',
            $hasKeyword && $wordCount > 0);

        $inUrl = $hasKeyword && mb_stripos($url, str_replace(' ', '-', $keyword)) !== false;
        $checks[] = $this->check('keyword_in_url', 'keyword', 'Фраза в адресе', 1,
            $hasKeyword ? ($inUrl ? 'ok' : 'warn') : 'na',
            $hasKeyword ? ($inUrl ? 'Есть' : 'Нет') : 'Ключевая фраза не выбрана',
            'Адрес с фразой чуть усиливает релевантность и выглядит понятнее.',
            $hasKeyword);

        $density = $hasKeyword && $wordCount > 0 ? self::density($text, $keyword) * 100 : null;
        $densityStatus = 'na';
        if ($density !== null) {
            if ($density > 4.5) {
                $densityStatus = 'warn';
            } elseif ($density >= 0.4) {
                $densityStatus = 'ok';
            } else {
                $densityStatus = 'warn';
            }
        }
        $checks[] = $this->check('keyword_density', 'keyword', 'Плотность фразы 0,4–4,5%', 1,
            $densityStatus,
            $density === null ? 'Фраза не выбрана или нет текста' : $density . '%',
            $density !== null && $density > 4.5
                ? 'Переспам: фразы слишком много, поисковик может посчитать текст неестественным.'
                : 'Слишком редкое упоминание фразы — текст выглядит не по теме.',
            $density !== null);

        return $checks;
    }

    /** Уровень оценки: ok / warn / bad. */
    public static function level(int $score): string
    {
        if ($score >= self::SCORE_GOOD) {
            return 'ok';
        }
        return $score >= self::SCORE_OK ? 'warn' : 'bad';
    }

    /**
     * @return array<string,mixed>
     */
    private function check(string $id, string $group, string $label, int $weight, string $status, string $message, string $hint, bool $applies): array
    {
        if (!$applies) {
            $status = 'na';
        }

        return [
            'id' => $id,
            'group' => $group,
            'label' => $label,
            'weight' => $weight,
            'status' => $status,
            'message' => $message,
            'hint' => $hint,
        ];
    }

    private function slugStatus(string $slug): string
    {
        if ($slug === '') {
            return 'fail';
        }
        if (preg_match('/[а-яё]/iu', $slug)) {
            return 'fail';
        }
        if (strlen($slug) > 75) {
            return 'warn';
        }
        if (preg_match('/[_\s]/', $slug)) {
            return 'warn';
        }
        if (preg_match('/^\d+$/', $slug)) {
            return 'warn';
        }
        return 'ok';
    }

    /** Текст без разметки. */
    public static function plain(string $html): string
    {
        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $text = preg_replace('#<br\s*/?>|</p>|</div>|</li>|</h[1-6]>#i', ' ', $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    /** @return array<int,string> */
    public static function words(string $text): array
    {
        if ($text === '') {
            return [];
        }
        $words = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($words) ? $words : [];
    }

    /** @return array<int,string> */
    private static function matchAll(string $pattern, string $html): array
    {
        if ($html === '') {
            return [];
        }
        if (!preg_match_all($pattern, $html, $m)) {
            return [];
        }
        // Для img/a возвращаем весь тег, для h1 — внутренний текст
        return $m[1] ?? [];
    }

    /** Плотность фразы, доля от 0 до 1 (вхождения × слова фразы / все слова). */
    public static function density(string $text, string $keyword): float
    {
        $words = self::words($text);
        $total = count($words);
        if ($total === 0 || $keyword === '') {
            return 0.0;
        }
        $needle = mb_strtolower($keyword);
        $haystack = mb_strtolower($text);
        $keywordWords = max(1, count(self::words($keyword)));

        $count = 0;
        $offset = 0;
        while (($pos = mb_strpos($haystack, $needle, $offset)) !== false) {
            $count++;
            $offset = $pos + mb_strlen($needle);
        }

        return ($count * $keywordWords) / $total;
    }

    private static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }

    private static function short(string $value, int $limit): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }
        return mb_substr($value, 0, $limit - 1) . '…';
    }
}
