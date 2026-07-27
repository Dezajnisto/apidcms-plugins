CREATE TABLE IF NOT EXISTS user_favorites (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    entity_type TEXT NOT NULL,
    entity_slug TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE(user_id, entity_type, entity_slug)
);
CREATE INDEX IF NOT EXISTS idx_uf_user ON user_favorites(user_id);
CREATE INDEX IF NOT EXISTS idx_uf_type ON user_favorites(entity_type);
