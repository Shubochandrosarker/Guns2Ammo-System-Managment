# ROOT-CAUSE-SUMMARY — Stripe not charging online bookings

**Incident:** Online lane, class and event bookings are created, emailed,
displayed to the customer as confirmed, and counted as revenue on the staff
dashboard — while Stripe never creates a Checkout Session, never creates a
PaymentIntent, and never captures a charge.

**Start:** On or about **2026-07-20**, coinciding with the deployment of the
July-19 Stripe-webhook hotfix release (booking engine `1.9.9.15` → `1.9.9.16`,
Memberistic `1.18.3`, Verifyistic `1.4.6`). The bypass was made
**unconditional** on **2026-07-31** by booking engine `1.9.9.20`
(commit `4e52d76`), which force-enables pay-at-store for every public booking.

**Detected:** 2026-08-19, by the owner, from the Stripe dashboard — not by any
alert. There is no payment-integrity or Stripe-inactivity monitor in the system.

**Affected bookings:** REQUIRES PRODUCTION VERIFICATION — the exact count is one
SQL query (`sql/01-affected-bookings.sql`). Expected population: every row in
`wp_g2ab_bookings` with `source='web'`, `created_at >= 2026-07-20`, and
(`payment_mode='in_store'` AND `status='reserved'`) or (`total_amount=0` AND
`status='confirmed'` with no Memberistic entitlement).

**Affected CCW registrations:** the 3 the client identified are expected to be
`payment_mode='in_store'`, `status='reserved'`, `total_amount` = the course
price, `paid_amount=0`, `metadata.gateway='pay_in_store'`, with **no** row in
`wp_g2ab_payments` and **no** Stripe object of any kind. Query
`sql/05-ccw-forensics.sql`.

**Confirmed revenue loss:** $0 confirmed lost *to Stripe fees or failed
captures* — no money was taken and lost. The exposure is **uncollected**
revenue: `SUM(total_amount)` over the affected set. REQUIRES PRODUCTION
VERIFICATION (`sql/06-financial-impact.sql`).

**Potential revenue exposure:** uncollected `total_amount` for all web bookings
since 2026-07-21 that were payment-required and never charged, minus legitimate
$0 member-included and free-type bookings. Every affected customer still holds
a valid promise of service, so this is *collectible at the desk*, not written
off.

---

## Primary root cause

**Fail-open gateway selection in the public booking path.** When no online
gateway is usable — or, from `1.9.9.20`, unconditionally — the booking engine
silently reclassifies a payable public booking as `payment_mode='in_store'`,
`due_now = 0`, `status='reserved'`, `gateway='pay_in_store'` and **returns
success without ever calling Stripe**.

- Lane path, July-20 build: `g2a-booking-engine 1.9.9.16`,
  `includes/rest/class-bookings-controller.php:768` (gateway falls back to
  `pay_in_store`) and `:775-786` (`in_store` branch → `due_now = 0`,
  `status='reserved'`). `:470` makes `in_store` a **default** payment mode
  whenever a booking type's `payment_modes` column is empty.
- Lane path, July-31 build: `1.9.9.20`, same file `:838-852` —
  `$allow_desk_settlement` **force-adds** `in_store` to the booking type's modes
  and sets `pay_in_store`, gated only on `g2ab_require_public_prepay`, whose
  default is **0** (`:571-577`). Card-only lane types are overridden.
- Event/CCW path, July-20 build: `1.9.9.16` `:1339-1356` — if
  `pick_online_gateway_for_event()` returns null, the seat is reserved at
  `due_now = 0` with no error and no Stripe session.

## Secondary root cause

**The local system reports these unpaid holds as confirmed revenue.**
`G2AB_Booking_Visibility::operational_sql()` in `1.9.9.20`
(`includes/class-booking-visibility.php:43`) treats **any** `reserved` +
`in_store` row as operational regardless of source, so public web rows land on
rosters, the calendar, KPIs and the revenue report
(`includes/admin/class-reports.php:56-63` sums `total_amount` as
`gross_revenue`). The expiry cron explicitly skips `in_store`
(`includes/cron/class-booking-expiry-cron.php:52-55`), so these rows never
expire and hold inventory forever.

## Why Stripe did not charge

Because **no Stripe API call was ever made**. `G2AB_Gateway_Stripe::create_intent()`
is only reached when `payment_mode` is `full` or `deposit`. Once the request is
classified `in_store` with `due_now = 0`, the code path that builds the Checkout
Session is skipped entirely. Stripe therefore has no Checkout Session, no
PaymentIntent, no charge and no failed charge for these bookings — which is
exactly what the owner observed. This is **failure CASE C (Checkout Session was
never created)** combined with **CASE D (application selected pay-at-store /
$0)**. It is *not* CASE A (charged-but-unrecorded) and *not* CASE B (abandoned
checkout).

## Why the system appeared successful

Four independent surfaces asserted success without payment evidence:

1. The booking form's completion panel is hard-coded **“Booking confirmed”**
   (`1.9.9.16 includes/class-frontend.php:539`) and renders on any successful
   create response, including a `reserved`/`in_store` one.
2. `assets/js/frontend.js:66-68` (1.9.9.16) shows a green **“Payment received.
   Your booking is finalising.”** notice when the server status is *not*
   paid/confirmed — i.e. precisely when payment did not happen.
3. `1.9.9.16` sends the generic **“Reservation received”** confirmation email
   with a confirmation number for every created booking, with no payment status
   and no pay link (`includes/modules/email-automation/class-email-automation.php:42-47`;
   template at `class-email-engine.php:261-273`). The dedicated
   `pay_in_store_reservation` template that says *“pay at the front desk”* does
   not exist until `1.9.9.20`.
4. Staff screens and the revenue report counted the rows as confirmed bookings
   with full `total_amount` revenue (see secondary root cause).

## Immediate fix

**Deploy the already-written fix that is sitting in this repository and has
never reached production: `g2a-booking-engine 1.12.1` + `memberistic 1.21.0`
(`dist/`).** `G2AB_Checkout_Policy` (1.12.0) removes the public pay-at-store
path entirely, fails closed with HTTP 503 when no online gateway is available,
and `G2AB_Booking_Transitions::check_invariants()` refuses `paid`/`confirmed`
without a matching successful ledger row. Before that deploy, confirm in
WP-admin that **Stripe is enabled, its live secret key is present, and the
`stripe` add-on is active** — one toggle in *Settings → Add-ons* silently
converts every payable booking to a free reservation on any build before 1.10.0.

## Permanent fix

1. Ship 1.12.1/1.21.0 through the staging → Stripe-test → live-low-value
   sequence in `FIX-PLAN.md`.
2. Add the payment-integrity monitor, the Stripe-inactivity monitor and the
   daily WordPress↔Stripe reconciliation described in `FIX-PLAN.md` §P5 — this
   outage ran for **30 days** with no alert.
3. Backfill the four DB-level guards (CHECK/trigger equivalents) so a `paid`
   row without a successful ledger row is impossible at the storage layer, not
   only in PHP.
