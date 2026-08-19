# P0 INCIDENT AUDIT — Guns 2 Ammo: online bookings taken, Stripe never charged

**Date:** 2026-08-19
**Classification:** P0 — revenue loss + incorrect payment-state reporting
**Audit type:** Read-only. No production system, database, Stripe object or
customer record was touched, created, modified, retried, refunded or cancelled.
No secret value was read or printed.

---

## 0. Evidence boundary — read this before acting on anything below

Network egress from this audit environment to `guns2ammo.com` is blocked by
policy (agent proxy returned `403` to `CONNECT guns2ammo.com:443`; `WebFetch`
returned `EGRESS_BLOCKED`). There is no database credential, no Stripe API key
and no server shell in scope. **Every finding below is therefore derived from
the repository, from the shipped release ZIPs, and from Git history.**

Findings are labelled:

| Label | Meaning |
| --- | --- |
| **CONFIRMED FROM CODE** | Proven by reading the exact file that shipped in a release ZIP. Deterministic; no production lookup can contradict it. |
| **CONFIRMED FROM REPOSITORY HISTORY** | Proven from Git commits, release ZIP contents and dated release notes. |
| **REQUIRES PRODUCTION VERIFICATION** | A hypothesis about live configuration or data. Named query/check supplied. Not asserted as fact. |
| **REQUIRES STRIPE VERIFICATION** | Needs the live Stripe dashboard or API. Named check supplied. |

The single most important caveat, and the one the previous incident report
already recorded: **this repository is not production.** Deployment is a manual
ZIP upload (`DEPLOYMENT.md:14-31`), there is no CI/CD to the live site, and the
2026-07-19 audit stated plainly that its recommended fixes changed nothing live
until a release was built and uploaded. A repository-only reading can easily
conclude "fixed" while production runs another build. Section 3 quantifies that
drift and Section 15 lists the exact read-only commands an operator must run to
close every remaining gap.

Nothing in this document should be treated as a production observation.

---

## 1. Executive summary

### What broke

The public booking path **stops asking Stripe for money and tells everyone the
booking succeeded anyway.**

When the booking engine cannot use an online gateway — or, from the 2026-07-31
build, *unconditionally* — it reclassifies a payable public booking as
"pay at the store": `payment_mode = 'in_store'`, `due_now = 0`,
`status = 'reserved'`, `gateway = 'pay_in_store'`. It then returns HTTP 200 with
a success payload. No Stripe Checkout Session is created. No PaymentIntent
exists. No charge exists. No failed charge exists. Stripe has no record of the
booking at all, which is precisely what the owner saw.

The customer is then shown a hard-coded **"Booking confirmed"** panel, a green
**"Payment received"** notice, and an emailed **"Reservation received"** with a
confirmation number. Staff see the booking on the roster and the calendar, and
the Reports page counts its full `total_amount` as gross revenue. Nothing
anywhere says "unpaid".

### When it broke

- **2026-07-20** — the July-19 Stripe-webhook hotfix was shipped
  (`1c40d7d`: booking engine `1.9.9.15`, then `1.9.9.16` in `8cd61bd`;
  Memberistic `1.18.3`; Verifyistic `1.4.6`). This is the last day the owner
  reports a successful lane charge. On this build the Stripe bypass fires
  whenever **either** the lane booking type carries/defaults to an `in_store`
  payment mode **or** the Stripe gateway is unavailable/unregistered.
