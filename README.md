# Hail Mail Connect

A standalone WordPress plugin that connects your site to the [Hail CMS](https://hail.to)
API to **manage which of your registered WordPress users subscribe to a Hail
organisation's mailing lists** — from the WP admin, and via a front-end self-service
form your members can use themselves.

It is a sibling of the **Hail Connect** plugin but fully independent: its own OAuth
connection, its own settings, and no shared code at runtime. A site can run either,
both, or neither.

## Features

- **Independent OAuth2 connection** to a Hail organisation.
- **Browse mailing lists** for the connected organisation (read-only).
- **Per-list detail with two tabs:**
  - **WP Users** — every registered non-admin WP user with an "in list" checkbox;
    tick/untick and save to add or remove them.
  - **All Subscribers** — the list's Hail subscribers, each matched to a WP user by
    email, with status (subscribed / unsubscribed / bounced / etc.).
- **Self-service shortcode** `[hail_mail_subscribe]` — a logged-in member chooses which
  lists to join or leave; logged-out visitors get a native WordPress login form.
- **WordPress-core only** — no dependency on Restrict Content Pro or any other
  membership/SaaS plugin. User data comes from WP core (`WP_User_Query`).

## Requirements

- WordPress 6.0+
- PHP 7.4+
- A Hail account with an OAuth application (Client ID + Secret) and an Organisation ID.

## Installation

1. Upload the plugin zip via **Plugins → Add New → Upload Plugin**, or copy the
   `hail-mail-connect` folder into `wp-content/plugins/`.
2. Activate **Hail Mail Connect**.

## Configuration

1. Go to **Hail Mail → Settings**.
2. Enter your **Client ID**, **Client Secret**, and **Organisation ID**, and save.
3. Register the displayed **callback URL** as a redirect URI on your Hail application.
4. Click **Connect to Hail** and approve.
5. The **Granted scopes** line confirms what was authorised.

### Scopes

| Scope | Used for |
|-------|----------|
| `user.basic` | Identify the account and resolve the organisation. |
| `content.read` | Read mailing lists and subscribers. |
| `content.write` | Remove members from a list, and add members (with Hail's email-verification flow). |
| `studio` *(optional)* | Add members **without** the opt-in/verification flow. Requires a Hail-whitelisted client. |

**Studio scope** is opt-in: leave it off until Hail confirms your Client ID is
whitelisted for it, otherwise the authorisation will be rejected. Toggle it on the
Settings page, then disconnect and reconnect to pick it up. Without studio, everything
works except "add without opt-in" — adds fall back to the verifying endpoint.

## Usage

### Admin — manage list membership

**Hail Mail → Mailing Lists → (choose a list)**

- **WP Users** tab: tick users to add, untick to remove, then **Save changes**.
- **All Subscribers** tab: browse the list's Hail subscribers and see which match a WP user.

### Front end — self-service subscriptions

Add the shortcode to any page:

```
[hail_mail_subscribe]
```

A logged-in member sees the available lists with their current memberships pre-ticked
and can update them. Logged-out visitors get a WordPress login form and return to the
page after signing in.

**Attributes**

| Attribute | Default | Description |
|-----------|---------|-------------|
| `lists` | (all subscribable) | Comma-separated list IDs to offer. |
| `title` | "Manage your email subscriptions" | Heading text. |
| `button` | "Save preferences" | Submit button label. |
| `login` | `form` | Logged-out behaviour: `form` (native WP login form), `link` (login link), or `none`. |

Only lists flagged as publicly subscribable in Hail are offered, and a member can only
ever change **their own** subscriptions.

## Notes

- **Unsubscribe is non-destructive** — removing a member marks them unsubscribed in
  Hail (history is preserved), rather than deleting the contact.
- **Undeliverable addresses are rejected** by Hail (e.g. `@example.com`) — use real
  email addresses when testing.
- Subscriber data is never cached locally (privacy + freshness).

## Development

- Branches: `main` (release), `dev` (working). Open PRs from `dev` into `main`.

## License

Proprietary — © Hail.
