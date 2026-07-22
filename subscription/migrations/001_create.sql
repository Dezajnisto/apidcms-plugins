CREATE TABLE IF NOT EXISTS subscription_plans (
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

CREATE TABLE IF NOT EXISTS user_subscriptions (
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
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES subscription_plans(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_user_subscriptions_user ON user_subscriptions(user_id);
CREATE INDEX IF NOT EXISTS idx_user_subscriptions_status ON user_subscriptions(status);

-- Insert default plans
INSERT OR IGNORE INTO subscription_plans (name, slug, price, duration_days, description, features, sort_order) VALUES
('Bajto Light', 'light', 990, 180, 'Базовый доступ ко всем промтам', '["Доступ ко всем промтам","Копирование одним кликом","Новые промты каждую неделю","Email-поддержка"]', 10),
('Bajto Pro', 'pro', 1490, 365, 'Полный доступ + эксклюзивы', '["Всё из Light","Эксклюзивные промты","Приоритетная поддержка","Доступ к бета-промтам","История копирований"]', 20);
