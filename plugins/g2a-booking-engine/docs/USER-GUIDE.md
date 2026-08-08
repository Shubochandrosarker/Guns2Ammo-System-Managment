# G2A Booking Engine — User Guide

A complete operator's manual for the G2A Booking Engine. This guide walks you through installation, configuration, daily operations, and every shipped feature.

A polished PDF edition of this manual is available alongside this file at `docs/G2A-Booking-Engine-User-Documentation.pdf`.

---

## Table of contents

1. [What this plugin does](#what-this-plugin-does)
2. [System requirements](#system-requirements)
3. [Installation](#installation)
4. [First-run setup](#first-run-setup)
5. [Core concepts](#core-concepts)
6. [Resources / lanes](#resources--lanes)
7. [Booking types](#booking-types)
8. [Forms](#forms)
9. [Availability rules and blackout dates](#availability-rules-and-blackout-dates)
10. [Payment gateways](#payment-gateways)
11. [Email automation](#email-automation)
12. [PDF invoices](#pdf-invoices)
13. [Memberships (PMPro / Memberistic)](#memberships-pmpro--memberistic)
14. [WooCommerce bridge](#woocommerce-bridge)
15. [AI Auto-Reply](#ai-auto-reply)
16. [Migration tool](#migration-tool)
17. [Front desk and check-in](#front-desk-and-check-in)
18. [Calendar and reschedule / cancel](#calendar-and-reschedule--cancel)
19. [Shortcodes](#shortcodes)
20. [REST API](#rest-api)
21. [Hooks for developers](#hooks-for-developers)
22. [Troubleshooting](#troubleshooting)
23. [Uninstalling](#uninstalling)

---

## What this plugin does

The G2A Booking Engine turns any WordPress site into a complete booking, reservation, payment, and front-desk operations system. It was originally built for shooting ranges and firearms training centers, but the same engine cleanly handles any business that needs:

- Time-slot reservations for shared resources (lanes, bays, courts, rooms, instructors).
- Class-style group bookings with seat capacity.
- Member-only sessions with discount or free-credit rules.
- Online payment with deposits, full payment, or pay-on-arrival.
- A staff dashboard for walk-ins, phone bookings, and check-in.

Everything is stored in custom database tables (no post-meta bloat), exposed through a clean REST API, and every lifecycle event fires an action hook so external systems can listen.

---

## System requirements

- WordPress **6.2** or newer.
- PHP **8.0** or newer.
- MySQL 5.7+ / MariaDB 10.3+.
- Outbound HTTPS access from the server for Stripe / PayPal / Fortis / Authorize.net webhooks.

Optional integrations:

- WooCommerce 7.0+ (for the WooCommerce Bridge).
- Paid Memberships Pro 2.9+ (for PMPro discounts).
- Memberistic 1.0+ (for Memberistic plan integration).

---

## Installation

1. Download the plugin zip (`g2a-booking-engine-x.y.z.zip`).
2. In WordPress admin, go to **Plugins → Add New → Upload Plugin**.
3. Upload the zip, then click **Install Now** and **Activate**.
4. A `G2A Booking` menu appears in the WordPress admin sidebar.

After activation:

- Custom database tables are created.
- Default booking types, a default form, and 6 example lanes are seeded.
- The booking-expiry cron (`g2ab_cleanup_expired_reservations`) is scheduled every 5 minutes.
- A new role, **Range Member**, is registered for customers who complete a booking.

---

## First-run setup

A 10-minute setup gets you from activation to taking real bookings.

1. **Business profile.** `G2A Booking → Settings → General`. Set business name, address, phone, timezone, currency, date / time format.
2. **Resources.** `G2A Booking → Resources`. Edit the seeded lanes or add your own: name, capacity per slot, category (lane, class, room, etc.).
3. **Booking types.** `G2A Booking → Booking Types`. Each type maps a service (e.g. "Lane Rental", "CCW Class") to its rules: duration, buffer time, capacity mode, payment modes, members-only flag.
4. **Availability.** `G2A Booking → Availability`. Set weekly open / close hours per resource and add blackout dates for holidays.
5. **Payments.** `G2A Booking → Settings → Payments`. **Configure Stripe first — it is not optional.** A non-member must pay online before a lane is held, so until an online gateway is live, public bookings cannot complete at all. Pay-In-Store is for staff-created bookings only and will not serve a public reservation.
6. **Email automation.** `G2A Booking → Settings → Email Automation`. Set the From name + From address and the admin notification email. The default templates are production-ready.
7. **Drop the shortcode.** Add `[g2a_lane_booking]` to a public page. Visit it as a logged-out user and run a test booking end-to-end.

---

## Core concepts

A few terms used throughout the rest of this guide.

- **Resource.** Something a customer reserves — a lane, a bay, a court, an instructor, a room.
- **Booking type.** A bookable service — what you're selling. A booking type is attached to a form and may be restricted to specific resources.
- **Form.** The fields you ask the customer to fill out. The default form collects name / email / phone; you can build your own.
- **Capacity mode.**
  - `booking_count` — one booking per slot (default for lane rentals).
  - `party_size` — capacity is the sum of party sizes (used by classes).
- **Payment mode.** Per booking type: `full` (pay full at booking), `deposit` (pay a deposit, settle on arrival), `in_store` (pay everything on arrival), `free`.
- **Reservation hold.** Number of minutes a `pending` booking is held before being released. Default 15.

---

## Resources / lanes

**Where:** `G2A Booking → Resources`.

For each resource you can set:

- Name and slug.
- Category (used to group / filter in the booking flow).
- Capacity per slot (used when `capacity_mode = booking_count`).
- Sort order.
- Active flag (deactivated resources don't appear in the public booking form).
- External reference (used by the migration tool to keep imports idempotent).

Tip: Use the category field to split "lanes" from "class rooms" from "instructors". The frontend can be filtered by category through the shortcode.

---

## Booking types

**Where:** `G2A Booking → Booking Types`.

A booking type is the product you sell. Each carries:

- **Duration** (minutes).
- **Buffer before / after** — instructor prep, lane cleanup, safety briefing. Both buffers are enforced when checking conflicts, in both directions (candidate vs. existing).
- **Capacity mode** — see Core Concepts.
- **Base price** and optional **deposit amount**.
- **Payment modes** — which payment methods are allowed.
- **Members only** flag.
- **Form id** — which form the customer fills out for this service.

When you set `members_only = 1`, non-members see a configurable login / join message instead of the booking flow.

---

## Forms

**Where:** `G2A Booking → Forms`.

Drag-and-drop form builder with field types for text, email, phone, select, radio, checkbox, textarea, date, time, and hidden. Every field can be required or optional.

Any field labeled with the key `name`, `email`, or `phone` is automatically used as the customer-identity field — the email-automation merge tags `{customer_name}`, `{customer_email}`, `{customer_phone}` resolve to whatever the customer typed.

---

## Availability rules and blackout dates

**Where:** `G2A Booking → Availability`.

Per resource you set weekly open / close windows (Monday 09:00–21:00, Tuesday 09:00–21:00, etc.) and blackout dates for holidays.

The availability engine combines:

1. The resource's weekly window for the requested day.
2. The booking type's duration + buffers.
3. Existing bookings for the same resource and time range.
4. The capacity mode for the booking type.

Every booking insert wraps in a database transaction with `SELECT ... FOR UPDATE` locks, so two simultaneous customers cannot book the same slot — exactly one will succeed and the other gets a clear "already booked" message.

---

## Payment gateways

**Where:** `G2A Booking → Settings → Payments`.

Six gateways ship out of the box:

| Gateway | Tier | Best for |
| --- | --- | --- |
| **Pay-In-Store** | Free | Reservation-only flow, walk-in friendly stores |
| **Stripe** | Free | Anyone with a Stripe account — Checkout Sessions |
| **PayPal** | Free | Smart Buttons, Venmo, Pay Later |
| **Fortis Pay** | Pro | Lower card-present rates + native ACH |
| **Authorize.net** | Pro | Established merchant accounts; HMAC-verified silent post |
| **WooCommerce Bridge** | Pro | Sites that already check out through WooCommerce |

For each gateway you provide live + test API keys, choose the default payment mode (full / deposit), and set the webhook URL inside the gateway's own dashboard.

Each gateway fires the same lifecycle hooks (`g2ab_payment_succeeded`, `g2ab_payment_refunded`, etc.), so the email and invoice modules work the same regardless of which gateway processed the payment.

---

## Email automation

**Where:** `G2A Booking → Settings → Email Automation`.

Templated, branded HTML emails for every lifecycle event:

- `booking_created` — reservation received, includes payment link.
- `booking_confirmed` — confirmation issued.
- `booking_paid` — payment received, invoice attached.
- `booking_reminder_24h` — 24 hours before start.
- `booking_reminder_2h` — 2 hours before start (off by default).
- `booking_cancelled` — booking cancelled.
- `booking_no_show` — customer didn't show.
- `booking_completed` — post-visit review request.

Reminders are sent by a 5-minute cron that dedupes against the `g2ab_logs` table — a customer never receives the same reminder twice.

Each template supports merge tags: `{customer_name}`, `{customer_email}`, `{customer_phone}`, `{booking_id}`, `{uuid}`, `{resource_name}`, `{start_at}`, `{end_at}`, `{duration}`, `{party_size}`, `{amount}`, `{currency}`, `{business_name}`, `{business_phone}`, `{business_address}`, `{invoice_url}`, `{pay_url}`, `{cancel_url}`, `{site_url}`, `{date_now}`, `{brand_color}`, `{brand_logo_url}`.

The branded HTML wrapper uses your saved logo and accent color so every email matches your range's look.

---

## PDF invoices

**Where:** `G2A Booking → Settings → Invoices`.

Auto-generated PDF invoices for paid bookings. Generation runs on `g2ab_booking_paid` and `g2ab_payment_succeeded`, so the invoice exists regardless of whether the paid email is enabled. If the paid email is enabled, the PDF is attached automatically.

Customers download via a signed-token URL of the form `https://your-site.com/?g2ab_invoice_pdf=<uuid>&t=<signed_token>`. Staff with `manage_g2ab_payments` can also view any invoice from the Payments admin screen.

---

## Memberships (PMPro / Memberistic)

**Where:** `G2A Booking → Settings → PMPro` (or `Memberistic`).

If Paid Memberships Pro is active, the PMPro module detects the logged-in user's level and:

- Filters `g2ab_user_is_member` so `members_only` booking types open up.
- Applies a configurable percent discount per level via `g2ab_booking_pricing`.

Two enforcement modes:

- `any_active` — any active level qualifies as "member".
- `configured_only` — only levels that have a configured discount rule qualify.

Memberistic works the same way for the Memberistic plugin; both can run side by side.

When a non-member tries to book a `members_only` type they see a configurable "Please log in or upgrade" message.

---

## WooCommerce bridge

**Where:** `G2A Booking → Settings → WooCommerce`.

When the bridge is on, choosing the **WooCommerce** gateway routes the booking through WC checkout. A WC order is created with the booking as a line item, and any active WC payment method (Stripe, PayPal, Square, manual) becomes available.

Status sync is bidirectional:

- `woocommerce_order_status_processing|completed` → booking flips to `paid`, fires `g2ab_booking_paid`.
- `woocommerce_order_status_cancelled|refunded` → mirrored to the booking.

This is the recommended path if your range already runs WooCommerce for retail (ammo, accessories) so bookings and store sales land in the same order list.

---

## AI Auto-Reply

**Where:** `G2A Booking → Settings → AI Auto-Reply`.

An optional addon that drafts reply emails for incoming booking-related questions. It speaks any OpenAI-compatible chat-completions endpoint, so you can pick the provider that suits you:

- **OpenRouter** — best variety of free + paid models.
- **OpenAI** — cheapest production-grade option (e.g. `gpt-4o-mini`).
- **Groq** — fastest free tier.
- **Together AI** — good balance.
- **Custom** — point it at any OpenAI-compatible URL, including self-hosted llama.cpp / vLLM / LM Studio.

A safe-default system prompt refuses anything unsafe — no firearms misuse advice, no legal advice, no targeting minors. The draft is shown to staff for review before sending.

---

## Migration tool

**Where:** `G2A Booking → Migration` (under the G2A Booking menu).

A 5-step wizard that imports bookings, resources, services, and payments from other booking plugins.

Built-in adapters:

- **Amelia** — detects an Amelia install and reads its tables directly.
- **CSV** — generic CSV adapter, paste your column → field mapping in the UI.
- **Bookly** — adapter stub, marked Coming Soon.
- **BookingPress** — adapter stub, marked Coming Soon.

The wizard runs in **dry-run mode first** so you can preview record counts and field mappings before touching real data. Live runs are batched through Action Scheduler (falls back to WP-Cron if Action Scheduler isn't present), so a 50 000-record import doesn't time out.

Every imported row carries an `external_ref` so re-running the same migration is idempotent — and every run has a single-click rollback that deletes only the rows that run created.

---

## Front desk and check-in

**Where:** `G2A Booking → Front Desk`, or place the `[g2ab_frontdesk]` shortcode on a public page.

A purpose-built terminal for the desk laptop. Shows today's roster (with a date picker for any day), instant search by name / email / phone / confirmation, and per-booking actions:

- **Check in** — marks the customer present, time-stamped.
- **Verify waiver** — sets the waiver-verified flag.
- **Collect payment** — records a partial or full payment; the booking flips to `paid` once the balance hits zero.
- **Add note** — staff-only note attached to the booking.
- **Mark no-show** — flips status to `no_show` and fires the no-show email.
- **Print receipt** — clean monospace 380-column receipt, opens in a new tab.

Every front-desk action writes an entry into `g2ab_booking_activity` so you have a complete audit trail.

---

## Calendar and reschedule / cancel

**Where:** `G2A Booking → Calendar`.

A FullCalendar 6 admin view with day / week / month layouts, color-coded by status, filterable by lane, category, or status. Drag a booking to reschedule it. Click for a quick-action panel.

Customer-facing reschedule and cancel pages are exposed via shortcodes (see below). Both require a signed token issued at booking time, so customers can change only their own bookings.

---

## Shortcodes

| Shortcode | What it renders |
| --- | --- |
| `[g2a_lane_booking]` | Main public booking flow |
| `[g2a_booking_form id="N"]` | Same flow, scoped to a specific form/booking type |
| `[g2a_booking_form type="ccw-class"]` | Scoped by booking-type slug |
| `[g2a_event_banner]` | A promotional banner for the next upcoming event |
| `[g2a_upcoming_events]` | A list of upcoming events |
| `[g2ab_reschedule]` | Customer reschedule page (uses `?uuid=` and `?token=`) |
| `[g2ab_cancel_booking]` | Customer cancel page (uses `?uuid=` and `?token=`) |
| `[g2ab_frontdesk]` | Front-desk terminal for staff |

---

## REST API

Base URL: `https://your-site.com/wp-json/g2a-booking/v1/`.

| Endpoint | Method | Auth | Notes |
| --- | --- | --- | --- |
| `/bookings` | POST | Nonce | Create a booking |
| `/bookings/{uuid}` | GET | Public | Booking status |
| `/bookings/{uuid}/confirm-payment` | POST | Token | Confirms payment via the booking's gateway |
| `/bookings/{uuid}/customer-reschedule` | POST | Token | Customer-initiated reschedule |
| `/bookings/{uuid}/customer-cancel` | POST | Token | Customer-initiated cancel |
| `/forms` | GET | Public | Active forms + field schemas |
| `/calendar/events` | GET | Cap | Calendar feed (manage_g2ab_bookings) |
| `/calendar/reschedule\|cancel\|no-show\|complete` | POST | Cap | Staff calendar actions |
| `/frontdesk/today\|search` | GET | Cap | Front-desk list / search |
| `/frontdesk/{checkin\|verify-waiver\|collect-payment\|no-show\|note}` | POST | Cap | Staff actions |
| `/frontdesk/receipt/{id}` | GET | Cap | Printable receipt |
| `/admin/bookings` | POST | Cap | Manual booking |
| `/webhooks/{gateway}` | POST | Signed | Inbound gateway webhooks |

All capability-gated routes require `manage_g2ab_bookings` (or stricter, e.g. `manage_g2ab_payments` for invoices) and a valid `X-WP-Nonce` header.

---

## Hooks for developers

The plugin fires the following action hooks during a booking's lifecycle. Listening to these is the supported way to integrate with external systems (CRM, SMS, accounting, kiosks).

```php
do_action( 'g2ab_booking_created',      $booking_id, $booking_data );
do_action( 'g2ab_booking_reserved',     $booking_id );
do_action( 'g2ab_booking_confirmed',    $booking_id );
do_action( 'g2ab_booking_paid',         $booking_id, $payment_data );
do_action( 'g2ab_booking_rescheduled',  $booking_id, $old_data, $new_data );
do_action( 'g2ab_booking_cancelled',    $booking_id, $reason );
do_action( 'g2ab_booking_completed',    $booking_id );
do_action( 'g2ab_booking_no_show',      $booking_id );
do_action( 'g2ab_booking_expired',      $booking_id );

do_action( 'g2ab_payment_intent_created', $gateway_id, $booking_id, $amount, $payload );
do_action( 'g2ab_payment_succeeded',      $booking_id, $gateway_id, $payload );
do_action( 'g2ab_payment_refunded',       $booking_id, $amount, $gateway_id, $payload );

do_action( 'g2ab_booking_checked_in',     $booking_id, $staff_user_id );
do_action( 'g2ab_waiver_verified',        $booking_id, $staff_user_id );
do_action( 'g2ab_payment_collected',      $booking_id, $amount, $method );
```

Filters worth knowing about:

```php
apply_filters( 'g2ab_user_is_member',        $bool, $user_id, $booking_type, $context );
apply_filters( 'g2ab_booking_pricing',       $pricing, $booking_type, $party_size, $user_id );
apply_filters( 'g2ab_invoice_can_view',      $bool, $booking, $reason );
apply_filters( 'g2ab_email_attachments',     $paths, $event, $booking, $context );
apply_filters( 'g2ab_register_gateways',     $manager ); // action — register custom gateway adapters
```

---

## Troubleshooting

**"I updated but the site still shows the old version."** Most often PHP OPcache holding stale bytecode. Visit `?g2ab_version_check=1` as an administrator to confirm. Restart PHP-FPM or flush OPcache from your host.

**"Customers aren't getting emails."** Check `Settings → Email Automation` — the From address must be a real address on the site domain or your transactional email service (SendGrid, Mailgun, SES) for deliverability. Test with the **Send test email** button.

**"Bookings book outside open hours."** Each resource has its own weekly availability. Confirm the affected resource's hours under `Availability`.

**"WooCommerce gateway doesn't show up."** The WooCommerce Bridge addon must be active and WooCommerce must be installed. Toggle the addon under `Settings → Addons`.

**"Safe mode is on."** The plugin auto-enables safe mode if a fatal happens during init. Check `Settings → General → Last fatal error` for the file and line, fix it, then untick safe mode.

---

## Uninstalling

Deactivating the plugin leaves all data in place — you can reactivate and pick up where you left off.

To fully remove the plugin and its data:

1. `G2A Booking → Settings → General → Remove all data on uninstall` — set to **Yes**.
2. **Plugins → G2A Booking Engine → Delete**.

WordPress will run `uninstall.php`, which drops all custom tables, removes all `g2ab_*` options, unschedules cron events, and removes the **Range Member** role.