- **2026-07-31** — `1.9.9.20` (`4e52d76`, "Fix member/guest lane pricing,
  walk-in checkout") added `$allow_desk_settlement`, which **force-adds**
  `in_store` to a booking type's modes even when the type is configured
  card-only, gated on an option that defaults to off. From this build the bypass
  is unconditional for every public lane booking.
- **2026-08-19** — the owner detects it from the Stripe dashboard. No monitor
  fired. Thirty days of bookings.

### Why

Three design decisions compounding:

1. **Fail-open gateway selection.** "No usable online gateway" was treated as a
   reason to make the booking free rather than a reason to refuse the booking.
2. **A one-click kill switch with no warning.** `pay_in_store` is a
   `default_active` add-on described in the UI as *"Always free, always
   available"* (`includes/class-addon-manager.php:88`). Disabling the Stripe
   add-on, or clearing `g2ab_stripe_enabled`, silently converts every payable
   public booking into a free reservation — with no admin notice, no log entry
   at severity `error`, and no change to what the customer is told.
3. **Operational/reporting predicates that count unpaid holds as revenue.**
   `reserved` + `in_store` was defined as "operational" regardless of who
   created it, so a public web hold was indistinguishable from a staff-recorded
   walk-in.

### Affected systems

| System | Affected? | Notes |
| --- | --- | --- |
| G2A Booking Engine — lane bookings | **Yes, primary** | `POST /wp-json/g2a-booking/v1/bookings` |
| G2A Booking Engine — event/class bookings incl. CCW | **Yes, primary, separate defect** | `POST /wp-json/g2a-booking/v1/events/book` |
| G2A Booking Engine — Stripe webhook | Degraded since ≈2026-07-10 | Pre-existing from the 2026-07-19 incident; **not** the cause of no-charge |
| Memberistic — membership subscriptions | Separate Stripe consumer, separate keys, separate webhook | Not implicated in lane/CCW non-charging; own risks in §7 |
| G2A POS Core — Stripe Terminal | Independent (`g2a_pos_stripe`) | Not implicated; no shared config |
| WooCommerce | Latent risk only | §7.4 |

### Financial exposure

**Uncollected, not lost.** No money was taken and mishandled — money was never
requested. Every affected customer received a service promise the business
honoured (or will honour) without payment. The exposure is
`SUM(total_amount)` over the affected set, and it is largely **collectible at
the front desk** because the customers are identified, contactable, and were
told to expect a booking.

The exact figure is a single SQL query and **REQUIRES PRODUCTION VERIFICATION** —
see `sql/06-financial-impact.sql`. This audit will not invent a number.

---

## 2. Incident timeline (2026-07-10 → 2026-08-19)

Sources: `git log`, release ZIPs in `archives/releases-legacy/` and `dist/`,
`archives/docs/INCIDENT-AUDIT-2026-07-19-STRIPE-SIGNUP.md`,
`archives/release-notes/RELEASE-2.5.0.md`.
**CONFIRMED FROM REPOSITORY HISTORY** unless marked otherwise.

| Date | Commit / artefact | Change | Payment impact |
| --- | --- | --- | --- |
| ≈2026-07-10 | — | Stripe begins recording consecutive delivery failures on `/wp-json/g2a-booking/v1/webhooks/stripe` | Local payment recording broken. **Charging still worked.** |
| 2026-07-11 | `ddb2b63` (pre-window, cited by prior audit) | Webhook returns HTTP 422 for events the plugin does not own | Guarantees a permanent failure stream → Stripe auto-disable |
| 2026-07-15 | `c0ba294` | `prefer_stripe_for_public_payment()` and the `in_store` fall-through branch present in the mirrored tree | The lane bypass **mechanism** exists from here |
| 2026-07-16 | `8dcb2b9`, `d858f15`, `67b3650` | POS 3.3.5, Memberistic 1.18.2 | None |
| **2026-07-19** | `b32cde3` | **Stripe webhook + membership signup incident audit committed.** Report only, no code fixes. Stripe had disabled the booking endpoint. | Documents the outage; changes nothing live |
| **2026-07-20** | **`1c40d7d`** | **Hotfix release shipped: booking engine `1.9.9.15`, Memberistic `1.18.3`, Verifyistic `1.4.6`.** Fixes the webhook signature header, 2xx-acks foreign events, adds `wp_g2ab_webhook_events` table (DB v1.5.3), defers paid side-effects, adds WP-CLI `stripe-audit`/`reconcile`. | **The suspected onset.** New DB migration; new webhook behaviour; the pre-existing `in_store` fall-through and the fail-open event path both ship unchanged. See §4. |
| 2026-07-20 | `8cd61bd` | Booking engine `1.9.9.16` + theme 1.27.14, Memberistic 1.18.4, Verifyistic 1.4.7, FFL 1.21.1, Formistic 2.1.1 | Same routing code as 1.9.9.15 |
| 2026-07-20 | `3a3f823` | advanced-ffl-checkout 1.15.1 → 1.21.0 (six minor versions in one step) | Unrelated to bookings; large-blast-radius same-day change |
| 2026-07-20 | `d824541`, `4e0c6e2`, `9046847`, `77846f4`, `23c828f` | Site-wide dark/light contrast sweep across theme templates, FFL checkout, Formistic, Verifyistic | Front-end only |
| 2026-07-20 | `f435ec5`, `950929d`, `cf51ab2`, `16ab190` | **System Release 2.5.0** — every component version-bumped and re-zipped | Large simultaneous release surface on the exact cut-off date |
| 2026-07-31 | `4bc9dd5` | Booking engine `1.9.9.19`, Memberistic `1.18.5` | Adds the **event** fail-closed guard (`g2ab_allow_event_pay_in_store_fallback`, default 0). Lane bypass untouched. |
| **2026-07-31** | **`4e52d76`** | **"Fix member/guest lane pricing, walk-in checkout" → booking engine `1.9.9.20`, Memberistic `1.18.6`** | **Makes the lane Stripe bypass unconditional** via `$allow_desk_settlement`. Also fixes the email-attribution defect (§5.4) and adds `class-booking-visibility.php`, which classifies public `reserved`+`in_store` rows as operational revenue. |
| 2026-07-31 | `c77bdb3`, `8324ce1`, `54f7486`, and 8 more | POS 3.4.0, dashboard deploy prep, CI/runner work | None |
| 2026-08-01 | `d615e03`, `5049373`, `05adddb` | `g2a-chat-worker` added; brain scope isolation | None |
| 2026-08-06 | `092ec02` | Dashboard prepared for `app.guns2ammo.com` | None |
| **2026-08-08** | `df283ac` | **Booking engine `1.10.0` + Memberistic `1.19.0`** | **The real fix begins.** Public pay-at-store retired; `g2ab_require_public_prepay` and `g2ab_allow_event_pay_in_store_fallback` marked *retired/ignored*; Guest-Pass auto-creation stopped. |
| 2026-08-08 | `0f0c419`, `ffb12b3`, `ea5d517`, `a3eb540` | PMPro removal, repo reorg, `dist/` builds, packaging fixes → booking engine `1.11.0` | Hardening |
| 2026-08-13 | `d54b056`, `58aa4d2` | Memberistic `1.21.0`, POS `3.5.0` | Hardening |
| 2026-08-15 | `cd6b152`, `8bc116f`, `643b4bf` (HEAD) | WooCommerce single-product redesign, chatbot widget | None |
| **2026-08-19** | — | **Owner reports: no Stripe charges for online bookings; last basic-lane charge 2026-07-20; 3 CCW customers never charged.** | This audit |

### The July 19 → July 20 relationship

The prior incident (webhook disabled, memberships not activating) and this one
are **different failures with a shared trigger date**, and it matters that they
are not conflated:

- A disabled or failing webhook explains *local state not updating after a
  successful Stripe charge* (**CASE A**). It **cannot** explain Stripe having no
  Checkout Session at all.
- The owner is reading the **Stripe** dashboard. Stripe shows nothing. That is
  **CASE C** — the session was never created.

What connects them is the **response** to the July-19 incident, not its cause.
The July-20 release window is the moment when (a) new booking-engine code was
uploaded, (b) a new DB migration was required, and (c) an operator was working
inside *G2A Booking → Settings → Payments* and *Settings → Add-ons* following the
incident runbook — the two screens whose switches silently disable Stripe for the
whole booking system. See §4.3 for the exact production checks that distinguish
these.

---

## 3. Architecture — every payment path

### 3.1 Canonical intended flow

```
Customer
   │
   ▼
Booking UI  (inline IIFE in class-frontend.php, or [g2ab_event_booking])
   │  POST — no `gateway` param is ever sent by the shipped JS   ◀── see §5.1
   ▼
REST endpoint
   ├── lanes  : POST /wp-json/g2a-booking/v1/bookings
   ├── events : POST /wp-json/g2a-booking/v1/events/book      ◀── DIFFERENT CODE
   └── admin  : POST /wp-json/g2a-booking/v1/admin/bookings   (capability + nonce)
   │
   ▼
Pricing / entitlement resolution
   calculate_pricing() → filter g2ab_booking_pricing
                       → Memberistic Entitlement_Service (1.19.0+)
                       → filter g2ab_lane_entitlement      (1.12.0+ only)
   │
   ▼
Gateway selection  ◀══════ THE FAILURE POINT ══════
   1.12.0 : G2AB_Checkout_Policy::pick_online_gateway()  — fails CLOSED (503)
   1.9.9.x: prefer_stripe_for_public_payment() + in_store fall-through — fails OPEN
   │
   ├─ payable ──▶ Stripe Checkout Session (mode=payment)
   │                 client_reference_id = booking uuid
   │                 metadata.booking_id / booking_uuid / payment_attempt_id
   │                 ▼
   │              PaymentIntent ▶ Charge / Capture
   │                 ▼
   │              Webhook  POST /wp-json/g2a-booking/v1/webhooks/stripe
   │                 signature verify → G2AB_Payment_Validator
   │                 → wp_g2ab_payments row status='succeeded'
   │                 → G2AB_Booking_Transitions::transition(booking,'paid')
   │                       ▲ invariant: successful ledger row REQUIRED
   │                 ▼
   │              booking.status = 'paid'
   │                 ▼
   │              g2ab_booking_paid → confirmation email, invoice, capacity
   │
   └─ $0 / in_store ──▶ status = 'confirmed' or 'reserved'
                        NO Stripe call at any point            ◀── the bug
```

### 3.2 A — Lane booking

**Guest / non-member (intended):** lane selection → `POST /bookings` →
`calculate_pricing()` → payable → Stripe Checkout → payment → webhook → `paid`.

**Member (intended):** authenticated session → Memberistic entitlement →
`membership_source='memberistic'`, `total = 0` → `payment_mode='free'`,
`status='confirmed'`, no Stripe required.

**Can these two be confused?**

- **1.12.0 (repo HEAD): no.** `G2AB_Checkout_Policy::resolve_lane()`
  (`includes/services/class-checkout-policy.php:127-176`) refuses a $0 total
  unless it is attributable to either an eligible entitlement with
  `membership_source === 'memberistic'` **or** a genuinely free booking type;
  otherwise it returns `g2ab_pricing_invalid` (HTTP 409) and the booking is not
  created. `lane_entitlement()` additionally rejects an entitlement whose
  `user_id` is not the authenticated user (`:110-116`).
  **CONFIRMED FROM CODE.**
- **1.9.9.16 (July-20 build): yes, two ways.** (a) The `in_store` branch makes a
  payable guest booking `due_now = 0` (§5.2). (b) `calculate_pricing()` is
  handed the **email-matched** user id, so a logged-out guest who types a
  member's address is priced as that member (§5.4). **CONFIRMED FROM CODE.**

### 3.3 B — CCW / training / event booking

This does **not** share the lane checkout code. **CONFIRMED FROM CODE.**

| Aspect | Value |
| --- | --- |
| Booking type | Synthetic type from `G2AB_Events::get_or_create_event_booking_type()`; the row carries `event_id` + `event_occurrence_id` |
| Event id | `wp_g2ab_events.id`, occurrence `wp_g2ab_event_occurrences.id` |
| Pricing source | `G2AB_Events::price_for( $event, $occ, $is_member )` — per-occurrence price with a per-event member price; **not** `booking_type.base_price`, **not** `member_discount` |
| Frontend form | `[g2ab_event_booking]` → `includes/frontend/class-shortcode-event-booking.php` |
| REST endpoint | `POST /wp-json/g2a-booking/v1/events/book` → `create_event_booking()` |
| Gateway selection | `pick_online_gateway_for_event()` — a **different** resolver from the lane path |
| Payment requirement | 1.9.9.16: **fail-open**. 1.9.9.19+: fail-closed unless `g2ab_allow_event_pay_in_store_fallback=1`. 1.12.0: fail-closed, no opt-out |
| Booking status | `total>0` → `pending` (hold) if a gateway exists; `reserved` if not (1.9.9.16); `confirmed` if `total<=0` |
| Stripe session | Created after the seat reservation commits, same `create_intent()` as lanes |
| Webhook handling | Identical — `checkout.session.completed` → `mark_booking_paid()` |

Seat capacity is enforced by `G2AB_Event_Capacity_Service::reserve_seats_atomically()`,
and `G2AB_Events::ACTIVE_STATUSES` includes `reserved` — so an unpaid `reserved`
CCW seat **consumes real capacity** and blocks paying customers.

**Traced CCW transaction (July-20 build, no online gateway available):**

```
[UI]      [g2ab_event_booking] → POST /events/book {occurrence_id, seats:1, ...}
[PHP]     create_event_booking()                       class-bookings-controller.php:1268
          $unit_price = G2AB_Events::price_for(...)    :1319   → e.g. 150.00
          $total      = 150.00                         :1320
          $gateway    = pick_online_gateway_for_event() :1339  → NULL   ◀── fail-open
          else branch                                   :1352-1356
              payment_mode    = 'in_store'
              gateway_used_id = 'pay_in_store'
              due_now         = 0.0
              initial_status  = 'reserved'
[DB]      INSERT wp_g2ab_bookings                       :1391-1420
              status='reserved' payment_mode='in_store'
              total_amount=150.00 paid_amount=0 source='web'
              metadata={"gateway":"pay_in_store","due_now":0,...}
          (no INSERT into wp_g2ab_payments — ever)
[STRIPE]  ——— nothing ———
[EMAIL]   g2ab_booking_created → "Reservation received — <course> on <date>"
[UI]      "Booking confirmed" panel
[STAFF]   roster + calendar + Reports gross_revenue += 150.00
```

### 3.4 C — Memberships (Memberistic)

```
[memberistic_checkout] → POST /?memberistic_checkout_handler=1
   → membership row status='pending'
   → Stripe Checkout (mode=subscription)   ◀── customer IS charged here
   → webhook POST /wp-json/memberistic/v1/webhooks/stripe
        checkout.session.completed → create/link WP user, pending→active,
        store stripe_customer_id / stripe_subscription_id, payment row, emails
```

**This is a separate payment system on separate credentials.**
**CONFIRMED FROM CODE:**

| | Booking engine | Memberistic | POS |
| --- | --- | --- | --- |
| Secret key | `g2ab_stripe_secret_key` / `G2AB_STRIPE_SECRET` | `memberistic_settings[stripe_live_secret_key]` / `[stripe_test_secret_key]` | `g2a_pos_stripe['secret_key']` |
| Mode switch | `g2ab_stripe_test_mode` (**default 1 = TEST**) | `stripe_mode` (`live`/`test`) | `g2a_pos_stripe['mode']` |
| Enable flag | `g2ab_stripe_enabled` (default 0) | `stripe_enabled` (`yes`/`no`, default `no`) | — |
| Webhook URL | `/wp-json/g2a-booking/v1/webhooks/stripe` | `/wp-json/memberistic/v1/webhooks/stripe` | none |
| Webhook secret | `g2ab_stripe_webhook_secret` / `G2AB_STRIPE_WEBHOOK_SECRET` | `memberistic_settings[stripe_webhook_secret]` | — |

There is **no shared option key and no shared code path**, so one subsystem
cannot overwrite another's Stripe configuration. That is good isolation — and it
also means **the three can silently be pointed at different Stripe accounts or
different modes**, and nothing in the product compares them. See §4.3.

### 3.5 D — POS

`plugins/g2a-pos-core/includes/Payments/StripeTerminal.php` reads only
`get_option('g2a_pos_stripe')` (`:68,:74`); `API/SettingsController.php:89-104`
is the only writer and masks `secret_key` on read. `grep` for `g2ab_` or
`booking` inside `API/StripeController.php` returns **nothing** — POS does not
touch booking payments and cannot alter booking Stripe config.
**CONFIRMED FROM CODE.**

### 3.6 E — WooCommerce

- `G2AB_Gateway_Woocommerce` (`modules/woocommerce-bridge/class-woo-gateway.php`)
  is a registered booking gateway that creates a WC order and redirects to WC
  checkout. It is registered **only** by the WooCommerce Bridge module, not by
  `G2AB_Gateway_Manager`'s constructor.
- `is_available()` returns `class_exists('WooCommerce')` — i.e. **true whenever
  WooCommerce is active, regardless of whether any WooCommerce payment gateway
  is configured** (`class-woo-gateway.php:24-26`). **CONFIRMED FROM CODE.**
- `G2AB_Checkout_Policy::pick_online_gateway()` falls through to
  `foreach ($mgr->available() as $gateway)` after Stripe and PayPal, so with
  Stripe down and the bridge active, a booking can be routed into WooCommerce.
- `class-woo-sync.php::on_paid()` requires `$order->is_paid()` plus an amount
  cross-check plus the transition service — good. But `$order->is_paid()` is
  **true for Cash-on-Delivery / cheque orders that reach `processing`**, which
  would mark a booking `paid` with no money collected.
  **Latent — REQUIRES PRODUCTION VERIFICATION** (§15 check W1).

---

## 4. Production vs repository drift

### 4.1 What the repository says is current

`VERSIONS.md` (last updated 15 Aug 2026) and the plugin headers agree:

| Component | Repository version | Build in `dist/` |
| --- | --- | --- |
| memberistic-membership-solutions | 1.21.0 | ✔ |
| **g2a-booking-engine** | **1.12.0** | ✔ |
| g2a-pos-core | 3.5.0 | ✔ |
| advanced-ffl-checkout | 1.21.1 | ✔ |
| verifyistic | 1.4.7 | ✔ |
| formistic | 2.1.1 | ✔ |
| messageistic | 0.8.1 | ✔ |
| g2a-theme-control | 1.0.1 | ✔ |
| g2a-business-api | 0.4.3 | ✔ |
| theme guns2ammo | 1.29.0 | ✔ |

### 4.2 What production is likely running — and why

**REQUIRES PRODUCTION VERIFICATION.** Reasoning, all **CONFIRMED FROM
REPOSITORY HISTORY**:

- Deployment is manual ZIP upload; there is no pipeline (`DEPLOYMENT.md:14-31`).
- The last release with an **operator-facing deployment runbook** is
  `plugins/g2a-booking-engine/docs/DEPLOYMENT-1.10.0.md`, and its **Rollback**
  section names *"the previous plugin versions (booking engine 1.9.9.20,
  Memberistic 1.18.6)"* — establishing 1.9.9.20/1.18.6 as the last shipped pair.
