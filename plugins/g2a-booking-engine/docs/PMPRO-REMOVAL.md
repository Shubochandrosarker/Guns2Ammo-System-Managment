# Paid Memberships Pro removal (booking engine 1.11.0 / Memberistic 1.20.0)

Memberistic is now the **single source of truth** for membership, entitlement,
plan and booking-eligibility decisions. Paid Memberships Pro can be deactivated
and deleted with no fallback, bridge or compatibility layer left behind.

## Why the dependency existed

The booking engine was originally built to sit on top of whichever membership
plugin a site happened to run, so membership questions were answered by
whatever it could find at runtime — PMPro first, Memberistic second:

- `G2AB_REST_Bookings_Controller::is_active_member()` called
  `pmpro_hasMembershipLevel()` when the function existed, ahead of everything
  else. On a site with both plugins installed, PMPro silently won every
  entitlement decision.
- `G2AB_Frontend::current_user_is_member()` did the same, then fell back to the
  `memberistic_active_plan_id` user meta. That meta is written on activation
  but **never cleared on expiry or cancellation**, so a lapsed member kept
  members-only access indefinitely.
- A whole `pmpro-memberships` module (settings screen + discount rules) mapped
  PMPro levels to booking discounts through the same `g2ab_user_is_member` /
  `g2ab_booking_pricing` filters Memberistic uses — two rulebooks racing on
  the same filters, with the winner decided by module activation order.
- `G2AB_Plugin::init_components()` booted a `G2AB_Integration_PMPro` class that
  never shipped — dead code guarded by `class_exists()`.

## What replaced it

### `Membership_Service` (Memberistic) — the public authority

`includes/class-membership-service.php` is the façade every other plugin calls.
It delegates lane-entitlement rules to `Integrations\Entitlement_Service` rather
than restating them, so there is exactly one rulebook.

| Method | Answers |
| --- | --- |
| `get_user_membership_status( $user_id )` | Structured live status: membership id, plan id/slug/name, status, role, start/renewal dates, `is_live`, `expired` |
| `user_has_active_plan( $user_id, $plan_slug = '' )` | Live membership, optionally on a specific plan |
| `get_user_plan( $user_id )` | The plan row, or `null` |
| `is_guest_user( $user_id )` | No live membership |
| `can_book_lane( $user_id, $ctx )` | Delegates to `Entitlement_Service` |
| `requires_payment_for_booking( $user_id, $ctx )` | Inverse of the above |
| `assign_plan_after_payment( $user_id, $plan, $evidence )` | Refuses without **verified** payment evidence |
| `assign_plan_manually( $user_id, $plan, $reason, $actor )` | Requires capability **and** an audited reason |
| `remove_plan( $user_id, $plan_id, $reason, $actor )` | Expires the membership, preserves all history |

Global helpers (safe for other plugins to call, all `function_exists()`-guarded
at the call site):

```php
memberistic_get_membership_status( $user_id );
memberistic_user_has_active_membership( $user_id, $plan_slug = '' );
memberistic_can_user_book( $user_id, $booking_context = array() );
memberistic_booking_requires_payment( $user_id, $booking_context = array() );
memberistic_is_guest_user( $user_id );
```

### Booking engine consumption

The booking engine no longer makes membership assumptions of its own:

- `is_active_member()` and `current_user_is_member()` call
  `memberistic_user_has_active_membership()`.
- Lane pricing/entitlement flows through `G2AB_Checkout_Policy::lane_entitlement()`
  → the `g2ab_lane_entitlement` filter → `Entitlement_Service`.
- The bundled Memberistic discount module asks
  `memberistic_get_membership_status()` for plan ids instead of querying
  membership tables itself.

**Fail-closed:** with Memberistic absent, every one of these answers "not a
member" (staff with `manage_g2ab_bookings` excepted). Members-only inventory
and member pricing lock down rather than opening up.

## Booking payment flow

**Member (live plan that includes lane time)**
1. Entitlement resolves `eligible = true`, `pricing_type = member_included`.
2. Amount due `$0`, booking created `confirmed`.
3. Confirmation email sent immediately.

**Non-member / guest / ineligible plan (incl. Guest Pass, trial, expired)**
1. Entitlement resolves `eligible = false`.
2. Server calculates the full public price — no deposits, no client totals.
3. Booking created `pending` (a **checkout hold**, never operational).
4. Stripe/WooCommerce checkout is created; on failure the request **fails
   closed** — hold expired, inventory released, recoverable error, no account
   provisioned, no email.
5. Only a verified payment moves the booking to `paid`, via the transition
   service's ledger invariant.
6. A declined payment moves it to the new `payment_failed` status (retryable,
   slot released, never on a roster). An abandoned hold expires on the cron.

## WooCommerce order-status mapping

| WooCommerce order status | Booking status |
| --- | --- |
| `processing`, `completed` | `paid` (requires a matching successful ledger row) |
| `pending`, `on-hold` | stays `pending` (a failed attempt is revived to `pending`) |
| `failed` | `payment_failed` |
| `cancelled` | `cancelled` |
| `refunded` | `refunded`, or `partially_refunded` for a partial |

