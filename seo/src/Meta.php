<?php
/**
 * Мета-теги: что реально уходит в <head>.
 *
 * Тема сайта задаёт свой <title> и description, поэтому плагин не просит
 * шаблон о помощи, а аккуратно правит готовый HTML: заменяет title, убирает
 * дубли description/robots/canonical/og и вставляет свой блок.
 */

namespace Plugins\Seo;

class Meta
{
    /**
     * Итоговые значения для документа: SEO-поле, если заполнено, иначе
     * значение из контента.
     *
     * @return array<string,mixed>
     */
    public static function effective(array $doc, array $row, Settings $settings): array
    {
        $title = trim((string) ($row['title'] ?? ''));
        $description = trim((string) ($row['description'] ?? ''));
        $image = trim((string) ($row['og_image'] ?? ''));

        $titleSource = $title !== '' ? 'seo' : 'content';
        if ($title === '') {
            $title = trim((string) ($doc['title'] ?? ''));
        }
        if ($description === '') {
            $description = trim((string) ($doc['description'] ?? ''));
        }
        if ($image === '') {
            $image = trim((string) ($doc['image'] ?? ''));
        }
        if ($image === '') {
            $image = trim((string) $settings->get('default_og_image', ''));
        }

        $robots = trim((string) ($row['robots'] ?? ''));
        if ($robots === '') {
            $robots = 'index,follow';
        }

        $canonical = trim((string) ($row['canonical'] ?? ''));
        $canonical = $canonical !== '' ? self::absolute($canonical) : self::absolute((string) ($doc['url'] ?? '/'));

        return [
            'title' => $title,
            'display_title' => self::applyTemplate($title, (string) $settings->get('title_suffix', '')),
            'title_source' => $titleSource,
            'description' => $description,
            'description_source' => trim((string) ($row['description'] ?? '')) !== '' ? 'seo' : 'content',
            'image' => $image,
            'robots' => $robots,
            'canonical' => $canonical,
            'focus_keyword' => trim((string) ($row['focus_keyword'] ?? '')),
            'og_title' => trim((string) ($row['og_title'] ?? '')) !== '' ? trim((string) $row['og_title']) : $title,
            'og_description' => trim((string) ($row['og_description'] ?? '')) !== '' ? trim((string) $row['og_description']) : $description,
            'type' => ($doc['type'] ?? '') === 'record' ? 'article' : 'website',
        ];
    }

    /** Подставить заголовок в шаблон «{title} — Сайт». */
    public static function applyTemplate(string $title, string $template): string
    {
        $template = trim($template);
        if ($template === '') {
            return $title;
        }
        if (strpos($template, '{title}') !== false) {
            return trim(str_replace('{title}', $title, $template));
        }
        return trim($title . ' ' . $template);
    }

