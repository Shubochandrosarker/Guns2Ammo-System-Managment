# Guns 2 Ammo — system feature list

What the system does, grouped by domain. Versions for every component are in
[VERSIONS.md](VERSIONS.md).

---

## 1. Membership (Memberistic 1.22.0)

**The single membership authority.** Everything else in the system asks
Memberistic; nothing makes its own membership assumptions.

**Plans & members**
- Plan catalogue with stable slugs, pricing, billing cycles, benefits and per-plan settings
- Memberships with status lifecycle: `active`, `comped`, `trial`, `past_due`, `suspended`, `expired`, `cancelled`, `needs_review`
- Household/family memberships — linked people on one membership, each with their own account
- Corporate group memberships: seat pools, owner self-service portal, invitations, seat requests, group reporting
- Member digital card, dynamic check-in QR, member account dashboard

**Public service API** — `Membership_Service` + global helpers
- `get_user_membership_status()` — live status, plan, role, dates, expiry
- `user_has_active_plan()` / `get_user_plan()` / `is_guest_user()`
- `can_book_lane()` / `requires_payment_for_booking()` — booking entitlement
- `assign_plan_after_payment()` — refuses without **verified** payment evidence
- `assign_plan_manually()` — requires capability **and** an audited reason
- `remove_plan()` — expires entitlement, preserves all history

**Entitlement rules**
- Only `defender`, `patriot`, `guardian` include free lane time (configurable via `memberistic_lane_included_plan_slugs`)
- Only `active` and `comped` statuses are eligible (configurable via `memberistic_lane_eligible_statuses`)
- Resolved from the **authenticated session only** — a typed email never grants or reveals membership
- Expired renewal date disqualifies, even on an `active` row

**Billing**
- Stripe subscriptions, checkout, billing portal, webhook-driven activation
- WordPress-side cancellation propagates to Stripe (with retry + admin visibility); every cancel path — the members app, the generic membership PATCH endpoint, and the WooCommerce refund bridge — goes through the same Stripe-first gate
- Payment history, receipts, failed-payment recovery

**Discount codes** — Integrations tab addon (toggle: Discount Codes)
- Staff create/manage coupon codes entirely in wp-admin — percent or fixed amount off, restricted to specific plans and/or a billing cycle, an active window, and total + per-customer (by email) redemption limits
- Applies for the first billing cycle only, a set number of months, or every renewal forever — enforced by a real Stripe Coupon + Promotion Code created automatically behind each active code, so recurring-discount duration is Stripe-confirmed, not hand-rolled
- Plan/cycle/usage-limit checks run against Memberistic's own data before Stripe is ever contacted; a code only reaches the customer's Checkout Session once already validated
- Full redemption log per code (who, when, plan, before/after amount) recorded only from the Stripe-confirmed `checkout.session.completed` webhook — idempotent per checkout session, matching the plugin's "verified payment evidence only" rule everywhere else

**Waivers** — e-sign for members, guests and kiosk stations; minors; generated PDFs; versioned waiver text with re-consent; expiry and renewal reminders; waiver archive with import

**Tooling** — CSV importer for legacy member bases; `wp memberistic guest-pass-audit` (dry-run by default, batched, resumable, reversible)

---

## 2. Booking (G2A Booking Engine 1.12.0)

**Booking types & inventory**
- Lane bookings, classes, instructor sessions, events with occurrences
- Two capacity modes: `booking_count` (lanes) and `party_size` (seats)
- Buffer time before/after, enforced in both directions
- Business hours, blackout dates, min lead time, max advance window
- Database-level locking — two customers cannot take the same slot

**Payment policy (the core business rule)**
- Eligible members reserve for **$0**, confirmed immediately
- Everyone else pays the **full server-calculated price online** before the lane is held
- Public deposits and public pay-at-store are not permitted
- Offline gateways (`pay_in_store`, cash, terminal, comp, manual) are rejected on public payable endpoints — even for a crafted request
- **No admin override exists.** There is no setting, filter or capability that lets a public non-member hold a lane without paying; desk settlement lives only in the capability-gated staff endpoint, which records who took the booking
- Fail-closed: if checkout can't be created, no reservation, no account, no email — recoverable error instead

**Booking states**
`pending` (checkout hold) · `reserved` · `confirmed` · `paid` · `partially_refunded` · `completed` · `cancelled` · `no_show` · `refunded` · `payment_failed` · `expired`

- Central transition service with an explicit allowed-transition map
- `paid` requires a matching successful payment-ledger entry (booking, currency, amount)
- `confirmed` requires $0-with-valid-reason or verified payment
- Refunded states require a gateway refund or an explicitly recorded offline refund
- Every change carries the previous status into hooks and the audit log

**Payments**
- Stripe (Checkout Sessions, signed webhooks), PayPal, Fortis, Authorize.net, WooCommerce bridge
- Server-side amount recalculation; browser totals are never trusted
- Persistent claim-based webhook dedup — retries survive transient failures without double-charging
- Request idempotency fingerprinting actor, type, resource, time, quantity, gateway, amount and currency
- Partial-refund awareness across gateways

**Operations**
- Front-desk terminal: today's roster, search, check-in, waiver verification, payment collection, printable receipts
- FullCalendar admin view with drag-to-reschedule
- Checkout Attempts diagnostics screen for holds, failures and expiries
- Range Guests list + CSV export for paid non-member customers
- Reports, KPIs and exports — all sharing one operational visibility predicate
- Customer-facing reschedule and cancel pages behind signed tokens

