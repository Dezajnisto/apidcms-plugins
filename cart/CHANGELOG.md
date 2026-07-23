# Cart — Changelog

## [1.0.0] — 2025-12-20

### Added
- AJAX-добавление товаров в корзину
- Гостевая корзина по session_id
- Привязка корзины к пользователю (account)
- Изменение количества и удаление
- Twig-функции: `cart_count()`, `cart_total()`
- REST API: `/cart/add`, `/cart/remove`, `/cart/update`
- Миграция: `cart_items`
