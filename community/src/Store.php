<?php
/**
 * Слой данных сообщества: каналы, темы, посты.
 *
 * Ключевая идея (взята у rainlab/forum-plugin): тема — обычная запись, у которой
 * может быть embed_code. Пустой код — тема форума, заполненный — тема-комментарии
 * к странице сайта. Поэтому комментарии не требуют ни своих таблиц, ни своего
 * кода: это те же посты, что и в форуме, только в скрытом канале.
 */

namespace Plugins\Community;

class Store
{
    public const MODE_FORUM = 'forum';
    public const MODE_COMMENTS = 'comments';
    public const MODE_COMMUNITY = 'community';

    private \Core\Database $db;
    private Settings $settings;
    private ?bool $hasAccounts = null;

    public function __construct(?\Core\Database $db = null, ?Settings $settings = null)
    {
        if ($db === null) {
            $pm = \Core\PluginManager::getInstance();
            $db = $pm->getDatabase();
            if (!$db) {
                throw new \RuntimeException('community: нет соединения с БД');
            }
        }
        $this->db = $db;
        $this->settings = $settings ?? new Settings();
    }

    public function db(): \Core\Database
    {
        return $this->db;
    }

    public function settings(): Settings
    {
        return $this->settings;
    }

    /**
     * Есть ли на сайте плагин account (таблица пользователей).
     *
     * Сообщество умеет работать и без него: тогда форум только для чтения,
     * а комментарии пишут гости. Это нужно для сайтов, где регистрации нет
     * вовсе — например, для витрины самого apidcms.
     */
    public function hasAccounts(): bool
    {
        if ($this->hasAccounts === null) {
            try {
                $row = $this->db->query(
                    "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'plugin_account_users'"
                )->fetch();
                $this->hasAccounts = (bool) $row;
            } catch (\Throwable $e) {
                $this->hasAccounts = false;
            }
        }

        return $this->hasAccounts;
    }

    /** JOIN пользователей — только если таблица есть, иначе NULL-поля. */
    private function userJoin(string $alias = 'u'): string
    {
        return $this->hasAccounts()
            ? "LEFT JOIN plugin_account_users {$alias} ON {$alias}.id = "
            : '';
    }

    // ---------------------------------------------------------------- каналы

    /** @return array<int,array<string,mixed>> */
    public function channels(string $mode = '', bool $includeHidden = false): array
    {
        $sql = "SELECT * FROM plugin_community_channels WHERE 1=1";
        $params = [];

        if ($mode !== '') {
            $sql .= " AND mode = ?";
            $params[] = $mode;
        }
        if (!$includeHidden) {
            $sql .= " AND is_hidden = 0";
        }
        $sql .= " ORDER BY sort_order, title";

        return $this->db->query($sql, $params)->fetchAll() ?: [];
    }

    public function channelBySlug(string $slug): ?array
    {
        $row = $this->db->query(
            "SELECT * FROM plugin_community_channels WHERE slug = ?",
            [$slug]
        )->fetch();

        return $row ?: null;
    }

    public function channel(int $id): ?array
    {
        $row = $this->db->query(
            "SELECT * FROM plugin_community_channels WHERE id = ?",
            [$id]
        )->fetch();

        return $row ?: null;
    }

    /** Служебный канал комментариев: берём по настройке, создаём при необходимости. */
    public function commentsChannel(): array
    {
        $slug = trim((string) $this->settings->get('comments_channel', 'comments')) ?: 'comments';
        $channel = $this->channelBySlug($slug);
        if ($channel) {
            return $channel;
        }

        $this->db->query(
            "INSERT INTO plugin_community_channels (slug, title, description, mode, is_hidden, sort_order)
             VALUES (?, ?, ?, ?, 1, 100)",
            [$slug, 'Комментарии к страницам', 'Служебный канал: темы создаются автоматически', self::MODE_COMMENTS]
        );

        return $this->channelBySlug($slug) ?? [];
    }

