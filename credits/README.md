# Credits — Плагин кредитов

Плагин-фундамент для учёта баланса пользователей в [apidcms](https://github.com/Dezajnisto/apidcms).

## Возможности

- Баланс пользователя (таблица `user_balances`)
- Immutable ledger (`credit_transactions`) — все операции неизменяемы
- Атомарные операции: пополнение, списание
- Прямая покупка кредитов через любой платёжный шлюз
- Бонусные кредиты при активации подписки
- Twig-функции: `credits_balance()`, `credits_can_use()`, `credits_purchase_rates()`

## Зависимости

- **account** — обязательная (для сессий пользователей)
- **yookassa** (или другой шлюз) — опциональная (для покупки кредитов)
- **subscription** — опциональная (для бонусных кредитов)

## Установка

1. Установи и активируй плагин `account`
2. Скопируй папку `credits/` в `plugins/` твоего проекта
3. Для прямой покупки кредитов установи `yookassa` (или другой шлюз)
4. В админке: Плагины → Credits → настрой валюту и тарифы

## Настройки

| Ключ | Тип | Описание |
|------|-----|----------|
| credits_currency_name | text | Название валюты в родительном падеже |
| credits_purchase_enabled | checkbox | Разрешить прямую покупку |
| credits_purchase_rates | textarea | JSON-массив тарифов |

## Hook API

Credits выставляет контракт, который реализуют платёжные шлюзы:

| Хук | Тип | Направление |
|-----|-----|-------------|
| `credits.can_deduct` | filter | Проверить, можно ли списать (по умолчанию: balance >= amount) |
| `credits.create_payment` | filter | credits → шлюз: создать платёж |
| `credits.payment.confirmed` | action | шлюз → credits: платёж прошёл |
| `credits.payment.canceled` | action | шлюз → credits: платёж отменён |
| `credits.balance_changed` | action | credits → другие: баланс изменился |

Credits слушает:

| Хук | От кого | Что делает |
|-----|---------|-----------|
| `subscription.activated` | Subscription | Начисляет бонусные кредиты |

## Публичное API

```php
use Plugins\Credits\Service;

$balance = Service::getBalance($userId);
$canUse  = Service::canDeduct($userId, 5);
$newBal  = Service::add($userId, 100, 'purchase', 'Покупка', 'purchase:123');
$newBal  = Service::deduct($userId, 1, 'deduction', 'Генерация', 'generation:42');
$history = Service::getHistory($userId, 50);
```

## Типы транзакций

| type | Описание |
|------|----------|
| purchase | Покупка кредитов через платёжный шлюз |
| bonus | Бонус от подписки |
| deduction | Списание за использование |
| refund | Возврат |
| manual | Ручное начисление/списание админом |

## Twig

```twig
<p>Баланс: {{ credits_balance() }}</p>
{% if credits_can_use(5) %}
    <button>Сгенерировать (5 кредитов)</button>
{% else %}
    <a href="/credits/buy">Купить кредиты</a>
{% endif %}
```

## Лицензия

MIT
