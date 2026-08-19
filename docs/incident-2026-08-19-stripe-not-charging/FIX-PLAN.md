# FIX-PLAN — 2026-08-19 Stripe non-charging incident

Ordered P0 → P5. **No plugin code was modified by this audit** — the audit was
read-only, and the code fix for the primary root cause already exists in this
repository and has simply never been deployed. The one change this branch does
make is additive and non-runtime: a regression test suite
(`plugins/g2a-booking-engine/tests/unit/PaymentIntegrityRegressionTest.php`).

---

## The single most important line in this document

> **The fix for the primary root cause is already written, tested and built.
> It is `dist/g2a-booking-engine-1.12.0.zip` + `dist/memberistic-membership-solutions-1.21.0.zip`.
> Production is expected to be running `1.9.9.20` + `1.18.6`. Shipping those two
> ZIPs, together, in one window, is P0.**

Writing more code before that deployment happens would repeat the exact mistake
that produced this incident: fixes accumulating in a repository that production
never receives.

---

## P0 — Stop financial loss (today)

### P0-1 — Restore Stripe availability, before anything else

| | |
| --- | --- |
| **Priority** | P0 |
| **Component** | WordPress configuration (no code) |
| **File** | n/a — `wp_options` |
| **Function** | `G2AB_Gateway_Manager::register()` (`includes/class-manager.php:36-41`), `G2AB_Gateway_Stripe::is_available()` (`includes/payments/class-stripe.php:21-23`) |
| **Current behaviour** | If `g2ab_addons_active` omits `stripe`, or `g2ab_stripe_enabled ≠ 1`, or the secret key is empty, every payable public booking silently becomes a free pay-at-store reservation. No error, no notice, no distinguishable log entry. |
| **Correct behaviour** | Stripe registered, enabled, live secret key present, `g2ab_stripe_test_mode = 0`. |
| **Proposed change** | Run §15 checks C1, C2, C3, C6 from the audit. Repair whatever is wrong. This is a settings change, not a deploy. |
| **Risk** | Low. On 1.9.9.20 it is **not sufficient** on its own — `$allow_desk_settlement` bypasses Stripe even when Stripe is healthy. Do P0-2 as well. |
| **Tests required** | One live low-value non-member lane booking; confirm a `cs_live_…` session appears in Stripe. |

### P0-2 — Interim mitigation while still on 1.9.9.20

| | |
| --- | --- |
| **Priority** | P0 |
| **Component** | WordPress configuration |
| **Function** | `public_prepay_required()` (`class-bookings-controller.php:571-577`, 1.9.9.20) |
| **Current behaviour** | `get_option('g2ab_require_public_prepay', 0)` — **default 0** ⇒ `$allow_desk_settlement` true ⇒ every public lane booking bypasses Stripe. |
| **Correct behaviour** | Prepay required for every public lane booking. |
| **Proposed change** | `wp option update g2ab_require_public_prepay 1` and `wp option update g2ab_allow_event_pay_in_store_fallback 0`. Then verify per booking type that `payment_modes` does **not** contain `in_store` (`wp db query "SELECT id,name,payment_modes FROM wp_g2ab_booking_types"`) — an empty column defaults to `full,in_store` and bypasses Stripe independently (RC-1 trigger 2). |
| **Risk** | Medium. Genuine front-desk walk-ins must now be recorded through *Front Desk → New booking* rather than the public form. Brief the counter staff before flipping it. |
| **Tests required** | Non-member lane booking must redirect to Stripe. Member lane booking must still be $0. CCW booking must redirect to Stripe. |
| **Note** | **This is a tourniquet, not the fix.** It does not close RC-2 on 1.9.9.16, RC-4, or RC-5. Both options are *retired and ignored* in 1.10.0+, so this step is discarded by P1-1. |

### P0-3 — Freeze the evidence

Before changing any Stripe or Cloudflare setting: capture §15 checks S3, S4 and
I1 (webhook endpoint state, last delivery responses, WAF events). Rotating the
signing secret or re-enabling the endpoint first destroys the record that
settles H1 and H17.

---

## P1 — Payment-state integrity (this week)

### P1-1 — Deploy booking engine 1.12.0 + Memberistic 1.21.0