- That runbook has a *"Legacy pay-at-store rows"* section instructing the
  operator to export `status='reserved' AND payment_mode='in_store' AND
  source='web'` rows **before** deploying and decide per row whether to collect
  at the desk or cancel. **That section only exists because those rows exist.**
  It is documentary evidence, written inside this repository, that the public
  pay-at-store path was live and producing rows.
- The 1.10.0 runbook's step 4 (Stripe-test lane + event bookings) and step 9
  (post-deploy reconciliation) produce artefacts. None are present in the repo.
- If 1.10.0/1.11.0/1.12.0 had been deployed, the bypass would have stopped and
  the owner would have seen charges resume in August. He reports none.

**Working conclusion (HIGH confidence, not CONFIRMED): production is on
`g2a-booking-engine 1.9.9.20` + `memberistic 1.18.6`, i.e. the 2026-07-31
build, and has never received 1.10.0, 1.11.0 or 1.12.0.**

### 4.3 Version-by-date reconstruction

| Date | Booking engine | Memberistic | Basis |
| --- | --- | --- | --- |
| 2026-07-19 | 1.9.9.14 (or earlier 1.9.9.x) | 1.18.2 | Prior audit: *"1.9.9.14 is byte-identical to repo HEAD"* at that time |
| **2026-07-20** | **1.9.9.15 → 1.9.9.16** | **1.18.3 → 1.18.4** | `1c40d7d`, `8cd61bd` |
| 2026-07-21 | 1.9.9.16 | 1.18.4 | no releases between |
| **2026-07-31** | **1.9.9.19 → 1.9.9.20** | **1.18.5 → 1.18.6** | `4bc9dd5`, `4e52d76` |
| 2026-08-01 | 1.9.9.20 | 1.18.6 | no plugin releases |
| **2026-08-19 (today)** | **1.9.9.20 (expected)** | **1.18.6 (expected)** | §4.2 — **REQUIRES PRODUCTION VERIFICATION** |

Repository at each of those dates ran ahead of production from 2026-08-08.

### 4.4 File-hash comparison table (operator fills column 3)

Repository-side SHA-256 of each shipped ZIP, so the operator can compare a
production `wp plugin list` / file hash without sending anything anywhere:

Run `sha256sum archives/releases-legacy/*.zip dist/*.zip` to regenerate. The
production column can be filled with
`wp plugin get g2a-booking-engine --field=version` plus
`sha256sum wp-content/plugins/g2a-booking-engine/includes/rest/class-bookings-controller.php`
compared against the same file extracted from the candidate ZIP.

| Component | GitHub version | Production version | Same code? | Risk |
| --- | --- | --- | --- | --- |
| g2a-booking-engine | 1.12.0 | ❓ (expect 1.9.9.20) | ❓ | **CRITICAL** — the entire Stripe bypass |
| memberistic-membership-solutions | 1.21.0 | ❓ (expect 1.18.6) | ❓ | **HIGH** — entitlement service absent < 1.19.0 |
| g2a-pos-core | 3.5.0 | ❓ | ❓ | LOW — isolated |
| advanced-ffl-checkout | 1.21.1 | ❓ | ❓ | LOW |
| verifyistic | 1.4.7 | ❓ | ❓ | MEDIUM — age gate blocks checkout when tripped |
| theme guns2ammo | 1.29.0 | ❓ | ❓ | LOW |
| WooCommerce | n/a (not vendored) | ❓ | n/a | MEDIUM — §3.6 |

### 4.5 Database-schema drift (H19)

The 2026-07-20 release bumped `G2AB_DB_VERSION` to **1.5.3** and added
`wp_g2ab_webhook_events` via `dbDelta` (`class-installer.php`, diff in `1c40d7d`).
`1.10.0` additionally expects `wp_g2ab_email_log`, and `class-stripe.php`'s
`payment_table_columns()` probes for `checkout_session_id`, `payment_intent_id`,
`idempotency_key_hash`, `attempt_number`, `amount_minor`, `due_now_minor`,
`refunded_amount_minor`, `checkout_expires_at`, `updated_at` on
`wp_g2ab_payments` and **degrades silently** when they are missing.

