# CLAUDE.md — Hail Mail Connect Plugin

Context file for Claude Code sessions. Automatically loaded when working in this directory.

## Overview

**Hail Mail Connect** is a standalone WordPress plugin that connects to the [Hail CMS](https://hail.to)
API to pull an organisation's **mailing lists and subscribers**. It is a sibling of the `hail-connect`
plugin (same workspace, `../hail-connect/`) and **shares its OAuth2 + caching architecture** — but the
code is *copied and adapted*, not imported. Each plugin is portable and self-contained, and they ship as
separate git repos, so a site may have one without the other.

- **Status:** v0.1.0 — auth + list management + self-service shortcode built. **Studio write path verified end-to-end on live Hail** (client `TvzwOh3` "APPA Experiment", studio scope granted; self-service subscribe via `studio_add_subscribers` lands immediately, no verification). Note: Hail rejects undeliverable domains like `@example.com` ("Not a good contact email address") — test with real/Gmail `+alias` emails.
- **Git repo:** `BHBradley/hail-mail-connect` (separate from hail-connect). Branches: `main` (release) + `dev` (working). Do work on `dev`.
- **Text Domain:** `hail-mail-connect`
- **Option / hook prefix:** `hail_mail_connect_*` (distinct from hail-connect's `hail_connect_*` so the two never collide in `wp_options`)

## Locked architecture decisions

- **Independent OAuth connection** — its own client_id/secret/tokens; never shares hail-connect's. Requires the **studio-whitelisted** Hail application as its client (studio is gated to `config('studio.client_id')` server-side).
- **Requested scopes:** `user.basic content.read content.write studio`. Granted scopes are **captured** (`hail_mail_connect_tokens.granted_scopes`); `Hail_Mail_Connect_API::has_scope()` gates write UI.
  - `content.read` → browse lists + subscribers, resolve contact by email
  - `content.write` → remove-from-list (soft unsubscribe); also the **add fallback** when studio is absent (this path triggers Hail email verification)
  - `studio` → add subscriber WITHOUT opt-in/verification — **preferred for all adds when granted**. Gated to a Hail-whitelisted client_id.
- **Dedicated "Hail Mail" admin menu** (top-level) → Mailing Lists + Settings. A list opens a **tabbed detail**: **WP Users** (default — manage membership) + **All Subscribers**.
- **Mailing lists are read-only** in v1. No list CRUD — list management stays in Hail (deliberate; a possible future content.write seam, not v1).
- **WP user pool = WP core users, minus admins** (`role__not_in administrator` — role-based for accurate pagination; custom-admin-role / multisite super-admin exclusion is a future seam). No RCP / membership-plugin dependency.
- **Subscriber data is never cached** (PII + freshness). List metadata: no cache for v1 (small org sizes: <1000 typical, few-thousand max).

## Build status (files present)

- `hail-mail-connect.php` — singleton bootstrap, dedicated menu (SVG icon), activation defaults
- `includes/class-hail-mail-connect-api.php` — OAuth (Authorization Code, lazy refresh, 401-retry, rotation), scope capture + `has_scope()`, opt-in studio scope (`get_requested_scope()`), generalised `request()` (GET/POST/PUT/DELETE), mail reads, `find_contact_by_email()`, org-slug resolution + `get_hail_mail_dashboard_url()`, writes (`create_subscriber`, `add/remove_subscribers`, `studio_add_subscribers`)
- `includes/class-hail-mail-connect-settings.php` — Connection settings (credentials, connect/disconnect, granted-scope display, studio opt-in checkbox, AJAX test)
- `includes/class-hail-mail-connect-lists.php` — Mailing Lists index + **tabbed list detail**: WP Users tab (non-admin users, in-list checkboxes, Save via `admin-post` → studio add / content.write remove) + All Subscribers tab (Hail subscribers + WP-match). Carded tables, Hail palette, "Open in Hail Mail" deep link, search, pagination, `subscriber_index()` full-set fetch
- `includes/class-hail-mail-connect-shortcodes.php` — `[hail_mail_subscribe]` self-service form + AJAX (WP-core login, studio-preferring add, content.write remove, WP_DEBUG-gated error detail)
- `includes/class-hail-mail-connect-updater.php` — GitHub Releases self-updater (ported from hail-connect): reads `github_repo`/`github_token` from settings, hooks the WP update transient, caches latest release 12h, injects auth headers, renames the zipball folder on install. Settings → **Updates** section. Releases are cut from tags on `main`.
- `assets/css/{admin,public}.css`, `assets/js/{admin,public}.js`, `assets/img/menu-icon.svg`, `uninstall.php`, `.gitignore`, `README.md`

## Hail mail soft-unsubscribe semantics (verified in hail-master)

- `DELETE mail/lists/{id}/subscribers` (removeExistingSubscribers) sets `unsubscribed_date = now()` — a **soft unsubscribe**, not a delete (`MailList/ManagementController.php:340`).
- `PUT mail/lists/{id}/subscribers` (addExistingSubscribers) sets `unsubscribed_date = null` — resubscribe (line 382).
- New contact → `POST organisations/{org}/mail/subscribers` with `gave_consent=true`.
- All `content.write`; a contact's `lists[]` carry `pivot.unsubscribed_date` (null = currently subscribed).

## `[hail_mail_subscribe]` (item 5)

Logged-in WP user self-manages list membership. **Adds prefer the studio endpoint** (`studio_add_subscribers`, no verification) when `has_scope('studio')`, else fall back to `content.write` create (which triggers Hail email verification). Removes use `content.write` (soft unsubscribe). Email is read server-side from the current user (never the request). Submitted ids validated against the org's subscribable set (`show_on_outputs`, non-SMS). Attrs: `lists` (restrict offered ids), `title`, `button`, `login` (`form` = native `wp_login_form` for logged-out visitors [default], `link` = wp-login link, `none`). Currently renders for **all** logged-in users (admins included). Hail rejects undeliverable domains (e.g. `@example.com`) with "Not a good contact email address" — test with real/Gmail `+alias` addresses.

**WP-core-only, no membership-plugin dependency.** Logged-out login uses WordPress's native `wp_login_form()` — NOT RCP or any SaaS/membership plugin. (An earlier RCP `[login_form]` auto-detection was removed per [[hmc-wp-core-only]].)

## Resolved

- **Studio whitelisting** — client `TvzwOh3` ("APPA Experiment ( /studio API )", created under `studio@hail.to`) added to `config('studio.client_id')` by Hail (Patrick, 2026-06-09, with a multi-client patch). Studio scope now granted; write path verified live.
- **Admin exclusion** for the WP Users tab → role-based (`role__not_in administrator`).

## Open questions (carried)

- Whether `[hail_mail_subscribe]` should hide for admins (currently shows for all logged-in users).
- Friendlier self-service UX when an add is rejected (e.g. bounced/undeliverable email) — currently a generic error.

## Relationship to Hail Connect

| Shared (adapt from `../hail-connect/`) | Divergent (build new here) |
|---|---|
| OAuth2 Authorization Code flow, token storage + rotation, lazy refresh | Mailing-list + subscriber endpoints |
| `wp_options`-based cache layer (direct storage, SWR, sweep cron) | Subscriber data model + WP-user matching by email |
| Admin settings page scaffolding (WP Settings API, tabbed) | Studio scope (`POST /studio/mail/lists/{id}/subscribers`) for Phase B add-subscriber |

**Why copy, not import:** the two plugins are independent installs with independent release cycles. A bug
fix in hail-connect's OAuth client does **not** propagate here automatically — port it deliberately.

When adapting code, change every `hail_connect` / `Hail_Connect` identifier to the `hail_mail_connect` /
`Hail_Mail_Connect` equivalent so option keys, nonces, hooks, and class names stay namespaced.

## Background / design

The feature was originally designed as a "Hailmail" tab *inside* hail-connect, then pivoted to this
standalone plugin (2026-06-08). The parked design — Phase A read-only browse (lists, subscribers, WP-user
matches), Phase B add-subscriber via Hail's upcoming `/studio` scope — is captured in project memory under
the Hail Mail Connect section. The full original plan lives at
`/Users/bbradley/Documents/dev/nzpf-local/hailmail_idea_for_claude.MD`; check the open questions for the
Hail dev (scope name, client_id whitelisting, payload shape, DELETE capability) before starting Phase B.

## Hail API endpoints (to confirm)

Mailing-list / subscriber endpoints are not yet documented here — fill in as they're confirmed against the
live API. Phase B depends on the `/studio` scope and `POST /studio/mail/lists/{id}/subscribers` going live.

## Conventions

- All HTTP via `wp_remote_get()` / `wp_remote_post()` — no raw cURL (matches hail-connect)
- Admin AJAX endpoints use `check_ajax_referer()` with a `hail_mail_connect_admin` nonce
- OAuth `state` uses `wp_create_nonce()` / `wp_verify_nonce()` for CSRF protection
- Settings and tokens stored under separate option keys so saving the form doesn't overwrite rotating tokens
