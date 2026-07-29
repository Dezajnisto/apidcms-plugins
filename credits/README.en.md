# Credits — Balance & Transactions

User balance and immutable transaction ledger for [apidcms](https://github.com/Dezajnisto/apidcms). Direct credit purchases, subscription bonuses, pay-per-use.

## Features

- User credit balance with immutable transaction ledger
- Direct credit purchases (fixed packages)
- Subscription bonuses (monthly credit allocation)
- Pay-per-use: any plugin can debit via `credits.debit` action
- Full transaction history for users

## Dependencies

- **account** — required for user binding

## Installation

1. Copy the `credits/` folder into your project's `plugins/`
2. Admin panel: Plugins → Credits → Activate
3. Configure credit packages in Settings → Credits

## Twig

```twig
<p>Balance: {{ credits_balance() }} credits</p>
<a href="/credits/buy">Buy credits</a>
```

## Hooks

```php
// Debit credits for pay-per-use
$pm = PluginManager::getInstance();
$ok = $pm->doAction('credits.debit', $userId, $amount, 'AI image generation');
```

## License

MIT
