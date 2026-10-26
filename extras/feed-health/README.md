# Fever Feed Health (optional add-on)

Optional, **standalone** single-page tool to check and maintain Fever subscriptions. It does **not** patch Fever core and is **not** loaded unless you install the file yourself.

Uses Fever’s existing `page.css` / UI chrome so it looks like the rest of the reader.

## Why

Fever shows “last refreshed” in the reader, but not whether a feed URL still serves valid RSS/Atom, fails TLS/DNS, or has gone stale. This page lists every subscription, live-checks them in small batches, and lets you edit or delete broken ones without leaving Fever.

## Requirements

- A normal Fever install ([mcaskill/fever](https://github.com/mcaskill/fever) or compatible)
- Same database / account config Fever already uses (`firewall/config/`)

No separate credentials file. Authentication reuses Fever’s **web login cookie** (same account as the reader). You can also log in on this page.

## Install

This folder lives under `extras/` in the Fever repository so it is never auto-loaded.

1. Copy `feed-health.php` from `extras/feed-health/` into your Fever web root (same folder as `index.php`).
2. Open `https://your-fever.example/feed-health.php` while logged into Fever (or log in there).
3. To uninstall, delete `feed-health.php` from the web root.

Expected layout after copying:

```text
index.php
feed-health.php       ← copied from extras/feed-health/
feed-api.php          ← optional, separate add-on
firewall/
  app/libs/…
  app/views/default/styles/page.css
  config/db.php
  config/key.php
```

## Features

| Feature | Notes |
|---------|--------|
| Inventory | All feeds with title, URL, groups, item counts, Fever timestamps |
| Live check | Batched HTTP/TLS fetch + RSS/Atom detection (Stop cancels mid-run) |
| Status | **green** OK · **yellow** reachable but stale/empty/auth · **red** broken |
| Edit | Title, feed URL, site URL; optional refresh after save; redirect hint when detected |
| Delete | One feed at a time, with browser confirm |

Checks are read-mostly. Edit/delete change Fever’s database the same way the optional [`extras/feed-api`](../feed-api/) does (in-place URL updates keep group memberships).

## Security notes

- Protect the Fever web root as usual (HTTPS, do not expose to the public internet without login).
- This page is only usable when logged into Fever; it does not accept `api_key` in the query string.
- Prefer identifying feeds by the live database id shown in the UI.

## Relationship to other extras

| | `feed-api.php` | `feed-health.php` |
|--|--|--|
| Audience | scripts / automation | human in a browser |
| Auth | `api_key` (POST body) | Fever web login cookie |
| UI | none (JSON) | single-page dashboard |

You can install either, both, or neither.

## License

Fever itself remains copyright © Shaun Inman. This add-on is meant for licensed Fever installs (including the mcaskill maintenance fork). Align contribution licensing with whatever the upstream maintainer prefers before opening a PR.
