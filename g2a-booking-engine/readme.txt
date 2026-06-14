=== G2A Booking Engine ===
Contributors: wordpressistic
Tags: booking, reservation, scheduling, appointments, shooting range, firearms, classes, calendar, woocommerce, memberships, payments, stripe, paypal
Requires at least: 6.2
Tested up to: 6.5
Requires PHP: 8.0
Stable tag: 1.14.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A complete WordPress booking, reservation, and front-desk operations engine — built for shooting ranges, firearms training centers, and any time-slot business that takes online payments.

== Description ==

**G2A Booking Engine** is a production-grade booking system that turns any WordPress site into a complete reservation, payment, and front-desk operations platform. It was originally built for indoor shooting ranges and firearms training centers, but the same engine cleanly handles any business that sells time on a shared resource.

If your business looks like one of these, this plugin is for you:

* **Shooting ranges** — lane bookings, instructor-led sessions, CCW classes, members-only hours.
* **Firearms training centers** — multi-seat class enrollment with waivers and roster management.
* **Appointment-based services** — bays, courts, rooms, studios, treatment chairs, simulators.
* **Membership clubs** — member-only access with discount tiers and free monthly credits.
* **Retail + experience hybrids** — bookings that flow through your existing WooCommerce checkout.

= The vision =

Most WordPress booking plugins do one thing: rent a time slot. G2A Booking Engine is built around a bigger idea — that the booking is just the **start** of the customer journey. The real value lives in everything that happens around it: reminders, payments, deposits, member rules, walk-ins, check-ins, waivers, roster management, retail purchases, refunds, no-show recovery, and the long-term customer relationship.

So instead of shipping a booking widget and stopping, this plugin ships a complete operations spine:

* A booking engine that takes the reservation.
* A payment layer that supports Stripe, PayPal, Fortis, Authorize.net, Pay-in-Store, or your existing WooCommerce checkout.
* A reservation-hold cron that automatically frees abandoned bookings.
* An email automation engine with lifecycle templates, branded HTML, and a 5-minute reminder cron with dedupe.
* A signed-token PDF invoice generator that fires on every successful payment.
* Membership rules that respect Paid Memberships Pro and Memberistic levels (members-only booking types, percent discounts, free credits).
* A front-desk terminal for the desk laptop — today's roster, instant search, check-in, waiver verification, on-the-spot payment collection, printable receipts.
* A FullCalendar admin view with drag-to-reschedule and quick actions.
* Customer-facing reschedule + cancel pages, signed-token protected.
* A migration wizard with dry-run preview and full rollback, importing from Amelia, Bookly, BookingPress, or a generic CSV.
* An AI Auto-Reply addon that drafts replies to incoming booking questions using any OpenAI-compatible provider (OpenRouter, OpenAI, Groq, Together, or your own self-hosted endpoint).
* A clean REST API at `/wp-json/g2a-booking/v1/` for every operation.
* A complete action / filter hook system so external CRMs, SMS providers, and accounting systems can plug in.

Everything is stored in custom database tables (not post-meta), every gateway fires the same lifecycle hooks, and every public endpoint is signature- or nonce-protected. This is a system you can hand to a real range with real customers and real revenue.

= Feature highlights =

**Booking engine**

* Real-time availability with database-level locking — two simultaneous customers cannot book the same slot.
* Buffer time (before / after) enforced in both directions for cleanup, instructor prep, and safety briefings.
* Two capacity modes: `booking_count` for lane-style reservations, `party_size` for class-style group bookings.
* Reservation hold with automatic expiry of abandoned `pending` bookings every 5 minutes.
* Per-booking-type payment modes: full, deposit, in-store, or free.
* Members-only flag with a configurable login / join message for non-members.
* Standardised status set: `pending`, `reserved`, `confirmed`, `paid`, `completed`, `cancelled`, `no_show`, `refunded`, `expired`.

**Payments**

* Pay-In-Store (free) — reserve now, pay at the counter.
* Stripe (free) — Checkout Sessions with signed webhook verification.
* PayPal (free) — Smart Buttons, Venmo, Pay Later.
* Fortis Pay (pro) — lower card-present rates + native ACH.
* Authorize.net (pro) — hosted payment page with HMAC-verified silent post.
* WooCommerce Bridge (pro) — route bookings through any active WC gateway, with bidirectional status sync.
* Server-side amount cross-check on every gateway — undercharges are rejected and logged.
* Webhook event-id deduplication so replayed webhooks can't double-credit or reopen refunded bookings.

**Email automation**

