# Account — Changelog

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
