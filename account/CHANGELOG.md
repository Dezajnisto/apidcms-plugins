# Account — Changelog

## [1.2.0] — 2026-11-24

### Added

- **Подтверждение email** — настройка `require_email_confirm`. При включении регистрация
  создаёт пользователя со статусом `pending` и отправляет письмо со ссылкой
  `/account/confirm?token=...` (живёт 24 часа). После перехода аккаунт становится
  `active`, пользователь сразу авторизован, срабатывает хук `account.confirmed`.
- Хук `account.registered` — когда подтверждение выключено и вход происходит сразу.
- Параметр `?return=/путь` для `/login` и `/register`: после входа пользователь попадает
  туда, откуда пришёл (например, «купить тариф → войти»). Адрес проверяется — только
  локальный путь, без `//` и переводов строк (защита от открытого редиректа).
- Шаблоны `registered_check_email`, `confirm_error`, `email_confirm`.
- Общий `mailer()`: конфиг почты читается из `system_settings` в одном месте.

### Fixed

- **`$db->update()` вызывался с устаревшей сигнатурой** `($table, $data, $where, $params)`.
  Ядро ждёт `($table, $id, $data)`, поэтому собирался пустой `SET` и запрос падал с
  `near "WHERE": syntax error`. Из-за этого **не сохранялся профиль и не менялся пароль**.
  Исправлено в трёх местах: подтверждение почты, сохранение профиля, смена пароля.
- `login` для неподтверждённого аккаунта говорит «аккаунт не подтверждён», а не
  «неверный email или пароль».

### Changed

- В манифесте убран ключ `path` (внутренний путь одной из установок) и включён
  `enabled: true` — это базовый плагин, от него зависят `subscription` и другие.


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