* Templated, branded HTML emails for every lifecycle event.
* 5-minute reminder cron with dedupe via `wp_g2ab_logs`.
* Configurable From name, From address, admin notification address, brand colour, logo, footer.
* 20+ merge tags covering customer, booking, business, payment, and invoice details.
* Plays nicely with any transactional email service (SendGrid, Mailgun, SES) via WordPress's `wp_mail`.

**PDF invoices**

* Auto-generated on payment success — independent of whether the paid email is enabled.
* Signed-token download URLs (`?g2ab_invoice_pdf=<uuid>&t=<token>`) with capability fallback for staff.
* Auto-attached to the paid-confirmation email when email automation is enabled.
* HTML print-fallback for hosts without Dompdf.

**Memberships**

* Paid Memberships Pro and Memberistic integration modules, can run side by side.
* Detects logged-in member level, with a DB fallback if the level helper functions aren't available.
* Per-level percent discount rules — applied to all booking types or a selected list.
* Members-only booking types with a configurable upgrade / login message for non-members.
* On first paid booking, the customer is auto-tagged with a `Range Member` role and per-plan sub-roles for fine-grained access control.

**Migration tool**

* 5-step wizard: pick source → dry run → review mapping → live import → optional rollback.
* Built-in adapters for **Amelia** and **generic CSV**.
* **Bookly** and **BookingPress** adapter stubs in the registry, marked Coming Soon.
* Batched processing via Action Scheduler with WP-Cron fallback — handles 50 000-row imports without timing out.
* Idempotent: every imported row carries an `external_ref`, so re-running the same migration is safe.
* Full rollback by run id — removes only the rows that run created.

**Front desk + check-in**

* `G2A Booking → Front Desk` admin terminal: today's roster, date picker for any day, instant search by name / email / phone / confirmation.
* Per-booking actions: Check in, Verify waiver, Collect payment, Add note, Mark no-show, Print receipt.
* `[g2ab_frontdesk]` shortcode mounts the same terminal on a public page for desk laptops that never enter wp-admin.
* Print-friendly receipt at `/g2a-booking/v1/frontdesk/receipt/{booking_id}` — monospace, 380-column, thermal-printer ready.
* Every action writes to the `g2ab_booking_activity` log for a complete audit trail.

**Calendar + reschedule / cancel**

* FullCalendar 6 admin view — day / week / month, color-coded by status, filterable by lane / category / status.
* Drag a booking to reschedule it. Click for a quick-action panel.
* Customer-facing `[g2ab_reschedule]` and `[g2ab_cancel_booking]` shortcodes — signed-token protected.

**WooCommerce bridge** (Pro)

* WC order created per booking, with line items per booking type.
* Bidirectional sync: `processing` / `completed` → booking `paid`; `cancelled` / `refunded` → mirrored.
* All active WC gateways become bookable.

**AI Auto-Reply** (Pro)

* OpenAI-compatible chat-completions client — works with OpenRouter, OpenAI, Groq, Together AI, or any self-hosted endpoint (llama.cpp, vLLM, LM Studio).
* Safe-by-default system prompt: refuses unsafe firearms advice, legal advice, and minor-targeting requests.
* Drafts replies for staff review — never sends without approval.

**SEO**

* 6 SEO landing-page templates under `/event/{slug}/` with full JSON-LD schema.

**Developer experience**

* REST API at `/wp-json/g2a-booking/v1/` covering bookings, forms, calendar, front desk, admin, and webhooks.
* Complete action / filter hook system — every lifecycle event fires a hook external systems can listen on.
* Auto-discovered modules: drop a folder into `includes/modules/` with a `module.php` manifest and it appears in the Addons tab.
* Composer-free, no build step required.
* Activation-time OPcache invalidation so updates never serve stale bytecode.
* Auto safe-mode if a module fatals during init — the admin stays accessible so you can recover.

= What ships in this plugin =

* Core booking engine, REST API, admin UI, frontend booking form.
* 6 built-in payment gateways (Pay-In-Store, Stripe, PayPal, Fortis, Authorize.net, WooCommerce Bridge).
* 8 auto-discovered feature modules (Email Automation, PDF Invoices, Migration Tool, PMPro Memberships, Memberistic, Verifyistic / Waiver hooks, WooCommerce Bridge, AI Auto-Reply).
* SEO landing-page templates and JSON-LD schema.
* 6 example shooting-range lanes seeded on activation.
* A custom `Range Member` role for tracked customers.

== Installation ==

1. Download the plugin zip and upload it under **Plugins → Add New → Upload Plugin**.
2. Activate the plugin.
3. Visit **G2A Booking → Settings** to configure your business profile, payment gateways, and email templates.
4. Drop the shortcode `[g2a_lane_booking]` onto a public page.
5. Run an end-to-end test as a logged-out visitor.

