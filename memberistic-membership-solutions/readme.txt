=== Memberistic Membership Solutions ===
Contributors: wordpressistic
Tags: membership, operations, staff dashboard, stripe, woocommerce, rest api
Requires at least: 6.0
Requires PHP: 8.0
Tested up to: 6.7
Stable tag: 1.44.0
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

= 1.10.0 =
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
