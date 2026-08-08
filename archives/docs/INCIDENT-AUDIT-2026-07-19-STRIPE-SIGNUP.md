# G2A Incident Audit — Stripe webhook disabled + membership signups broken — 2026-07-19

Read-only code audit of the repository against three reported symptoms. No live
systems were touched, no secret values read. **This branch contains the report
only — no code fixes** (each recommended fix is listed with exact file:line so
they can be implemented and signed off deliberately). Production deploys are
manual zip uploads, so nothing here changes live behavior until a release is
built and uploaded.

Symptoms reported:

1. **Stripe email**: the live-mode webhook endpoint
   `https://guns2ammo.com/wp-json/g2a-booking/v1/webhooks/stripe` failed for
   nine consecutive days (≈ since Jul 10) — *"132 requests had other errors"* +
   *"2 requests timed out"* — and Stripe has **disabled the endpoint**.
2. **Membership signups don't activate** — people sign up (and are charged by
   Stripe) but their account/membership never changes.
3. **Other customers trying to sign up get "too many attempts"** errors.

---

## Executive summary

**This is one story, not three.** The site runs **two** Stripe consumers — the
booking engine and Memberistic (memberships) — but only **one** webhook endpoint
(the booking one) has ever been documented or configured in the Stripe
dashboard. Membership activation is **webhook-only** and its events had nowhere
to go. Meanwhile the booking endpoint itself cannot succeed: a header-name bug
makes **every** delivery fail signature lookup with HTTP 400, and a July 11
change added a second failure mode (HTTP 422 for any event the plugin doesn't
own). Nine days of guaranteed non-2xx responses is precisely Stripe's
auto-disable condition. Customers whose paid memberships never activated
retried signup — burning per-IP rate-limit buckets that (thanks to a missing
Cloudflare trusted-proxy config) are **shared between unrelated customers** —
producing the "too many attempts" wall for everyone else.

| # | Symptom | Root cause(s) | Confidence |
|---|---------|---------------|------------|
| 1 | Stripe disabled the webhook | **(a)** `Stripe-Signature` header lookup can never match → 400 on every delivery (`class-stripe.php:184`); **(b)** since Jul 11, HTTP 422 for every event the booking engine doesn't own (`class-webhooks-controller.php:79-81`); **(c)** the retry-dedup marks events consumed *before* processing, so Stripe retries can't recover (`functions.php:199`) | (a),(b),(c) confirmed in code; which one Stripe recorded is settled by the delivery log (see §1.5) |
| 2 | Memberships never activate | Activation (and even WP-user creation) happens **only** when `checkout.session.completed` reaches `memberistic/v1/webhooks/stripe` — an endpoint **no doc ever instructs adding to Stripe**; the booking endpoint 422s those events instead. No thank-you-page fallback, no pending-row reconciliation exists. | Confirmed in code; endpoint presence needs a dashboard look |
| 3 | "Too many attempts" at signup | **(a)** Memberistic checkout throttle: 8 attempts/10 min per IP, charged even on success and *before* the age-gate check, failing **closed** on DB-lock timeouts (`class-stripe-service.php:530-540`); **(b)** Verifyistic age-gate limiter buckets collapse onto shared Cloudflare edge IPs because its trusted-proxy list is empty (`class-verifyistic-security.php:218-252`) — its exact string is *"Too many attempts. Please wait a few minutes and try again."*; **(c)** symptom 2 feeds both: stuck-pending customers retry and drain the buckets | Confirmed in code; which limiter fired is settled by one log grep (§3.4) |

**Money-risk callout (act first):** customers who paid but stayed "pending" and
retried signup were sent to a **second live Stripe subscription** — the code
reuses the pending row but always creates a fresh Checkout session
(`class-stripe-service.php:595-606`), and a later activation overwrites
`stripe_subscription_id`, orphaning the first still-billing subscription
(`:1105-1114`). Search Stripe for customers with 2+ active subscriptions since
Jul 10 and refund/cancel duplicates before anything else (§6 step 0).

---