    public function ensureDefaultChannels(): void
    {
        $existing = (int) ($this->db->query(
            "SELECT COUNT(*) AS c FROM plugin_community_channels"
        )->fetch()['c'] ?? 0);

        if ($existing > 0) {
            return;
        }

        $defaults = [
            ['news', 'Новости и объявления', 'Что нового на сайте', self::MODE_FORUM, 0, 10],
            ['help', 'Помощь и вопросы', 'Спрашиваем, отвечаем, разбираемся', self::MODE_FORUM, 0, 20],
            ['comments', 'Комментарии к страницам', 'Служебный канал: темы создаются автоматически', self::MODE_COMMENTS, 1, 100],
        ];

        foreach ($defaults as [$slug, $title, $description, $mode, $hidden, $order]) {
            $this->db->query(
                "INSERT INTO plugin_community_channels (slug, title, description, mode, is_hidden, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?)",
                [$slug, $title, $description, $mode, $hidden, $order]
            );
        }
    }

    // ---------------------------------------------------------------- темы

    /** @return array{items:array<int,array<string,mixed>>,total:int} */
    public function topics(int $channelId, int $page = 1, int $perPage = 20): array
    {
        $total = (int) ($this->db->query(
            "SELECT COUNT(*) AS c FROM plugin_community_topics WHERE channel_id = ?",
            [$channelId]
        )->fetch()['c'] ?? 0);

        $offset = max(0, ($page - 1) * $perPage);
        $author = $this->hasAccounts() ? 'u.name AS author_name' : 'NULL AS author_name';
        $join = $this->hasAccounts()
            ? 'LEFT JOIN plugin_account_users u ON u.id = t.start_user_id'
            : '';

        $items = $this->db->query(
            "SELECT t.*, {$author}
               FROM plugin_community_topics t
               {$join}
              WHERE t.channel_id = ?
              ORDER BY t.is_sticky DESC, COALESCE(t.last_post_at, t.created_at) DESC
              LIMIT ? OFFSET ?",
            [$channelId, $perPage, $offset]
        )->fetchAll() ?: [];

        return ['items' => $items, 'total' => $total];
    }

    public function topic(int $id): ?array
    {
        $author = $this->hasAccounts() ? 'u.name AS author_name' : 'NULL AS author_name';
        $join = $this->hasAccounts()
            ? 'LEFT JOIN plugin_account_users u ON u.id = t.start_user_id'
            : '';

        $row = $this->db->query(
            "SELECT t.*, {$author}, c.slug AS channel_slug, c.title AS channel_title,
                    c.mode AS channel_mode, c.is_moderated AS channel_moderated
               FROM plugin_community_topics t
               {$join}
               LEFT JOIN plugin_community_channels c ON c.id = t.channel_id
              WHERE t.id = ?",
            [$id]
        )->fetch();

        return $row ?: null;
    }

    public function topicBySlug(int $channelId, string $slug): ?array
    {
        $row = $this->db->query(
            "SELECT id FROM plugin_community_topics WHERE channel_id = ? AND slug = ?",
            [$channelId, $slug]
        )->fetch();

        return $row ? $this->topic((int) $row['id']) : null;
    }

    /** Тема, привязанная к странице. Именно эта функция делает страницу темой. */
    public function topicByEmbed(string $code, int $channelId): ?array
    {
        $row = $this->db->query(
            "SELECT id FROM plugin_community_topics WHERE embed_code = ? AND channel_id = ? LIMIT 1",
            [$code, $channelId]
        )->fetch();

        return $row ? $this->topic((int) $row['id']) : null;
    }

