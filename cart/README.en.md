# Cart — Shopping Cart

Full-featured shopping cart for [apidcms](https://github.com/Dezajnisto/apidcms) with AJAX add-to-cart and checkout.

## Features

- AJAX add-to-cart without page reload
- Cart icon with item counter in the header
- Cart page with quantity editing and item removal
- Checkout form with name, phone, email, address
- Order storage in `plugin_cart_items` table
- Optional user binding (requires `account` plugin)

## Dependencies

- **account** — optional, for user-bound carts

## Installation

1. Copy the `cart/` folder into your project's `plugins/`
2. Admin panel: Plugins → Cart → Activate
3. Plugin creates `plugin_cart_items` table automatically
4. Use `{{ render_form('cart') }}` in your templates

## Settings

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `require_account` | checkbox | false | Require login for checkout |

## Twig

```twig
{# Add-to-cart button #}
<button class="add-to-cart" data-product-id="{{ product.id }}">Add to Cart</button>

{# Cart icon #}
<a href="/cart">Cart ({{ cart_count() }})</a>
```

## Routes

| URL | Description |
|-----|-------------|
| `/cart` | Cart page |
| `/cart/add` | AJAX add endpoint |
| `/cart/remove` | AJAX remove endpoint |
| `/cart/checkout` | Checkout page |

## License

MIT