**Email automation**
- Branded 600px responsive template with bulletproof CTA buttons and plain-text fallback
- Purpose-bound signed action links (complete payment, view, cancel, reschedule) with expiry and site-wide revocation
- "Complete payment" safely mints a fresh Stripe session when the original expired
- Delivery log with idempotency keys — webhook retries cannot duplicate emails
- Confirmations only after verified payment; reminders select operational bookings only
- Per-template preview and send-test

---

## 3. Point of sale (G2A POS Core 3.4.0)

- Counter checkout, cart, tenders, receipts
- Membership lookup at the counter — **Memberistic only**
- Range check-in and booking lookup by customer
- Inventory, products, categories
- PDF receipt generation (ships its own runtime dependencies)
- Admin SPA with provider configuration and test-lookup tooling

---

## 4. Supporting components

| Component | Features |
| --- | --- |
| **advanced-ffl-checkout** 1.21.1 | FFL transfer workflow, dealer database, customer portal, 2FA, reminders, webhooks, CSV export, licence/feature gating |
| **verifyistic** 1.4.7 | ID/age verification, QR verification endpoints, staff verification card |
| **formistic** 2.1.1 | Form builder for public site forms with submissions |
| **messageistic** 0.8.1 | Transactional messaging / SMS bridge |
| **g2a-theme-control** 1.0.1 | Theme presentation toggles |
| **g2a-referrals** 1.0.0 | Membership referral rewards — Crockford codes, first-touch attribution, append-only reward ledger, Guest Pass redemption on lane bookings, refund reversal, member Rewards tab, front-desk lookup, hash-chained audit |
| **g2a-business-api** 0.4.3 | REST backend for the staff dashboard at app.guns2ammo.com — server-managed sessions (HttpOnly cookie + CSRF header), analytics aggregation across WooCommerce, bookings and Memberistic |
| **guns2ammo** theme 1.30.0 | Public site, Elementor-compatible, SEO redirects, structured data |
| **dashboard-app** | Staff/owner dashboard |
| **g2a-chat-worker** + **cloudflare-rag-worker** | Site chat with retrieval-backed answers |

---

## 4b. Referral rewards (G2A Referrals 1.0.0)

**The model.** A referred friend who buys any membership gets +1 month on
their first term; the referrer earns a Guest Pass worth one free lane hour.
Time rather than cash, because 69% of members buy annual — a 20% cash
discount would cost $60–130 of revenue per referral, while an extra month
costs an empty lane. Every value, window and piece of customer-facing copy
is editable in Referrals → Settings.

| Area | Behaviour |
| --- | --- |
| Codes | `G2A-XXXXXX`, Crockford base32 (no I/L/O/U) so staff can read one aloud at the counter; DB-enforced uniqueness with retry |
| Attribution | `?ref=` sets a 90-day first-touch cookie; visits stored against a salted `visitor_hash`, never a raw IP |
| Qualification | Rewards fire on the friend's **confirmed membership payment**, never on signup; `UNIQUE (friend_membership_id)` makes a webhook retry a no-op |
| Ledger | Append-only. Balance is always `SUM(amount)`; there is no mutable balance column. Reversals and expiries write negative rows and nothing is ever deleted |
| Guest Pass redemption | Opt-in per booking, hooked at priority 12 (after Memberistic's 11). **Never consumed when the booking total is already $0** — members already get lane time free, and burning a reward on a free booking is the worst bug this feature could ship |
| Expiry | Guest Passes expire after 90 days by default, swept nightly in bounded batches |
| Caps | 5 rewarded referrals per member per month by default; a capped referral is recorded and audited, not silently dropped |
| Fraud | Self-referral blocked on user id, email, device fingerprint and payment instrument; volume thresholds flag for review rather than auto-reject |
| Reversal | Refund, dispute or cancellation inside a 14-day hold window reverses both sides, via Memberistic's existing Stripe webhook rather than a second endpoint |
| Stacking | Best single offer wins, never combined, with a configurable price floor |
| Privacy | The referrer sees "Sarah M. — joined 12 Aug — Rewarded" and never the friend's email, phone, plan price or purchases |
| Admin | Overview with outstanding reward liability in dollars, referrers, conversions, rewards, front-desk code lookup, hash-chain-verifiable audit log |

**Product page (theme 1.30.0).** A cache-safe promotional banner (empty
reserved placeholder hydrated from `/wp-json/g2ar/v1/context`, so AirLift
never caches a member variant), a "Try At Range" block on FFL products at
priority 34, and a rotating confidence strip replacing the old "Free FFL
Transfer" badge — every claim in it factually true, with no stock counters
or invented urgency.

---

## 5. Security & data integrity

- Nonce verification and capability checks on every admin and staff action
- Prepared SQL throughout; no unvalidated `$_POST`/`$_GET`/`$_REQUEST` use
- Webhook signature validation for every gateway
- Idempotent webhook and order handlers
- No booking confirmation from client-side state
- No membership assignment from untrusted request data
- Audit logs for status changes, membership assignment/removal, refunds and manual overrides
- Signed, expiring, revocable tokens for all public action links; no open redirects

## 6. Quality gates (CI)

| Gate | Scope |
| --- | --- |
| `no-pmpro` | Whole repo — fails on any Paid Memberships Pro dependency |
| `plugin-sync` | Monorepo plugin copies vs standalone repos, byte-identical — **fails closed** if it cannot clone and compare |
| `plugin-versions` | Every plugin header version matches its runtime version constant |
| PHP lint | Every PHP file, PHP 8.1 + 8.3 |
| PHPUnit | 67 booking-engine tests, 38 Memberistic tests, 63 referrals tests |
| JS syntax | All plugin assets |
| PHPCS PSR-12 | New service classes (advisory) |
| Composer validate | POS, business API, messageistic |
