# Subscription Plugin — Changelog

## 1.3.0 — 2026-07-22

### Changed (BREAKING)

- **YooKassa extracted to separate plugin.** All payment processing code (YooKassa API, webhook, return page) moved to new `yookassa` plugin.
- **Hook API for payment gateways.** Subscription now defines a hook contract that any payment gateway can implement:
  - Filter `subscription.create_payment` — gateways respond with payment URL
  - Filter `subscription.check_payment` — gateways respond with payment status
  - Action `subscription.payment.confirmed` — gateways call on success
  - Action `subscription.payment.canceled` — gateways call on cancel
  - Action `subscription.payment.return` — gateways call on return from bank
- Removed `yookassa_shop_id` / `yookassa_secret_key` settings (now in yookassa plugin)

### Migration

1. Install and enable `yookassa` plugin
2. Copy YooKassa credentials to yookassa plugin settings
3. Update subscription plugin (this version)
4. Reset OPcache

### Files

- `plugin.json` — v1.3.0, removed YooKassa settings
- `init.php` — removed YooKassa routes, added hook API listeners
- `controllers/SubscriptionController.php` — removed YooKassa methods, uses hook API

## 1.2.2 — 2026-07-22

### Changed

- **duration_months → duration_days** (INTEGER). Flexible values: 1, 7, 30, 180, 365 days.
- Migration: automatically converts existing duration_months to duration_days (×30)
- `format_duration()` Twig function with Russian pluralization

### New

- **Free plans:** plans with `price=0` activate immediately without payment gateway
- **Real YooKassa integration:** createPayment → redirect → webhook → activate
- Webhook endpoint: POST /subscription/webhook
- Return page: GET /subscription/return → check status → redirect

### Files

- `init.php` — new routes, duration migration
- `controllers/SubscriptionController.php` — YooKassa API, free plans, formatDuration()

## 1.2.1 — 2026-07-21

### New

- YooKassa payment settings (shop_id, secret_key) in plugin.json

## 1.2.0 — 2026-07-18

### Fixed

- `subscription_demo()` reads from `plugin.json` via PluginManager

## 1.1.0 — 2026-07-17

### New

- **POST /catalog-copy/{slug}** — increments `copies_count` for catalog items
- JS integration: `copyPrompt()` sends fetch after clipboard copy and updates counter on page

### Files

- `controllers/SubscriptionController.php` — `countCopy()` method
- `init.php` — new route handler for `/catalog-copy/{slug}`

## 1.0.0 — 2026-07-09

### Initial release

- Subscription plans and user subscriptions (demo mode)
- `/catalog-content/{slug}` — gated content endpoint
- `/subscribe/{plan}` — subscription flow
- Twig functions: `is_subscriber()`, `current_subscription()`, `get_plans()`
- Admin menu integration
