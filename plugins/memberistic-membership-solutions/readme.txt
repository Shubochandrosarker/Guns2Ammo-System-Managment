=== Memberistic Membership Solutions ===
Contributors: wordpressistic
Tags: membership, operations, staff dashboard, stripe, woocommerce, rest api
Requires at least: 6.0
Requires PHP: 8.0
Tested up to: 6.7
Stable tag: 1.20.0
License: GPLv2 or later

A modern membership operations engine for service businesses. Co-developed by WordPressistic and launch partner Guns 2 Ammo (https://guns2ammo.com).

== Description ==

Memberistic is the membership operations engine behind the Guns 2 Ammo Membership Engine — and the foundation of the Memberistic commercial product.

It is co-developed in partnership with **Guns 2 Ammo (https://guns2ammo.com)**, a US-based indoor shooting range and firearms retail business. Every workflow in the plugin — the Defender / Patriot / Guardian plan tiers, the linked-member system, the staff dashboard, the booking-engine integration, the kiosk-ready waiver model, and the POS-ready schema — is battle-tested in production at Guns2Ammo.

If you run a service business that combines memberships with check-ins, waivers, family / linked members, lane bookings, retail, or staff workflows — a shooting range, fitness studio, climbing gym, dive shop, golf simulator, racquet club, makerspace — Memberistic was built for you.

Highlights:

* Custom database with 10 dedicated tables.
* Three default membership tiers (Defender, Patriot, Guardian) seeded on first install — the canonical Guns 2 Ammo plan structure.
* Ten membership statuses including suspended and needs-review.
* Linked / family-member CRUD with per-person waiver, phone, DOB, relationship, and history.
* React-driven admin: Dashboard, Members, Plans, Payments, Check-Ins, Activity, Settings, Emails, Integrations.
* 14 frontend shortcodes and auto-mapped branded pages.
* Stripe Checkout for monthly + annual subscriptions, with full webhook handling.
* WooCommerce bridge with auto-created hidden products and signed `/webhooks/woocommerce` route.
* 13 transactional email templates, 20 merge tags, three daily cron jobs (renewal reminders, auto-expire, waiver follow-up).
* REST API under `/wp-json/memberistic/v1/` with 30+ endpoints.
* Six custom staff roles plus admin: Manager, Staff, Cashier, Instructor, KIOSK Operator, POS Staff.
* Content-restriction overlay for plan-gated posts and pages.

Built by [WordPressistic](https://www.wordpressistic.com) in partnership with [Guns 2 Ammo](https://guns2ammo.com).

== Installation ==

1. Upload `memberistic-membership-solutions/` to `wp-content/plugins/`.
2. Activate Memberistic Membership Solutions in WordPress admin.
3. Confirm Defender, Patriot, Guardian appear under Memberistic > Plans.
4. Open Memberistic > Settings and save your client brand settings, Stripe keys, and (optionally) WooCommerce settings.
5. Use Memberistic > Tools > Page Mapping to create the branded frontend pages.

The Stripe webhook URL is `https://YOUR-SITE/wp-json/memberistic/v1/webhooks/stripe`.

== Frequently Asked Questions ==

= Who built this plugin? =

Memberistic is co-developed by [WordPressistic](https://www.wordpressistic.com) and launch partner [Guns 2 Ammo](https://guns2ammo.com). Guns 2 Ammo is the flagship deployment; every workflow has been pressure-tested against its real US shooting-range and retail operations.

= Where is the documentation? =

See `README.md`, `docs/AUDIT_REPORT.md`, `docs/INSTALL.md`, `docs/HOOKS.md`, and `docs/PARTNERS.md` in the plugin directory.

= How do I add a new email template? =

Filter `memberistic_email_templates` to register the template id and label, then filter `memberistic_email_template_subject` and `_body` for that id. All 20 documented merge tags are available via `strtr()`.

= Can I run cron jobs more often than daily? =

Yes — unschedule `memberistic_daily_renewal_reminders`, `memberistic_daily_expire_memberships`, and `memberistic_daily_waiver_followup` and re-schedule with your preferred cadence.

= How do I uninstall and clear data? =

Set Settings > Advanced > "Delete data on uninstall" to Yes before removing the plugin. Otherwise the tables remain in place for safe re-activation.

== Changelog ==

See CHANGELOG.md for the full history.

Note: the plugin's version numbering was reset after 1.46.1 back to a 1.9.9.x line. Entries below are in release order (newest first), so 1.46.1 and the earlier "legacy line" 1.10.0 release predate the 1.9.9.2–1.12.0 entries above them.

Note: entries for 1.14.0–1.18.0 (the native waiver e-signature engine, kiosk mode, dashboard Waivers page, and SMS automations) are not yet documented here — see CHANGELOG.md and the merged PR history in the meantime.

= 1.18.6 =
* Admin menu now always reads "Memberistic" instead of the customer-facing brand label, so shortening the brand for emails no longer renames the whole admin menu.
* Customer-facing branding (emails, waivers, PDFs, member portal) now prefers the Business name setting over the shorthand brand label, so customers see the full business name rather than an abbreviation.
* The Stripe webhook health notice is now dismissible and only appears on the WordPress dashboard and Memberistic screens — it previously rendered on every admin page, including other plugins' dashboards, with no way to dismiss it. It also links straight to the payments screen, and returns automatically if the underlying problem changes.
* New: plans can mark booking types as included outright (`settings.included_booking_types`), so a Guest Pass bought at the counter resolves lane bookings to $0 without needing a 100% discount rule per booking type.
* New: `g2ab_advisory_membership_hint` tells the booking engine a typed email may belong to a member — without granting a discount — so the front desk can verify ID and apply the member rate.

= 1.18.5 =
* Hotfix: checkout advisory lock names are now hashed and capped at MySQL's 64-character limit, preventing false "Checkout is busy" failures before Stripe is contacted.
* Hotfix: advisory lock failures now fall back to a durable database rate-limit table instead of taking checkout offline.
* Security: checkout handler is POST/action/nonce-only, sends no-cache headers, checks same-origin referers when available, and validates Stripe checkout redirect hosts.
* Fix: checkout-start email is no longer sent before Stripe returns a Checkout Session; activation remains Stripe webhook/API-authoritative.
* UX: the checkout form disables its submit button while connecting to secure checkout to reduce double submissions.

= 1.18.3 =
* Hotfix: Stripe webhooks now hold the idempotency lock through processing and mark events processed only after successful handling.
* Hotfix: pending checkout retries reuse or reconcile the saved Stripe Checkout Session instead of creating a second subscription.
* Hotfix: checkout completion validates Stripe mode, session metadata, plan, billing cycle, amount, currency, email, payment status, and subscription status before activation.
* Hotfix: the thank-you page now confirms Stripe status server-side and shows active, processing, failed, or manual-review states honestly.
* Hotfix: renewal webhooks support current nested Stripe invoice subscription references, and inconclusive Stripe API checks no longer downgrade active members.
* Hotfix: role synchronization now runs after account provisioning.
* New: pending memberships store `stripe_checkout_session_id` and `stripe_checkout_expires_at`.
* New: WP-CLI `memberistic stripe-audit` and `memberistic stripe-reconcile` dry-run-first recovery commands.

= 1.18.2 =
* Fix: the plugin bootstrap is now idempotent, so if a second/stray copy of the plugin folder is ever active on the same site (e.g. a "memberistic-membership-solutions-main" GitHub-zip leftover alongside the real folder), it becomes fully inert instead of emitting PHP warnings that break `header()`/redirect calls on check-in, checkout, and waiver-signing/printing pages. Staff now get a clear admin notice naming both plugin paths instead of visitor-facing PHP warnings.

= 1.18.1 =
* Security: the self-serve waiver signing link/token no longer lives forever. It now expires after 60 days (`memberistic_waiver_token_validity_days` filter) and rotates automatically the next time a link is generated (e.g. a reminder email); it also rotates immediately the moment a member completes their waiver, so the exact link that was just used to sign can't be replayed later. Previously the token was minted once per member and never changed — a copy of an old emailed/texted link (forwarded mail, shared device, browser history) remained a standing "sign as this member" credential indefinitely. Already-issued links keep working (backfilled with a fresh window on first use after the update) — nothing breaks for members who haven't signed yet.

= 1.13.1 =
* Security: the booking-engine waiver bridge now auto-satisfies the booking waiver by EMAIL match only. The previous name fallback let a guest who typed a name matching any prior signer skip the waiver checkbox on the public booking form. Staff lookup screens keep name/DOB search.

= 1.13.0 =
* Fix: guaranteed Stripe cancellation — cancelling a membership on the site now stops the Stripe subscription FIRST and only then marks the membership cancelled. If Stripe cannot be reached, the membership keeps its current status (no more "cancelled locally but still billing"), the failure is shown as a persistent wp-admin notice, and automatic retries (5 min → 48 h backoff) finish the cancellation the moment Stripe confirms. Staff can still force a local-only cancel explicitly (REST force=true).
* New: memberistic_stripe_cancel_retry cron + persistent failed-cancel notice; every attempt, failure, and retry is logged to the membership activity feed.

= 1.12.0 =
* Change: unified the monorepo (1.10.7) and dedicated-repo (1.11.0) lines of the plugin into one tree — both the 1.10.x fixes (Stripe cancel propagation, token-bridge stylesheet) and the Advanced FFL Checkout bridge are now present everywhere. No functional changes beyond the merge.

= 1.11.0 =
* New: Advanced FFL Checkout bridge — a member's own online FFL firearm-transfer history on their account dashboard, and a heads-up on the staff verification card. Read-only, off by default.

= 1.10.1 =
* Fix: cancelling a membership on the site now cancels the member's Stripe subscription too — previously only the local status changed and Stripe kept billing. Covers the members app Cancel action, admin status edits, and the legacy wp-admin members page. Failed cancels are logged to the activity feed instead of failing silently.
* New: memberistic_stripe_cancel_at_period_end filter to stop billing at period end instead of immediately.

= 1.10.0 =
* New: coreSTORE (Coreware) POS bridge — membership tier + status pushed to the hosted POS for automatic in-store member discounts. Independent from the G2A POS bridge.
* New: automatic per-plan WooCommerce member discounts with category rules; savings stamped on orders and shown to members.
* New: Shop tab on the member account dashboard — orders, downloads, addresses, payment methods, and lifetime member savings in one place.
* New: memberistic_membership_status_changed action.

= 1.9.9.5 =
* Fix: post-login redirect now carries a unique cache-busting query arg so a stale CDN/page-cache copy of the account page can never be served to a freshly signed-in member. Purge the site/CDN cache once after updating.

= 1.9.9.4 =
**Sign Out now actually signs members out.**

* FIX: **Members couldn't log out.** The theme filters the logout link to `/login/?action=logout` (the same way it does login and forgot-password), but the branded page didn't handle `logout` — so clicking "Sign Out" just landed back on the login page, still signed in. `/login/` now processes the logout action: it verifies the standard WordPress `log-out` nonce, clears the session, and redirects to the home page (or wherever `redirect_to` points). A stale logout link shows a one-tap "Sign Out" confirmation with a fresh link.
* CHANGE: The account screen's "no membership" state now also shows a **Sign Out** link, so a signed-in visitor without a membership isn't stuck.

= 1.9.9.3 =
**Live-site hardening for the login fixes.**

* FIX: **Forgot-password could still appear "dead" on the live site** because a page cache / CDN served the cached *login* HTML for `/login/?action=lostpassword` and the reset links too. The login surface (the login, forgot-password and reset views) now sends no-cache headers and sets `DONOTCACHEPAGE`, so caching plugins and CDNs keep it dynamic. After updating, clear your site cache once.
* FIX: **"Set up member logins" notice would not clear** when a flagged membership had no email on file (the repair tool can't create a login without one). The count is now email-aware — only members that can actually be given a login are flagged — and provisioning falls back to a linked person's email when the primary row has none (common in imported data), so the notice clears once the real work is done.
* CHANGE: **One Save button on the Settings screen** — the duplicate header "Save changes" button was removed; the sticky footer save bar remains.

= 1.9.9.2 =
**Critical login & password fixes — members can sign in, reset passwords, and reach their digital card.**

* FIX: **Forgot/Reset password did nothing.** The branded /login/ page only ever drew the login form, so the theme's "Forgot password?" link (and the "set your password" links in welcome emails) dead-ended on /login/?action=lostpassword with no handler. /login/ is now a complete, theme-independent auth surface — it processes login, lost-password and reset-password actions itself, so the whole flow works even when wp-login.php is blocked or redirected by the theme.
* FIX: **Password-reset and "set your password" email links now land on the working page.** WP builds those links with wp-login.php?action=rp, which bypasses the theme's URL filter; they're now rewritten to /login/?action=rp so every link reaches the handler above.
* FIX: **Staff-added and imported members had no login.** Only Stripe checkout created a WP user account, so members added in the admin or via CSV had a membership but nothing to sign into and no way to set a password. New members are now given a WP account + a set-password email automatically (on activation and on staff create), and a one-click "Set up member logins" tool in the admin repairs the existing backlog — idempotent, and never emails a member twice.

= 1.46.1 =
Booking integration fixes. Member booking discounts now actually apply — they are read from the membership's PLAN settings (the memberships table has no settings column, so discounts were silently always empty). The renewal/expiry check is timezone-correct and no longer mis-parses a DATETIME renewal date as a "double time specification" (which had made active members read as not-bookable). Booking-form pages are exempted from content restriction so a mis-set "required plan" can never hide the lane-booking form. Member roles + active-plan meta are cleared on expiry — without stripping roles from a member who still holds another active membership. Content-gating membership resolution now matches the booking integration (honors email-linked memberships and rechecks renewal). Booking metadata carries the plan id (not the membership row id) for correct role assignment. Stripe checkout currency is validated against an allowlist.

= 1.10.0 (legacy line) =
Admin operations release. Members page gains server-side pagination, eight KPI cards (Total / Active / Pending / Past Due / Expired / Cancelled / New This Month with MoM growth % / Waiver Missing), and a bulk-action option to change waiver status for many memberships at once. Plans page is rebuilt as an animated card grid with per-plan member counts (total / active / other). Payments page gets pagination, six KPI cards (lifetime revenue, this-month revenue + MoM growth, new-member vs renewal payments, failed payments, visible-on-page), and a richer CSV export. Import flow now keeps every row: expired members import as expired, members with no matching plan import under a new "No Plan" sentinel plan with status = needs_review, members with no email still import. Order/payment imports never drop a row — orphan emails create a stub Instore member and emailless rows attach to a shared Instore Walk-in membership. Emails page becomes a React console with KPI cards (sent today/week/month, delivery rate, contact coverage) and a filterable, paginated directory; CSV export now includes 13 properly labelled columns including waiver dates and renewal info.

= 1.9.0 =
Admin-side waiver management. Each linked person row on the Members detail panel now has an inline Edit action that opens a full editor (full name, email, phone, relationship, waiver status, waiver signed/expires dates, member status) plus a Remove action for non-primary people. The `PUT /people/{id}` endpoint now accepts `waiver_signed_at` and `waiver_expires_at`, auto-stamps `waiver_signed_at` when the status flips to `signed`, and writes a `waiver_signed` / `waiver_expired` activity entry whenever waiver status changes. UI-only update — schema unchanged.

= 1.8.0 =
Member import: upload a Paid Memberships Pro members CSV or an orders export and bring the data into Memberistic with a dry-run preview before commit.

= 1.7.1 =
Documentation release. Adds a dedicated Partners page (`docs/PARTNERS.md`), expanded README with launch-partner framing, and acknowledgement of the WordPressistic × Guns 2 Ammo joint venture across the plugin docs and headers. No functional changes.

= 1.7.0 =
Full audit pass against the canonical Memberistic feature spec. Default Guns 2 Ammo plans (Defender / Patriot / Guardian) now seed automatically. Email automation gets six new templates, full merge-tag support, an email log table, and daily cron jobs for renewal reminders, auto-expire, and waiver follow-up. Stripe `invoice.payment_succeeded` now extends renewal dates. WooCommerce bridge auto-creates the six hidden products, handles refund / cancellation, and exposes a signed `/webhooks/woocommerce` REST route. Adds `PUT /people/{id}` and `DELETE /people/{id}`. Members search adds 9 filter dimensions and matches linked-member names plus Stripe / Woo / POS customer IDs. Adds KIOSK Operator and POS Staff placeholder roles. Adds `suspended` and `needs_review` statuses end to end. New `memberistic_email_logs` and `memberistic_integrations` tables. Full audit report shipped in `docs/AUDIT_REPORT.md`.

= 1.6.0 =
Retired the legacy server-rendered views. Email automation foundation, saved-filter views, React Settings console.

= 1.5.6 =
Membership user roles, plan-specific roles, and post / page restriction controls; modern restriction overlay.

= 1.5.0 =
Online Stripe memberships linked to WordPress users; lifecycle emails; Booking Engine + WooCommerce bridge foundations; expanded REST endpoints; reporting.

= 1.0.0 =
Initial Phase 1 foundation build.
