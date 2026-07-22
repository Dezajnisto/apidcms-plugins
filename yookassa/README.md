# YooKassa — Payment Gateway Plugin

Платёжный шлюз для [apidcms](https://github.com/Dezajnisto/apidcms). Интеграция с [YooKassa](https://yookassa.ru/) (бывшая Яндекс.Касса).

## Возможности

- Приём платежей через YooKassa API (разовые и рекуррентные)
- Webhook для автоматического подтверждения
- Идемпотентность (UUID v4)
- Настройка shopId и секретного ключа через админку
- Реализует [subscription hook API](#интеграция-с-другими-плагинами)

## Зависимости

Нет. Может работать самостоятельно или с другими плагинами.

## Установка

1. Скопируй папку `yookassa/` в `plugins/` твоего проекта
2. В админке: Плагины → YooKassa → Настройки
3. Введи Shop ID и Secret Key из [личного кабинета YooKassa](https://yookassa.ru/my)
4. Настрой webhook в ЛК YooKassa: `https://твой-сайт.ru/yookassa/webhook`

## Настройки

| Параметр | Описание |
|----------|----------|
| `yookassa_shop_id` | ID магазина из ЛК YooKassa |
| `yookassa_secret_key` | Секретный ключ API (начинается с `test_` или `live_`) |

## Интеграция с другими плагинами

YooKassa реализует hook-контракт плагина `subscription`. Если оба плагина активны:

1. **subscription** вызывает `applyFilters('subscription.create_payment', ...)`
2. **yookassa** отвечает — создаёт платёж через API и возвращает URL для редиректа
3. После оплаты **yookassa** получает webhook → вызывает `doAction('subscription.payment.confirmed', ...)`
4. **subscription** активирует подписку

Та же схема работает с любым другим плагином, которому нужна оплата (cart, donate, etc.).

## Тестовый режим

- Используй тестовые ключи (начинаются с `test_`)
- Тестовая карта: `5555 5555 5555 4477`
- Код: `123`, срок: любой будущий

## Лицензия

MIT
