# Account — Личный кабинет

Плагин пользователей для [apidcms](https://github.com/Dezajnisto/apidcms): регистрация, авторизация, remember-me и профиль.

## Возможности

- Регистрация новых пользователей с email и паролем
- Авторизация с remember-me токеном
- Middleware-проверка сессии на каждом запросе
- Хеширование паролей через `password_hash()` (bcrypt)
- Профиль пользователя с редактированием имени, телефона, аватара
- Опциональное подтверждение email
- Twig-функции: `is_logged_in()`, `current_user()`

## Зависимости

Нет. Это фундаментальный плагин, от него зависят другие:

- **cart** — привязывает корзину к пользователю
- **subscription** — привязывает подписки
- **credits** — привязывает баланс

## Установка

1. Скопируй папку `account/` в `plugins/` твоего проекта
2. В админке: Плагины → Account → Активировать
3. Плагин создаст таблицы `users` и `user_tokens` автоматически
4. Открой `/register` для проверки регистрации

## Настройки

| Ключ | Тип | По умолчанию | Описание |
|------|-----|-------------|----------|
| `require_email_confirm` | checkbox | false | Требовать подтверждение email при регистрации |
| `default_role` | text | user | Роль, назначаемая новым пользователям |

## Создаваемые таблицы

### users

| Поле | Тип | Описание |
|------|-----|----------|
| id | INTEGER PK | — |
| email | TEXT UNIQUE | Email пользователя |
| password_hash | TEXT | bcrypt-хеш пароля |
| name | TEXT | Имя пользователя |
| phone | TEXT | Телефон |
| avatar | TEXT | URL аватара |
| status | TEXT | active / blocked |

### user_tokens

| Поле | Тип | Описание |
|------|-----|----------|
| id | INTEGER PK | — |
| user_id | INTEGER FK | → users.id |
| token | TEXT UNIQUE | Токен (remember-me) |
| type | TEXT | Тип токена |
| expires_at | DATETIME | Срок действия |

## Маршруты

| URL | Описание |
|-----|----------|
| `/login` | Страница входа |
| `/register` | Страница регистрации |
| `/profile` | Профиль пользователя |
| `/logout` | Выход |

## Twig

```twig
{% if is_logged_in() %}
  Привет, {{ current_user().name }}!
{% else %}
  <a href="/login">Войти</a>
{% endif %}
```

## Лицензия

MIT
