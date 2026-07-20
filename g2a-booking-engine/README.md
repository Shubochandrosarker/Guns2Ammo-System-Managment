# G2A Booking Engine

A production-grade WordPress booking, reservation, payment, and front-desk operations plugin. Built for shooting ranges and firearms training centers, the same engine cleanly handles any time-slot business: bays, courts, rooms, studios, simulators, instructors, and member clubs.

[![Plugin Version](https://img.shields.io/badge/version-1.9.9.16-1f3864.svg)](#)
[![Requires PHP](https://img.shields.io/badge/PHP-%E2%89%A5%208.0-777BB4.svg)](#)
[![Requires WordPress](https://img.shields.io/badge/WordPress-%E2%89%A5%206.2-21759b.svg)](#)
[![License](https://img.shields.io/badge/license-GPLv2%2B-green.svg)](LICENSE)

---

## What this is

Most booking plugins rent a time slot and stop. G2A Booking Engine ships the full operations spine around a booking — reservations, payments, deposits, member rules, walk-ins, check-ins, waivers, reminders, invoices, retail integration, customer-facing reschedule and cancel, plus a complete REST and hook system.

It's the plugin you install when you want to run a real business on WordPress, not just collect form submissions.

## Highlights

- **Race-safe booking engine.** Every insert wraps in a database transaction with `SELECT ... FOR UPDATE` row locks. Two simultaneous customers cannot book the same slot.
- **Buffer + capacity logic.** Buffer-before / buffer-after enforced in both directions. Two capacity modes: one booking per slot (lanes) or sum of party size (classes).
- **6 built-in payment gateways.** Pay-In-Store, Stripe, PayPal, Fortis Pay, Authorize.net, plus a WooCommerce Bridge that exposes every active WC gateway.
- **Lifecycle email automation.** Branded HTML templates for every event, 20+ merge tags, 5-minute reminder cron with dedupe.
- **PDF invoices.** Signed-token download URLs, auto-generated on payment success, auto-attached to the paid email.
- **Membership rules.** Paid Memberships Pro and Memberistic modules — detect the logged-in level, apply per-level percent discounts, gate members-only booking types.
- **Front-desk terminal.** Today's roster, instant search, check-in, waiver verification, on-the-spot payment collection, printable receipts.
- **FullCalendar admin view.** Drag to reschedule, color-coded by status, filter by lane / category / status.
- **Customer reschedule + cancel.** Signed-token protected, configurable min-lead window.
- **Migration wizard.** Amelia and CSV adapters built in (Bookly and BookingPress stubs in the registry). Dry-run preview, batched processing via Action Scheduler with WP-Cron fallback, full rollback by run id.
- **REST API.** Every operation exposed at `/wp-json/g2a-booking/v1/`.
- **Action / filter hooks.** External CRMs, SMS providers, and accounting systems plug in cleanly.

## Installation

1. Download the latest zip from the [Releases](../../releases) page.
2. WordPress admin → **Plugins → Add New → Upload Plugin** → upload the zip.
3. **Activate**.
4. Visit **G2A Booking → Settings** and follow the first-run setup.
5. Drop `[g2a_lane_booking]` on a public page and run an end-to-end test as a logged-out visitor.

A full operator's manual ships with the plugin at `docs/USER-GUIDE.md` (Markdown) and `docs/G2A-Booking-Engine-User-Documentation.pdf` (PDF).

## Requirements

| Component | Minimum |
| --- | --- |
| WordPress | 6.2 |
| PHP | 8.0 |
| MySQL / MariaDB | 5.7 / 10.3 |

Optional integrations: WooCommerce 7.0+, Paid Memberships Pro 2.9+, Memberistic 1.0+.

## Repository layout

```
g2a-booking-engine/
├── g2a-booking-engine.php   Plugin bootstrap + autoloader
├── uninstall.php            Data cleanup on plugin delete
├── readme.txt               Plugin description (WordPress format)
├── index.php                Directory silence
├── LICENSE                  GPL-2.0+
├── assets/                  Frontend CSS / JS
├── docs/                    User guide (Markdown + PDF)
└── includes/                All PHP runtime code
    ├── admin/               WP-admin screens
    ├── cron/                Scheduled job handlers
    ├── frontend/            Public shortcodes + render code
    ├── helpers/             Procedural helpers
    ├── modules/             Auto-discovered feature modules
    ├── payments/            Gateway adapters + manager
    ├── rest/                REST API controllers
    └── services/            Domain services
```

## Modules

Each module under `includes/modules/<slug>/` is self-contained — it ships a `module.php` manifest that the core auto-discovers, and an Addons-tab toggle that activates it.

| Module | Default | Tier | Purpose |
| --- | --- | --- | --- |
| `email-automation` | On | Free | Lifecycle-event emails, reminders, branded templates |
| `pdf-invoices` | On | Pro | Signed-token PDF invoices, auto-generated on payment |
| `migration` | On | Free | Amelia / Bookly / BookingPress / CSV importer with rollback |
| `pmpro-memberships` | On | Free | PMPro level detection, member-only, discount rules |
| `memberistic` | Off | Free | Memberistic plan integration |
| `verifyistic` | Off | Free | Waiver / e-signature integration hooks |
| `woocommerce-bridge` | Off | Pro | Route bookings through WooCommerce checkout, bidirectional sync |
| `ai-autoreply` | Off | Pro | OpenAI-compatible reply drafts for incoming emails |
| `ffl-checkout` | On | Free | Advanced FFL Checkout integration — dealer→resource sync, shared "FFL Firearm Pickup" booking type, status push-back onto transfer records |

## REST API

Base URL: `https://your-site.com/wp-json/g2a-booking/v1/`

Categories: `bookings`, `forms`, `calendar`, `frontdesk`, `admin/bookings`, `webhooks/{gateway}`.

All capability-gated routes require `manage_g2ab_bookings` (or stricter) and a valid `X-WP-Nonce` header. Customer-facing reschedule / cancel routes are gated by the signed `confirm_token` issued at booking creation.

Full endpoint reference is in `docs/USER-GUIDE.md`.

## Lifecycle hooks

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

Filters worth knowing:

```php
apply_filters( 'g2ab_user_is_member',     $bool, $user_id, $booking_type, $context );
apply_filters( 'g2ab_booking_pricing',    $pricing, $booking_type, $party_size, $user_id );
apply_filters( 'g2ab_invoice_can_view',   $bool, $booking, $reason );
apply_filters( 'g2ab_email_attachments',  $paths, $event, $booking, $context );
apply_filters( 'g2ab_register_gateways',  $manager ); // action — register custom gateway adapters
```

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

## Author

[Wordpressistic](https://wordpressistic.com)

---

> Originally commissioned for Guns 2 Ammo, generalised for the WordPress booking ecosystem.
