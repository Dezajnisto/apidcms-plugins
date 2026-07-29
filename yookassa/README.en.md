# YooKassa — Payment Gateway

Universal payment processing via YooKassa API for [apidcms](https://github.com/Dezajnisto/apidcms). Independent of consumer plugins — any plugin can use `payments.*` hooks.

## Features

- Payment link generation via YooKassa API
- Webhook receiver for payment status updates
- Auto-confirmation of completed payments
- Plugin-agnostic: any plugin can trigger payments via hooks
- Test and live mode

## Dependencies

None. This is a payment gateway — consumer plugins (subscription, credits) depend on it.

## Installation

1. Copy the `yookassa/` folder into your project's `plugins/`
2. Admin panel: Plugins → YooKassa → Activate
3. Enter Shop ID and Secret Key in plugin settings
4. Set up webhook URL in YooKassa dashboard

## Settings

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `shop_id` | text | — | YooKassa Shop ID |
| `secret_key` | text | — | YooKassa Secret Key |
| `test_mode` | checkbox | true | Use YooKassa sandbox |

## Hooks

```php
// Trigger a payment from any plugin
$pm = PluginManager::getInstance();
$paymentUrl = $pm->applyFilters('payments.create', [
    'amount' => 500.00,
    'currency' => 'RUB',
    'description' => 'Subscription — 1 month',
    'return_url' => 'https://mysite.ru/account',
]);
```

## License

MIT
