# Account — User Profile

User plugin for [apidcms](https://github.com/Dezajnisto/apidcms): registration, login, remember-me and profile.

## Features

- New user registration with email and password
- Login with remember-me token
- Session middleware on every request
- Password hashing via `password_hash()` (bcrypt)
- User profile with name, phone, avatar editing
- Optional email confirmation
- Twig functions: `is_logged_in()`, `current_user()`

## Dependencies

None. This is a foundation plugin, depended on by:

- **cart** — links cart to user
- **subscription** — links subscriptions
- **credits** — links balance

## Installation

1. Copy the `account/` folder into your project's `plugins/`
2. Admin panel: Plugins → Account → Activate
3. The plugin auto-creates `users` and `user_tokens` tables
4. Open `/register` to test registration

## Settings

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `require_email_confirm` | checkbox | false | Require email confirmation on registration |
| `default_role` | text | user | Role assigned to new users |

## Created Tables

### users

| Column | Type | Description |
|--------|------|-------------|
| id | INTEGER PK | — |
| email | TEXT UNIQUE | User email |
| password_hash | TEXT | bcrypt password hash |
| name | TEXT | User name |
| phone | TEXT | Phone number |
| avatar | TEXT | Avatar URL |
| status | TEXT | active / blocked |

### user_tokens

| Column | Type | Description |
|--------|------|-------------|
| id | INTEGER PK | — |
| user_id | INTEGER FK | → users.id |
| token | TEXT UNIQUE | Remember-me token |
| type | TEXT | Token type |
| expires_at | DATETIME | Expiration |

## Routes

| URL | Description |
|-----|-------------|
| `/login` | Login page |
| `/register` | Registration page |
| `/profile` | User profile |
| `/logout` | Logout |

## Twig

```twig
{% if is_logged_in() %}
  Hello, {{ current_user().name }}!
{% else %}
  <a href="/login">Sign in</a>
{% endif %}
```

## License

MIT