A ZIP upload that replaces files without the upgrade routine running leaves the
schema behind the code. **REQUIRES PRODUCTION VERIFICATION** — §15 check D1.
Note this is a *recording* defect, not the charging defect.

---

## 5. Confirmed root causes

Every item here is **CONFIRMED FROM CODE** against the exact file inside the
named release ZIP. Paths are relative to the plugin root inside the ZIP.

---

### RC-1 — Lane bookings silently reclassified as free pay-at-store (CONFIRMED)

**Confidence: CONFIRMED**
**Affected dates: 2026-07-20 → present**
**Build: `g2a-booking-engine` 1.9.9.15 / 1.9.9.16, worsened in 1.9.9.20**

**File:** `includes/rest/class-bookings-controller.php`
**Function:** `G2AB_REST_Bookings_Controller::create_booking()`

**1.9.9.16, lines 758-786:**

```php
758:  $gateway = null;
759:  if ( class_exists( 'G2AB_Gateway_Manager' ) ) {
760:      $mgr = G2AB_Gateway_Manager::instance();
761:      if ( $gateway_id ) { $gateway = $mgr->get( $gateway_id ); }
763:      if ( ! $gateway ) { $gateway = $mgr->pick_for_type( $booking_type ); }
765:      $gateway = $this->prefer_stripe_for_public_payment( $gateway, $gateway_id, $booking_type );
766:  }
768:  $gateway_used_id = ( $gateway && method_exists( $gateway, 'id' ) ) ? $gateway->id() : 'pay_in_store';
770:  $modes = $this->payment_modes_for_type( $booking_type );
771:  if ( $total <= 0 || ( in_array( 'free', $modes, true ) && $total <= 0 ) ) {
772:      $payment_mode = 'free'; $due_now = 0.0; ...
775:  } elseif ( 'pay_in_store' === $gateway_used_id || ( in_array( 'in_store', $modes, true ) && empty( $gateway_id ) ) ) {
776:      $payment_mode    = 'in_store';
777:      $gateway_used_id = 'pay_in_store';
778:      $due_now         = 0.0;
   ...
786:  $initial_status = ( $total <= 0 ) ? 'confirmed' : ( 'in_store' === $payment_mode ? 'reserved' : 'pending' );
```

Two independent triggers, either sufficient:

1. **Line 768** — if the gateway manager yields no gateway object, `pay_in_store`
   is assumed. The first disjunct on line 775 then fires **regardless of the
   booking type's configuration**. `G2AB_Gateway_Manager::get('stripe')` returns
   `null` when the `stripe` add-on is inactive (`class-manager.php:36-41`), and
   `prefer_stripe_for_public_payment()` only returns Stripe when
   `is_available()` — `g2ab_stripe_enabled === 1 && secret_key !== ''`
   (`includes/payments/class-stripe.php:21-23`).
2. **Line 775, second disjunct** — `in_array('in_store', $modes) && empty($gateway_id)`.
   `$gateway_id` is **always empty from the public UI**: the shipped booking
   JavaScript never sends a `gateway` parameter (`grep -n "gateway"
   assets/js/frontend.js` in 1.9.9.16 returns only a comment on line 46).
   And `payment_modes_for_type()` line 470 returns
   `array('full','in_store')` whenever the booking type's `payment_modes` column
   is empty or null. So **a lane type with an unset `payment_modes` column
   bypasses Stripe even when Stripe is perfectly healthy.**

**1.9.9.20, lines 834-861 — the same defect made unconditional:**

```php
838:  // Walk-in lane types are frequently configured card-only, which is what
839:  // forced every public booking through Stripe: with no `in_store` mode
840:  // there was no other branch to fall into, so an abandoned form left an
841:  // `open` checkout attempt behind. Unless the site explicitly requires
842:  // prepay, a public booking may always settle at the front desk.
844:  $allow_desk_settlement = empty( $booking_type->members_only )
845:      && empty( $gateway_id )
846:      && ( $needs_desk_rate || ! $this->public_prepay_required( $booking_type ) );
848:  if ( $allow_desk_settlement ) {
849:      if ( ! in_array( 'in_store', $modes, true ) ) {
850:          $modes[] = 'in_store';          // ◀ OVERRIDES a card-only booking type
851:      }
852:      $gateway_used_id = 'pay_in_store';
853:  }
```

with

```php
571:  private function public_prepay_required( $booking_type ) {
572:      return (bool) apply_filters( 'g2ab_require_public_prepay',
574:          1 === (int) get_option( 'g2ab_require_public_prepay', 0 ),   // ◀ DEFAULT 0
575:          $booking_type );
576:  }
```

`g2ab_require_public_prepay` was **introduced by this same commit**, so unless an
administrator found and enabled a brand-new, undocumented checkbox on the day of
release, it is unset → `0` → prepay not required → `$allow_desk_settlement` is
**true for every public, non-members-only lane booking**. `prefer_stripe_for_public_payment()`
also returns early in this state (`:583-585`), so Stripe is not even preferred.

**Database result:** `wp_g2ab_bookings` row with `status='reserved'`,
`payment_mode='in_store'`, `total_amount = <full price>`, `paid_amount = 0`,
`source='web'`, `metadata.gateway='pay_in_store'`, `metadata.due_now=0`.
**No row is written to `wp_g2ab_payments`.**

**Stripe result:** nothing. `create_intent()` is unreachable.

**Why the booking appeared valid:** §5.5.
**Why money was not collected:** the code decided none was due.

---

### RC-2 — Paid events (CCW) fail open to a free reserved seat (CONFIRMED)

**Confidence: CONFIRMED**
**Affected dates: 2026-07-20 → 2026-07-31 (fixed by 1.9.9.19), and any period
where `g2ab_allow_event_pay_in_store_fallback=1`**
**Build: `g2a-booking-engine` 1.9.9.15 / 1.9.9.16**

**File:** `includes/rest/class-bookings-controller.php`
**Function:** `create_event_booking()`, lines 1336-1356

```php
1336: // ─── Gateway selection — paid events take payment NOW ───────────────
1337: // Pick a real online gateway (prefers Stripe, then PayPal, then any
1338: // available card gateway). Only when none is configured do we fall back
1339: // to reserve-and-pay-at-the-desk.
1339: $gateway         = ( $total > 0 ) ? $this->pick_online_gateway_for_event( $bt, $gateway_id ) : null;
1340: $gateway_used_id = ( $gateway && method_exists( $gateway, 'id' ) ) ? $gateway->id() : 'pay_in_store';
1342: if ( $total <= 0 ) { ... }
1346: } elseif ( $gateway && method_exists( $gateway, 'create_intent' ) ) {
1347:     $payment_mode = 'full'; $due_now = $total; $initial_status = 'pending';
1349: } else {
1350:     $payment_mode    = 'in_store';
1351:     $gateway_used_id = 'pay_in_store';
1352:     $due_now         = 0.0;
1353:     $initial_status  = 'reserved';        // ◀ seat held, $0 due, no Stripe, no error
1354: }
```

`pick_online_gateway_for_event()` (`:1235-1262`) returns `null` when Stripe and
PayPal are both unregistered or unavailable, and **1.9.9.16 does not even
error when an explicitly requested gateway is unavailable** — it falls through
to the preference loop (`:1246-1251`, no `else` returning `WP_Error`).

The comment on line 1338 shows this was an intentional convenience. On a paid
CCW course it is a revenue leak: the seat is reserved, `ACTIVE_STATUSES`
includes `reserved` so it consumes capacity, and the customer is emailed a
confirmation number.

**Fixed 2026-07-31** in 1.9.9.19 (`:1484`):
`if ( $total > 0 && ! $gateway && 1 !== (int) get_option('g2ab_allow_event_pay_in_store_fallback', 0) ) { return WP_Error }`
— default 0, so fail-closed.
**Fully fixed** in 1.12.0 (`:1684-1692`) with no opt-out.

---

### RC-3 — "No usable gateway" is a one-click, unlogged switch (CONFIRMED)

**Confidence: CONFIRMED (mechanism) / REQUIRES PRODUCTION VERIFICATION (whether it fired)**

Both RC-1 trigger 1 and RC-2 depend on Stripe being unusable. Three independent
switches make that true, all reachable from wp-admin, none of which produces an
error, an admin notice at the point of booking, or a distinguishable log entry:

| Switch | Location | Effect |
| --- | --- | --- |
| `g2ab_addons_active` no longer contains `stripe` | *G2A Booking → Settings → Add-ons* (`admin_post_g2ab_toggle_addon`) | `G2AB_Gateway_Manager::register()` **refuses to register** the gateway (`class-manager.php:36-41`) → `get('stripe')` returns `null` → also makes the webhook endpoint answer `503 gateway_not_loaded` |
| `g2ab_stripe_enabled = 0` | *Settings → Payments → Stripe* checkbox | `is_available()` false (`class-stripe.php:21-23`) |
| `g2ab_stripe_secret_key = ''` | same screen | `is_available()` false |

`pay_in_store` is `'default_active' => true` and is described in the add-ons UI
as **"Always free, always available."** (`includes/class-addon-manager.php:88`).
So the failure mode of turning Stripe off is not "bookings stop" — it is
"bookings become free".

