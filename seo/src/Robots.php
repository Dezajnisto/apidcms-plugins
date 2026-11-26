<?php
/**
 * robots.txt, если своего файла на сайте нет.
 *
 * Плагин не «улучшает» чужой robots.txt: в режиме auto он отдаёт свой только
 * тогда, когда в навигации CMS нет страницы robots.txt. Иначе правки владельца
 * сайта молча перекрывались бы — а robots.txt ломает индексацию тихо.
 */

namespace Plugins\Seo;

class Robots
{
    public static function build(Settings $settings): string
    {
        $lines = [
            'User-agent: *',
            'Allow: /',
            'Disallow: /admin/',
            'Disallow: /admin',
            'Disallow: /storage/database/',
            'Disallow: /core/',
            'Disallow: /config/',
            'Disallow: /plugins/',
            'Disallow: /api/',
            'Disallow: /*?s=',
            'Disallow: /*?page=',
            '',
            '# Страница ошибки не должна попадать в индекс',
            'Disallow: /404',
            '',
        ];

        if ($settings->bool('sitemap_enabled', true)) {
            $lines[] = 'Sitemap: ' . Meta::absolute('/sitemap.xml');
        }

        return implode("\n", $lines) . "\n";
    }
}
