# Subscription Plugin — Changelog

## 1.1.1 — 2026-07-18

### Fixed

- **demo_mode reading:** `subscription_demo()` now reads from `plugin.json` via PluginManager instead of stale `system_settings` value
- **demo access validation:** `getContent()` verifies actual `demo_mode` setting before honoring `?demo=1`, preventing bypass via manual URL
- **closure scope:** fixed `Undefined variable $pm` error by using `PluginManager::getInstance()` directly inside the Twig function closure

### Files

- `init.php` — `subscription_demo()` reads from plugin.json, fixed closure scope
- `controllers/SubscriptionController.php` — `getContent()` validates demo_mode before allowing demo access
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
