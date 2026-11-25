<?php
/**
 * Markdown → HTML для сообщений сообщества.
 *
 * Parsedown ядра в safe mode экранирует любой сырой HTML, поэтому <script>
 * из сообщения не пройдёт. Сверху — свой фильтр ссылок и картинок: пропускаем
 * только http(s), относительные адреса и mailto, остальное вырезаем.
 */

namespace Plugins\Community;

class Markdown
{
    public static function toHtml(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        $parsedown = new \Parsedown();
        $parsedown->setSafeMode(true);
        $html = $parsedown->text($text);

        return self::sanitize($html);
    }

    /** Сколько ссылок в тексте — по ним антиспам решает «на проверку или сразу». */
    public static function linkCount(string $text): int
    {
        return (int) preg_match_all('#https?://#i', $text);
    }

    private static function sanitize(string $html): string
    {
        // href/src: только безопасные схемы. Всё прочее — пустая ссылка.
        $html = preg_replace_callback(
            '#\b(href|src)\s*=\s*"([^"]*)"#i',
            function ($m) {
                $value = html_entity_decode($m[2], ENT_QUOTES);
                return $m[1] . '="' . htmlspecialchars(self::safeUrl($value), ENT_QUOTES) . '"';
            },
            $html
        ) ?? $html;

        // Внешние ссылки открываем в новой вкладке и без передачи реферера.
        $html = preg_replace(
            '#<a href="(https?://[^"]+)"#i',
            '<a href="$1" rel="nofollow noopener noreferrer" target="_blank"',
            $html
        ) ?? $html;

        return $html;
    }

    private static function safeUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        // Parsedown кодирует двоеточие в схеме (javascript%3A…), поэтому
        // проверяем схему по раскодированному виду.
        $probe = str_ireplace(['%3a', '&#58;', '&colon;'], ':', $url);

        // javascript:, data:, vbscript: и подобное — вырезаем
        if (preg_match('#^[a-z0-9+.\-]*:#i', $probe, $m)) {
            $scheme = strtolower(rtrim($m[0], ':'));
            if (!in_array($scheme, ['http', 'https', 'mailto'], true)) {
                return '';
            }
        }

        return $url;
    }
}
