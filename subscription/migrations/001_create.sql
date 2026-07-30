CREATE TABLE IF NOT EXISTS plugin_subscription_plans (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    slug TEXT UNIQUE NOT NULL,
    price NUMERIC NOT NULL,
    duration_days INTEGER NOT NULL,
    description TEXT,
    features TEXT,
    is_active INTEGER DEFAULT 1,
    sort_order INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS plugin_subscription_subscriptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    plan_id INTEGER NOT NULL,
    status TEXT DEFAULT 'pending',
    payment_id TEXT,
    payment_provider TEXT DEFAULT 'yookassa',
    amount NUMERIC NOT NULL,
    started_at DATETIME,
    expires_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES plugin_account_users(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES plugin_subscription_plans(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_plugin_subscription_subscriptions_user ON plugin_subscription_subscriptions(user_id);
CREATE INDEX IF NOT EXISTS idx_plugin_subscription_subscriptions_status ON plugin_subscription_subscriptions(status);

-- Insert default plans
INSERT OR IGNORE INTO plugin_subscription_plans (name, slug, price, duration_days, description, features, sort_order) VALUES
('Bajto Light', 'light', 990, 180, 'Базовый доступ ко всем промтам', '["Доступ ко всем промтам","Копирование одним кликом","Новые промты каждую неделю","Email-поддержка"]', 10),
('Bajto Pro', 'pro', 1490, 365, 'Полный доступ + эксклюзивы', '["Всё из Light","Эксклюзивные промты","Приоритетная поддержка","Доступ к бета-промтам","История копирований"]', 20);