A full operator's manual ships with the plugin at `docs/USER-GUIDE.md` (and a PDF edition at `docs/G2A-Booking-Engine-User-Documentation.pdf`).

== Frequently Asked Questions ==

= Does the plugin require WooCommerce? =

No. WooCommerce is optional. Enable the WooCommerce Bridge addon only if you want bookings to flow through your existing WC checkout.

= Can I use it on a multisite network? =

Yes. Tables are created per site on activation and removed per site on uninstall.

= How does it prevent double bookings? =

Every booking insert runs inside a database transaction with `SELECT ... FOR UPDATE` row locks on the resource and time range. Two simultaneous customers booking the same slot will result in exactly one success and one clear "already booked" error.

= Does it work with my payment processor? =

Stripe, PayPal, Fortis, and Authorize.net are built in. Anything else is reachable via the WooCommerce Bridge (any active WC gateway becomes bookable). Or write a custom gateway adapter — see the developer hooks section of the user guide.

= Does it handle classes with multiple seats? =

Yes. Set the booking type's `capacity_mode` to `party_size` and the engine will sum party sizes against the resource capacity instead of counting bookings. A CCW class with 12 seats can be filled by three bookings of party 4, two of party 6, twelve of party 1, etc.

= Can members get discounts? =

Yes. The PMPro and Memberistic addons detect the logged-in user's level and apply configurable per-level percent discounts. 100% = free booking.

= How do I import bookings from another plugin? =

`G2A Booking → Migration`. Built-in adapters for Amelia and CSV; Bookly and BookingPress adapter stubs are in the registry. Every migration supports dry-run preview before touching real data and full rollback by run id afterwards.

= Is the data deleted when I uninstall? =

Only if you opt in. Set **Settings → Remove all data on uninstall** to ON before deleting the plugin.

= Is there an API? =

Yes. REST API at `/wp-json/g2a-booking/v1/` covers bookings, forms, calendar, front desk, admin, and webhooks. Every action / filter hook in the lifecycle is documented in the user guide.

== Screenshots ==

1. Admin dashboard — at-a-glance stats and quick actions.
2. Public booking form — Calendly-style two-column flow.
3. Front-desk terminal — today's roster with quick actions.
4. FullCalendar admin view — drag to reschedule.
5. Addons tab — enable / disable feature modules.
6. Email automation templates — branded, merge-tag friendly.
7. Migration wizard — dry-run preview before live import.

== Changelog ==

= 1.12.4 =
**Lifecycle, payment, and invoice correctness fixes.**

* FIX: `{invoice_url}` merge tag in customer emails now includes the signed
  `t=` token. Previously customers clicking the link were hit with a 403
  because the renderer requires a valid HMAC token for guest access.
* FIX: Email automation handlers now normalize the `g2ab_booking_*` hook
  argument. Hooks fired with a booking id (vs. a row) used to collapse to
  `[0 => 123]` inside `build_tags()`, blanking every merge tag in the
  rendered email. A `resolve_booking()` helper now fetches the row by id
  when needed, mirroring the PDF Invoices module.
* FIX: Verifyistic auto-accept now satisfies the waiver requirement at the
  REST validation layer via `g2ab_waiver_satisfied`. Previously the
  enrichment ran POST-insert, so age-verified visitors were still blocked
  by the controller's pre-insert waiver check.
* FIX: Invoice "Pay Now" CTA (`?g2ab_pay={uuid}`) is now handled. Resolves
  the booking, redirects to the existing WooCommerce pay-for-order URL if
  a WC order exists, otherwise forwards to the public booking page so the
  frontend script can resume the pay flow.
* FIX: WooCommerce gateway billing prefill referenced a non-existent
  `$booking->fields` column. Switched to the real `form_data` JSON column
  and prefer the canonical `customer_*` row columns where present.
* FIX: Hardcoded `$` currency symbol in invoice HTML and `booking_paid`
  email templates now respects the `g2ab_currency` option (USD, CAD, GBP,
  EUR, AUD, NZD). New `{amount_formatted}` merge tag exposes the
  currency-aware value to custom templates.
* FIX: PDF Lite generator transliterates accented characters via
  `iconv('UTF-8','ASCII//TRANSLIT//IGNORE')` instead of stripping them to
  spaces, so names like "José" / "Renée" render correctly. Falls back to
  the legacy strip when iconv is unavailable.
