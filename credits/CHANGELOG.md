# Credits Plugin — Changelog

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
