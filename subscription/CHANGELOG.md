# Subscription Plugin — Changelog

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