| | |
| --- | --- |
| **Priority** | P1 (the real fix) |
| **Component** | `g2a-booking-engine`, `memberistic-membership-solutions` |
| **Files** | `dist/g2a-booking-engine-1.12.0.zip`, `dist/memberistic-membership-solutions-1.21.0.zip` |
| **Functions closed** | `G2AB_Checkout_Policy::resolve_lane()`, `::pick_online_gateway()`, `::require_payment_for_non_members()`; `create_event_booking()` fail-closed guard; `G2AB_Booking_Transitions::check_invariants()`; `G2AB_Booking_Visibility::staff_sources()`; `G2AB_Payment_Validator` |
| **Current behaviour (1.9.9.20)** | Public payable booking → `in_store`, `due_now = 0`, `reserved`, no Stripe. Public `reserved`+`in_store` counted as operational revenue. Unpaid holds never expire. |
| **Correct behaviour (1.12.0)** | A public payable booking may only use an online gateway (`class-checkout-policy.php:154-162` rejects every offline gateway id); with no usable online gateway the request **fails closed** with HTTP 503 and no booking row (`::pick_online_gateway()` → `fail_closed_checkout()` at `class-bookings-controller.php:214-250` expires the row and releases inventory); a $0 total on a paid type is rejected `409 g2ab_pricing_invalid` unless attributable to a Memberistic entitlement for the **same authenticated user**; `paid`/`confirmed` require a matching successful ledger row; public `reserved`+`in_store` rows are no longer operational. |
| **Proposed change** | Deploy. Both plugins, one window, Memberistic first (install order — booking engine consults its `Entitlement_Service`). |
| **Risk** | **Medium-high, and it must be planned.** (a) Legacy public pay-at-store rows disappear from rosters — export and triage them **before** deploying, per `DEPLOYMENT-1.10.0.md` §"Legacy pay-at-store rows". (b) If Stripe is misconfigured, bookings now **fail** instead of silently going free — that is correct, and it is also a visible outage, so P0-1 must be done first. (c) Deploying the booking engine without Memberistic ≥1.19.0 breaks member $0 lanes (H18). |
| **Tests required** | Full 20-row matrix below, on staging in Stripe test mode, then the live low-value smoke test. Plus both post-deploy reconciliation queries from `DEPLOYMENT-1.10.0.md`. |

### P1-2 — Remove the two invariant-bypassing fallbacks

| | |
| --- | --- |
| **Priority** | P1 |
| **Component** | `g2a-booking-engine` |
| **Files** | `includes/modules/woocommerce-bridge/class-woo-sync.php:116-124`; `includes/services/class-checkin-service.php:202-211` |
| **Function** | `G2AB_Woo_Sync::on_paid()`, `G2AB_Checkin_Service::collect_payment()` |
| **Current behaviour** | Both fall back to a direct `$wpdb->update(... 'status' => 'paid' ...)` when `class_exists('G2AB_Booking_Transitions')` is false — bypassing `check_invariants()`. |
| **Correct behaviour** | If the transition service is missing, the plugin is broken; refuse the operation and log at `error`, never write `paid`. |
| **Proposed change** | Delete both fallback branches; replace with a logged `WP_Error`. |
| **Risk** | Very low — the class is always loaded in 1.12.0; the branches are dead code that only exists to defeat the guard. |
| **Tests required** | A WordPress integration test (the guard is `G2AB_Booking_Transitions::check_invariants()`, which queries `wp_g2ab_payments` and so is out of scope for the unit bootstrap): assert that `transition($id,'paid')` with no successful ledger row returns `g2ab_paid_requires_ledger`, and that a front-desk collection succeeds because it inserts the `captured` row first. The unit suite covers the reachable half — `testPaidIsReachableOnlyFromPrePaymentStates`, `testUnpaidStatesCannotJumpStraightToCompleted`. |

### P1-3 — Enforce the invariant at the storage layer

| | |
| --- | --- |
| **Priority** | P1 |
| **Component** | `g2a-booking-engine` — `includes/class-installer.php` |
| **Current behaviour** | The "a paid booking has a successful ledger row" invariant lives only in PHP. Any direct SQL, migration or third-party plugin can violate it. |
| **Correct behaviour** | Violation detected within one cron cycle even when PHP is bypassed. |
| **Proposed change** | MySQL cannot express this as a CHECK constraint across tables. Instead add a 15-minute scheduled integrity job that runs `sql/02-paid-without-evidence.sql` and raises a critical alert on any row (see P5-1). Also add a non-null `payment_id` foreign-key column on `wp_g2ab_bookings` populated at transition time, so the link is structural. |
| **Risk** | Low — additive schema. |
| **Tests required** | Insert a violating row in a staging DB and confirm the monitor fires. |

