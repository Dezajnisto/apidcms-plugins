-- SEO-поля для любой сущности: страницы, записи таблиц, разделы, посты.
-- Отдельная таблица, а не колонки: плагин не меняет схему ядра, поэтому
-- работает на любой установке без миграции CMS.
CREATE TABLE IF NOT EXISTS plugin_seo_meta (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    entity_type TEXT NOT NULL,
    entity_table TEXT NOT NULL DEFAULT '',
    entity_id INTEGER NOT NULL DEFAULT 0,
    title TEXT,
    description TEXT,
    focus_keyword TEXT,
    canonical TEXT,
    robots TEXT DEFAULT '',
    og_title TEXT,
    og_description TEXT,
    og_image TEXT,
    schema_json TEXT,
    score INTEGER,
    checked_at DATETIME,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (entity_type, entity_table, entity_id)
);

CREATE INDEX IF NOT EXISTS idx_seo_meta_entity
    ON plugin_seo_meta (entity_type, entity_table, entity_id);

-- 301-редиректы: переезд страницы не должен терять трафик.
CREATE TABLE IF NOT EXISTS plugin_seo_redirect (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    from_path TEXT NOT NULL UNIQUE,
    to_path TEXT NOT NULL,
    code INTEGER NOT NULL DEFAULT 301,
    hits INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Лог 404: откуда приходят и чего не хватает.
CREATE TABLE IF NOT EXISTS plugin_seo_404 (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    path TEXT NOT NULL UNIQUE,
    hits INTEGER NOT NULL DEFAULT 1,
    referer TEXT,
    first_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_seen DATETIME DEFAULT CURRENT_TIMESTAMP
);
