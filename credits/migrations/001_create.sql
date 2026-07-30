CREATE TABLE IF NOT EXISTS plugin_credits_user_balances (
    user_id INTEGER PRIMARY KEY,
    balance INTEGER NOT NULL DEFAULT 0,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES plugin_account_users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS plugin_credits_transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    amount INTEGER NOT NULL,
    type TEXT NOT NULL DEFAULT 'manual',
    description TEXT,
    reference TEXT,
    balance_after INTEGER NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES plugin_account_users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_ct_user ON plugin_credits_transactions(user_id);
CREATE INDEX IF NOT EXISTS idx_ct_created ON plugin_credits_transactions(created_at);