    /**
     * Блок тегов для вставки в <head>.
     *
     * @param array<string,mixed> $e итог из effective()
     * @param array<string,mixed> $doc документ (для JSON-LD)
     */
    public static function head(array $e, array $doc, Settings $settings, string $siteName): string
    {
        $out = [];

        // Невалидный UTF-8 (текст из 1С, Word, чужой базы) даёт пустую строку
        // в htmlspecialchars — тег выходит пустым, и страница теряет заголовок.
        $esc = static function ($value): string {
            $value = (string) $value;
            if ($value !== '' && !preg_match('//u', $value)) {
                $value = Store::toUtf8($value);
            }
            return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        };

        if (($e['title'] ?? '') !== '') {
            $out[] = '<title>' . $esc($e['display_title']) . '</title>';
        }
        if (($e['description'] ?? '') !== '') {
            $out[] = '<meta name="description" content="' . $esc($e['description']) . '">';
        }
        $out[] = '<meta name="robots" content="' . $esc($e['robots']) . '">';
        $out[] = '<link rel="canonical" href="' . $esc($e['canonical']) . '">';

        if ($settings->bool('og_enabled', true)) {
            $out[] = '<meta property="og:type" content="' . $esc($e['type']) . '">';
            $out[] = '<meta property="og:url" content="' . $esc($e['canonical']) . '">';
            if ($siteName !== '') {
                $out[] = '<meta property="og:site_name" content="' . $esc($siteName) . '">';
            }
            if (($e['og_title'] ?? '') !== '') {
                $out[] = '<meta property="og:title" content="' . $esc($e['og_title']) . '">';
            }
            if (($e['og_description'] ?? '') !== '') {
                $out[] = '<meta property="og:description" content="' . $esc($e['og_description']) . '">';
            }
            if (($e['image'] ?? '') !== '') {
                $image = self::absolute($e['image']);
                $out[] = '<meta property="og:image" content="' . $esc($image) . '">';
                $out[] = '<meta name="twitter:card" content="summary_large_image">';
                $out[] = '<meta name="twitter:image" content="' . $esc($image) . '">';
            } else {
                $out[] = '<meta name="twitter:card" content="summary">';
            }
            if (($e['og_title'] ?? '') !== '') {
                $out[] = '<meta name="twitter:title" content="' . $esc($e['og_title']) . '">';
            }
            if (($e['og_description'] ?? '') !== '') {
                $out[] = '<meta name="twitter:description" content="' . $esc($e['og_description']) . '">';
            }
        }

        if ($settings->bool('jsonld_enabled', true)) {
            foreach (self::jsonLd($e, $doc, $siteName) as $node) {
                $json = json_encode($node, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (is_string($json)) {
                    $out[] = '<script type="application/ld+json">' . $json . '</script>';
                }
            }
        }

        return "\n    " . implode("\n    ", $out) . "\n";
    }

    /**
     * Микроразметка: WebSite для всего сайта, хлебные крошки для вложенных
     * адресов, Article для записей таблиц.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function jsonLd(array $e, array $doc, string $siteName): array
    {
        $nodes = [];
        $url = (string) ($e['canonical'] ?? '');

        $site = [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'url' => self::baseUrl() . '/',
        ];
        if ($siteName !== '') {
            $site['name'] = $siteName;
        }
        $nodes[] = $site;

        $path = (string) parse_url($url, PHP_URL_PATH);
        $segments = array_values(array_filter(explode('/', trim($path, '/')), fn($s) => $s !== ''));
        if (count($segments) >= 2) {
            $items = [];
            $acc = '';
            foreach ($segments as $i => $segment) {
                $acc .= '/' . $segment;
                $items[] = [
                    '@type' => 'ListItem',
                    'position' => $i + 1,
                    'name' => $i === count($segments) - 1 ? (string) $e['title'] : $segment,
                    'item' => self::baseUrl() . $acc,
                ];
            }
            $nodes[] = [
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => $items,
            ];
        }

        if (($doc['type'] ?? '') === 'record' && ($e['title'] ?? '') !== '') {
            $article = [
                '@context' => 'https://schema.org',
                '@type' => 'Article',
                'headline' => (string) $e['title'],
                'mainEntityOfPage' => $url,
            ];
            if (($e['description'] ?? '') !== '') {
                $article['description'] = (string) $e['description'];
            }
            if (($e['image'] ?? '') !== '') {
                $article['image'] = self::absolute((string) $e['image']);
            }
            $date = (string) ($doc['updated_at'] ?? '');
            if ($date !== '' && strtotime($date)) {
                $article['dateModified'] = date('c', strtotime($date));
            }
            $nodes[] = $article;
        }

        return $nodes;
    }

    /** Абсолютный адрес из пути от корня. */
    public static function absolute(string $path): string
    {
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        return self::baseUrl() . '/' . ltrim($path, '/');
    }

    /** Схема и хост сайта (с учётом прокси). */
    public static function baseUrl(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (($_SERVER['SERVER_PORT'] ?? '') === '443');
        $scheme = $https ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost'));

        return $scheme . '://' . $host;
    }
}
