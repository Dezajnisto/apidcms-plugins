<?php
/**
 * CSRF-токены форм сообщества.
 *
 * В ядре своих токенов нет, а формы сообщества принимают сообщения от гостей —
 * без защиты любой чужой сайт мог бы писать в форум от имени посетителя.
 * Токен = метка времени + HMAC от метки и случайного зерна сессии.
 */

namespace Plugins\Community;

class Csrf
{
    private const SESSION_KEY = 'community_csrf_seed';
    private const TTL = 7200; // 2 часа: посетитель успевает написать длинный ответ

    public static function token(): string
    {
        $ts = (string) time();
        return $ts . '.' . self::sign($ts);
    }

    public static function valid($token): bool
    {
        $token = (string) $token;
        if (strpos($token, '.') === false) {
            return false;
        }

        [$ts, $sign] = explode('.', $token, 2);
        if (!ctype_digit($ts)) {
            return false;
        }

        if (abs(time() - (int) $ts) > self::TTL) {
            return false;
        }

        return hash_equals(self::sign($ts), $sign);
    }

    /** Поле формы целиком — чтобы шаблоны не собирали разметку сами. */
    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(self::token(), ENT_QUOTES) . '">';
    }

    private static function sign(string $ts): string
    {
        $secret = (new Settings())->csrfSecret();
        return substr(hash_hmac('sha256', $ts . '|' . self::seed(), $secret), 0, 40);
    }

    private static function seed(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(16));
        }

        return (string) $_SESSION[self::SESSION_KEY];
    }
}
