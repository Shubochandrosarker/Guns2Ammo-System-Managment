=== G2A Booking Engine ===
Contributors: wordpressistic
Tags: booking, reservation, scheduling, appointments, shooting range, firearms, classes, calendar, woocommerce, memberships, payments, stripe, paypal
Requires at least: 6.2
Tested up to: 6.5
Requires PHP: 8.0
Stable tag: 1.9.9.1
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

= 1.9.9.1 =
**Premium polish: clean typography, reorganised dashboard, and a brand-new Shooters CRM.**

* CHANGE: **new typeface across the whole plugin.** The condensed stencil/military font (Rajdhani / Oswald / Impact) is gone — every screen, button, price and heading now uses a clean, modern **Inter** stack on both the storefront and the admin. Booking widgets, event lists, banners, landing pages, invoices and emails all read crisp and smart.
* NEW: **Shooters CRM** (G2A Booking → Shooters). Every client is a shooter, so the Customers tab is reframed as a proper CRM: animated KPI cards (total · new · returning rate · at-risk · lapsed), a 12-month **growth** chart, a **lifecycle mix** breakdown, live search + segment filters (VIP / New / Active / At Risk / Lapsed), animated shooter cards, and a slide-in profile with full booking history and lifetime value.
* NEW: **Win-them-back churn list.** Shooters who have gone quiet (60+ days) are surfaced automatically, highest-value first, each with a one-tap **Send offer** email — so lapsed customers can be pulled back instead of forgotten.
* CHANGE: **Dashboard reorganised, Amelia-style.** Scope tabs split the numbers that used to be mixed together — **Overview · Lanes · Events · Classes** — each with its own KPIs (with trend %), revenue trend chart, status mix, upcoming list and a **Top trends** table. A 7D / 30D / 90D / 1Y period selector drives the whole view.
* CHANGE: **Bookings roster reorganised by type.** A tabbed list — **All · Lanes · Events · Classes** — in a clean light table with shooter avatars, type chips, status badges, live search, status filter and CSV export. The booking detail view is rebuilt to match, keeping full intel, status control and the audit log.
* All metrics flow through one shared analytics layer, so the Dashboard, the Shooters CRM and the Bookings roster always agree. No schema changes — everything is derived live from existing bookings.

= 1.9.9 =
**Premium UI pass: cleaner event displays, light theme, landing fixes.**

