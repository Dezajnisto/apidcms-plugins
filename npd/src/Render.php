<?php
/**
 * Рендер страницы чеков в теме сайта.
 *
 * FrontController держит свой Twig приватно, поэтому ссылку на него забираем в
 * хуке twig.init — он срабатывает в конструкторе FrontController, до
 * front.router.before. Иначе страница отдаётся мимо темы (и мимо front.output,
 * на котором висят стили и виджеты других плагинов).
 */

namespace Plugins\Npd;

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
        return self::$twig !== null;
    }

    /** Полная страница в оформлении темы. */
    public static function page(string $template, array $data = []): void
    {
        if (!self::ready()) {
            http_response_code(500);
            echo 'Шаблоны сайта недоступны';
            return;
        }

        $html = (string) self::$twig->render($template, $data);

        try {
            $html = \Core\PluginManager::getInstance()->applyFilters('front.output', $html, self::$fc);
        } catch (\Throwable $e) {
            // без остальных подписчиков страница всё равно рабочая
        }

        echo $html;
    }
}