* PERF: Email reminder cron's `NOT IN (SELECT booking_id FROM g2ab_logs)`
  subquery now has a composite `(event_type, booking_id)` index. New
  installs already had this in dbDelta; existing installs get a one-shot
  self-healing ALTER on the next admin/cron tick (guarded by the
  `g2ab_logs_idx_v1` option flag).
* NEW: Memberistic module now fires a fail-safe on `g2ab_booking_created`
  that upserts the customer's email into the People_Repository when
  present, so the booking-to-person link doesn't silently disappear when
  the upstream plugin is misconfigured. No-op when Memberistic isn't
  installed.
* Version bumped to 1.12.4. Schema version stays at 1.6.1 (no new tables;
  the index already lives in the dbDelta CREATE TABLE for fresh installs).

= 1.12.3 =
* FIX: Manual Booking (Bookings → Manual Booking) failed for every staff
  member with "Invalid start time." The admin form uses an HTML5
  <input type="datetime-local">, whose browser-submitted value carries a
  literal "T" separator (YYYY-MM-DDTHH:MM); the REST controller only accepted
  the space-separated form and rejected the request before it ever reached the
  database. The controller now normalises the "T" separator to a space, so
  phone, walk-in, and staff bookings save correctly.

= 1.4.0 =
**Front Desk + Check-in.**

* NEW: Bookings → Front Desk admin page. Today's roster (with date picker), instant search by name / email / phone / UUID, per-booking quick actions: Check in, Verify waiver, Collect payment, Add note, Mark no-show, Print receipt. Auto-refreshes every 60s.
* NEW: `[g2ab_frontdesk]` shortcode. Same terminal as wp-admin, mounted on a public page for desk laptops that never enter the dashboard. Capability-gated: only logged-in users with `manage_g2ab_bookings` see it.
* NEW: REST `/frontdesk/today`, `/frontdesk/search`, `/frontdesk/{checkin,verify-waiver,collect-payment,no-show,note}`, `/frontdesk/receipt/{id}`. All gated by `manage_g2ab_bookings` + nonce.
* NEW: `G2AB_Checkin_Service` — single source of truth for check-in / payment / note operations. Idempotent check-in, payment auto-promotes booking status to `paid` when the balance hits zero, all actions write to the activity log.
* NEW: Lifecycle hooks `g2ab_booking_checked_in`, `g2ab_customer_checked_in`, `g2ab_waiver_verified`, `g2ab_payment_collected`. Email Automation listens to these alongside the existing booking lifecycle events.
* NEW: Print-friendly receipt at `/g2a-booking/v1/frontdesk/receipt/{booking_id}` — clean monospace layout, "Print" button, prints to a thermal-style 380px column. Opens in a new tab for staff to print on demand.
* FIX: Email From address and admin notification email — the engine was reading two option keys that the settings UI never saved. Aligned both on the canonical keys so customised values are now honored.
* FIX: Reminder cron was inserting into `wp_g2ab_logs` using column names that don't exist in the real schema. Rewrote the SELECT subqueries and the INSERT to match the actual table — reminders no longer fail silently.
* FIX: Duplicate-email guard now checks the addon-activation state instead of `class_exists`, so deactivating the Email Automation addon now correctly falls back to the basic confirmation email.
* FIX: PDF invoice generation is now triggered directly by `g2ab_booking_paid` and `g2ab_payment_succeeded`, independent of whether the paid email is enabled.
* FIX: WooCommerce gateway no longer double-registered. The bridge addon is the single source of truth so the gateway respects the addon-active gate.
* FIX: Hardcoded `email_auto` / `email_templates` / `invoices` addon cards that collided with the real modules `email_automation` and `pdf_invoices` have been removed.
* DB: Schema bumped to 1.4.0. dbDelta adds `bookings.checked_in_at` and creates `g2ab_checkins` (id, booking_id, checked_in_by, checked_in_at, waiver_verified, payment_verified, note, created_at).

= 1.3.0 =
**Real Calendar + Reschedule / Cancel + Status Expansion.**

