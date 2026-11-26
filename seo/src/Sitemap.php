<?php
/**
 * sitemap.xml: карта сайта из страниц, разделов и записей таблиц.
 *
 * В карту попадает только то, что реально открыто: записи со статусом
 * «активное» и документы без noindex. Страницы с noindex в sitemap — прямой
 * конфликт сигналов, поисковик ругается в панели.
 *
 * XML кэшируется в storage/cache на час: карта тяжёлая, а genережится она
 * на каждый запрос робота. Кнопка «Пересобрать» в админке кэш сбрасывает.
 */

namespace Plugins\Seo;

class Sitemap
{
    private const TTL = 3600;

    public static function build(Inventory $inventory, Settings $settings): string
    {
        $cached = self::fromCache();
        if ($cached !== null) {
            return $cached;
        }

        $docs = $inventory->sitemapDocs();
        $meta = self::metaByKey($inventory);

        // Мультиязычный сайт: каждый язык — отдельный адрес и отдельная
        // запись в карте. Иначе версия /en/... не попадёт в обход вовсе.
        $languages = $inventory->extraLanguages();

        $lines = ['<?xml version="1.0" encoding="UTF-8"?>'];
        $lines[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($docs as $doc) {
            $row = $meta[$doc['key']] ?? [];
            $robots = strtolower((string) ($row['robots'] ?? ''));
            if (strpos($robots, 'noindex') !== false) {
                continue;
            }

            $canonical = trim((string) ($row['canonical'] ?? ''));
            $paths = [(string) $doc['url']];
            if ($canonical === '') {
                foreach ($languages as $lang) {
                    $paths[] = '/' . $lang . ($doc['url'] === '/' ? '' : $doc['url']);
                }
            } else {
                $paths = [$canonical];
            }

            $lastmod = (string) ($row['updated_at'] ?? ($doc['lastmod'] ?? ''));
            $timestamp = $lastmod !== '' ? strtotime($lastmod) : false;

            foreach ($paths as $path) {
                $lines[] = '  <url>';
                $lines[] = '    <loc>' . self::esc(Meta::absolute($path)) . '</loc>';
                if ($timestamp) {
                    $lines[] = '    <lastmod>' . date('Y-m-d', $timestamp) . '</lastmod>';
                }
                $lines[] = '  </url>';
            }
        }

        $lines[] = '</urlset>';
        $xml = implode("\n", $lines) . "\n";

        self::toCache($xml);

        return $xml;
    }

    /**
     * @return array<string, array<string,mixed>>
     */
    private static function metaByKey(Inventory $inventory): array
    {
        try {
            $db = \Core\PluginManager::getInstance()->getDatabase();
            if (!$db) {
                return [];
            }
            $store = new Store($db);
            return $store->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function invalidate(): void
    {
        $file = self::cacheFile();
        if ($file && is_file($file)) {
            @unlink($file);
        }
    }

    private static function fromCache(): ?string
    {
        $file = self::cacheFile();
        if ($file === null || !is_file($file)) {
            return null;
        }
        if (time() - (int) @filemtime($file) > self::TTL) {
            return null;
        }
        $content = @file_get_contents($file);
        return is_string($content) && $content !== '' ? $content : null;
    }

    private static function toCache(string $xml): void
    {
        $file = self::cacheFile();
        if ($file === null) {
            return;
        }
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($file, $xml);
    }

    /**
     * Кэш живёт рядом с другими кэшами сайта, никогда внутри ядра.
     */
    private static function cacheFile(): ?string
    {
        $base = defined('STORAGE_PATH') ? STORAGE_PATH : null;
        if ($base === null) {
            return null;
        }
        return rtrim($base, '/\\') . '/cache/seo/sitemap.xml';
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