* FIX: **event landing pages 404'd** when the site's rewrite rules got cleared (by another plugin or a permalink re-save). The `/event/{slug}/` rule now self-heals — it re-registers automatically the moment it goes missing, so landing pages keep resolving.
* FIX: **event landing hero text was hard to read** (the theme's own page title could show through). The hero is now a solid, opaque block with crisp text.
* NEW: **light theme** across the event displays — the landing page defaults to a clean light look (set `g2ab_event_landing_theme` to `dark` to switch back), and `[g2a_event_booking]`, `[g2a_upcoming_events]` and `[g2a_events_calendar]` all take `theme="light"`.
* CHANGE: **[g2a_upcoming_events]** rebuilt — a clean, premium **list** layout (Amelia-style rows: date · name · status · price · button) plus a refined **card** layout (`layout="card"`). Scope a page with `category="ccw-class"` or `event="…"` so a CCW page shows only CCW dates and a Ladies page shows only Ladies nights. Optional search box.
* CHANGE: **[g2a_event_booking]** now has a proper header, intro line and quick-facts (price · dates · seats) instead of a bare dropdown.
* FIX: contrast — Range Status console title/headings and the Events “Dates & Times” heading now render clearly on any admin colour scheme.

= 1.6.1 =
**New: Staff Range Status console + QR self check-in.**

* NEW: **Range Status console** (G2A Booking → Range Status, or `[g2ab_range_console]`) — a live operations board for the desk: a real-time lane map (in use / reserved / open with occupant, end time and waiver status), KPI cards (lanes in use, open lanes, checked in, today’s revenue), one-tap **walk-in** booking + check-in on any open lane, today’s reservations and a live payment feed. Polls every 20s.
* NEW: **QR self check-in** — the console shows a printable QR poster; customers scan it to open a mobile **self check-in** page (`[g2ab_self_checkin]` / `/?g2ab_checkin=1`), find their booking by code or name+phone, have their waiver validated, and check in without staff.

= 1.6.0 =
**Events: instant payment, member discounts, public calendar, redesigned displays + a Shortcodes tab.**

* FIX: **event date/time changed after saving** — occurrence times are stored in your site timezone but were displayed through a UTC conversion that shifted them (e.g. 10:00 AM showing as 3:00 AM). All event displays now show the exact time you entered.
* NEW: **instant payment for paid events** — booking a paid class/event now takes payment immediately through your active online gateway (Stripe → PayPal → any card gateway). It only falls back to “pay at the front desk” when no online gateway is configured.
* NEW: **per-event member discount** — set a member discount (%) right on the event edit page, or a fixed member price (0 = free for members). Members are charged the discounted price at checkout.
* NEW: **[g2a_events_calendar]** — a public month calendar of your events with an “upcoming events” sidebar and search (Amelia-style).
* CHANGE: **admin Calendar** now opens in **month view** by default, adds a list view, and shows class/event bookings by **event name** instead of “null”.
* CHANGE: **event banner** is now three customizable, animated formats — `style="spotlight"` (full hero + countdown), `style="strip"` (compact), `style="ticket"` (event-ticket card) — with an `accent` colour option.
* CHANGE: **event landing page** hero redesigned — premium, clean layout with a next-date / time / price / seats stat strip and subtle animations.
* CHANGE: **upcoming events list** text cleaned up and made easier to read.
* NEW: **Shortcodes** admin page (G2A Booking → Shortcodes) listing every shortcode with its attributes and a one-click copy button.

= 1.5.0 =
**New: full Events system (Amelia-style) + unified booking form + Guns2Ammo restyle.**

* NEW: **Events** — schedule seat-limited activities (Ladies Night, CCW classes, competitions) with specific dates/times, a seat count per date, and a single price each (free *or* paid). Manage them under **G2A Booking → Events**: create an event, then add as many dates/times as you like, each with its own seats and an optional price override.
* NEW: **Event booking** — customers pick an event, pick a date (with live seats-remaining and price), and reserve a seat. Free events auto-confirm; paid events take payment online (Stripe) or reserve for pay-at-the-desk. Seats are reserved race-safely so a date can never be oversold.
* NEW: **Unified booking form** — the booking form now has a *"What do you want to book?"* switch: **Lane / Range Time** (the existing lane flow, unchanged) or **Events & Classes**. The switch only appears when you have upcoming events.
* NEW: **Event displays** — a responsive `/event/{slug}/` landing page (hero, description, booking widget, OpenGraph + Event schema), plus reworked `[g2a_event_banner]` (featured event hero with countdown + seats) and `[g2a_upcoming_events]` (event cards) that read real events. New `[g2a_event_booking]` widget for embedding the booking flow anywhere.
* CHANGE: the booking form now defaults to the **Guns2Ammo dark/orange** look. Any colours you saved in the Form Customizer still take priority.
* FIX: form section titles could render dark-on-dark on some themes (a theme's heading colour overrode the form's); titles now set their own colour.
* Data: adds `g2ab_events` + `g2ab_event_occurrences` tables and links event bookings into the normal bookings table, so the front desk, calendar, payments and emails all handle event seats with no extra setup. Schema upgrades automatically on update.

= 1.4.6 =
**Admin Calendar and Front Desk now render (verified live).**

* FIX: **Admin Calendar** stayed blank even after 1.4.5 bundled FullCalendar. The init script was attached to an empty-`src` script handle, which WordPress does not emit, so the calendar never initialised. The init now attaches to the real FullCalendar handle and runs whether the DOM is still loading or already ready — the week/month grid renders.
* FIX: **Front Desk** roster never appeared because a stray escaped quote in the inline script produced a JavaScript syntax error (`Invalid or unexpected token`), aborting the whole desk script. The script now parses and the roster, totals, search, and per-booking actions all load.

= 1.4.5 =
**Admin fixes (Phase 1): Manual Booking, Calendar, Staff page, event landing, and dark UI.**

* FIX: **Manual Booking** "Invalid start time" — the date/time picker submits an ISO value (`2026-06-28T18:00`); the server now normalises the `T` separator so staff bookings save.
* FIX: **Admin Calendar** rendered blank because FullCalendar was loaded from a CDN that a firewall/security plugin can block. FullCalendar is now **bundled with the plugin** (no external request), and the calendar shows a clear message if the library is ever blocked instead of a silent blank.
* FIX: **Staff terminal page** showed the literal text `[g2ab_staff_console]` — that shortcode is now registered (alias of `[g2ab_frontdesk]`), so the staff console renders.
* FIX: **Event landing pages** (`/event/{slug}/`) 404'd after updates because rewrite rules weren't flushed; they now flush automatically once per version.
* FIX: **Dark-on-dark admin UI** — the "FILTER" / "UPDATE STATUS" buttons and the "MISSION BRIEF" booking-detail title now render in readable white on their dark backgrounds.
* Front Desk: error messages now stay on screen so a failed roster load shows its reason instead of a blank page.

= 1.4.4 =
**Hardening / diagnostics for the booking submit button.**

* The booking submit no longer silently dies on an unexpected error: any synchronous failure is now caught and shown on-screen (and the button is re-enabled) instead of leaving a non-responsive "Reserve my lane" button.
* The page-level form safety-net now only prevents the native form submit; it no longer stops event propagation, which guarantees the AJAX submit handler always receives the click.
* The nonce-refresh URL falls back to the standard `admin-ajax.php` path if the localized value is ever missing (e.g. a stale cached page).
* No change to the normal booking flow — these only affect error/edge cases.

= 1.4.3 =
**Fix "Cookie check failed" for logged-in members at booking submit.**

* FIX: logged-in members got *"Cookie check failed"* when clicking "Reserve my lane," while guests booked fine. When a logged-in user's browser sends the WordPress auth cookie with the booking request, WP core requires a valid `wp_rest` nonce; if the booking page was served from cache the embedded nonce is stale, so WP rejects the request (`rest_cookie_invalid_nonce`) before the plugin runs. The 1.4.1 self-heal couldn't recover it because it refreshed the nonce over the REST API, which hits the same cookie-nonce gate for logged-in users.
* The nonce is now refreshed over **admin-ajax** (`wp_ajax_g2ab_refresh_nonce`), which runs in the member's authenticated context with no REST nonce gate, so it can mint a valid nonce; the booking form fetches it and retries the submit once, transparently. Guests are unaffected.

= 1.4.2 =
**Critical booking fix — multi-shooter lane bookings + Memberistic integration.**

* FIX (CRITICAL, the live outage): booking a lane for more than one shooter was rejected with "Party size exceeds the resource capacity of 1." Lanes are seeded with capacity 1 (one lane) but the booking form invites 1–4 shooters, and the capacity check was applied to every booking type. The party-size-vs-capacity check now only applies to resources that actually count people (capacity_mode = party_size, e.g. classrooms); for a single lane, party size is the number of shooters sharing that lane and no longer blocks the booking. This is independent of the cookie/age-verification fixes in 1.4.1.
* FIX: post-booking hooks (g2ab_booking_created / g2ab_booking_status_changed) are now wrapped so a fault in any listener (membership, corporate enrollment, email, third-party) can no longer turn an already-saved booking into a 500 the customer sees as a failure.
* FIX: member detection no longer trusts the stale `memberistic_active_plan_id` user-meta (which isn't cleared on expiry), so an expired member no longer keeps member pricing or members-only access. Live membership status is resolved authoritatively through the `g2ab_user_is_member` filter (Memberistic / PMPro).
* ADD: the booking waiver gate now honors a `g2ab_waiver_satisfied` filter, so a membership plugin can auto-satisfy the waiver for a customer who already has a signed waiver on file.
* FIX: corrected a wrong repository method call in the bundled (optional) Memberistic module.

= 1.4.1 =
**Booking reliability — "cookies won't let me book a lane" fix.**

* FIX (the reported issue): The Verifyistic age-verification gate rejected returning customers who still held a valid age-verification cookie. The freshness window defaulted to 60 minutes, so any verification older than an hour was discarded and the booking POST was refused with a 403 — even though the customer's cookie was present and long-lived. The default is now 525600 (≈1 year), which lets the Verifyistic cookie's own expiry govern trust. Set it lower only if you deliberately want to force re-verification sooner.
* FIX: "Require age verification before booking" now fails OPEN when the Verifyistic plugin/backend isn't actually loaded, instead of silently blocking every guest booking. The gate is only enforced when there is a verification backend to enforce against, so compliance is preserved when it can function.
* FIX: The booking form now self-heals a stale `wp_rest` nonce. If a page was served from a full-page/CDN cache with an expired nonce, the form fetches a fresh nonce from a new uncached `/wp-json/g2a-booking/v1/nonce` endpoint and retries the booking once, instead of failing with "Invalid or missing nonce."
* FIX: Booking pages are now marked uncacheable early (at `template_redirect`, with a `Cache-Control: no-store` header) so the embedded nonce can't be cached stale by edge caches that ignore `DONOTCACHEPAGE`.
* FIX: The guest booking rate-limiter no longer locks out legitimate customers who share one public IP (range Wi-Fi / NAT / mobile CGNAT). The cap is far more generous and a successful booking now clears the counter, so only repeated failed attempts accumulate.
* FIX: The age-verification request guard now anchors on the plugin's REST namespace, so a `/bookings` route in another plugin can't trip it, and stale-vs-missing verification cookies now return distinct, diagnosable error codes.
* FIX: Public bookings only prefer Stripe when Stripe is actually configured and available, so a payable booking is never handed to a disabled gateway.
* FIX: Membership (PMPro) eligibility filter is now additive — it can no longer revoke access that another membership provider already granted.
* FIX: The seeded CCW Class booking type now uses `party_size` capacity counting (seats, not bookings) on fresh installs.

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
