# Account — User Profile

Adds registration, login and user profile to your site.

## Features

- **Registration:** email, name, phone, password (bcrypt)
- **Login:** email + password, Remember-me (30-day cookie)
- **Password recovery:** magic-link via email (one-time token)
- **Password change:** for authenticated users
- **Profile:** view and edit name, phone number
- **Twig functions:** `is_logged_in()`, `current_user()`

## Routes

| Path | Action |
|------|--------|
| `/register` | Registration |
| `/login` | Login |
| `/account/forgot` | Password recovery |
| `/account/reset?token=***` | Magic-link login |
| `/profile` | Profile |
| `/account/change-password` | Change password |
| `/logout` | Logout |

## Settings

- `require_email_confirm` — require email confirmation (in development)
- `default_role` — default role for new users
- `reset_token_ttl` — magic-link expiry (1h / 24h / 7d)

## Tables

- `users` — user accounts
- `user_tokens` — tokens (remember-me, password-reset)

## Requirements

- apidcms >= 1.0.0
- Configured email (`email_driver`, `email_from_*`) for magic-link

## Security

- bcrypt password hashing
- Tokens: 64 chars (random_bytes), single-use
- Rate-limit: one active reset token per email
- Generic messages (doesn't reveal email existence)