### P1-4 — `g2ab_stripe_test_mode` must not default to TEST

| | |
| --- | --- |
| **Priority** | P1 |
| **Component** | `g2a-booking-engine` — `includes/class-payment-validator.php:126-129`, `includes/admin/class-settings-pro.php:322` |
| **Current behaviour** | `get_option('g2ab_stripe_test_mode', 1)` — an unset option means TEST. `validate_stripe_checkout_session()` then rejects genuine **live** sessions as `g2ab_payment_mode_mismatch`, turning real charges into unrecorded payments (CASE A). |
| **Correct behaviour** | Derive the mode from the configured key prefix (`sk_live_` vs `sk_test_`) and treat a mismatch between the stored flag and the key prefix as a hard configuration error surfaced as an admin notice. |
| **Proposed change** | Add `G2AB_Gateway_Stripe::mode()` returning `live`/`test` from the key prefix; make the option advisory; raise an admin notice on disagreement. |
| **Risk** | Low. |
| **Tests required** | Unit test over the four (flag, prefix) combinations. |

### P1-5 — Add `g2ab_stripe_publishable_key` to `SECRET_OPTIONS`

`includes/admin/class-settings-pro.php:51-60`. It matches the generic `_key`
branch at `:656-660`, so submitting the field empty wipes it. Two-line fix.
(SEC-3.)

### P1-7 — Release packaging can ship dev tooling into production

| | |
| --- | --- |
| **Priority** | P1 (low effort, real blast radius) |
| **Component** | `scripts/build-release-zips.sh` |
| **Current behaviour** | The exclude list is deliberately narrow and keeps `vendor/` (POS needs it for FPDI/FPDF). The booking engine has **no** Composer runtime dependency, but a developer who runs `composer install` to execute the unit tests leaves `plugins/g2a-booking-engine/vendor/` on disk — and it would be packaged into the release ZIP. `includes/modules/pdf-invoices/class-invoice-engine.php:56` then `require_once`s `G2AB_PATH . 'vendor/autoload.php'` in production, loading PHPUnit into a live site. |
| **Correct behaviour** | The booking engine's root `vendor/` is never packaged. Its legitimate runtime assets live in `assets/vendor/` and must keep shipping. |
| **Proposed change** | Add `'*/g2a-booking-engine/vendor/*'` to `EXCLUDES`. This branch also adds the two paths to `.gitignore` so they cannot be committed. |
| **Risk** | Very low, and narrowly targeted — POS and the theme are unaffected. |
| **Tests required** | Build the ZIP after `composer install` and assert `unzip -l dist/g2a-booking-engine-*.zip | grep -c '/vendor/phpunit/'` is `0`, and that `assets/vendor/` is still present. |

### P1-6 — Audit-log every payment-capability change

| | |
| --- | --- |
| **Priority** | P1 |
| **Component** | `g2a-booking-engine` — `includes/class-addon-manager.php::handle_toggle()`, `includes/admin/class-settings-pro.php::handle_save()` |
| **Current behaviour** | Toggling the `stripe` add-on, or clearing `g2ab_stripe_enabled`, leaves **no trace anywhere**. The switch that caused a 30-day revenue outage is invisible in every log. |
| **Correct behaviour** | Every change to `g2ab_addons_active`, `g2ab_stripe_enabled`, `g2ab_stripe_test_mode`, `g2ab_stripe_secret_key` (set/unset only, never the value), `g2ab_stripe_webhook_secret` and `g2ab_payment_gateway_default` writes a `wp_g2ab_logs` row at severity `warning` with the acting user, and disabling an online gateway shows a persistent dismissible admin notice naming the revenue consequence. |
| **Risk** | Very low, additive. |
| **Tests required** | Unit test asserting a log row per toggle. |

---

## P2 — Stripe / webhook reliability

### P2-1 — Re-establish both webhook endpoints

After P0-3 has captured the evidence:

1. Booking endpoint `https://guns2ammo.com/wp-json/g2a-booking/v1/webhooks/stripe`
   — enable it, **live** mode, subscribe to exactly `checkout.session.completed`
   and `charge.refunded` (subscribing to "all events" is what produced the
   original failure stream).
