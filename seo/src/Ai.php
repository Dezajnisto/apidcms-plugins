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
    public function generate(array $doc, string $keyword = ''): array
    {
        $title = (string) ($doc['title'] ?? '');
        $text = Analyzer::plain((string) ($doc['content'] ?? ''));
        $text = mb_substr($text, 0, 4000);

        $system = "Ты SEO-редактор. Пишешь заголовок и описание страницы для поисковой выдачи на русском языке. "
            . "Заголовок: 30-65 символов, содержит главную мысль страницы, без кликбейта и без названия сайта. "
            . "Описание: 120-165 символов, конкретно объясняет, что на странице, в конце — мягкий призыв. "
            . "Не используй фразы вроде «лучший», «уникальный», «широкий выбор», если этого нет в тексте. "
            . "Если дана ключевая фраза — она должна естественно войти в заголовок и в описание. "
            . "Ответ — только JSON без пояснений: {\"title\": \"...\", \"description\": \"...\", \"focus_keyword\": \"...\"}. "
            . "focus_keyword — 2-4 слова, самая вероятная поисковая фраза страницы.";

        $prompt = "Адрес страницы: " . ($doc['url'] ?? '/') . "\n"
            . ($title !== '' ? "Текущий заголовок: {$title}\n" : '')
            . ($keyword !== '' ? "Ключевая фраза (обязательна): {$keyword}\n" : '')
            . "\nТекст страницы:\n" . ($text !== '' ? $text : '(текста нет — опирайся на заголовок и адрес)');

        $raw = $this->ai->chat(
            [['role' => 'user', 'content' => $prompt]],
            $system,
            0.4,
            600
        );

        $data = self::parseJson($raw);
        if ($data === null) {
            throw new \Exception('Модель вернула не JSON — попробуйте ещё раз');
        }

        return [
            'title' => self::fit(trim((string) ($data['title'] ?? '')), Analyzer::TITLE_MAX),
            'description' => self::fit(trim((string) ($data['description'] ?? '')), Analyzer::DESC_MAX),
            'focus_keyword' => trim((string) ($data['focus_keyword'] ?? $keyword)),
        ];
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
