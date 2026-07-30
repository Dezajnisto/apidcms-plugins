-- Account plugin: plugin_account_users
CREATE TABLE IF NOT EXISTS plugin_account_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    name TEXT DEFAULT '',
    phone TEXT DEFAULT '',
    avatar TEXT DEFAULT '',
    status TEXT DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Account plugin: plugin_account_tokens
CREATE TABLE IF NOT EXISTS plugin_account_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token TEXT NOT NULL UNIQUE,
    type TEXT NOT NULL DEFAULT 'remember',
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES plugin_account_users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_plugin_account_tokens_token ON plugin_account_tokens(token);
CREATE INDEX IF NOT EXISTS idx_plugin_account_tokens_user ON plugin_account_tokens(user_id);

