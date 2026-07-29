# Favorites — Bookmarks

Universal bookmarks for any content in [apidcms](https://github.com/Dezajnisto/apidcms). Heart button in cards, profile panel, toggle API.

## Features

- Heart button toggle for any content type
- Works with entity_relations for flexible binding
- User favorites panel in profile
- REST toggle API for AJAX
- Count display on item cards

## Dependencies

- **account** — required for user binding

## Installation

1. Copy the `favorites/` folder into your project's `plugins/`
2. Admin panel: Plugins → Favorites → Activate
3. Add the heart button to your Twig templates

## Twig

```twig
{# Heart toggle button #}
<button class="fav-toggle" data-item-id="{{ item.id }}" data-table="products">
  {% if is_favorited(item.id, 'products') %}❤️{% else %}🤍{% endif %}
</button>

{# User favorites page #}
<a href="/favorites">My Favorites ({{ fav_count() }})</a>
```

## License

MIT
