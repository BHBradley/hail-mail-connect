# CLAUDE.md — Hail Mail Connect Plugin

Context file for Claude Code sessions. Automatically loaded when working in this directory.

## Overview

**Hail Mail Connect** is a standalone WordPress plugin that connects to the [Hail CMS](https://hail.to)
API to pull an organisation's **mailing lists and subscribers**. It is a sibling of the `hail-connect`
plugin (same workspace, `../hail-connect/`) and **shares its OAuth2 + caching architecture** — but the
code is *copied and adapted*, not imported. Each plugin is portable and self-contained, and they ship as
separate git repos, so a site may have one without the other.

- **Status:** v0.1.0 — auth layer built (bootstrap + API + Connection settings). Mailing-list browse, subscriber↔WP matching, and the self-service shortcode are next.
- **Git repo:** separate from hail-connect; initialised locally, default branch `dev` (no remote yet)
- **Text Domain:** `hail-mail-connect`
- **Option / hook prefix:** `hail_mail_connect_*` (distinct from hail-connect's `hail_connect_*` so the two never collide in `wp_options`)

## Locked architecture decisions

- **Independent OAuth connection** — its own client_id/secret/tokens; never shares hail-connect's. Requires the **studio-whitelisted** Hail application as its client (studio is gated to `config('studio.client_id')` server-side).
- **Requested scopes:** `user.basic content.read content.write studio`. Granted scopes are **captured** (`hail_mail_connect_tokens.granted_scopes`); `Hail_Mail_Connect_API::has_scope()` gates write UI.
  - `content.read` → browse lists + subscribers
  - `content.write` → self-service subscribe/unsubscribe, admin remove-from-list
  - `studio` → admin add-without-opt-in only (the sole studio-gated feature)
- **Dedicated "Hail Mail" admin menu** (top-level) → Mailing Lists (browse) + Settings.
- **Mailing lists are read-only** in v1. No list CRUD — list management stays in Hail (deliberate; a possible future content.write seam, not v1).
- **WP user pool = WP core users, minus admins.** No RCP / membership-plugin dependency. (Open: exclude by `administrator` role vs `manage_options` capability.)
- **Subscriber data is never cached** (PII + freshness). List metadata: no cache for v1 (small org sizes: <1000 typical, few-thousand max).

## Build status (files present)

- `hail-mail-connect.php` — singleton bootstrap, dedicated menu, activation defaults
- `includes/class-hail-mail-connect-api.php` — OAuth (Authorization Code, lazy refresh, 401-retry, rotation), scope capture + `has_scope()`, generalised `request()` (GET/POST/PUT/DELETE), mail read methods, write methods (`create_subscriber`, `add_existing_subscribers_to_list`, `remove_subscribers_from_list`, `studio_add_subscribers`)
- `includes/class-hail-mail-connect-settings.php` — Connection settings (credentials, connect/disconnect, granted-scope display, studio-not-granted notice, AJAX test) + Mailing Lists **stub** page (`render_lists_page`)
- `assets/{css/admin.css,js/admin.js}`, `uninstall.php`, `.gitignore`

## Open questions (carried)

- "Not administrators" = exclude `administrator` role, or anyone with `manage_options` (catches custom admin roles + multisite super admins)? Leaning capability-based.
- Should `[hail_mail_subscribe]` render for logged-in admins, or no-op for `manage_options` users (not the mailing audience)?
- **Unsubscribe semantics** unverified — remove-from-list (`DELETE mail/lists/{id}/subscribers`) vs Hail's unsubscribe-substatus flow. Verify against hail-master before building item 5.

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