2. Memberistic endpoint `https://guns2ammo.com/wp-json/memberistic/v1/webhooks/stripe`
   — **the prior audit found this was never added to Stripe at all.** Create it,
   subscribe to `checkout.session.completed`, `invoice.payment_succeeded`,
   `invoice.payment_failed`, `customer.subscription.deleted`, and store its own
   signing secret in `memberistic_settings[stripe_webhook_secret]` (it is **not**
   the same secret as the booking endpoint).
3. Verify signing secrets by sending one signed test event to each and
   confirming a `200` plus fresh `*_webhook_last_processed_at` options.

### P2-2 — Exempt webhook paths from Cloudflare browser challenges

Create a Cloudflare rule that skips Bot Fight Mode, Managed Challenge and
Turnstile for `POST /wp-json/g2a-booking/v1/webhooks/*` and
`POST /wp-json/memberistic/v1/webhooks/*`. Security is preserved by Stripe
signature verification, which both endpoints enforce before any processing.
Confirm `POST /wp-json/*` is never cached.

### P2-3 — Restore trusted-proxy IP resolution site-wide

Verifyistic ≥1.4.6 fixes its own; the same shared-edge-IP bucket collapse still
affects the Memberistic guest-waiver limiter, the advanced-ffl-checkout limiters
and the POS staff-login lockout (prior audit §3.3). Seed Cloudflare CIDRs the way
`class-formistic-g2a-defaults.php` already does.

---

## P3 — Reconcile historical bookings

### P3-1 — Produce the affected-customer list

Run `sql/01`, `sql/05`, `sql/06`, `sql/08`; export Stripe payments for
2026-07-15 → today; join on `booking_uuid == client_reference_id`; fill
`payment-reconciliation-2026-08-19.csv`; classify per the audit §17 vocabulary.

### P3-2 — Handle each classification

| Classification | Action |
| --- | --- |
| `MISSING_STRIPE_SESSION`, service already delivered | Write it off or invoice with a personal call. **Never auto-charge.** |
| `MISSING_STRIPE_SESSION`, service still upcoming | Email the customer honestly: an error meant they were not charged; offer a payment link (`?g2ab_pay={uuid}` / front-desk collection) and confirm the booking stands either way. |
| `MISSING_STRIPE_SESSION`, CCW seats | Same, individually and by phone — a course seat is high value and the customer must not arrive expecting a paid seat that was cancelled. |
| `STRIPE_PAID_LOCAL_NOT_UPDATED` | `wp g2ab stripe-reconcile` (the WP-CLI command added 2026-07-20), or replay the event from the Stripe dashboard. |
| `LOCAL_PAID_NO_STRIPE` | **Escalate as a new P0.** |
| `ABANDONED_CHECKOUT` | Expire the hold. Not loss. |
| Duplicate subscriptions (prior incident) | Cancel/refund the orphan **manually, per customer**. |

### P3-3 — Non-negotiable constraints

- **No stored card is charged. No off-session PaymentIntent is created. No past
  Checkout Session is resent in bulk.** Every affected customer was told at
  booking time that nothing was due.
- No booking status is edited to make a report balance.
- No affected row is deleted — it is the customer list.

---

## P4 — Tests

Implemented and runnable:
`plugins/g2a-booking-engine/tests/unit/PaymentIntegrityRegressionTest.php`
(`cd plugins/g2a-booking-engine && composer install && vendor/bin/phpunit`).

| # | Scenario | Expected | Coverage |
| --- | --- | --- | --- |
| 1 | Non-member lane | Stripe payment mandatory | automated |
| 2 | Defender lane, entitled | $0 / included | automated |
| 3 | Patriot lane, entitled | $0 / included | automated |
| 4 | Guardian lane, entitled | $0 / included | automated |
| 5 | Expired member | Stripe payment required | automated |
| 6 | Logged-out guest | Stripe payment required | automated |
| 7 | CCW paid class | Stripe payment mandatory | automated (policy) + staging |
| 8 | Other paid event | Stripe payment mandatory | automated (policy) + staging |
| 9 | Failed Stripe card | Booking NOT paid | staging (Stripe test card `4000000000000002`) |
| 10 | Customer closes Checkout | Booking NOT paid | staging |
| 11 | Expired Checkout Session | Booking NOT paid | staging |
| 12 | Successful Stripe payment | Booking paid | staging + live low-value |
| 13 | Duplicate webhook | Idempotent | staging (`stripe trigger` twice) |
| 14 | Webhook delayed | Safe reconciliation | staging (hold the forward, then release) |
| 15 | Crafted success URL | Cannot mark paid | automated + staging |
| 16 | Wrong amount | Manual review / reject | automated |
| 17 | Wrong currency | Manual review / reject | automated |
| 18 | Wrong booking metadata | Reject | automated |
| 19 | Stripe unavailable | **Fail closed** for payment-required bookings | automated |
| 20 | Offline gateway requested on a public endpoint | Reject | automated |

