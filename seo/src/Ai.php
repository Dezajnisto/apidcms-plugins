<?php
/**
 * Нейросеть для SEO-полей.
 *
 * Ключ берётся из настроек CMS (ai_api_key, ai_model) — тот же, что у
 * встроенного ассистента: пользователю не нужно заводить второй ключ.
 * Нейросеть пишет только текст (заголовок и описание), а не оценку — оценку
 * считает офлайновый анализатор, иначе она зависела бы от настроения модели.
 */

namespace Plugins\Seo;

class Ai
{
    private \Core\AI $ai;

    public function __construct(\Core\Database $db)
    {
        $settings = new \Core\Settings($db);
        $apiKey = (string) $settings->get('ai_api_key', '');
        $model = (string) $settings->get('ai_model', 'deepseek-chat');

        $this->ai = new \Core\AI($apiKey, $model);
    }

    public function ready(): bool
    {
        try {
            $settings = new \Core\Settings(\Core\PluginManager::getInstance()->getDatabase());
            return trim((string) $settings->get('ai_api_key', '')) !== '';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param array<string,mixed> $doc документ из Inventory
     * @param string $keyword ключевая фраза, если уже выбрана
     * @return array{title:string,description:string,focus_keyword:string}
     * @throws \Exception
     */
    public function generate(array $doc, string $keyword = '', string $field = 'all'): array
    {
        $title = (string) ($doc['title'] ?? '');
        $text = Analyzer::plain((string) ($doc['content'] ?? ''));
        $text = mb_substr($text, 0, 4000);

        $context = "Адрес страницы: " . ($doc['url'] ?? '/') . "\n"
            . ($title !== '' ? "Текущий заголовок: {$title}\n" : '')
            . ($keyword !== '' ? "Ключевая фраза (обязательна, входит естественно): {$keyword}\n" : '')
            . "\nТекст страницы:\n" . ($text !== '' ? $text : '(текста нет — опирайся на заголовок и адрес)');

        $common = "Ты SEO-редактор для русскоязычного сайта. Пишешь по фактическому содержанию страницы, "
            . "без кликбейта, без «лучший», «уникальный», «широкий выбор», если этого нет в тексте. "
            . "Ответ — только JSON без пояснений и без markdown.";

        switch ($field) {
            case 'title':
                $system = $common . " Дай один заголовок страницы: 30-65 символов, главная мысль, "
                    . "без названия сайта в конце. Формат: {\"title\": \"...\"}";
                $max = 200;
                break;

            case 'description':
                $system = $common . " Дай одно описание для поисковой выдачи: 120-165 символов, "
                    . "конкретно что на странице, в конце — мягкий призыв. Формат: {\"description\": \"...\"}";
                $max = 300;
                break;

            case 'keyword':
                $system = $common . " Предложи три поисковые фразы, под которые эта страница может "
                    . "отвечать: 2-4 слова каждая, от более общего запроса к более точному, "
                    . "без повторов. Формат: {\"keywords\": [\"...\", \"...\", \"...\"]}";
                $max = 300;
                break;

            default:
                $system = $common . " Дай заголовок (30-65 символов), описание (120-165 символов) "
                    . "и самую вероятную поисковую фразу (2-4 слова). "
                    . "Формат: {\"title\": \"...\", \"description\": \"...\", \"focus_keyword\": \"...\"}";
                $max = 600;
                break;
        }

        $raw = $this->ai->chat(
            [['role' => 'user', 'content' => $context]],
            $system,
            0.4,
            $max
        );

        $data = self::parseJson($raw);
        if ($data === null) {
            throw new \Exception('Модель вернула не JSON — попробуйте ещё раз');
        }

        return self::normalize($data, $keyword, $field);
    }

    /**
     * Привести ответ модели к тому, что ждёт интерфейс: обрезать по длине,
     * выкинуть пустое, для фраз — массив без дублей.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function normalize(array $data, string $keyword, string $field): array
    {
        $out = [];

        if ($field === 'keyword') {
            $list = $data['keywords'] ?? ($data['focus_keyword'] ?? []);
            if (!is_array($list)) {
                $list = [$list];
            }
            $phrases = [];
            foreach ($list as $phrase) {
                $phrase = trim(mb_strtolower((string) $phrase));
                $phrase = preg_replace('/\s+/u', ' ', $phrase) ?? $phrase;
                if ($phrase !== '' && !in_array($phrase, $phrases, true)) {
                    $phrases[] = $phrase;
                }
            }
            return ['keywords' => array_slice($phrases, 0, 3)];
        }

        if (isset($data['title']) && trim((string) $data['title']) !== '') {
            $out['title'] = self::fit(trim((string) $data['title']), Analyzer::TITLE_MAX);
        }
        if (isset($data['description']) && trim((string) $data['description']) !== '') {
            $out['description'] = self::fit(trim((string) $data['description']), Analyzer::DESC_MAX);
        }
        if (isset($data['focus_keyword']) && trim((string) $data['focus_keyword']) !== '') {
            $out['focus_keyword'] = trim((string) $data['focus_keyword']);
        } elseif ($keyword !== '') {
            $out['focus_keyword'] = $keyword;
        }

        return $out;
    }

    /**
     * Модель иногда оборачивает JSON в ```json ... ``` или добавляет текст
     * до него — вытаскиваем первый объект.
     *
     * @return array<string,mixed>|null
     */
    private static function parseJson(string $raw): ?array
    {
        $raw = trim($raw);
        if (preg_match('/```(?:json)?\s*(.*?)```/is', $raw, $m)) {
            $raw = trim($m[1]);
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return null;
    }

    /** Обрезать по границе слова, чтобы не рвать фразу посередине. */
    private static function fit(string $value, int $limit): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }
        $cut = mb_substr($value, 0, $limit);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > $limit * 0.6) {
            $cut = mb_substr($cut, 0, $space);
        }
        return rtrim($cut, " .,;:—-");
    }
}
