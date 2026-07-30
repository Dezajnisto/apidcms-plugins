# Cart — Changelog

## [1.1.0] — 2026-07-30

### Changed

- **Table renamed** with `plugin_cart_` prefix: `cart_items` → `plugin_cart_items`
- Prevents conflicts with user-created tables
- **Schema verification** via `PRAGMA table_info` + `ALTER TABLE ADD COLUMN`
- Updated README with correct table names

## [1.0.0] — 2025-12-20

### Added
- AJAX-добавление товаров в корзину
- Гостевая корзина по session_id
- Привязка корзины к пользователю (account)
- Изменение количества и удаление
- Twig-функции: `cart_count()`, `cart_total()`
- REST API: `/cart/add`, `/cart/remove`, `/cart/update`
- Миграция: `cart_items`