The July-19 runbook sent an operator to exactly these screens on 2026-07-20 to
install a webhook signing secret. That is the most plausible trigger for the
2026-07-20 cut-off under the 1.9.9.16 build. **It is a hypothesis, not an
observation** — §15 checks C1-C3 settle it in under a minute.

---

### RC-4 — Guest priced as a member via email attribution (CONFIRMED)

**Confidence: CONFIRMED**
**Affected dates: up to 2026-07-31 (fixed in 1.9.9.20)**

`create_booking()` in 1.9.9.16:

```php
741:  $user_id = $this->ensure_customer_user( $customer_name, $customer_email, $customer_phone );
      // ↑ returns the EXISTING user matched by typed email (:559-560)
751:  $pricing = $this->calculate_pricing( $booking_type, $party_size, $user_id, $customer_email );
```

and `calculate_pricing()` (`:473-505`) passes that `$user_id` straight into
`apply_filters('g2ab_booking_pricing', $pricing, $booking_type, $party_size, $user_id, $customer_email)`.

Memberistic 1.18.6 had **already fixed** email-only matching on its side
(`includes/integrations/class-booking-engine.php:96-99`, "Audit C27 … discount
only applies to the LOGGED-IN member") — it resolves by `$user_id`. **The fix is
defeated by the caller**, which hands it an email-matched id. A logged-out
visitor typing a member's address therefore receives that member's plan rules,
including `booking_type_is_included()` → `total = 0.0`,
`membership_source='memberistic'` (`:110-124`) → `payment_mode='free'`,
`status='confirmed'`, no Stripe.

This is a **cross-plugin composition defect**: neither plugin is wrong in
isolation. 1.9.9.20 fixed it by introducing `authenticated_user_id()` and the
advisory-only `g2ab_advisory_membership_hint`. 1.12.0 hardened it further with
the `entitlement_user_mismatch` check in
`G2AB_Checkout_Policy::lane_entitlement()` (`:110-116`).

---

### RC-5 — Four surfaces assert payment without payment evidence (CONFIRMED)

**Confidence: CONFIRMED** — this is why nobody noticed for 30 days.

1. **Completion panel.** `includes/class-frontend.php:539` (1.9.9.16) —
   `<h3 class="g2ab-done__title">Booking confirmed</h3>` with a green tick SVG
   and confetti, rendered on any successful create response.
2. **Payment-return notice.** `assets/js/frontend.js:66-68` (1.9.9.16):
   ```js
   } else {
       showInlineNotice('<strong>Payment received.</strong><br>Your booking is
           finalising. You\'ll get a confirmation email shortly.', 'success');
   }
   ```
   This is the `else` branch — it runs when the server reports the booking is
   **not** paid/confirmed. Anyone loading `?g2ab_paid=<any-uuid>` sees
   "Payment received."
3. **Email.** `modules/email-automation/class-email-automation.php:42-47`
   (1.9.9.16) fires `booking_created` for every booking with no payment-mode
   check; the template (`class-email-engine.php:261-273`) reads *"Reservation
   Received"* with a Confirmation #, no amount due and no pay link. The honest
   `pay_in_store_reservation` template (*"Reservation held — pay at the store"*)
   first appears in 1.9.9.20.
4. **Staff + reporting.** In 1.9.9.20,
   `includes/class-booking-visibility.php:43` defines operational as
   `status IN ('confirmed','paid','completed') OR (status='reserved' AND
   payment_mode='in_store')` — **with no source restriction**, so public web
   holds are operational. `includes/admin/class-reports.php:56-63` then computes
   `COALESCE(SUM(b.total_amount),0) AS revenue` over exactly that predicate.
   **The Reports page showed revenue that Stripe never collected.**

1.12.0 fixes all four: the visibility class adds a `staff_sources()` restriction
(`class-booking-visibility.php:44-56,72-77`), the email module routes
`in_store`+`reserved` to the pay-at-store template and suppresses confirmation
for unpaid holds (`class-email-automation.php:41-56`), and the payment-return
JS never claims success without a verified `/status` result
(`assets/js/frontend.js:11-17`).

---

### RC-6 — Unpaid pay-at-store holds never expire (CONFIRMED)

`includes/cron/class-booking-expiry-cron.php:50-56` selects only
`payment_mode IN ('full','deposit')`. An `in_store` row is therefore **never**
expired. Combined with `G2AB_Events::ACTIVE_STATUSES` including `reserved`, every
bypassed booking permanently consumes lane time or a class seat and permanently
inflates the revenue report. Deliberate (documented at `:11-13`) and correct for
a *staff-created* walk-in; wrong for a public web row that should never have
existed.

---

## 6. Contributing factors

| # | Factor | Evidence | Effect |
| --- | --- | --- | --- |
| CF-1 | Webhook outage from ≈2026-07-10 | Prior audit §1 | Broke local recording *before* this incident; masked the transition by making "payments not showing up" feel normal |
| CF-2 | No payment-integrity or Stripe-inactivity monitor anywhere in the codebase | grep: no scheduled job compares bookings against Stripe | 30 days undetected |
| CF-3 | Manual ZIP deployment, no pipeline, no deployed-version record | `DEPLOYMENT.md:14-31` | Repo fixes (1.10.0 → 1.12.0) never reached production |
| CF-4 | Two independent Stripe consumers plus POS, no cross-check | §3.4 | Mode/account divergence is possible and invisible |
| CF-5 | `g2ab_stripe_test_mode` **defaults to 1 (TEST)** | `class-payment-validator.php:126-129` | If never explicitly saved to 0, `validate_stripe_checkout_session()` rejects **live** sessions as `g2ab_payment_mode_mismatch` — a fail-closed trap that would turn real charges into unrecorded payments (CASE A) |
| CF-6 | Six version bumps of `advanced-ffl-checkout` (1.15.1→1.21.0) plus a site-wide CSS sweep plus a full System Release, **all on 2026-07-20** | §2 | Enormous same-day blast radius; nothing was individually verified |
| CF-7 | `G2AB_Gateway_Woocommerce::is_available()` returns true whenever WooCommerce is active | `class-woo-gateway.php:24-26` | A booking can be routed to WooCommerce with no card gateway configured |
| CF-8 | Verifyistic age gate rate-limits on shared Cloudflare edge IPs (< 1.4.6) | Prior audit §3.2 | Can hard-403 checkout for whole cohorts |
| CF-9 | No release produced the artefacts its own runbook requires | `DEPLOYMENT-1.10.0.md` steps 4 & 9 | No evidence any release was ever verified against Stripe |

---

## 7. Hypothesis ledger — H1…H20, proved or rejected

| # | Hypothesis | Verdict | Basis |
| --- | --- | --- | --- |
| **H1** | Production Stripe webhook remained disabled/misconfigured after 2026-07-19 | **UNRESOLVED — REQUIRES STRIPE VERIFICATION.** Even if true it explains only CASE A, never a missing Checkout Session. The July-20 build *did* fix the header bug and the 422 regression, so a re-enabled endpoint should work — but nothing re-enables it automatically. | §15 check S3 |
| **H2** | Production was not actually upgraded to the repaired booking plugin | **HIGH — likely TRUE.** 1.10.0/1.11.0/1.12.0 contain the real fix and there is no evidence of deployment; the 1.10.0 runbook's rollback target is 1.9.9.20 | §4.2 |
| **H3** | A deployment around 2026-07-20 changed gateway selection | **PARTIALLY TRUE.** `1c40d7d` did **not** alter the routing branch. But it replaced the plugin and required a DB migration, and the surrounding incident work put an operator on the two screens that disable Stripe. The *decisive* gateway-selection change is 2026-07-31 (`4e52d76`) | §2, §5 RC-1 |
| **H4** | Non-member public bookings routed to `pay_in_store` | **CONFIRMED** | RC-1 |
| **H5** | CCW/event booking uses a different payment policy than lanes | **CONFIRMED** | §3.3, RC-2 |
| **H6** | Bookings became confirmed/reserved without payment | **CONFIRMED** | RC-1, RC-2 (`initial_status='reserved'`) |
| **H7** | Bookings were falsely labelled paid | **CONFIRMED at the presentation layer, REJECTED at the data layer.** No code writes `status='paid'` without a ledger row. But the UI says "Payment received", the panel says "Booking confirmed", and the Reports page books `total_amount` as revenue | RC-5 |
| **H8** | Checkout Sessions created but never completed | **REJECTED as the primary cause** — sessions were never created. Some abandoned `pending` rows will exist and must be classified `ABANDONED_CHECKOUT`, not counted as loss | §13 |
| **H9** | Checkout Sessions were never created | **CONFIRMED — this is the incident** | RC-1, RC-2 |
| **H10** | Production is using Test-mode credentials | **UNRESOLVED — REQUIRES PRODUCTION VERIFICATION.** `g2ab_stripe_test_mode` defaults to **1**. Would produce charges in the *test* dashboard, i.e. "no charges" in live | §15 check C2, CF-5 |
| **H11** | Production is connected to another Stripe account | **UNRESOLVED — REQUIRES PRODUCTION VERIFICATION.** Architecturally possible: booking, Memberistic and POS hold three independent key sets with no cross-check | §3.4, §15 check C4 |
| **H12** | Memberistic entitlement / Guest Pass bug zeroed the price | **PLAUSIBLE for the production-era build.** Memberistic < 1.19.0 auto-created Guest Pass memberships (per `DEPLOYMENT-1.10.0.md`); 1.18.6 has **no** `Entitlement_Service`, and `booking_type_is_included()` sets `total = 0.0` outright. Current 1.21.0 explicitly excludes `guest-pass`/`range-guest` (`class-entitlement-service.php:59-69`) | §15 check D3 |
| **H13** | Email-matching / member-attribution granted benefits to guests | **CONFIRMED for ≤1.9.9.16** | RC-4 |
| **H14** | The success URL is trusted without Stripe verification | **REJECTED.** `confirm_payment()` retrieves the session from the Stripe API, requires `client_reference_id === booking.uuid`, `payment_status === 'paid'`, and `mark_booking_paid()` re-validates amount, currency, mode, livemode, metadata and an existing attempt row (`class-payment-validator.php:86-152`). A crafted `?g2ab_paid=` cannot flip DB state | §11 |
| **H15** | Frontend JS displays success before server confirmation | **CONFIRMED for 1.9.9.16** (`frontend.js:66-68`); **fixed in 1.12.0** | RC-5.2 |
| **H16** | Cloudflare blocks Stripe callbacks | **UNRESOLVED — REQUIRES PRODUCTION VERIFICATION.** Would explain CASE A only | §12 |
| **H17** | Webhook signing secret does not match the active endpoint | **UNRESOLVED — REQUIRES STRIPE VERIFICATION.** Would surface as `400 Signature mismatch`. CASE A only | §15 check S4 |
| **H18** | Different production plugin versions are incompatible | **CONFIRMED as a real class of defect** — RC-4 is exactly a version-composition failure (booking 1.9.9.16 + Memberistic 1.18.6). Also: booking 1.12.0 requires Memberistic ≥1.19.0's `Entitlement_Service` for member $0 lanes; deploying them apart breaks member pricing | §4, VERSIONS.md install-order note |
| **H19** | A DB migration did not run after deployment | **UNRESOLVED — REQUIRES PRODUCTION VERIFICATION.** DB v1.5.3 (`wp_g2ab_webhook_events`) landed 2026-07-20; `payment_table_columns()` degrades silently | §4.5, §15 check D1 |
| **H20** | Booking types/events have incorrect allowed-gateway configuration | **CONFIRMED as sufficient on its own.** An empty `payment_modes` column defaults to `full,in_store` (`:470`) and, with the JS never sending `gateway`, routes every booking to pay-at-store even with Stripe healthy | RC-1 trigger 2, §15 check D2 |

---

## 8. Failure-class assignment

| Case | Description | Applies here? |
| --- | --- | --- |
| **A** | Stripe charged, local system failed to record | Only for the pre-2026-07-20 webhook outage, and possibly for Memberistic subscriptions. **Not** the reported symptom |
| **B** | Checkout created, customer never completed | Some `pending` rows. Legitimate abandonment; **not** loss |
| **C** | **Checkout Session was never created** | ✅ **THE INCIDENT** — RC-1, RC-2 |
| **D** | **Application selected pay-at-store / $0** | ✅ **THE INCIDENT** — RC-1, RC-2, RC-4 |
| **E** | Application falsely marked booking paid | Presentation layer only (RC-5). No DB row is `paid` without evidence |
| **F** | Wrong Stripe account / mode | **Unresolved** — H10, H11 |

---

## 9. Booking status state machine

`includes/services/class-booking-statuses.php` + `class-booking-transitions.php`
(1.12.0). Allowed edges (`class-booking-transitions.php:38-52`):

```
draft ─┐
pending ──▶ paid | confirmed | cancelled | expired | payment_failed
payment_failed ──▶ pending | paid | cancelled | expired
reserved ──▶ paid | confirmed | completed | cancelled | expired | no_show
confirmed ──▶ completed | cancelled | no_show | paid | refunded
paid ──▶ completed | cancelled | no_show | refunded | partially_refunded
expired ──▶ pending | paid | cancelled
```

### Every writer of `status = 'paid'`

| # | File:line | Function | Caller | Evidence required |
| --- | --- | --- | --- | --- |
| 1 | `includes/payments/class-stripe.php:546` | `mark_booking_paid()` | Stripe webhook + `confirm_payment()` | Full `G2AB_Payment_Validator` pass **and** an existing `wp_g2ab_payments` attempt row. ✔ |
| 2 | `includes/services/class-checkin-service.php:193-211` | `collect_payment()` | Front-desk REST, `manage_g2ab_bookings` | Inserts a `captured` ledger row **first**, then transitions. ✔ *(authorised `front_desk_payment` exception)* |
| 3 | `modules/woocommerce-bridge/class-woo-sync.php:102` | `on_paid()` | WC order-status hooks | `$order->is_paid()` + amount cross-check + ledger row + transition. ⚠ `is_paid()` is true for COD — see §3.6 |
| 4 | `modules/woocommerce-bridge/class-woo-sync.php:119` | same, fallback branch | only if `G2AB_Booking_Transitions` is missing | ⚠ **bypasses the invariant.** Dead in a correct install; should be removed |
| 5 | `includes/services/class-checkin-service.php:203-210` | same, fallback branch | only if `G2AB_Booking_Transitions` is missing | ⚠ same |

**The gate:** `G2AB_Booking_Transitions::check_invariants()` (`:189-208`) refuses
`paid` without `successful_ledger_entry()`, and refuses `confirmed` on a
`total_amount > 0` booking without one (`:210-228`). `skip_invariants` exists in
the context contract (`:85`) but **no caller in the entire repository passes it**
(verified by grep). ✔

**Verdict on H7 at the data layer: rejected.** The state machine is sound in
1.12.0. The incident is entirely upstream of it — bookings that never needed to
become `paid` because they were never priced as payable.

---

## 10. Pricing audit

`calculate_pricing()` (1.12.0 `:581-613`) pipeline:

```
base_price × party_size                       → subtotal
booking_type.member_discount %                → applied only if is_member_for_discount()
filter g2ab_booking_pricing                   → Memberistic plan rules / included types
  clamp: discount ≤ subtotal, total ≥ 0, percent ≤ 100, keys sanitised (:604-610)
→ total
→ G2AB_Checkout_Policy::resolve_lane()        → amount_due / payment_mode / initial_status
```

Guards present in **1.12.0 only**:

- A `$0` total on a **paid** booking type is rejected with `g2ab_pricing_invalid`
  (409) unless `entitlement.eligible && membership_source === 'memberistic'`
  (`class-checkout-policy.php:133-146`).
- An entitlement claiming eligibility for a **different** user id is discarded
  (`:110-116`).
- Amounts are validated a second time against Stripe:
  `expected_due_now_minor()` is compared to `session.amount_total` exactly
  (`class-payment-validator.php:118-123`).

**Per-booking comparison table** (`expected / calculated / stored total /
stored due_now / Stripe amount / actual charge`) **REQUIRES PRODUCTION
VERIFICATION** — `sql/03-pricing-comparison.sql`. For every RC-1/RC-2 row the
expected shape is: `total_amount = full price`, `metadata.due_now = 0`, Stripe
columns all NULL. Accidental 100% discounts (RC-4) instead show
`total_amount = 0`, `status='confirmed'`, `member_discount_percent = 100`.

---

## 11. Success-URL security (Phase 10)

**Verdict: the success URL is NOT trusted. H14 REJECTED. CONFIRMED FROM CODE.**

`success_url` is `…?g2ab_paid={uuid}&session_id={CHECKOUT_SESSION_ID}` plus a
confirm token. Visiting it only triggers client-side polling.

`POST /wp-json/g2a-booking/v1/bookings/{uuid}/confirm-payment`
(`class-bookings-controller.php:1253-1308`) enforces, in order:

1. UUID shape `^[a-f0-9-]{36}$` (`:1259`).
2. Rate limit 12 attempts / 5 min per identity+uuid (`:1262-1268`).
3. Confirm-token gate — hashed and stored in `metadata.confirm_token_hash`;
   mismatch → HTTP 403 (`:1290-1296`).
4. Gateway round-trip: `G2AB_Gateway_Stripe::confirm_payment()`
   (`class-stripe.php:287-301`) does a live `GET /v1/checkout/sessions/{id}`,
   rejects unless `client_reference_id === booking.uuid`, and only proceeds on
   `payment_status === 'paid'`.
5. `mark_booking_paid()` re-validates everything
   (`class-payment-validator.php:86-152`): `cs_(test|live)_…` shape, `mode=payment`,
   `payment_status=paid` **and** `status=complete`, `client_reference_id`,
   `metadata.booking_id` **and** `metadata.booking_uuid`, currency allow-list,
   `amount_total === expected_due_now_minor` (exact), `livemode` vs
   `g2ab_stripe_test_mode`, stored-attempt session match, and booking-state
   eligibility. Then a `FOR UPDATE` transaction and PaymentIntent-reuse
   detection (`class-stripe.php:493-502`).

**Crafted-success-URL attempts and their outcomes (CONFIRMED FROM CODE):**

| Attempt | Result |
| --- | --- |
| `GET /?g2ab_paid=<real-uuid>` with no token | JS polls `/status`; unverified → *"We're confirming your payment…"* then an explicit failure state with "Try payment again". **No DB change.** |
| `POST /confirm-payment` with no `confirm_token` on a booking that has one stored | `403 g2ab_invalid_confirm_token` |
| `POST /confirm-payment` with a valid token but a `session_id` from a different booking | `409 stripe_session_booking_mismatch` |
| Replaying another booking's genuinely paid session | `g2ab_payment_reference_mismatch` / `g2ab_payment_metadata_mismatch` |
| A real paid session for the right booking but the wrong amount | `g2ab_payment_amount_mismatch`, logged as `payment_manual_review` |
| A **test-mode** session while `g2ab_stripe_test_mode=0` | `g2ab_payment_mode_mismatch` |
| 13+ attempts in 5 minutes | `429` |

**But note the 1.9.9.16 regression:** the *display* lied (RC-5.2) even though the
data layer held. Customers were told "Payment received" on the failure path.

---

## 12. Cloudflare / infrastructure (Phase 15)

**REQUIRES PRODUCTION VERIFICATION — no live access.** What the code establishes:

- Both webhook endpoints are `permission_callback => '__return_true'` and rely
  entirely on signature verification, so they are safe to exclude from browser
  challenges — and **must** be, since Stripe cannot solve a challenge.
- The repository already documents that the origin does **not** restore visitor
  IPs — *"PHP sees Cloudflare's IP as the visitor"* (`docs/FORMISTIC_G2A_SETUP.md:115`).
  Verifyistic ≥1.4.6 adds Cloudflare-aware trusted-proxy resolution; earlier
  builds collapse rate-limit buckets onto shared edge IPs and can hard-403
  checkout for unrelated visitors (prior audit §3.2).
- A Cloudflare block on the webhook path produces **CASE A**, never a missing
  Checkout Session. **It cannot be the cause of the reported symptom.**

Checks: §15 I1-I4.

---

## 13. Database forensics (Phase 11) — queries, not results

All queries are **read-only `SELECT`s** and live in `sql/`. Adjust the `wp_`
prefix if production differs (check `$table_prefix` in `wp-config.php`).

| File | Purpose |
| --- | --- |
| `sql/00-schema-and-config-probe.sql` | Table prefix, DB version, presence of `wp_g2ab_webhook_events` / `wp_g2ab_email_log`, `wp_g2ab_payments` column set, all payment-relevant option rows **with secrets masked** |
| `sql/01-affected-bookings.sql` | Every web booking since 2026-07-20 routed to pay-at-store or zeroed without entitlement |
| `sql/02-paid-without-evidence.sql` | The runbook invariant: `status='paid'` with no successful ledger row — **must return 0** |
| `sql/03-pricing-comparison.sql` | Expected vs calculated vs stored vs due-now per booking |
| `sql/04-segment-counts.sql` | Counts since 2026-07-20 by type / status / payment_mode / gateway |
| `sql/05-ccw-forensics.sql` | The CCW/event population, masked |
| `sql/06-financial-impact.sql` | Exposure totals split into the four buckets of §14 |
| `sql/07-webhook-and-log-forensics.sql` | Webhook health options, `wp_g2ab_webhook_events`, `payment_manual_review` and gateway log entries since 2026-07-19 |
| `sql/08-reconciliation-export.sql` | Produces `payment-reconciliation-2026-08-19.csv` |

The headline query, reproduced here because it is the one the runbook already
declares must be empty:

```sql
SELECT b.id, b.uuid, b.customer_email, b.start_at, b.total_amount,
       b.status, b.payment_mode,
       p.id AS payment_id, p.gateway, p.transaction_id, p.status AS payment_status
  FROM wp_g2ab_bookings b
  LEFT JOIN wp_g2ab_payments p
    ON p.booking_id = b.id
   AND p.status IN ('succeeded','captured','paid','completed')
 WHERE b.status = 'paid'
   AND p.id IS NULL;
```

**Expected result under this incident: zero rows** (the state machine holds).
If it returns rows, a *second, more serious* defect exists and the priority
changes — treat it as a new P0.

The query that will not be empty:

```sql
SELECT id, uuid, customer_email, start_at, total_amount, status, payment_mode, source
  FROM wp_g2ab_bookings
 WHERE created_at >= '2026-07-20 00:00:00'
   AND source = 'web'
   AND payment_mode = 'in_store';
```

---

## 14. Financial impact (Phase 14) — method, not invented numbers

`sql/06-financial-impact.sql` splits every web booking created
2026-07-21 → today into four mutually exclusive buckets. **Only bucket 2 is
exposure.**

| Bucket | Definition | Treatment |
| --- | --- | --- |
| 1. Legitimate free | `total_amount = 0` **and** (`metadata.membership_source='memberistic'` or the booking type's `base_price = 0`) | **Not loss.** Exclude. |
| 2. **Uncollected payable** | `total_amount > 0` **and** `paid_amount = 0` **and** no successful `wp_g2ab_payments` row **and** `status IN ('reserved','confirmed','completed','no_show')` | **The exposure.** Collectible at the desk. |
| 3. Abandoned checkout | `status IN ('pending','expired','payment_failed')` with an `open`/`failed` Stripe attempt | **Not loss.** Normal funnel drop-off. |
| 4. Cancelled | `status='cancelled'` | Not loss. |

Report as: affected lane bookings / expected lane revenue / actually charged /
unpaid; the same three lines for events and classes; the same for CCW
specifically; then the total. **Confirmed loss is $0** — no money was captured
and mishandled. **Potentially collectible** is bucket 2. Cross-check bucket 2's
"actually charged" column against the Stripe balance report for the same period
before quoting any figure to the owner.

---

## 15. Exactly what to check on production — read-only, in order

Run in this order; the first three take under a minute and decide everything.

**Configuration (no secret values are printed by any of these):**

| # | Check | Command |
| --- | --- | --- |
| **C1** | Is the Stripe add-on registered? | `wp option get g2ab_addons_active --format=json` — **must contain `"stripe"`** |
| **C2** | Is Stripe enabled and in LIVE mode? | `wp option get g2ab_stripe_enabled` → must be `1`; `wp option get g2ab_stripe_test_mode` → must be `0` (**default is 1**) |
| **C3** | Are the keys present? Print **length and prefix only** | `wp eval 'foreach(["g2ab_stripe_secret_key","g2ab_stripe_publishable_key","g2ab_stripe_webhook_secret"] as $k){$v=(string)get_option($k);printf("%s: set=%s len=%d prefix=%s\n",$k,$v?"YES":"NO",strlen($v),substr($v,0,8));}'` |
| **C4** | Which Stripe account? Fingerprint only | In the Stripe dashboard, compare the `acct_…` id against the key prefix recorded in C3. Do **not** print key bodies. Confirm Memberistic (`memberistic_settings[stripe_mode]`, `[stripe_live_secret_key]` prefix) and POS (`g2a_pos_stripe['mode']`) resolve to the **same** account |
| **C5** | Do wp-config constants override the DB? | `wp eval 'foreach(["G2AB_STRIPE_SECRET","G2AB_STRIPE_PUBLISHABLE","G2AB_STRIPE_WEBHOOK_SECRET"] as $c){printf("%s: %s\n",$c,defined($c)?"DEFINED (overrides DB)":"not defined");}'` |
| **C6** | The prepay switch | `wp option get g2ab_require_public_prepay` → on 1.9.9.20 anything other than `1` means **every** public lane booking bypasses Stripe |
| **C7** | Event fallback switch | `wp option get g2ab_allow_event_pay_in_store_fallback` → must be `0`/absent |
| **C8** | Default gateway | `wp option get g2ab_payment_gateway_default` |

**Versions:**

| # | Check | Command |
| --- | --- | --- |
| V1 | Deployed versions | `wp plugin list --fields=name,status,version` |
| V2 | Code identity of the decisive file | `sha256sum wp-content/plugins/g2a-booking-engine/includes/rest/class-bookings-controller.php` and compare against the same file extracted from `archives/releases-legacy/g2a-booking-engine-1.9.9.20.zip` and `dist/g2a-booking-engine-1.12.0.zip` |
| V3 | Does the fix exist on disk at all? | `ls wp-content/plugins/g2a-booking-engine/includes/services/class-checkout-policy.php` — **absent ⇒ production predates 1.12.0 ⇒ the bypass is live** |

**Database:**

| # | Check | Command |
| --- | --- | --- |
| D1 | Did migrations run? | `wp option get g2ab_db_version`; `wp db query "SHOW TABLES LIKE '%g2ab_webhook_events'"`; `wp db query "SHOW COLUMNS FROM wp_g2ab_payments"` |
| D2 | Booking-type gateway config | `wp db query "SELECT id,name,slug,base_price,member_discount,payment_modes,members_only,is_active FROM wp_g2ab_booking_types"` — an **empty `payment_modes`** on a paid lane type is RC-1 trigger 2 |
| D3 | Guest Pass / entitlement config | `wp option get memberistic_lane_included_plan_slugs`; `wp db query "SELECT pl.slug, m.status, COUNT(*) FROM wp_memberistic_memberships m JOIN wp_memberistic_plans pl ON pl.id=m.plan_id GROUP BY 1,2"` |
| D4 | The runbook invariant | `sql/02-paid-without-evidence.sql` — must be empty |
| D5 | The affected population | `sql/01-affected-bookings.sql` |

**Stripe (dashboard, read-only):**

| # | Check |
| --- | --- |
| S1 | Payments → filter 2026-07-15 → today. Confirm the last successful lane charge and that no `cs_live_…` sessions exist for booking UUIDs after it |
| S2 | Developers → Events → `checkout.session.completed` since 2026-07-15. Absence of sessions (rather than unpaid ones) confirms **CASE C** |
| S3 | Developers → Webhooks → the booking endpoint: enabled/disabled, live/test, subscribed events (should be exactly `checkout.session.completed` + `charge.refunded`), last successful and last failed delivery, and the **response body** of the most recent failure |
| S4 | Same page: does the endpoint's signing secret match the value length/prefix recorded in C3? Roll it only after the audit, and update WordPress in the same window |
| S5 | Confirm the **Memberistic** endpoint `/wp-json/memberistic/v1/webhooks/stripe` exists at all — the prior audit found it was never added |
| S6 | Customers with **2+ active subscriptions** since 2026-07-10 (the double-charge trap from the prior audit, §2.2) — refund/cancel duplicates **manually** |

**Infrastructure:**

| # | Check |
| --- | --- |
| I1 | Cloudflare → Security → Events, filter path `/wp-json/g2a-booking/v1/webhooks/stripe` and `/wp-json/memberistic/v1/webhooks/stripe` since 2026-07-19 |
| I2 | Bot Fight Mode / Managed Challenge / Turnstile / rate-limit rules covering `/wp-json/*` |
| I3 | Cache rules — `POST` to `/wp-json/*` must never be cached |
| I4 | Is WP-Cron alive? `wp cron event list --fields=hook,next_run_relative | grep g2ab`; `wp eval 'var_dump(defined("DISABLE_WP_CRON") && DISABLE_WP_CRON);'` — a dead cron means holds never expire and reconciliation never runs |

**Logs:** grep the PHP error log, `wp-content/debug.log` and
`wp_g2ab_logs` from 2026-07-19 for: `stripe`, `checkout`, `gateway`,
`payment_intent_failed`, `payment_manual_review`, `pay_in_store`, `signature`,
`no_webhook_secret`, `gateway_not_loaded`, and HTTP `401 403 422 429 500 502 503`.
`sql/07-webhook-and-log-forensics.sql` does the DB half.

---

## 16. Affected customers

**REQUIRES PRODUCTION VERIFICATION.** `sql/08-reconciliation-export.sql`
produces `payment-reconciliation-2026-08-19.csv` with one row per booking and
the classification vocabulary of §17. Email addresses are masked in the exported
CSV (`a***@d***.com`) via the expression in that file; the unmasked join key is
`booking_uuid`. No card data, no Stripe secret and no full PII leaves the
database. The CSV template with its header row and worked example rows ships
alongside this report.

---

## 17. Reconciliation dataset (Phase 13)

Columns: `booking_id, booking_uuid, booking_type, customer, booking_created,
service_date, expected_amount, local_amount, payment_mode, local_status,
local_payment_status, stripe_checkout_session, stripe_payment_intent,
stripe_charge, stripe_status, stripe_amount, classification, action_required`.

| Classification | Meaning | Expected action |
| --- | --- | --- |
| `OK_PAID` | Ledger row + Stripe charge + amounts agree | none |
| `OK_MEMBER_FREE` | `total=0`, `membership_source='memberistic'` | none |
| `OK_CANCELLED` | cancelled/refunded | none |
| `ABANDONED_CHECKOUT` | `pending`/`expired` with an unpaid session | none — not loss |
| `PAY_IN_STORE` | `in_store` created by **staff** (`source` in admin/frontdesk/pos/phone) | collect at desk as designed |
| **`MISSING_STRIPE_SESSION`** | **`source='web'`, payable, no session — RC-1/RC-2** | **contact customer; offer a payment link; collect at check-in** |
| `STRIPE_SESSION_UNPAID` | Session exists, `payment_status != paid` | expire the hold |
| `STRIPE_PAID_LOCAL_NOT_UPDATED` | Charge exists, booking not `paid` — **CASE A** | replay the webhook / `wp g2ab stripe-reconcile` |
| `LOCAL_PAID_NO_STRIPE` | `status='paid'`, no Stripe charge, no non-Stripe ledger row | **escalate — new P0** |
| `WRONG_AMOUNT` | Charge ≠ expected | manual review |
| `WRONG_GATEWAY` | Settled through a gateway that took no money (e.g. WC COD) | manual review |
| `MANUAL_REVIEW` | anything else | triage |

---

## 18. Security findings

| # | Finding | Severity | Status |
| --- | --- | --- | --- |
| SEC-1 | 1.9.9.16 tells any visitor of `?g2ab_paid=<uuid>` that payment was received (`frontend.js:66-68`) | Medium — misrepresentation, no state change | Fixed in 1.12.0 |
| SEC-2 | Guest priced as a member by typing that member's email (RC-4) | High — free/discounted service + membership-status disclosure via price | Fixed in 1.9.9.20; hardened in 1.12.0 |
| SEC-3 | `g2ab_stripe_publishable_key` matches the generic `_key` branch in `handle_save()` but is **not** in `SECRET_OPTIONS`, so an empty submit wipes it (`class-settings-pro.php:656-660`) | Low | Open — add it to the keep-on-empty list |
| SEC-4 | `class-woo-sync.php:119` and `class-checkin-service.php:203-210` write `status='paid'` directly when `G2AB_Booking_Transitions` is absent | Low (dead in a correct install) | Open — delete the fallbacks |
| SEC-5 | Memberistic webhook returns 200 unconditionally in <1.18.3, suppressing Stripe retries | Medium | Fixed 1.18.3 |
| SEC-6 | Verifyistic rate-limit buckets on shared Cloudflare edge IPs (<1.4.6) | Medium — denial of checkout | Fixed 1.4.6 |
| SEC-7 | No audit-log entry is written when the Stripe add-on is toggled or `g2ab_stripe_enabled` changes | Medium — the switch that caused a 30-day revenue outage leaves no trace | **Open** |

No secret value, card number or full customer identifier appears anywhere in
this report or in the generated CSV.

---

## 19. Remediation — see `FIX-PLAN.md`

Ordered P0 → P5, with file, function, current behaviour, correct behaviour,
proposed change, risk and required tests for each item.

## 20. Test plan — see `FIX-PLAN.md` §P4 and `tests/unit/PaymentIntegrityRegressionTest.php`

The 20-scenario regression matrix is implemented as runnable PHPUnit tests in
`plugins/g2a-booking-engine/tests/unit/PaymentIntegrityRegressionTest.php`
(`vendor/bin/phpunit`). Scenarios that need a live gateway are listed there as
the manual staging checklist.

## 21. Deployment, rollback and monitoring — see `FIX-PLAN.md` §§P2, P5, Deployment

---

## 22. What must NOT be done

- **Do not auto-charge any historical customer.** No stored card, no saved
  PaymentMethod, no off-session PaymentIntent, no "retry" against any past
  booking. Every affected customer was told they owed nothing at the time of
  booking; charging them now without contact is both a chargeback generator and
  a legal problem. Generate the list, contact people, collect at the desk.
- Do not resend live Checkout Sessions to old bookings in bulk.
- Do not refund or cancel anything before the reconciliation CSV is reviewed —
  except the duplicate subscriptions from the July-19 incident (S6), which are
  actively over-billing customers and should be handled manually and
  individually.
- Do not mass-update booking statuses to "paid" to make the reports look right.
- Do not rotate the Stripe webhook signing secret before §15 S3/S4 are recorded —
  you would destroy the evidence that settles H1 and H17.
- Do not delete the affected `reserved`/`in_store` rows. They are the customer
  list.
- Do not deploy 1.12.0 without Memberistic ≥1.19.0 in the same window — member
  $0 lanes depend on Memberistic's `Entitlement_Service` (H18).
- Do not enable `g2ab_require_public_prepay` and call it fixed. It is a mitigation
  for 1.9.9.20 only, it does not exist in 1.12.0, and it does not close RC-2 or
  RC-4.
