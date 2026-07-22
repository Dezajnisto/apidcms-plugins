# YooKassa Plugin — Changelog

## 1.0.0 — 2026-07-22

### Initial release

- **YooKassa API client:** `createPayment()`, `getPayment()`, UUID idempotency keys
- **Webhook endpoint:** `POST /yookassa/webhook` — processes YooKassa notifications
- **Return page:** `GET /yookassa/return` — delegates to subscription plugin
- **Hook API implementation:** responds to `subscription.create_payment` and `subscription.check_payment` filters
- **Plugin settings:** `yookassa_shop_id`, `yookassa_secret_key`

### Architecture

YooKassa plugin implements the subscription hook contract, making it a drop-in payment gateway. Any plugin that needs payment processing calls subscription hooks; yookassa responds if configured.

Extracted from subscription plugin v1.2.2 to separate payment infrastructure from business logic.
