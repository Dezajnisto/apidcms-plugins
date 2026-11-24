# Account — User Profile

Adds registration, login and user profile to your site.

## Features

- **Registration:** email, name, phone, password (bcrypt)
- **Login:** email + password, Remember-me (30-day cookie)
- **Email confirmation:** link in an email, account stays inactive until confirmed (optional)
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
| `/account/confirm?token=***` | Email confirmation |
| `/account/reset?token=***` | Magic-link login |
| `/profile` | Profile |
| `/account/change-password` | Change password |
| `/logout` | Logout |

## Settings

- `require_email_confirm` — require email confirmation (in development)
- `default_role` — default role for new users
- `reset_token_ttl` — magic-link expiry (1h / 24h / 7d)

## Tables

- `plugin_account_users` — user accounts
- `user_tokens` — tokens (remember-me, password-reset)

## Requirements

- apidcms >= 1.0.0
- Configured email (`email_driver`, `email_from_*`) for magic-link

## Security

- bcrypt password hashing
- Tokens: 64 chars (random_bytes), single-use
- Rate-limit: one active reset token per email
- Generic messages (doesn't reveal email existence)

## Hooks

| Hook | Type | When |
|------|------|------|
| `account.registered` | action | account created and active right away (confirmation off) |
| `account.confirmed` | action | email confirmed, account switched to `active` |

## Email confirmation

Enabled with `require_email_confirm`. The value is stored in `plugin_settings`
(core 2.7+), so updating the plugin never changes a site's behaviour.

Requires working mail (`system_settings`: `email_driver` = `api` or `smtp`,
`email_from_email`). Without it the user stays `pending` and cannot log in — turn
the setting on only where email delivery actually works.

## Post-login redirect

`/login?return=/subscribe/pro` and `/register?return=/subscribe/pro` send the user to
that path after a successful login. Only local paths are accepted (starting with `/`,
no `//`); external URLs are ignored.