Business invariants asserted:

| Invariant | Assertion |
| --- | --- |
| 1 | `payment_required && !verified_payment` ⇒ status ≠ `paid` |
| 2 | `status = paid` ⇒ successful ledger evidence, unless explicitly `member_included` / `comped` / `admin_manual_payment` / `front_desk_payment` |
| 3 | Stripe unavailable ⇒ no silent conversion to free/pay-at-store |
| 4 | Event seat confirmation ⇒ payment evidence unless explicitly authorised |
| 5 | UI/email/admin/REST never say PAID unless the authoritative state is `paid` |

---

## P5 — Monitoring (the reason this ran for 30 days)

### P5-1 — Payment-integrity monitor — every 15 minutes

```sql
SELECT COUNT(*) FROM wp_g2ab_bookings b
 LEFT JOIN wp_g2ab_payments p
   ON p.booking_id = b.id AND p.status IN ('succeeded','captured','paid','completed')
 WHERE b.status IN ('paid','confirmed')
   AND b.total_amount > 0
   AND p.id IS NULL
   AND NOT (b.status = 'confirmed'
            AND JSON_UNQUOTE(JSON_EXTRACT(b.metadata,'$.membership_source')) = 'memberistic');
```

`> 0` ⇒ **CRITICAL**, page the owner. Implement as an Action Scheduler job in
`g2a-business-api` so it survives WP-Cron being disabled.

### P5-2 — Stripe-inactivity monitor — hourly

> If, over the last **6 hours**, `web` bookings created `> 0`
> **and** payable web bookings created `> 0`
> **and** successful Stripe payment rows created `= 0`
> ⇒ **CRITICAL: "Bookings are being taken and Stripe is collecting nothing."**

Add a second, slower alarm: no successful Stripe charge in **24 hours** during
opening hours, regardless of booking volume. Either alarm would have fired on
2026-07-21.

### P5-3 — Gateway-configuration watchdog — every 15 minutes

Alert whenever any of the following becomes true, because each one silently
converts revenue to zero: `stripe` absent from `g2ab_addons_active`;
`g2ab_stripe_enabled ≠ 1`; empty secret key; `g2ab_stripe_test_mode = 1` while
the key is `sk_live_`; `g2ab_require_public_prepay ≠ 1` on a pre-1.10.0 build;
any lane booking type with `in_store` in — or an empty — `payment_modes`.

### P5-4 — Webhook health

Alert on: `g2ab_stripe_webhook_last_processed_at` older than 24h while payable
bookings exist; any `wp_g2ab_webhook_events` row in `failed`; ≥3 consecutive
signature failures; the endpoint reporting `disabled` in the Stripe API. Cover
the **Memberistic** endpoint with the same rules — it has its own
`memberistic_stripe_webhook_last_*` options.

### P5-5 — Daily reconciliation

06:00 daily: pull Stripe Checkout Sessions, PaymentIntents and Charges for the
previous 48h; join to `wp_g2ab_bookings` on `client_reference_id`; alert on any
`STRIPE_PAID_LOCAL_NOT_UPDATED`, `LOCAL_PAID_NO_STRIPE`, `WRONG_AMOUNT` or
`MISSING_STRIPE_SESSION`. Email the owner a one-line daily digest —
*"N bookings, $X expected, $Y collected, Z discrepancies"* — so silence is
never the same as health.

### P5-6 — Record what is deployed

Have the booking engine write its own version and file hash to an option on
every load, expose it at `GET /wp-json/g2a-booking/v1/health` (public, no
secrets), and alert when it diverges from the intended release. This incident's
central difficulty — *nobody knows what production is running* — is a solvable
engineering problem.

---

## Deployment plan (mandatory sequence)

1. **Backup** the full database, with `wp_g2ab_*` and `wp_memberistic_*` verified
   restorable. Snapshot `wp-content/plugins/`.
