# Hail Mail Connect — TODO

Working notes. Not shipped (exclude from the distributable zip, like CLAUDE.md).

## Now — test on remote staging
Upload `hail-mail-connect.zip` (Plugins → Add New → Upload Plugin) and verify:

- [ ] Activate cleanly; "Hail Mail" menu appears.
- [ ] Settings → enter Client ID / Secret / Organisation ID for the **staging** site.
- [ ] Register the staging **callback URL** (shown on Settings) on the Hail app.
- [ ] Connect to Hail (Studio OFF first) → succeeds.
- [ ] Enable Studio scope → reconnect → Granted scopes shows `… content.write studio`.
      (Studio is keyed to the whitelisted client `TvzwOh3`, not the site — same client works.)
- [ ] Mailing Lists → lists load and match Hail.
- [ ] WP Users tab → add a real-email user (Gmail `+alias`) → lands in Hail immediately (no verification).
- [ ] WP Users tab → remove a user → marked unsubscribed in Hail.
- [ ] `[hail_mail_subscribe]` on a page → logged-in member can subscribe/unsubscribe; logged-out sees WP login form.
- [ ] Confirm data shapes (subscriber `lists[].pivot.unsubscribed_date`, list `show_on_outputs`) behave on live responses.

## Security follow-ups (from audit)
- [ ] Add a per-user **rate limit** to the `ajax_subscribe` endpoint (transient, ~10 saves/min). Mitigates authenticated abuse / Hail API churn. (reCAPTCHA NOT needed on the subscribe form — it's authenticated + own-email-only.)
- [ ] If open registration is enabled, put **reCAPTCHA + email confirmation on the registration flow** (RCP/WP) — that's the anonymous boundary, not our form.

## Pre-production checklist
- [ ] Set `WP_DEBUG` back to `false` (currently `true` in local `wp-config.php`).
- [ ] If repo is private: add a fine-grained PAT (Contents: Read-only) under Settings → Updates for the self-updater.
- [ ] Rotate the Hail token/cookie that was exposed during debugging.

## Shortcode `[hail_mail_subscribe]`
- [x] Members-only: render nothing for unauthenticated visitors (login form/option removed).
- [ ] Better default heading than "Manage your email subscriptions". (Note: `title="..."` already overrides it; consider also accepting a `heading="..."` alias since it's the more intuitive name.)

## Open questions / nice-to-haves
- [ ] Should `[hail_mail_subscribe]` hide for admins too (currently renders for all logged-in users)?
- [ ] Friendlier self-service message when an add is rejected (e.g. bounced/undeliverable email) instead of the generic error.
- [ ] Reconsider green for Disconnect / Reset if it reads as too strong (could go secondary).
- [ ] WP-user pool admin exclusion is role-based (`administrator`); revisit if custom admin roles / multisite super admins matter.

## Release
- [ ] Create GitHub Release `v0.1.0` on `main` (tag + changelog) and attach the zip.
- [ ] Next version: bump `Version` + `HAIL_MAIL_CONNECT_VERSION` in `hail-mail-connect.php` before tagging.