All of these now route through `G2AB_Booking_Transitions`, so each change is
validated against the allowed-transition map, carries the previous status into
`g2ab_booking_status_changed`, and lands in the audit log. The ledger row is
written **first** (idempotently, keyed on `gateway` + `wc_<order_id>`) because
it is the payment evidence the `paid` invariant requires — so repeated
`processing` → `completed` hooks or replayed webhooks cannot double-insert
payments, double-confirm bookings, or double-send emails.

## Membership assignment rules

A Memberistic plan is assigned **only** when:

- `assign_plan_after_payment()` receives `payment_verified = true` **and** an
  order id or gateway transaction reference (Stripe webhook, WooCommerce paid
  order, POS membership sale); or
- `assign_plan_manually()` is called by a user holding
  `manage_options` (filterable via `memberistic_manage_memberships_capability`)
  with a non-empty reason, written to the activity log and the audit log.

It is **never** assigned by:

- user registration (no `user_register` listener assigns plans),
- WooCommerce customer creation (no `woocommerce_created_customer` listener),
- booking creation or booking payment (the auto Guest Pass enrollment on
  `g2ab_booking_created` / `g2ab_booking_paid` was removed in 1.10.0),
- checkout session creation, pending payments, or failed payments,
- guest-booking conversion.

Paid non-member bookers become the `range_guest` **customer segment** (user
meta + booking stats), which is not a membership.

## Booking status vocabulary

The stored status set is unchanged apart from the new `payment_failed`, so no
data migration is required. Where the specification's vocabulary differs from
the stored slug, the difference is presentation only:

| Spec name | Stored status | Notes |
| --- | --- | --- |
| `pending_payment` | `pending` | `G2AB_Booking_Statuses::payment_label()` renders "Pending Payment"; never operational |
| `payment_failed` | `payment_failed` | **New** — distinct from an expired hold, retryable |
| `confirmed` | `confirmed` / `paid` | `paid` implies confirmed with money on the ledger |
| `cancelled`, `expired`, `refunded` | same | — |
| `checked_in` | `g2ab_checkins` table | Kept as a separate record with its own timestamps, staff attribution and waiver/payment verification flags — it is an event, not a booking state |
| `no_show` | `no_show` | — |

Renaming `pending` → `pending_payment` in the database was deliberately **not**
done: it would rewrite live rows for a cosmetic gain, and every operational
surface already treats `pending` as "unpaid hold, not a reservation".

## Admin settings

| Setting | Default | Effect |
| --- | --- | --- |
| `g2ab_require_nonmember_payment` | **on** | Non-members must complete online checkout before the lane is held |
| `g2ab_allow_nonmember_front_desk` | **off** | Only takes effect when the above is off; allows a public web booking to be settled at the desk. Never applies to paid events |
| `g2ab_reservation_hold_minutes` | 15 | How long an unpaid hold blocks the slot (bounded 1–1440) |
| `g2ab_payment_gateway_default` | `stripe` | Default online gateway (was `pay_in_store`) |
| `memberistic_lane_included_plan_slugs` | `defender, patriot, guardian` | Plans whose membership includes lane time |
| `memberistic_lane_eligible_statuses` | `active, comped` | Statuses that keep the benefit usable |

Both new booking-engine toggles are saved through the existing settings handler
with capability check (`manage_g2ab_settings`), nonce verification
(`g2ab_save_settings_pro`) and explicit unchecked-box clearing.

## Migrating an existing PMPro member base

Memberistic ships a **CSV importer** (Memberistic → Import) — the only
remaining place the PMPro name appears, and deliberately so. It reads a PMPro
members/orders export; it never talks to a live PMPro installation.

- Legacy level names map to Memberistic plan slugs case-insensitively.
- "Additional Member" levels import as linked people on the household
  membership.
- Every imported membership records its legacy id and level in the notes.
- Unknown users are **never** mapped to Guest Pass Annual — unmapped rows are
  flagged for manual review instead.

For memberships this system created by mistake, use the audit command
(dry-run by default):

```bash
wp memberistic guest-pass-audit                 # dry run + CSV report
wp memberistic guest-pass-audit --apply         # expire high-confidence rows
wp memberistic guest-pass-audit --rollback --apply
```

No destructive cleanup runs automatically.

## Verifying the removal

```bash
# Booking engine: expected to print nothing.
grep -Rni "pmpro\|paid-memberships-pro" g2a-booking-engine \
  --include="*.php" --include="*.js" | grep -v "/tests/" | grep -v "/vendor/"

# Memberistic: expected to match ONLY the CSV importer + two comments.
grep -Rni "pmpro\|paid-memberships-pro" memberistic-membership-solutions \
  --include="*.php" --include="*.js" | grep -v "/tests/" | grep -v "/vendor/"
```

Both are enforced in CI by `PmproRemovalTest` in each plugin, which scans the
shipped tree on every push. The Memberistic test carries an explicit
allowlist for the migration tooling and fails if an allow-listed file stops
mentioning PMPro (so the list cannot rot into a blanket exemption).
