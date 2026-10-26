# Fever Feed Management API (optional add-on)

Optional, **standalone** endpoint for managing Fever feeds. It does **not** patch Fever core and is **not** loaded unless you install the file yourself.

Drop `feed-api.php` next to Fever’s `index.php`. Remove the file to disable the add-on completely.

## Why

The classic Fever client API (`/?api`) is for readers (sync items, mark read). It never gained feed add/edit/delete. This add-on fills that gap for automation and maintenance without changing Fever’s UI or client protocol.

## Requirements

- A normal Fever install ([mcaskill/fever](https://github.com/mcaskill/fever) or compatible)
- Same database / account config Fever already uses (`firewall/config/`)

No separate credentials file. Authentication reuses Fever’s existing `api_key`:

```text
api_key = md5(email + ":" + password)
```

Pass the key only in the **POST body** (form fields or JSON). Query-string keys are ignored.

## Install

This folder lives under `extras/` in the Fever repository so it is never auto-loaded.

1. Copy `feed-api.php` from `extras/feed-api/` into your Fever web root (same folder as `index.php`).
2. Call `https://your-fever.example/feed-api.php` with POST.
3. To uninstall, delete `feed-api.php` from the web root.

The script expects Fever’s usual layout after copying:

```text
index.php
feed-api.php          ← copied from extras/feed-api/
firewall/
  app/libs/…
  config/db.php
  config/key.php
```

## Actions

All requests: `POST`, `Content-Type: application/x-www-form-urlencoded` or `application/json`.

| action | Purpose |
|--------|---------|
| `status` | Feed counts / freshness (default) |
| `lookup` | One feed by `id` or `url` |
| `refresh` | Refresh one or more feeds via Fever’s own `refresh_feed()` |
| `update` | Change `new_url` / `title` / `site_url` in place (does **not** call `edit_feed()`, so group memberships stay) |
| `delete` | Delete one feed; requires `confirm=1` |

### Examples

Replace the placeholders. Do not commit real keys.

**Status**

```bash
curl -sS -X POST 'https://your-fever.example/feed-api.php' \
  -H 'Content-Type: application/json' \
  -d '{"api_key":"YOUR_API_KEY","action":"status"}'
```

**Lookup by URL**

```bash
curl -sS -X POST 'https://your-fever.example/feed-api.php' \
  -H 'Content-Type: application/json' \
  -d '{"api_key":"YOUR_API_KEY","action":"lookup","url":"https://example.com/feed"}'
```

**Update feed URL**

```bash
curl -sS -X POST 'https://your-fever.example/feed-api.php' \
  -H 'Content-Type: application/json' \
  -d '{"api_key":"YOUR_API_KEY","action":"update","url":"http://old.example/feed","new_url":"https://new.example/feed","refresh":1}'
```

**Delete one feed**

```bash
curl -sS -X POST 'https://your-fever.example/feed-api.php' \
  -H 'Content-Type: application/json' \
  -d '{"api_key":"YOUR_API_KEY","action":"delete","url":"https://example.com/feed","confirm":1}'
```

**Refresh stale feeds (limit 5)**

```bash
curl -sS -X POST 'https://your-fever.example/feed-api.php' \
  -H 'Content-Type: application/json' \
  -d '{"api_key":"YOUR_API_KEY","action":"refresh","limit":5}'
```

## Security notes

- Treat `api_key` like a password (HTTPS recommended).
- Prefer identifying feeds by **URL** (or look up the live id first); do not assume OPML export ids match the database.
- `delete` is single-feed only and requires `confirm=1`.
- Failed auth returns the same shape as Fever’s client API: `{ "api_version": 3, "auth": 0 }`.

## Relationship to core

| | Client API `/?api` | This add-on `feed-api.php` |
|--|--|--|
| Shipped with Fever | yes | no (optional file) |
| Used by Reeder etc. | yes | no |
| Manage feed URLs | no | yes |
| Mark items read | yes | no |

Suggested upstream layout for a PR: keep this under something like `extras/feed-api/` so it stays clearly optional and is never auto-loaded by Fever’s bootstrap.

## See also

[`extras/feed-health`](../feed-health/) — optional browser UI to live-check feeds and edit/delete them (Fever web login, not `api_key`).

## License

Fever itself remains copyright © Shaun Inman. This add-on is meant for licensed Fever installs (including the mcaskill maintenance fork). Align contribution licensing with whatever the upstream maintainer prefers before opening a PR.