2. Record current state: `wp plugin list`, all options from
   `sql/00-schema-and-config-probe.sql`, and §15 checks C1-C8, V1-V3, D1-D3.
3. Export the legacy pay-at-store rows (`DEPLOYMENT-1.10.0.md` §"Legacy
   pay-at-store rows") and **triage them before deploying** — they vanish from
   rosters afterwards.
4. **Staging**, restored from the production backup. Memberistic 1.21.0 first,
   then booking engine 1.12.0.
5. Confirm migrations ran: `g2ab_db_version`, `wp_g2ab_webhook_events`,
   `wp_g2ab_email_log`, the `wp_g2ab_payments` column set.
6. **Stripe TEST mode** on staging: non-member lane booking; entitled member
   lane booking ($0); paid CCW booking; another paid event booking; declined
   card; abandoned checkout; expired session.
7. `stripe listen --forward-to <staging>/wp-json/g2a-booking/v1/webhooks/stripe`
   — including a **duplicate** `checkout.session.completed` (must be idempotent)
   and a delayed delivery.
8. Failure test: disable Stripe on staging and confirm a payable booking now
   returns **503 and creates no booking**, rather than a free reservation.
9. Crafted-success-URL test: `?g2ab_paid=<uuid>` with no token, and
   `POST /confirm-payment` with a forged token — neither may change state.
10. Run both post-deploy reconciliation queries on staging; `sql/02` must be
    empty.
11. Mobile 320px + desktop booking flows; two concurrent checkouts on one slot.
12. **Review and sign off.** Attach the staging evidence to the release.
13. **Production deploy**, one window, Memberistic first, out of business hours.
14. Re-run step 5 on production.
15. **LIVE low-value controlled test**: one real non-member lane booking on the
    cheapest slot, paid with a real card. Confirm in Stripe: Checkout Session →
    PaymentIntent → Charge succeeded. Confirm in WordPress: `wp_g2ab_payments`
    row `succeeded`, booking `paid`, confirmation email says paid. Then refund
    that one charge from the Stripe dashboard and confirm the booking moves to
    `refunded`.
16. Repeat with one paid **event/CCW** booking — the event path is separate code
    and must be proven separately.
17. Run the full reconciliation (P3-1) against the new state.
18. Enable the P5 monitors and confirm each fires on a deliberately induced
    condition on staging.

---

## Rollback plan

Prepare **before** step 13, not after.

**Trigger:** any of — payable bookings failing at a rate above baseline; member
$0 lanes charging; `sql/02` returning rows; rosters missing legitimate bookings;
PHP fatals in the error log.

**Procedure:**

1. Stop new bookings: put the booking page behind a maintenance notice
   (do **not** deactivate the plugin — that orphans in-flight Stripe sessions).
2. Re-upload the previous ZIPs in reverse install order — booking engine
   `1.9.9.20` first (`archives/releases-legacy/g2a-booking-engine-1.9.9.20.zip`),
   then Memberistic `1.18.6`
   (`archives/releases-legacy/memberistic-membership-solutions-1.18.6.zip`).
   File replacement only.
3. **No schema rollback is required or permitted.** New tables/columns are
   ignored by the old code (`DEPLOYMENT-1.10.0.md` §Rollback). Never drop
   `wp_g2ab_webhook_events` or `wp_g2ab_email_log`.
4. **Immediately re-apply the P0-2 tourniquet** —
   `wp option update g2ab_require_public_prepay 1` and
   `g2ab_allow_event_pay_in_store_fallback 0` — otherwise rolling back
   reinstates the very bypass this incident is about.
5. Bookings created while 1.12.0 was live remain valid under 1.9.9.20: `pending`
   holds are expired by the existing cron, `paid`/`confirmed` rows are ordinary
   bookings.
6. `wp option update g2ab_action_token_version <n+1>` if issued email action
   links must be revoked.
7. Reconcile Stripe against WordPress for the deploy window before reopening
   bookings; any Checkout Session created under 1.12.0 that completed during the
   rollback needs `wp g2ab stripe-reconcile`.
8. Lift the maintenance notice only after one successful live low-value test on
   the rolled-back build.

**Rollback is worse than the incident in one specific way:** 1.9.9.20 is the
build that causes RC-1. Only roll back if 1.12.0 is actively breaking something
that P0-2 cannot contain, and treat it as a hold, not a resolution.
