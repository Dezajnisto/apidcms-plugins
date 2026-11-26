-- npd: журнал чеков «Мой налог»
CREATE TABLE IF NOT EXISTS plugin_npd_receipts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source TEXT NOT NULL DEFAULT 'payment',
    payment_id TEXT NOT NULL DEFAULT '',
    ref TEXT NOT NULL DEFAULT '',
    user_id INTEGER NOT NULL DEFAULT 0,
    amount TEXT NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT '',
    client_email TEXT NOT NULL DEFAULT '',
    client_phone TEXT NOT NULL DEFAULT '',
    income_type TEXT NOT NULL DEFAULT 'FROM_INDIVIDUAL',
    payment_type TEXT NOT NULL DEFAULT 'CASH',
    status TEXT NOT NULL DEFAULT 'pending',
    receipt_uuid TEXT NOT NULL DEFAULT '',
    receipt_url TEXT NOT NULL DEFAULT '',
    attempts INTEGER NOT NULL DEFAULT 0,
    last_error TEXT NOT NULL DEFAULT '',
    operation_time TEXT NOT NULL DEFAULT '',
    issued_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_plugin_npd_receipts_status ON plugin_npd_receipts(status);

CREATE INDEX IF NOT EXISTS idx_plugin_npd_receipts_user ON plugin_npd_receipts(user_id);

CREATE UNIQUE INDEX IF NOT EXISTS idx_plugin_npd_receipts_payment ON plugin_npd_receipts(payment_id) WHERE payment_id <> '';

CREATE INDEX IF NOT EXISTS idx_plugin_npd_receipts_created ON plugin_npd_receipts(created_at);
