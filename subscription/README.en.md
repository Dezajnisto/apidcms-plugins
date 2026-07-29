# Subscription — Memberships

Plans, subscriptions and gated access for [apidcms](https://github.com/Dezajnisto/apidcms). One-time trials, works with any payment gateway.

## Features

- Subscription plans with configurable price, duration, features list
- Trial periods for new users
- Automatic status tracking (active, expired, cancelled)
- Content gating by subscription level
- Works with any payment plugin via `payments.*` hooks
- Twig functions: `has_subscription()`, `current_subscription()`

## Dependencies

- **account** — required for user binding
- **yookassa** — optional, for payment processing

## Installation

1. Copy the `subscription/` folder into your project's `plugins/`
2. Admin panel: Plugins → Subscription → Activate
3. Configure plans in Settings → Subscription
4. Gated content: set `subscription_level` in page config

## Settings

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `trial_days` | number | 7 | Free trial period for new users |
| `default_plan` | select | — | Plan assigned on registration |

## Twig

```twig
{% if has_subscription() %}
  <p>Your plan: {{ current_subscription().name }}</p>
{% else %}
  <a href="/subscribe">Choose a plan</a>
{% endif %}
```

## License

MIT