    /**
     * Найти или создать тему для страницы.
     *
     * Повторный вызов не создаёт дубль: уникальный индекс (channel_id, slug)
     * и повторная проверка после неудачной вставки (два посетителя одновременно
     * открыли страницу впервые).
     */
    public function ensureEmbedTopic(string $code, int $channelId, string $subject): ?array
    {
        $existing = $this->topicByEmbed($code, $channelId);
        if ($existing) {
            return $existing;
        }

        $slug = $this->uniqueSlug($channelId, 'page-' . substr(md5($code), 0, 10));

        try {
            $this->db->query(
                "INSERT INTO plugin_community_topics
                    (channel_id, slug, subject, embed_code, start_user_id, last_post_at)
                 VALUES (?, ?, ?, ?, 0, NULL)",
                [$channelId, $slug, mb_substr($subject, 0, 250), $code]
            );
        } catch (\Throwable $e) {
            // гонка: пока мы собирались вставить, тему создал другой запрос
        }

        return $this->topicByEmbed($code, $channelId);
    }

    public function createTopic(int $channelId, string $subject, int $userId = 0): ?array
    {
        $slug = $this->uniqueSlug($channelId, $this->slugify($subject));

        $this->db->query(
            "INSERT INTO plugin_community_topics
                (channel_id, slug, subject, start_user_id, last_post_at)
             VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)",
            [$channelId, $slug, mb_substr($subject, 0, 250), $userId]
        );

        $id = (int) $this->db->query("SELECT last_insert_rowid() AS id")->fetch()['id'];
        $this->refreshChannelCounters($channelId);

