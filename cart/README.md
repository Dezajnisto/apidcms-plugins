# Cart — Корзина товаров

Плагин корзины для [apidcms](https://github.com/Dezajnisto/apidcms): AJAX-добавление, изменение количества и чекаут.

## Возможности

- AJAX-добавление товаров в корзину без перезагрузки страницы
- Гостевая корзина (по session_id) + привязка к пользователю после входа
- Изменение количества и удаление товаров
- Сохранение корзины в БД для авторизованных пользователей
- Twig-функции: `cart_count()`, `cart_total()`
- REST API: `POST /cart/add`, `POST /cart/remove`, `POST /cart/update`

## Зависимости

- **account** — опциональная (для привязки корзины к пользователю)

## Установка

1. Скопируй папку `cart/` в `plugins/` твоего проекта
2. В админке: Плагины → Cart → Активировать
3. Плагин создаст таблицу `plugin_cart_items` автоматически
4. Используй `POST /cart/add` с `product_id` и `quantity` для добавления товаров

## Настройки

| Ключ | Тип | По умолчанию | Описание |
|------|-----|-------------|----------|
| `currency` | text | ₽ | Символ валюты для отображения цен |
| `min_order` | number | 1 | Минимальное количество товара в заказе |

## Создаваемые таблицы

### plugin_cart_items

| Поле | Тип | Описание |
|------|-----|----------|
| id | INTEGER PK | — |
| user_id | INTEGER | ID пользователя (если авторизован) |
| session_id | TEXT | ID сессии гостя |
| product_table | TEXT | Таблица-источник товара |
| product_id | INTEGER | ID товара |
| quantity | INTEGER | Количество |

## AJAX API

### Добавить в корзину

```http
POST /cart/add
Content-Type: application/x-www-form-urlencoded

product_id=1&quantity=2
```

Ответ:
```json
{"success": true, "count": 3, "message": "Товар добавлен"}
```

### Изменить количество

```http
POST /cart/update
Content-Type: application/x-www-form-urlencoded

item_id=5&quantity=1
```

### Удалить из корзины

```http
POST /cart/remove
Content-Type: application/x-www-form-urlencoded

item_id=5
```

## Twig

```twig
<a href="/cart">Корзина ({{ cart_count() }}) — {{ cart_total() }} ₽</a>
```

## Интеграция с каталогом

Для кнопки «Добавить в корзину» на странице товара:

```html
<button onclick="addToCart({{ item.id }})">В корзину</button>

<script>
async function addToCart(productId) {
  const form = new FormData();
  form.append('product_id', productId);
  form.append('quantity', 1);
  const res = await fetch('/cart/add', { method: 'POST', body: form });
  const data = await res.json();
  if (data.success) alert(data.message);
}
</script>
```

## Лицензия

MIT
