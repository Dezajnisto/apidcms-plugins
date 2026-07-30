# Favorites — Избранное

Плагин для [apidcms](https://github.com/Dezajnisto/apidcms). Универсальные закладки/избранное для любого типа контента.

## Возможности

- **Универсальность** — `entity_type` + `entity_slug`: catalog, blog, page, product, любой тип
- **Toggle API** — один эндпоинт для добавления/удаления: `POST /api/favorites/toggle`
- **Кнопка-сердечко** — `favorite_button(type, slug)` с авто-переключением ❤️/🤍
- **Панель в профиле** — `favorite_items()` возвращает обогащённые данные для рендера
- **JS без зависимостей** — встроенный скрипт, без фреймворков

## Зависимости

- **account** — обязательная (для сессий пользователей)

## Установка

1. Установи и активируй плагин `account`
2. Скопируй папку `favorites/` в `plugins/` твоего проекта
3. Таблица `plugin_favorites_user_favorites` создастся автоматически при загрузке плагина

## Модель данных

```sql
plugin_favorites_user_favorites (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL,
    entity_type TEXT NOT NULL,    -- 'catalog', 'blog', 'page', 'product'...
    entity_slug TEXT NOT NULL,    -- slug записи
    created_at DATETIME,
    UNIQUE(user_id, entity_type, entity_slug)
)
```

## API

| Метод | URL | Описание |
|-------|-----|----------|
| POST | `/api/favorites/toggle` | Добавить/удалить. Body: `{entity_type, entity_slug}` → `{ok, favorited}` |
| GET | `/api/favorites/list` | Список избранного пользователя (JSON) |

## Twig-функции

```twig
{# Кнопка сердечка (для страницы записи) #}
{{ favorite_button('catalog', item.slug)|raw }}

{# JS-скрипты (вставь в конце страницы) #}
{{ favorites_scripts()|raw }}

{# Обогащённый список избранного (для профиля) #}
{% for item in favorite_items() %}
  <a href="/catalog/{{ item.slug }}">{{ item.title }}</a>
{% endfor %}

{# Сырой список без обогащения #}
{% for fav in user_favorites() %}
  {{ fav.entity_type }} / {{ fav.entity_slug }}
{% endfor %}

{# Счётчик #}
{{ favorites_count() }}
```

## Интеграция в проект

### 1. Страница записи (catalog_single.html.twig)

```twig
<div class="prompt-meta__stats">
  {{ favorite_button('catalog', item.slug)|raw }}
</div>
```

### 2. В конец страницы (перед `{% endblock %}`)

```twig
{{ favorites_scripts()|raw }}
```

### 3. Панель в профиле (profile.html.twig)

```twig
{% set fav_items = favorite_items() %}
{% if fav_items|length > 0 %}
<div class="fav-grid">
  {% for item in fav_items %}
  <div class="fav-card">
    <div class="fav-card__header">
      <span class="fav-card__badge">{{ item.category|default('') }}</span>
      <button class="fav-card__heart" onclick="favRemoveCard(event,'catalog','{{ item.slug }}')">✕</button>
    </div>
    <a href="/catalog/{{ item.slug }}">
      <div class="fav-card__title">{{ item.title }}</div>
    </a>
  </div>
  {% endfor %}
</div>
{% endif %}
```

### 4. CSS

```css
/* Кнопка-сердечко */
.fav-heart { /* pill button */ }
.fav-heart--active { /* active state */ }

/* Карточки в профиле */
.fav-grid { /* grid layout */ }
.fav-card { /* card style */ }
```

## Настройки

| Ключ | Тип | По умолчанию | Описание |
|------|-----|-------------|----------|
| `max_favorites` | number | 50 | Максимум избранного на пользователя |

## Для других типов контента

Плагин не привязан к таблице `catalog`. Чтобы добавить поддержку другого типа (блог, страницы, товары):

1. Используй тот же `favorite_button('blog', post.slug)` — кнопка работает с любым типом
2. В `init.php` плагина добавь обработку нового типа в `favorite_items()`:

```php
if ($fav['entity_type'] === 'blog') {
    $item = $db->query("SELECT ... FROM blog WHERE slug = ?", [$fav['entity_slug']])->fetch();
}
```

## Лицензия

MIT
