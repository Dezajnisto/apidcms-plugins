-- webchat: обращения с сайта
CREATE TABLE IF NOT EXISTS plugin_webchat_threads (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token TEXT NOT NULL UNIQUE,
    channel TEXT NOT NULL DEFAULT '',
    external_ref TEXT NOT NULL DEFAULT '',
    site TEXT NOT NULL DEFAULT '',
    page_url TEXT NOT NULL DEFAULT '',
    referrer TEXT NOT NULL DEFAULT '',
    user_agent TEXT NOT NULL DEFAULT '',
    ip_hash TEXT NOT NULL DEFAULT '',
    visitor_name TEXT NOT NULL DEFAULT '',
    visitor_contact TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'open',
    unread_admin INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_plugin_webchat_threads_token ON plugin_webchat_threads(token);
CREATE INDEX IF NOT EXISTS idx_plugin_webchat_threads_status ON plugin_webchat_threads(status);

-- webchat: сообщения диалога
CREATE TABLE IF NOT EXISTS plugin_webchat_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    thread_id INTEGER NOT NULL,
    direction TEXT NOT NULL DEFAULT 'in',
    body TEXT NOT NULL DEFAULT '',
    author TEXT NOT NULL DEFAULT '',
    external_mid TEXT NOT NULL DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (thread_id) REFERENCES plugin_webchat_threads(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_plugin_webchat_messages_thread ON plugin_webchat_messages(thread_id);
CREATE INDEX IF NOT EXISTS idx_plugin_webchat_messages_mid ON plugin_webchat_messages(external_mid);