* NEW: Bookings → Calendar admin page. Day / week / month views via FullCalendar 6 (CDN-loaded). Filter by lane/resource, category, or status. Drag a booking to reschedule it. Click a booking for a quick-action panel: Reschedule, Mark Completed, Mark No-Show, Cancel.
* NEW: REST `GET /calendar/events` returns FullCalendar event objects with status colors and payment badges (paid / partial / unpaid / no_charge).
* NEW: REST `POST /calendar/reschedule|cancel|no-show|complete` for staff actions, all behind the `manage_g2ab_bookings` capability.
* NEW: REST `POST /bookings/{uuid}/customer-reschedule` and `/customer-cancel`. Token-gated using the same `confirm_token` issued at booking creation in v1.2.4. Refuses changes inside the configured min-lead window.
* NEW: `[g2ab_reschedule]` and `[g2ab_cancel_booking]` shortcodes. Read `?uuid=` and `?token=` from the URL (the same tokens email merge tags emit) and post to the customer endpoints.
* NEW: Standardized status set — `pending`, `reserved`, `confirmed`, `paid`, `completed`, `cancelled`, `no_show`, `refunded`, `expired`. Each gets its own calendar color and centralized helpers via `G2AB_Booking_Statuses`.
* NEW: `g2ab_booking_activity` table + `G2AB_Booking_Activity` service. Every reschedule, cancel, complete, and no-show writes a row with old/new value, acting user, and a free-text note. Foundation for the v1.4.0 booking detail history panel.
* NEW: `bookings.cancel_reason`, `bookings.cancelled_by_user_id`, `bookings.rescheduled_at`, `bookings.original_start_at` columns. dbDelta adds them on upgrade; existing rows have `original_start_at` backfilled to their current `start_at`.
* DB: Schema bumped to 1.3.0.

= 1.2.4 =
**Stability + Real Booking Logic Update.**

* SECURITY: `?g2ab_version_check=1` is no longer public — now requires `manage_options`. Deployment diagnostics shouldn't be readable by anyone on the internet.
* SECURITY: `POST /bookings/{uuid}/confirm-payment` now requires a `confirm_token` issued at booking-creation time. Bookings that pre-date 1.2.4 fall back to the existing gateway verification path.
* NEW: Booking-hold expiry cron. `pending` / `reserved` bookings on `full` or `deposit` payment modes flip to `expired` after `g2ab_reservation_hold_minutes`, freeing the slot. Runs every 5 minutes via `g2ab_cleanup_expired_reservations`; fires `g2ab_booking_expired`.
* NEW: Buffer enforcement. Conflict checks now honor each booking type's `buffer_before` / `buffer_after`, both for the candidate booking AND for every existing booking it's compared against. Cleanup, instructor prep, and class setup time can no longer be double-booked.
* NEW: Capacity modes on booking types. `booking_count` (default — 1 booking per slot) and `party_size` (sum of party_size — for CCW class capacity = total seats). `lane_based` and `seat_based` are accepted as aliases. Existing class-category booking types are auto-migrated to `party_size` on upgrade.
* NEW: Manual Booking admin page (Bookings → Manual Booking) + `POST /admin/bookings` REST endpoint. Foundation for the v1.3.0 calendar quick-add. Staff can record phone bookings, walk-ins, cash payments, card-at-terminal, or admin comps without going through the public funnel.
* FIX: Duplicate confirmation emails. The legacy `send_confirmation_email()` only fires when the Email Automation module isn't active.
* FIX: REST controller registration no longer references missing classes.
* DB: Schema bumped to 1.2.0. Adds `booking_types.capacity_mode VARCHAR(20)` with sensible defaults.

= 1.2.0 =
**Security & correctness — all P0/P1 audit findings fixed.**

* SECURITY: Invoice URLs are now signed (HMAC-SHA256, 30-day expiry) and gated.
* SECURITY: Guest booking submissions no longer auto-login customers.
* SECURITY: REST `/bookings` endpoint requires a valid `wp_rest` nonce for every caller.
* SECURITY: All four live payment gateways verify a server-side amount cross-check before flipping a booking to paid.
* SECURITY: Webhook event-id de-duplication helper added so retried events can't double-credit or reopen refunded bookings.
* FIX: Booking creation is now race-safe AND capacity-aware. Wrapped in a single transaction with `SELECT … FOR UPDATE`.
* FIX: All booking datetimes are parsed and stored in the site timezone via `wp_timezone()`.

= 1.1.0 =
* NEW: Migration Tool — Amelia, CSV, Bookly (stub), BookingPress (stub) adapters with dry-run, batched processing, and rollback.
* NEW: AI Auto-Reply module — OpenAI-compatible client with safe-by-default prompts.

= 1.0.0 =
* Initial release — core booking engine, REST API, Stripe / Pay-In-Store gateways, REST API, custom DB schema.

== Upgrade Notice ==

= 1.4.0 =
Front Desk + Check-in terminal, real audit-fix release. Email automation now honors customised From / admin addresses (previously silently ignored), reminder cron writes to the correct schema, invoices generate independently of the paid email, and the WooCommerce gateway respects its addon toggle. Safe to upgrade — DB migration is automatic via dbDelta.
