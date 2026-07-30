CREATE TABLE IF NOT EXISTS plugin_cart_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    session_id TEXT NOT NULL,
    product_table TEXT NOT NULL DEFAULT 'products',
    product_id INTEGER NOT NULL,
    quantity INTEGER NOT NULL DEFAULT 1,
    added_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_cart_session ON plugin_cart_items(session_id);
CREATE INDEX IF NOT EXISTS idx_cart_user ON plugin_cart_items(user_id);
