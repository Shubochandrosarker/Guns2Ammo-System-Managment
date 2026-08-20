# G2A Referrals

Membership referral rewards for Guns 2 Ammo. A referred friend who buys a
membership gets a free month; the referrer earns a Guest Pass.

Self-contained: one plugin, no shared library, no multi-tenant layer. A
general WordPressistic affiliate product will be built separately and must
not take this on as a dependency.

## The reward model

| Side | Reward | Perceived value | Marginal cost |
| --- | --- | --- | --- |
| Friend (new member) | +1 month on their first term | $29.99 – $59.99 | ≈ $0 |
| Referrer (existing member) | 1 Guest Pass — one free lane hour for a guest | $20.00 | ≈ $0 |

Time, not cash, and deliberately so. 69% of members buy annual (258 annual
vs 114 monthly), so a 20% cash discount would cost $60–130 of revenue per
referral that is never collected. An extra month costs an empty lane. Retail
coupons would hook into nothing: one WooCommerce order all time, status
`wc-pending`, and zero members have ever placed a retail order.

Every reward type, value and expiry is editable in **Referrals → Settings**.
Nothing about "1 month" or "1 pass" is hard-coded.

## Two rules worth protecting

**A Guest Pass is never consumed when the booking total is already $0.**
Members hold `member_discount = 100.00` on lane bookings, so their lane time
is already free. If redemption ran blind, a member would burn a hard-earned
reward on a booking that cost nothing. `Redemption::apply()` checks the
total before anything else and silently skips; `Redemption::on_booking_created()`
checks it again at the point of consumption. Covered by
`tests/Unit/RedemptionTest.php`.

**Redemption hooks at priority 12.** Memberistic hooks both
`g2ab_booking_pricing` and `g2ab_booking_display_pricing` at 11 and only
overwrites when its own discount is larger, so running after it is what
makes the rule above see the member's real, final price.

## Policy defaults

| Setting | Default | Why |
| --- | --- | --- |
| `guest_pass_expiry_days` | **90** | Guest Passes expire. An unexpiring pass is an unbounded liability on the books; 90 days caps it and gives the reward urgency. `0` disables expiry. |
| `referral_cap_per_month` | **5** | Bounds lane capacity as well as abuse — every pass is a free hour someone has to staff. `0` disables the cap. |
| `hold_window_days` | 14 | A refund or cancellation inside this window reverses both rewards. |
| `cookie_days` | 90 | First-touch attribution window. |
| `try_at_range_fee_credit` | **no** | The "lane fee toward the purchase" line is a commercial promise. Off until the owner confirms. |
| `first_order_offer_enabled` | **no** | Off until the 10% offer is confirmed live and redeemable. |

## Data model

Five tables plus one derived cache, all `wpbx_g2ar_*`:

- `referrers` — one row per member, holding their `G2A-XXXXXX` code
- `visits` — link clicks, keyed by a salted `visitor_hash`; no raw IP is ever stored
- `conversions` — one row per referred membership, `UNIQUE (friend_membership_id)`
- `rewards` — **append-only ledger**; balance is always `SUM(amount)`
- `balances` — derived cache, rebuilt from the ledger on every write
- `events` — append-only, hash-chained audit

The ledger has no mutable balance column. Reversals and expiries write
negative rows; nothing is ever deleted, so "where did my pass go?" always
has an answer at the counter.

## Codes

`G2A-XXXXXX`, six characters of Crockford base32 (no `I`, `L`, `O`, `U`) so
staff can read one aloud across a desk. Uniqueness is a DB constraint with
retry. `Codes::normalize()` accepts what a human actually types — lower
case, missing prefix, `I` heard as `1`.

Share targets: SMS, WhatsApp, Messenger, email, and a downloadable QR PNG.
The QR encoder is in `includes/class-qr.php` rather than a web service: a
member's referral link is customer data and should not be sent to a third
party to be drawn. Its output was verified against OpenCV's `QRCodeDetector`
for versions 1–10; `tests/Unit/QRTest.php` pins a golden matrix hash around
that.

## Privacy

The referrer sees *"Sarah M. — joined 12 Aug — Rewarded"*. Never the
friend's email, phone, plan price or purchases. Audit payloads are scrubbed
of anything PII-shaped before they are written, and IPs are only ever stored
as a sha256 with a daily-rotating salt.

## Constraints this plugin is built around

- **AirLift page cache is active.** Member-variant markup must never reach
  cached HTML. Both banners render as an empty reserved placeholder filled
  by one call to `GET /wp-json/g2ar/v1/context`. When testing, always use a
  novel query string — repeating a URL returns cached output and makes a
  working build look broken.
- **Never use the `Bearer` auth scheme.** The JWT Authentication plugin
  intercepts it globally at `rest_pre_dispatch` and 403s the whole request.
  The edge also strips custom `X-*` headers. Cookie + nonce only.
- **Plan 5 "Guest Pass" is not a paying member** (102 active rows), already
  excluded from booking benefits by the `g2a-guest-pass-not-a-member`
  mu-plugin. Plan 5 holders may still refer, but earn a free month rather
  than a pass — they have no membership for a guest to be brought onto.
- **The host 502s under load and the Bridgistic relay caps at 30s.** Backfill
  and expiry run in bounded nightly batches; a write can complete
  server-side while reporting a timeout, so state is re-queried before retry.
- Never query unindexed `postmeta.meta_value` — that pattern has already
  timed this site out.

## Emails

Two templates — *Referral Reward Earned* and *Referral Free Month* —
registered into **Memberistic's** mailer through `memberistic_email_templates`
rather than sent by a mailer of our own. That keeps one send log, one HTML
wrapper, one set of merge tags and one kill-switch on the site, and means
staff edit and resend referral email in the same screen as everything else.

## Tests

```
composer install
composer test
```

63 unit tests, no live WordPress: WP functions and the repositories are
stubbed in `tests/bootstrap.php`. They cover the `$0` redemption rule, hook
priority, opt-in, code format, offer stacking, the four self-referral
vectors, the monthly cap and the QR encoder.
