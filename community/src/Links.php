<?php
/**
 * Адреса тем и сообществ.
 *
 * Одна тема может показываться в трёх местах: как тема форума, как комментарии
 * под страницей и (в будущем) как обсуждение внутри сообщества. Ссылку на неё
 * собираем в одном месте, чтобы письма, админка и шаблоны не расходились.
 */

namespace Plugins\Community;

class Links
{
    private Store $store;

    public function __construct(?Store $store = null)
    {
        $this->store = $store ?? new Store();
    }

    /** Абсолютный адрес темы: страница с комментариями, форум или запись каталога. */
    public function topicUrl(array $topic, bool $absolute = false): string
    {
        $embed = (string) ($topic['embed_code'] ?? '');
        $path = '';

        if ($embed !== '') {
            $path = $this->embedUrl($embed);
        }

        if ($path === '') {
            $prefix = $this->store->settings()->forumPrefix();
            $channelSlug = (string) ($topic['channel_slug'] ?? '');

            if ($channelSlug === '') {
                $channel = $this->store->channel((int) ($topic['channel_id'] ?? 0));
                $channelSlug = (string) ($channel['slug'] ?? '');
            }

            $path = '/' . $prefix
                . ($channelSlug !== '' ? '/' . $channelSlug : '')
                . '/' . (string) ($topic['slug'] ?? '');
        }

        return ($absolute ? $this->host() : '') . $path;
    }

    public function topicUrlById(int $topicId, bool $absolute = false): string
    {
        $topic = $this->store->topic($topicId);
        return $topic ? $this->topicUrl($topic, $absolute) : '';
    }

    /** Адрес страницы, к которой привязана тема, по её embed_code. */
    public function embedUrl(string $code): string
    {
        $parts = explode(':', $code);

        if (($parts[0] ?? '') === 'page' && isset($parts[1])) {
            $page = $this->store->db()->query(
                "SELECT slug FROM pages WHERE id = ?",
                [(int) $parts[1]]
            )->fetch();

            if ($page && (string) $page['slug'] !== '') {
                return '/' . ltrim((string) $page['slug'], '/') . '#comments';
            }
        }

        if (($parts[0] ?? '') === 'item' && isset($parts[1], $parts[2])) {
            $table = (string) $parts[1];
            $id = (int) $parts[2];

            if (!preg_match('/^[a-z0-9_]+$/i', $table)) {
                return '';
            }

            // Раздел, в котором живёт запись: его адрес даёт навигация.
            $nav = $this->store->db()->query(
                "SELECT url FROM navigation WHERE source_table = ? AND status = 'active' LIMIT 1",
                [$table]
            )->fetch();

            $slug = '';
            try {
                $row = $this->store->db()->query(
                    "SELECT slug FROM {$table} WHERE id = ?",
                    [$id]
                )->fetch();
                $slug = (string) ($row['slug'] ?? '');
            } catch (\Throwable $e) {
                $slug = '';
            }

            if ($nav && $slug !== '') {
                return '/' . trim((string) $nav['url'], '/') . '/' . $slug . '#comments';
            }
        }

        return '';
    }

    /** Хост сайта: из настроек, а если там пусто — из текущего запроса. */
    public function host(): string
    {
        try {
            $row = $this->store->db()->query(
                "SELECT setting_value FROM system_settings WHERE setting_key = 'site_url'"
            )->fetch();
            $host = rtrim((string) ($row['setting_value'] ?? ''), '/');
        } catch (\Throwable $e) {
            $host = '';
        }

        if ($host !== '') {
            return $host;
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '');
    }
}