## 1. Why Stripe disabled the booking webhook

Four independent defects stack on this one endpoint. Any one of them alone
produces a persistent failure stream.

### 1.1 CRITICAL — the signature header can never be found → 400 on every delivery

`G2AB_REST_Webhooks_Controller::dispatch()` flattens
`WP_REST_Request::get_headers()` and hands it to the gateway
(`g2a-booking-engine/includes/rest/class-webhooks-controller.php:51-56`).
WordPress canonicalizes REST header keys to lowercase-with-underscores, so the
key arrives as **`stripe_signature`**. The gateway then searches for the
hyphenated form with a strict comparison:

- `g2a-booking-engine/includes/payments/class-stripe.php:183-189` —
  `if ( 'stripe-signature' === strtolower( $k ) )` — `stripe_signature` never
  matches, `$sig_header` stays empty, and line 189 returns
  `WP_Error('no_signature', 'Missing Stripe-Signature header.', 400)`.

**Every live delivery — any event type, any configuration — returns HTTP 400.**
This shipped with the 1.9.9.x production line (in every zip from at least
1.9.9.10 through 1.9.9.14; 1.9.9.14 is byte-identical to repo HEAD), and it
retroactively explains the Jul 8 audit's Issue B ("Stripe payments not
reflecting; webhook is the only paid path"). Memberistic does this correctly by
reading `$_SERVER['HTTP_STRIPE_SIGNATURE']` directly
(`class-memberships-controller.php:1606`). The Fortis gateway has the identical
bug (`class-fortis.php:200`).

> **Fix:** read the canonicalized key (`$headers['stripe_signature']`, keeping
> the hyphenated form as fallback) or use `$_SERVER['HTTP_STRIPE_SIGNATURE']`
> like Memberistic. Apply the same to Fortis.

### 1.2 Since Jul 11 — HTTP 422 for every event the plugin doesn't own

Commit `ddb2b63` (Jul 11, "Round 3") changed the controller to return **422**
whenever `process_webhook_event()` reports `handled: false`
(`class-webhooks-controller.php:79-81`). The Stripe gateway marks
`handled: true` for exactly three cases (`class-stripe.php:222-242`):
`checkout.session.completed` with `payment_status=paid` **and** a matching
booking, matched `charge.refunded`, and `payment_intent.succeeded` (no-op ack).
Everything else 422s, including:

- `checkout.session.completed` with **no booking uuid** — i.e. **every
  Memberistic membership checkout** (`class-stripe.php:253-254`, reason
  `no_uuid`), and any session whose booking was already expired/deleted
  (`:258`, `booking_not_found` — a real race with the expiry cron);
- all `invoice.*` / `customer.subscription.*` (membership renewals),
  `checkout.session.expired`, `payment_intent.created/payment_failed`,
  `charge.succeeded/updated`;
- **every** `charge.refunded`, due to defect §4.2.

The zip archaeology confirms the regression's reach: the zip built **Jul 10**
(1.9.9.4) still returned 200 for unhandled events; the zip built **Jul 11**
(1.9.9.7, from `ddb2b63`) contains the 422, as does every booking zip currently
in `releases/`. The onset of Stripe's nine-day failure streak matches the
Jul 10–11 deploy window either way (see §1.5 for how to tell 1.1 vs 1.2 apart).

> **Fix:** acknowledge verified-but-not-ours events with 200 (`not_a_booking_
> session` for sessions without a booking uuid; plain ack for foreign event
> types). Reserve non-2xx for genuinely retryable states. Without this, the
> endpoint will be disabled again within days of re-enabling.

### 1.3 The dedup poisons Stripe's retries (makes 1.2 pointless)

