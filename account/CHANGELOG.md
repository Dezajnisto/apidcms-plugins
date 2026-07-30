# Account — Changelog

## [1.1.0] — 2026-07-30

### Changed

- **Tables renamed** with `plugin_account_` prefix: `users` → `plugin_account_users`, `user_tokens` → `plugin_account_tokens`
- Prevents conflicts with user-created tables (e.g. a user creates a `users` table via admin panel)
- **Schema verification** via `PRAGMA table_info` + `ALTER TABLE ADD COLUMN` — safe column additions on plugin update
- **FK references** from other plugins updated: `REFERENCES users(id)` → `REFERENCES plugin_account_users(id)`
## [1.0.0] — 2025-12-15

### Added
- Регистрация пользователей (email + пароль)
- Авторизация с сессией
- Remember-me через токены в `user_tokens`
- Middleware-проверка сессии на каждом запросе
- Хеширование паролей bcrypt
- Профиль с редактированием
- Twig-функции: `is_logged_in()`, `current_user()`
- Миграции: `users`, `user_tokens`
