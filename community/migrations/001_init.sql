-- Плагин «Сообщество»: форум, комментарии, сообщества.
--
-- Схема повторяет rainlab/forum-plugin (channels → topics → posts), плюс:
--   * channels.mode — forum | comments | community, один код на три сценария;
--   * topics.embed_code — привязка темы к странице сайта. Заполнен → тема
--     живёт под страницей как комментарии, пуст → обычная тема форума;
--   * posts.status — published | pending | spam | deleted (модерация);
--   * plugin_community_members — участники сообществ (фаза 2).

CREATE TABLE IF NOT EXISTS plugin_community_channels (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    parent_id     INTEGER NOT NULL DEFAULT 0,
    slug          TEXT NOT NULL UNIQUE,
    title         TEXT NOT NULL DEFAULT '',
    description   TEXT NOT NULL DEFAULT '',
    mode          TEXT NOT NULL DEFAULT 'forum',
    is_hidden     INTEGER NOT NULL DEFAULT 0,
    is_private    INTEGER NOT NULL DEFAULT 0,
    is_moderated  INTEGER NOT NULL DEFAULT 0,
    requires_join INTEGER NOT NULL DEFAULT 0,
    sort_order    INTEGER NOT NULL DEFAULT 0,
    count_topics  INTEGER NOT NULL DEFAULT 0,
    count_posts   INTEGER NOT NULL DEFAULT 0,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS plugin_community_topics (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    channel_id     INTEGER NOT NULL,
    slug           TEXT NOT NULL DEFAULT '',
    subject        TEXT NOT NULL DEFAULT '',
    embed_code     TEXT DEFAULT NULL,
    start_user_id  INTEGER NOT NULL DEFAULT 0,
    is_sticky      INTEGER NOT NULL DEFAULT 0,
    is_locked      INTEGER NOT NULL DEFAULT 0,
    count_posts    INTEGER NOT NULL DEFAULT 0,
    count_views    INTEGER NOT NULL DEFAULT 0,
    last_post_id   INTEGER NOT NULL DEFAULT 0,
    last_post_at   DATETIME DEFAULT NULL,
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (channel_id, slug)
);

CREATE INDEX IF NOT EXISTS idx_community_topics_embed
    ON plugin_community_topics(embed_code);
CREATE INDEX IF NOT EXISTS idx_community_topics_list
    ON plugin_community_topics(channel_id, is_sticky DESC, last_post_at DESC);

CREATE TABLE IF NOT EXISTS plugin_community_posts (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    topic_id     INTEGER NOT NULL,
    user_id      INTEGER NOT NULL DEFAULT 0,
    guest_name   TEXT NOT NULL DEFAULT '',
    guest_email  TEXT NOT NULL DEFAULT '',
    parent_id    INTEGER NOT NULL DEFAULT 0,
    content      TEXT NOT NULL DEFAULT '',
    content_html TEXT NOT NULL DEFAULT '',
    status       TEXT NOT NULL DEFAULT 'published',
    count_links  INTEGER NOT NULL DEFAULT 0,
    ip_hash      TEXT NOT NULL DEFAULT '',
    edit_user_id INTEGER NOT NULL DEFAULT 0,
    edited_at    DATETIME DEFAULT NULL,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_community_posts_topic
    ON plugin_community_posts(topic_id, created_at);
CREATE INDEX IF NOT EXISTS idx_community_posts_status
    ON plugin_community_posts(status);
CREATE INDEX IF NOT EXISTS idx_community_posts_ip
    ON plugin_community_posts(ip_hash, created_at);

CREATE TABLE IF NOT EXISTS plugin_community_followers (
    topic_id   INTEGER NOT NULL,
    user_id    INTEGER NOT NULL,
    notify     TEXT NOT NULL DEFAULT 'email',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (topic_id, user_id)
);

CREATE TABLE IF NOT EXISTS plugin_community_members (
    channel_id INTEGER NOT NULL,
    user_id    INTEGER NOT NULL,
    role       TEXT NOT NULL DEFAULT 'member',
    status     TEXT NOT NULL DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (channel_id, user_id)
);
