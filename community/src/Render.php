<?php
/**
 * Рендер страниц сообщества в теме сайта.
 *
 * FrontController держит свой Twig приватно, поэтому ссылку на него (и на сам
 * контроллер) забираем в хуке twig.init — он срабатывает в конструкторе
 * FrontController, до front.router.before. Так шаблоны плагина наследуют
 * base.html.twig темы и выглядят как обычные страницы сайта.
 */

namespace Plugins\Community;

class Render
{
    private static $twig = null;
    private static $fc = null;

    public static function bind($fc, $twig): void
    {
        self::$twig = $twig;
        self::$fc = $fc;
    }

    public static function ready(): bool
    {
        return self::$twig !== null && self::$fc !== null;
    }

    /** Полная страница: заголовок, меню, подвал — всё из темы сайта. */
    public static function page(string $template, array $data = []): void
    {
        echo self::html($template, self::withBase($data));
    }

    /** Кусок разметки — например, блок комментариев под текстом статьи. */
    public static function fragment(string $template, array $data = []): string
    {
        return self::html($template, $data);
    }

    public static function html(string $template, array $data): string
    {
        if (!self::ready()) {
            return '';
        }

        try {
            return (string) self::$twig->render($template, $data);
        } catch (\Throwable $e) {
            error_log('community: шаблон ' . $template . ' — ' . $e->getMessage());
            return '';
        }
    }

    /** Переменные, которые тема ждёт от FrontController::render(). */
    private static function withBase(array $data): array
    {
        $fc = self::$fc;

        $rawPath = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
        $locale = '';
        try {
            $locale = (string) ($fc->getSetting('site_lang') ?: '');
        } catch (\Throwable $e) {
            $locale = '';
        }

        $base = [
            '_GET' => $_GET,
            'navigation' => $fc->getNavigation(),
            'site_title' => $fc->getSetting('site_title') ?: 'Мой сайт',
            'site_description' => $fc->getSetting('site_description') ?: '',
            'custom_css' => $fc->getSetting('custom_css') ?: '',
            'css_version' => $fc->getSetting('custom_css_version') ?: '1',
            'site_lang' => $locale !== '' ? $locale : 'ru',
            'current_path' => $rawPath,
            'session' => new \Core\SessionProxy(),
        ];

        return array_merge($base, $data);
    }
}
