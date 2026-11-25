<?php
/**
 * Сообщения «одного показа» после редиректа: форма уходит POST'ом, страница
 * перезагружается, и нужно сказать «отправлено» или «уйдёт после проверки».
 */

namespace Plugins\Community;

class Flash
{
    private const KEY = 'community_flash';

    public static function set(string $type, string $text): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION[self::KEY] = ['type' => $type, 'text' => $text];
    }

    public static function take(): ?array
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $flash = $_SESSION[self::KEY] ?? null;
        unset($_SESSION[self::KEY]);

        return is_array($flash) ? $flash : null;
    }
}
