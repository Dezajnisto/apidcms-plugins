# Credits Plugin — Changelog

## 1.0.3 — 2026-11-26

### Fixed

- **Начисление и списание кредитов падали на реальной оплате.** `Service::add()` и
  `Service::deduct()` вызывали `$db->beginTransaction()` / `commit()` / `rollback()`,
  а у `Core\Database` таких методов нет — только PDO внутри. Ошибка возникала
  внутри `payments.confirmed`, PluginManager её перехватывал, поэтому после оплаты
  кредиты молча не начислялись: платёж прошёл, денег на балансе нет.
  Теперь транзакции идут через `$db->getConnection()`.
- Правка уже жила на одном сайте с 24.11.2026, но в каталог не вернулась и версию
  там не подняли — отсюда расхождение: у одних копий 1.0.2 работала, у других нет.

## [1.1.0] — 2026-07-30

### Changed

- **Tables renamed** with `plugin_credits_` prefix: `user_balances` → `plugin_credits_user_balances`, `credit_transactions` → `plugin_credits_transactions`
- Prevents conflicts with user-created tables
- **Schema verification** via `PRAGMA table_info` + `ALTER TABLE ADD COLUMN`
- **FK references** updated: `REFERENCES users(id)` → `REFERENCES plugin_account_users(id)`
## 1.0.0 — 2026-07-23

### Initial release

- **user_balances table** — per-user credit balance
- **credit_transactions table** — immutable ledger with type, description, reference
- **Atomic operations:** `add()`, `deduct()`, `canDeduct()`, `getBalance()`, `getHistory()`, `formatBalance()`
- **Direct purchase:** `/credits/buy` page with configurable rates (JSON)
- **Payment gateway integration:** `credits.create_payment` filter, `credits.payment.confirmed/canceled` actions
- **Subscription bonus:** listens to `subscription.activated` for bonus credits via plan features
- **Hook API:** `credits.can_deduct` filter, `credits.balance_changed` action
- **Twig functions:** `credits_balance()`, `credits_can_use()`, `credits_purchase_rates()`, `credits_purchase_enabled()`
- **Admin menu:** user_balances and credit_transactions table views
- **Settings:** currency name, purchase toggle, purchase rates (JSON)
- **Dependencies:** account (required), yookassa (optional), subscription (optional)

### Integration

- YooKassa 1.1.0: `credits.create_payment` filter, webhook dispatches `credits.payment.*`
- Subscription 1.3.2: `subscription.activated` hook for bonus credits
