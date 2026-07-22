# Subscription — Плагин подписок

Плагин для [apidcms](https://github.com/Dezajnisto/apidcms). Тарифы, платный доступ к контенту и абонентская логика.

## Возможности

- Тарифные планы: цена, длительность, описание, фичи
- Бесплатные тарифы (price=0) — мгновенная активация
- Платные тарифы — через любой платёжный шлюз (YooKassa, Stripe, etc.)
- Gated content: `/catalog-content/{slug}` доступен только подписчикам
- Счётчик копирований: `/catalog-copy/{slug}`
- Twig-функции: `is_subscriber()`, `current_subscription()`, `get_plans()`, `format_duration()`

## Зависимости

- **account** — обязательная (для сессий пользователей)
- **yookassa** (или другой шлюз) — опциональная (для платных тарифов)

Без платёжного шлюза работают только бесплатные тарифы (price=0).

## Установка

1. Установи и активируй плагин `account`
2. Скопируй папку `subscription/` в `plugins/` твоего проекта
3. Для платных тарифов установи `yookassa` (или другой шлюз)
4. В админке: открой таблицу `subscription_plans` и настрой тарифы

## Тарифные планы

Таблица `subscription_plans`:

| Поле | Тип | Описание |
|------|-----|----------|
| name | TEXT | Название тарифа |
| slug | TEXT | URL-идентификатор |
| price | NUMERIC | Цена в рублях (0 = бесплатно) |
| duration_days | INTEGER | Длительность в днях |
| description | TEXT | Описание |
| features | TEXT | JSON-массив фич |
| is_active | INTEGER | 1 = активен |
| sort_order | INTEGER | Порядок сортировки |

## Подписка пользователя

Переход по `/subscribe/{plan_slug}` запускает процесс:

1. **Бесплатный тариф** → мгновенная активация → редирект на `/profile`
2. **Платный тариф** → запрос к платёжному шлюзу через `subscription.create_payment` фильтр → редирект на страницу оплаты

После оплаты шлюз вызывает `subscription.payment.confirmed`, subscription активирует подписку.

## Hook API для платёжных шлюзов

Subscription **не знает** о конкретных шлюзах. Он выставляет контракт, а шлюзы его реализуют:

| Хук | Тип | Направление |
|-----|-----|-------------|
| `subscription.create_payment` | filter | subscription → шлюз: создать платёж |
| `subscription.check_payment` | filter | subscription → шлюз: проверить статус |
| `subscription.payment.confirmed` | action | шлюз → subscription: платёж прошёл |
| `subscription.payment.canceled` | action | шлюз → subscription: платёж отменён |
| `subscription.payment.return` | action | шлюз → subscription: возврат с банковской страницы |

## Twig-функции

```twig
{% if is_subscriber() %}
  <p>Доступ активен до {{ current_subscription().expires_at }}</p>
{% else %}
  {% for plan in get_plans() %}
    <div>{{ plan.name }} — {{ plan.price }}₽ / {{ format_duration(plan.duration_days) }}</div>
  {% endfor %}
{% endif %}
```

## Лицензия

MIT