`g2ab_webhook_event_is_new()` marks the event id consumed via `set_transient`
**at check time**, before any processing
(`g2a-booking-engine/includes/helpers/functions.php:195-200`; called at
`class-stripe.php:227-230`). So the first delivery of an unhandled event 422s
*and* consumes the id; Stripe's retry then short-circuits to
`duplicate_event → handled:true → 200` without re-running anything. The 422's
stated purpose (comment at `class-webhooks-controller.php:65-74`: "the gateway
keeps retrying, giving a transient race a chance to resolve") is structurally
impossible: retries never reprocess. Net effect: +1 permanent failure per event
in Stripe's health stats, zero recovery. (The Jul 8 audit flagged this same
trap; `ddb2b63` didn't touch it.)

> **Fix:** split "seen" from "processed" — only mark consumed after
> `handled === true` (or delete the transient when returning non-2xx).

### 1.4 The two timeouts — synchronous PDF/email work inside the webhook request

`mark_booking_paid()` fires `g2ab_payment_succeeded` in-request
(`class-stripe.php:327`), which triggers synchronous PDF invoice generation —
Dompdf render, mPDF fallback, even a `shell_exec('which wkhtmltopdf')`
(`class-pdf-invoices.php:33` → `class-invoice-engine.php:55-101`). On a slow
host that exceeds Stripe's delivery timeout. Memberistic similarly sends 2–3
emails synchronously per activation (`class-stripe-service.php:1144-1162`).

> **Fix:** return 2xx first, defer side effects via
> `wp_schedule_single_event()` — Stripe's own guidance.

### 1.5 Which defect did Stripe actually record? (one look settles it)

Open the disabled endpoint's **delivery attempts log** in the Stripe dashboard:

| Response seen | Live cause |
|---|---|
| `400` body `Missing Stripe-Signature header.` | §1.1 (header bug) — expected for any 1.9.9.x build |
| `422` body `{"ok":false, ...}` | §1.2 (422 regression) — implies a build ≥1.9.9.7 *and* that 1.1 was somehow bypassed (unlikely) |
| `400` `no_webhook_secret` / `Signature mismatch` | blank/wrong `g2ab_stripe_webhook_secret` (keep-on-empty trap from the Jul 8 audit) |
| `503 gateway_not_loaded` | 'stripe' addon toggled off (`class-manager.php:36-41`) — settings-disabled/empty keys do **not** cause 503 |
| Cloudflare challenge/1xxx page | WAF blocking Stripe's IPs — fix at Cloudflare, not in code |

Also check the endpoint's **"Listening to" event list**: the booking settings
UI and docs never state which events to subscribe
(`class-settings-pro.php:303`), so "all events" was plausibly selected — which
would guarantee a failure stream from membership/renewal events even after 1.1
is fixed. The correct list for this endpoint is exactly
`checkout.session.completed` + `charge.refunded`.

---

## 2. Why membership signups "don't get changed"

### 2.1 Activation — and even account creation — is webhook-only, and the webhook had nowhere to deliver

The signup flow (`[memberistic_checkout]` → POST
`/?memberistic_checkout_handler=1` → `Stripe_Service::handle_checkout_request`)
creates a membership row with `status='pending'` and redirects to Stripe
Checkout (mode=subscription). **The customer is charged immediately.** Locally,
nothing else happens until `checkout.session.completed` reaches
`POST /wp-json/memberistic/v1/webhooks/stripe`
(`class-memberships-controller.php:605-613`), whose handler
(`class-stripe-service.php:1080-1167`) is the **only** code that:

- creates/links the WP user (`ensure_user_for_completed_checkout`, `:1097` —
  deliberately deferred to post-payment), sending the set-password email;
- flips the row `pending → active`, stores
  `stripe_customer_id`/`stripe_subscription_id`;
- writes the payment row, sends activation/receipt emails, and fires
  `memberistic_membership_activated` (role sync → `memberistic_member` etc.).

There is **no fallback**: the thank-you page is static (the theme prints
"YOU'RE IN." — `template-thank-you.php:16` — and the shortcode ignores the
`membership_id` query arg, `class-shortcodes.php:221-227`); no cron ever
touches `pending` rows (the daily reconciliation only examines **active** rows
past their renewal date, `class-scheduler.php:167-246`).

Meanwhile **every repo checklist names only the booking endpoint** for the
Stripe dashboard (`docs/SYSTEM_WORKFLOW_v1.12.2.md:264`,
`docs/INCIDENT-AUDIT-2026-07-08.md:128`). Memberistic's own README/INSTALL do
document its URL and required events (`docs/INSTALL.md:51-60`: `checkout.
session.completed`, `invoice.payment_succeeded`, `invoice.payment_failed`,
`customer.subscription.deleted`), but no operator-facing runbook ever included
it. Membership sessions carry only `metadata.membership_id` — no
`client_reference_id` (`class-stripe-service.php:772-788`) — so when they land
on the booking endpoint they are `no_uuid` → 422 (§1.2), feeding symptom 1.

**Result:** charged customer, "YOU'RE IN." on screen, no WP account, no
password email, no role, row pending forever, Stripe billing monthly.

### 2.2 The double-charge trap on retry

A paid-but-pending customer who tries again is matched by the pending-reuse
guard (`class-stripe-service.php:595-606`) — which reuses the row but creates a
**new Checkout session**. Paying it creates a **second live subscription**.
Whichever session's webhook eventually processes overwrites
`stripe_customer_id`/`stripe_subscription_id` (`:1111-1112`), orphaning the
other subscription (it keeps billing with no local record). The account page
offers no "complete payment" path for `pending` (banner only covers
`past_due`/`expired`, `templates/account.php:135-145`) — while greeting the
member with "Your range access is active" (`:192`).

> **Fix:** store the Checkout session id on the pending row; on reuse, retrieve
> it first and activate if `payment_status=paid` instead of re-charging; on
> activation, cancel any sibling subscriptions with the same
> `metadata.membership_id`/email.

### 2.3 Memberistic's own webhook defects (matter as soon as its endpoint exists)

1. **PHP fatal on lock contention** —
   `class-stripe-service.php:929` does `return new WP_Error(...)` inside
   `namespace WordPressistic\Memberistic\Payments` with no `use WP_Error;`
   import → resolves to a nonexistent namespaced class → fatal → HTTP 500.
   Triggered exactly by Stripe's documented near-simultaneous duplicate
   deliveries (3s `GET_LOCK` timeout, `:1262-1268`). Every other `WP_Error` in
   the file is correctly `\WP_Error`. One-character fix; shipped since 1.12.0
   (`aed2e1c`, Jul 12).
2. **Marked processed before dispatch** — `mark_event_processed()` runs at
   `:936`, *before* the type switch at `:950`. A handler that fails mid-way
   permanently consumes the event; Stripe's retry is absorbed as duplicate
   (`:932-934`).
3. **Always 200** — the REST callback ignores the handler's return value and
   answers `{received:true}` regardless (`class-memberships-controller.php:
   1622-1624`), so genuinely failed activations suppress Stripe's retry
   machinery. (Flip side, confirmed negative result: this is why the
   memberistic endpoint *cannot* be the one Stripe disabled.)
4. **Renewal matching is fragile** — `handle_invoice_succeeded` reads
   `$invoice['subscription']` only (`:979`) and matches only by
   `stripe_subscription_id` (`:985`). On Stripe API version 2025-03-31+ the
   field moved under `parent.subscription_details`, and there is no
   `metadata.membership_id` fallback — renewals would silently no-op. Pin the
   endpoint's API version or fix the field read when creating the endpoint.
5. **Reconciliation can wrongly downgrade** — with renewals not arriving,
   active members drift past `renewal_date`; the daily cron then asks the
   Stripe API, but if Stripe is disabled/unreachable it returns null and after
   a 3-day grace flips genuinely-paid members to `past_due` with a
   "payment failed" email (`class-scheduler.php:189-218`). Never downgrade on
   an inconclusive lookup.
6. **Role-sync ordering** — role sync runs at priority 10 on
   `memberistic_membership_activated` but `Account_Provisioner` creates the
   user at priority 20 (`class-content-restrictions.php:39` vs
   `class-account-provisioner.php:44`), so activation paths that don't pre-link
   a user (WooCommerce bridge, manual REST renew/upgrade) yield active members
   **without** the member role. Move role sync after provisioning.

---

## 3. Why customers see "too many attempts" at signup

### 3.1 Memberistic's checkout throttle (fires on the exact signup action)

`handle_checkout_request` charges a per-IP bucket **before any validation, the
age-gate check, or Stripe work**: 8 attempts / 10 min → `wp_die` HTTP 429
*"Too many checkout attempts. Please wait a few minutes and try again."*
(`class-stripe-service.php:530-540`). Defects:

- **Successful checkouts count** (never decremented) — a family signing up on
  one home/shop Wi-Fi, or in-store signups behind the range's NAT, hit the cap
  with fully legitimate traffic.
- **Fails closed**: `atomic_check_and_increment` returns "over limit" when the
  3-second MySQL `GET_LOCK` times out (`:1240-1255`) — DB contention alone
  produces 429s with empty buckets.
- **Charged before the age-gate 403** (`:569-582`), so a customer blocked by
  the Verifyistic gate burns budget on every attempt, then the error *morphs*
  into "too many checkout attempts".
- **Fed by symptom 2**: stuck-pending customers retrying signup are the natural
  bucket-drainers.

### 3.2 Verifyistic's shared-edge-IP buckets (the verbatim message, sitewide)

The age-gate popup renders on **every** front-end page — including
`/memberships/` and `/checkout/` — for every unverified visitor
(`class-verifyistic-frontend.php:8,194-197`; no page targeting). Its exact
string *"Too many attempts. Please wait a few minutes and try again."* is
returned both by the verify handler and the token minter
(`class-verifyistic-ajax.php:37,48,69`). The buckets:

- 15 failures / 15 min (`vfy_rl_`), 30 token mints / 15 min (`vfy_mint_`) — and
  the JS mints **one token per page view** per unverified visitor
  (`frontend.js:27`), plus one per failed submit and per 403-retry.
- `client_ip()` only honors `CF-Connecting-IP` when `REMOTE_ADDR` is in a
  trusted-proxy allowlist that is **empty by default and seeded nowhere**
  (`class-verifyistic-security.php:218-252`). The repo's own docs confirm the
  origin has no IP restore — *"PHP sees Cloudflare's IP as the visitor"*
  (`docs/FORMISTIC_G2A_SETUP.md:115`) — and Formistic received exactly this fix
  (Cloudflare CIDRs seeded in `class-formistic-g2a-defaults.php:93,223`).
  **Verifyistic never did.**

So all Verifyistic buckets key on shared Cloudflare **edge** IPs: ~30 anonymous
page views, or 15 failed/bot age submissions, per edge IP per 15 minutes —
trivially reached by *unrelated* visitors — lock the gate for whole cohorts.
Because Memberistic hard-403s checkout when the gate isn't passed
(`class-verifyistic-bridge.php:206-228`, on by default when the integration is
enabled), a tripped gate = signups impossible.

### 3.3 Same bug class elsewhere (latent, fix in the same pass)

Raw-`REMOTE_ADDR` shared buckets: memberistic guest waiver 8/hr
(`class-waivers.php:882-896`), all advanced-ffl-checkout limits (portal 5/min,
onboarding 3/hr, dealer search 30/min — `class-wpistic-ffl-token.php:278`
default-empty allowlist), POS staff login lockout per username+edge-IP
(`AuthController.php:10-21`). The booking engine and theme login throttle trust
`CF-Connecting-IP` and are per-visitor (but spoofable if origin is directly
reachable). The booking engine has a separate never-cleared cap of 5
successful guest bookings/IP/hour (`class-bookings-controller.php:134-136`) —
group outings from one venue Wi-Fi will trip it.

### 3.4 Which limiter fired? (one grep settles it)

The exact message text identifies the source:

| Message | Source |
|---|---|
| "Too many **checkout** attempts…" | Memberistic throttle (§3.1) — grep access log for 429 on `POST /?memberistic_checkout_handler=1` |
| "Too many attempts. Please wait a few minutes and try again." | Verifyistic (§3.2) — 429s on `admin-ajax.php?action=verifyistic_token`; also `SELECT` transients `_transient_vfy_rl_%` / `_transient_vfy_mint_%` — few rows with high counts = shared-bucket collapse confirmed |
| "Too many failed sign-in attempts…" | theme wp-login throttle (`guns2ammo/inc/login.php:210-221`) — sign-**in**, not signup |
| "Too many **bookings** from this network…" | booking success cap (`class-bookings-controller.php:135`) |

---

## 4. Booking-side collateral (now that the endpoint is disabled)

1. **Charged-but-expired bookings.** The only remaining paid-path is the
   one-shot client-side return-page confirm
   (`frontend.js:34-77` → `POST /bookings/{uuid}/confirm-payment` →
   `confirm_payment`, `class-stripe.php:131-142`). If the customer closes the
   tab / JS fails, the 5-minute expiry cron flips the pending booking to
   `expired` after 15 minutes (`class-booking-expiry-cron.php:42-66`) and
   releases the slot — customer charged, no reservation, double-booking risk.
   Rows are **not** deleted; `mark_booking_paid` only skips `paid/completed`
   (`class-stripe.php:261-263`), so a manual replay can still rescue them (§6).
2. **Refunds have never synced.** Payment rows store the Checkout **session**
   id (`cs_…`) as `transaction_id` (`class-stripe.php:105`), but
   `mark_booking_refunded` searches it for the **payment_intent** id (`pi_…`,
   `:335-341`) — can never match → every `charge.refunded` was `handled:false`.
   Store the `payment_intent` id at mark-paid time.
3. **Paid emails/SMS never send even with a healthy webhook.** The Stripe path
   fires `g2ab_payment_succeeded` + `g2ab_booking_status_changed`
   (`class-stripe.php:327-328`) but the paid-confirmation email, Messageistic
   SMS, and corporate guest enrollment all listen to **`g2ab_booking_paid`**,
   which only the Woo bridge and front-desk check-in fire. Fire
   `g2ab_booking_paid` in `mark_booking_paid` (idempotently) or re-subscribe
   the listeners.
4. **Clock-skew footnote:** the signature tolerance rejects timestamps >10s in
   the future (`class-stripe.php:204`); a server clock >10s slow would 400
   everything. Use a symmetric ±5 min like Memberistic (`:857`). Check NTP.

---

## 5. Deployment traps that affect remediation

- **What's live is unknown.** Manual zip deploys; verify with
  `https://guns2ammo.com/?g2ab_version_check=1` (admin) and
  `wp plugin list` before anything else. All booking zips currently in
  `releases/` (1.9.9.10 → 1.9.9.14) carry the 422 regression *and* the header
  bug; the last pre-422 build (1.9.9.4, Jul 10) still had the header bug.
- **Downgrade trap.** Memberistic's version line went 1.46.0 → 1.9.9.5 →
  1.10.x → 1.18.x; a site installed from the Jul 5 zips sees every later zip
  as a downgrade (WP warns; `version_compare`-gated routines silently skip).
- **Unshipped fixes.** Memberistic **1.18.2** (fixes a confirmed live breakage:
  duplicate `*-main` plugin folder → headers-already-sent breaking
  checkout/check-in/waivers) and Verifyistic **1.4.5** exist in source only —
  **no zip was ever built**. Also check production `wp-content/plugins/` for a
  stray `memberistic-membership-solutions-main` folder.
- **Zip-overwrite never fires activation hooks** (caps/cron registered only on
  activation — the Jul 15 audit's G2A-CRIT-005): after the next upload,
  deactivate/reactivate booking engine + memberistic once, and verify
  `wp cron event list | grep memberistic`.
- `INSTALL.md`/`RELEASE-2.1.0.md` version tables are stale (name 1.18.0/3.3.0
  as current) and the "keep two zips" policy is violated (8 memberistic zips) —
  a manual uploader can easily grab a stale zip.

---

## 6. Recovery runbook (ordered)

**Step 0 — stop the bleeding (Stripe dashboard, ~30 min, no code):**
1. Find customers double-charged since Jul 10: Stripe → search for customers
   with 2+ active subscriptions / duplicate `metadata.membership_id`. Cancel
   duplicates, refund as appropriate (§2.2).
2. Open the disabled endpoint's delivery log and record the response codes and
   the "Listening to" event list — this confirms §1.5 and scopes the backfill.
3. Inventory stuck records:
   `SELECT id,status,created_at FROM wp_memberistic_memberships WHERE
   status='pending' AND created_at > '2026-07-01';` and cross-check emails
   against Stripe subscriptions. Same for bookings:
   `SELECT b.id,b.status,p.transaction_id FROM wp_g2ab_bookings b JOIN
   wp_g2ab_payments p ON p.booking_id=b.id WHERE p.gateway='stripe' AND
   p.status='pending' AND b.created_at > '2026-07-09';` — cross-check `cs_…`
   ids against Stripe for `payment_status=paid` (charged-but-expired customers
   to rescue/refund).

**Step 1 — code fixes (build booking-engine 1.9.9.15 + memberistic 1.18.3 + verifyistic 1.4.6 zips):**
- Booking: header lookup fix (§1.1, + Fortis); 200-ack for foreign events
  (§1.2); mark-consumed only after success (§1.3); store `payment_intent` id
  for refund matching (§4.2); fire `g2ab_booking_paid` (§4.3); defer PDF/email
  side effects (§1.4); symmetric signature tolerance (§4.4). Add a server-side
  reconciliation cron for pending/expired bookings with a `cs_` payment row.
- Memberistic: `\WP_Error` fatal fix (§2.3.1); mark-processed-after-success +
  non-2xx on handler failure (§2.3.2-3); invoice `parent.subscription_details`
  fallback + `metadata.membership_id` fallback (§2.3.4); pending-row
  reconciler + thank-you-page server-side session confirm (§2.1); pending-reuse
  session re-check to kill the double-charge (§2.2); never downgrade on
  inconclusive reconcile (§2.3.5); role-sync priority (§2.3.6); checkout
  throttle: count failures only, fail open on lock timeout (§3.1); check
  `signup_blocked()` before charging the bucket.
- Verifyistic: seed Cloudflare trusted proxies exactly like Formistic's
  defaults (§3.2), or define `VERIFYISTIC_TRUSTED_PROXIES` in `wp-config.php`.
- Theme: extend no-cache headers to checkout/memberships/renewal/thank-you
  templates (the Jul 8 hardening covered only login/account —
  `guns2ammo/inc/login.php:56`; the checkout template bakes a nonce into
  cacheable HTML, `templates/checkout.php:68`, and a stale/uid-mismatched
  nonce kills signups with "Checkout request could not be verified").

**Step 2 — deploy** (upload zips; deactivate/reactivate the two plugins once;
delete any stray `*-main` plugin folder; re-run `?g2ab_version_check=1`).

**Step 3 — Stripe dashboard config (the permanent fix):**
- **Re-enable** `https://guns2ammo.com/wp-json/g2a-booking/v1/webhooks/stripe`
  with events trimmed to exactly `checkout.session.completed` +
  `charge.refunded`. Its `whsec_…` goes in **`g2ab_stripe_webhook_secret`**
  (Bookings → Settings) only.
- **Create** `https://guns2ammo.com/wp-json/memberistic/v1/webhooks/stripe`
  with events `checkout.session.completed`, `invoice.payment_succeeded`,
  `invoice.payment_failed`, `customer.subscription.deleted`; pin an API version
  the code understands (≤2024-xx) until §2.3.4 ships. Its **different**
  `whsec_…` goes in Memberistic → Settings → Webhook secret only. Never swap
  the two secrets.
- Send a test event to each; expect 2xx in the delivery log.

**Step 4 — backfill:** from each endpoint's delivery log, "Resend" the missed
events from the outage window (memberistic first — activations create users);
manually activate any pending membership whose subscription is active in
Stripe; replay `confirm-payment` (or `mark_booking_paid` via wp-cli) for
charged-but-expired bookings; then re-check step 0.3 queries return empty.

**Step 5 — monitoring:** implement the `stripe_webhook_silent` alert for real —
it currently exists **only in dashboard mock data**
(`dashboard-app/src/mocks/data.ts:290`); the backend health check reports
"Stripe: configured" from key presence alone
(`class-system-health-provider.php:210-215`), which is why nine days of
failures were invisible. Record a last-webhook-received timestamp in both
plugins and alert when silent >24h while Stripe is enabled. Also verify NTP,
and confirm no Cloudflare WAF rule challenges `/wp-json/*/webhooks/stripe`.

---

## Appendix — finding index

| Finding | File:line | Severity |
|---|---|---|
| Stripe-Signature header lookup never matches → 400 all deliveries | `g2a-booking-engine/includes/payments/class-stripe.php:184` | Critical |
| 422 for every foreign/unmatched event (since Jul 11) | `g2a-booking-engine/includes/rest/class-webhooks-controller.php:80` | Critical |
| Membership activation webhook-only; endpoint never in any runbook; no fallback | `memberistic-membership-solutions/includes/payments/class-stripe-service.php:1097`, `class-memberships-controller.php:607` | Critical |
| Event id consumed before processing (booking) — retries poisoned | `g2a-booking-engine/includes/helpers/functions.php:199` | High |
| Pending-reuse → second live subscription (double charge) | `class-stripe-service.php:595-606, 1105-1114` | High |
| Unqualified `new WP_Error` → fatal in webhook lock path | `class-stripe-service.php:929` | High |
| Marked processed before dispatch + always-200 (memberistic) | `class-stripe-service.php:936`, `class-memberships-controller.php:1622` | High |
| Verifyistic buckets on shared CF edge IPs (empty trusted-proxy list) | `verifyistic/includes/class-verifyistic-security.php:218-252` | High → Critical behind CF |
| Checkout throttle counts successes, fails closed, charged pre-age-gate | `class-stripe-service.php:530-540, 1240-1255` | High |
| Expiry cron flips charged bookings to expired in 15 min | `g2a-booking-engine/includes/cron/class-booking-expiry-cron.php:42` | High |
| Refund matching cs_ vs pi_ — refunds never sync | `class-stripe.php:105` vs `:335-341` | High |
| Checkout page missed by no-cache hardening (baked nonce) | `memberistic templates/checkout.php:68`, `guns2ammo/inc/login.php:56` | High |
| Reconcile cron downgrades paid members on inconclusive API lookup | `memberistic includes/class-scheduler.php:189-218` | Medium |
| Role sync (prio 10) before user provisioning (prio 20) | `class-content-restrictions.php:39` vs `class-account-provisioner.php:44` | Medium |
| Paid email/SMS listen to `g2ab_booking_paid`, never fired by Stripe path | `class-stripe.php:327` vs `class-email-automation.php:28` | Medium |
| invoice.subscription field shape (API ≥2025-03-31) breaks renewals | `class-stripe-service.php:979` | Medium |
| Sync PDF invoice generation inside webhook request (timeouts) | `class-invoice-engine.php:55-101` | Medium |
| Booking docs never list required Stripe events | `class-settings-pro.php:303` | Medium |
| Guest waiver / FFL / POS raw-REMOTE_ADDR shared buckets | `class-waivers.php:882`, `class-wpistic-ffl-token.php:278`, `AuthController.php:17` | Medium |
| Webhook-silence alert exists only in dashboard mocks | `dashboard-app/src/mocks/data.ts:290` | Medium |
| Fortis header lookup has the same dead match | `g2a-booking-engine/includes/payments/class-fortis.php:200` | Low (inactive) |
| Asymmetric signature timestamp tolerance (−10s future) | `class-stripe.php:204` | Low |
| Annual billing toggle cosmetic-only (always charges monthly default) | `memberistic templates/plans-grid.php:38` | Low |
| Memberistic 1.18.2 / Verifyistic 1.4.5 never zipped; stale release docs | `releases/`, `INSTALL.md:13,30` | Medium (ops) |
