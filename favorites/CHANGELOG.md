# Favorites Plugin — Changelog

## [1.1.0] — 2026-07-30

### Changed

- **Table renamed** with `plugin_favorites_` prefix: `user_favorites` → `plugin_favorites_user_favorites`
- Prevents conflicts with user-created tables
- **Schema verification** via `PRAGMA table_info` + `ALTER TABLE ADD COLUMN`
- **FK references** updated: `REFERENCES users(id)` → `REFERENCES plugin_account_users(id)`
- Updated README with correct table names
## 1.0.0 — 2026-07-27

### Initial release

- Universal favorites/bookmarks: `user_favorites(entity_type, entity_slug)` — any content type
- API: `POST /api/favorites/toggle` (add/remove), `GET /api/favorites/list`
- Twig functions: `favorite_button()`, `favorite_items()`, `user_favorites()`, `favorites_count()`, `favorites_scripts()`
- Heart button with auto-toggle ❤️/🤍 on catalog pages
- Profile panel with fav-grid cards + remove animation
- Setting: `max_favorites` (default 50)
- Dependency: `account`