        return $this->topic($id);
    }

    public function lockTopic(int $topicId, bool $locked): void
    {
        $this->db->query(
            "UPDATE plugin_community_topics SET is_locked = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
            [$locked ? 1 : 0, $topicId]
        );
    }

    public function stickTopic(int $topicId, bool $sticky): void
    {
        $this->db->query(
            "UPDATE plugin_community_topics SET is_sticky = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
            [$sticky ? 1 : 0, $topicId]
        );
    }

    public function countView(int $topicId): void
    {
        $this->db->query(
            "UPDATE plugin_community_topics SET count_views = count_views + 1 WHERE id = ?",
            [$topicId]
        );
    }

    // ---------------------------------------------------------------- посты

    /**
     * @param bool $withPending показывать ли автору его сообщения на проверке
     * @return array{items:array<int,array<string,mixed>>,total:int}
     */
    public function posts(int $topicId, int $page = 1, int $perPage = 30, bool $withPending = false, int $viewerId = 0): array
    {
        // Свои сообщения на проверке показываем только вошедшему автору: гостя
        // опознать нечем, а показывать всем чужие непроверенные нельзя.
        $withPending = $withPending && $viewerId > 0;
        $statusSql = $withPending
            ? "AND (p.status = 'published' OR (p.status = 'pending' AND p.user_id = ?))"
            : "AND p.status = 'published'";
        $params = $withPending ? [$topicId, $viewerId] : [$topicId];

        $total = (int) ($this->db->query(
            "SELECT COUNT(*) AS c FROM plugin_community_posts p WHERE p.topic_id = ? {$statusSql}",
            $params
        )->fetch()['c'] ?? 0);

        $offset = max(0, ($page - 1) * $perPage);
        $author = $this->hasAccounts()
            ? 'u.name AS user_name, u.avatar AS user_avatar'
            : 'NULL AS user_name, NULL AS user_avatar';
        $join = $this->hasAccounts()
            ? 'LEFT JOIN plugin_account_users u ON u.id = p.user_id'
            : '';

        $items = $this->db->query(
            "SELECT p.*, {$author}
               FROM plugin_community_posts p
               {$join}
              WHERE p.topic_id = ? {$statusSql}
              ORDER BY p.created_at ASC, p.id ASC
              LIMIT ? OFFSET ?",
            array_merge($params, [$perPage, $offset])
        )->fetchAll() ?: [];

        return ['items' => $items, 'total' => $total];
    }

    /** Последние посты канала — для списка тем и для «свежего». */
    public function lastPosts(int $channelId, int $limit = 10): array
    {
        $author = $this->hasAccounts()
            ? 'COALESCE(u.name, p.guest_name) AS author'
            : 'p.guest_name AS author';
        $join = $this->hasAccounts()
            ? 'LEFT JOIN plugin_account_users u ON u.id = p.user_id'
            : '';

        return $this->db->query(
            "SELECT p.id, p.content, p.created_at, t.slug AS topic_slug, t.subject, {$author}
               FROM plugin_community_posts p
               JOIN plugin_community_topics t ON t.id = p.topic_id
               {$join}
              WHERE t.channel_id = ? AND p.status = 'published'
              ORDER BY p.created_at DESC
              LIMIT ?",
            [$channelId, $limit]
        )->fetchAll() ?: [];
    }

    public function post(int $id): ?array
    {
        $author = $this->hasAccounts() ? 'u.name AS user_name' : 'NULL AS user_name';
        $join = $this->hasAccounts()
            ? 'LEFT JOIN plugin_account_users u ON u.id = p.user_id'
            : '';

        $row = $this->db->query(
            "SELECT p.*, {$author} FROM plugin_community_posts p
               {$join}
              WHERE p.id = ?",
            [$id]
        )->fetch();

        return $row ?: null;
    }

    public function addPost(array $data): int
    {
        $this->db->query(
            "INSERT INTO plugin_community_posts
                (topic_id, user_id, guest_name, guest_email, parent_id, content, content_html,
                 status, count_links, ip_hash, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)",
            [
                (int) $data['topic_id'],
                (int) ($data['user_id'] ?? 0),
                (string) ($data['guest_name'] ?? ''),
                (string) ($data['guest_email'] ?? ''),
                (int) ($data['parent_id'] ?? 0),
                (string) ($data['content'] ?? ''),
                (string) ($data['content_html'] ?? ''),
                (string) ($data['status'] ?? 'published'),
                (int) ($data['count_links'] ?? 0),
                (string) ($data['ip_hash'] ?? ''),
            ]
        );

        $id = (int) $this->db->query("SELECT last_insert_rowid() AS id")->fetch()['id'];
        $this->refreshTopicCounters((int) $data['topic_id']);

        return $id;
    }

    public function setPostStatus(int $postId, string $status): void
    {
        $this->db->query(
            "UPDATE plugin_community_posts SET status = ? WHERE id = ?",
            [$status, $postId]
        );

        $row = $this->db->query(
            "SELECT topic_id FROM plugin_community_posts WHERE id = ?",
            [$postId]
        )->fetch();

        if ($row) {
            $this->refreshTopicCounters((int) $row['topic_id']);
        }
    }

    public function deletePost(int $postId): void
    {
        $this->setPostStatus($postId, 'deleted');
    }

    public function pending(int $limit = 100): array
    {
        $author = $this->hasAccounts()
            ? 'COALESCE(u.name, p.guest_name) AS author'
            : 'p.guest_name AS author';
        $join = $this->hasAccounts()
            ? 'LEFT JOIN plugin_account_users u ON u.id = p.user_id'
            : '';

        return $this->db->query(
            "SELECT p.*, t.subject, t.slug AS topic_slug, t.channel_id,
                    c.slug AS channel_slug, c.title AS channel_title, {$author}
               FROM plugin_community_posts p
               JOIN plugin_community_topics t ON t.id = p.topic_id
               JOIN plugin_community_channels c ON c.id = t.channel_id
               {$join}
              WHERE p.status = 'pending'
              ORDER BY p.created_at DESC
              LIMIT ?",
            [$limit]
        )->fetchAll() ?: [];
    }

    public function pendingCount(): int
    {
        return (int) ($this->db->query(
            "SELECT COUNT(*) AS c FROM plugin_community_posts WHERE status = 'pending'"
        )->fetch()['c'] ?? 0);
    }

    // ---------------------------------------------------------------- счётчики

    public function refreshTopicCounters(int $topicId): void
    {
        $row = $this->db->query(
            "SELECT COUNT(*) AS c, MAX(created_at) AS last_at
               FROM plugin_community_posts
              WHERE topic_id = ? AND status = 'published'",
            [$topicId]
        )->fetch();

        $last = $this->db->query(
            "SELECT id FROM plugin_community_posts
              WHERE topic_id = ? AND status = 'published'
              ORDER BY created_at DESC, id DESC LIMIT 1",
            [$topicId]
        )->fetch();

        $this->db->query(
            "UPDATE plugin_community_topics
                SET count_posts = ?, last_post_at = ?, last_post_id = ?, updated_at = CURRENT_TIMESTAMP
              WHERE id = ?",
            [(int) ($row['c'] ?? 0), $row['last_at'] ?? null, (int) ($last['id'] ?? 0), $topicId]
        );

        $topic = $this->db->query(
            "SELECT channel_id FROM plugin_community_topics WHERE id = ?",
            [$topicId]
        )->fetch();

        if ($topic) {
            $this->refreshChannelCounters((int) $topic['channel_id']);
        }
    }

    public function refreshChannelCounters(int $channelId): void
    {
        $topics = (int) ($this->db->query(
            "SELECT COUNT(*) AS c FROM plugin_community_topics WHERE channel_id = ?",
            [$channelId]
        )->fetch()['c'] ?? 0);

        $posts = (int) ($this->db->query(
            "SELECT COUNT(*) AS c FROM plugin_community_posts p
               JOIN plugin_community_topics t ON t.id = p.topic_id
              WHERE t.channel_id = ? AND p.status = 'published'",
            [$channelId]
        )->fetch()['c'] ?? 0);

        $this->db->query(
            "UPDATE plugin_community_channels SET count_topics = ?, count_posts = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
            [$topics, $posts, $channelId]
        );
    }

    // ---------------------------------------------------------------- подписки

    public function follow(int $topicId, int $userId, string $notify = 'email'): void
    {
        if ($userId <= 0) {
            return;
        }

        $this->db->query(
            "INSERT INTO plugin_community_followers (topic_id, user_id, notify)
             VALUES (?, ?, ?)
             ON CONFLICT (topic_id, user_id) DO UPDATE SET notify = excluded.notify",
            [$topicId, $userId, $notify]
        );
    }

    public function unfollow(int $topicId, int $userId): void
    {
        $this->db->query(
            "DELETE FROM plugin_community_followers WHERE topic_id = ? AND user_id = ?",
            [$topicId, $userId]
        );
    }

    public function followers(int $topicId): array
    {
        if (!$this->hasAccounts()) {
            return [];
        }

        return $this->db->query(
            "SELECT f.user_id, f.notify, u.email, u.name
               FROM plugin_community_followers f
               JOIN plugin_account_users u ON u.id = f.user_id
              WHERE f.topic_id = ?",
            [$topicId]
        )->fetchAll() ?: [];
    }

    // ---------------------------------------------------------------- мелочи

    private function uniqueSlug(int $channelId, string $base): string
    {
        $base = $base === '' ? 'topic' : $base;
        $slug = $base;
        $i = 2;

        while (true) {
            $exists = $this->db->query(
                "SELECT 1 AS x FROM plugin_community_topics WHERE channel_id = ? AND slug = ?",
                [$channelId, $slug]
            )->fetch();

            if (!$exists) {
                return $slug;
            }

            $slug = $base . '-' . $i;
            $i++;
        }
    }

    /** Транслит в адрес темы: кириллица в URL плохо живёт в мессенджерах и почте. */
    public function slugify(string $text): string
    {
        $map = [
            'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z',
            'и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r',
            'с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'ts','ч'=>'ch','ш'=>'sh',
            'щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
        ];

        $text = mb_strtolower($text);
        $text = strtr($text, $map);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
        $text = trim($text, '-');

        return mb_substr($text, 0, 60) ?: 'topic';
    }
}
