# Booking, Payment & Visibility Policy (v1.10.0)

This document is the architecture reference for the 1.10.0 payment-policy
release. It explains the root causes it fixes, the authoritative services that
replaced scattered conditions, and the contracts every consumer must follow.

## Root causes fixed

1. **Public payment bypass.** Public lane bookings defaulted to
   `pay_in_store`: the request was accepted with `status=reserved`,
   `due_now=0`, told the customer "Reservation received. Pay at the front desk
   on arrival" — and appeared on operational staff rosters without a cent
   collected. A crafted `gateway=pay_in_store` parameter forced the same path
   even where prepay was configured, and the public `/payment-methods` endpoint
   advertised the offline gateway.
2. **Automatic Guest Pass memberships.** Memberistic's corporate module
   auto-enrolled *every* booker's typed email as an `active` Guest Pass
   membership (annual, no renewal) on `g2ab_booking_created` — including for
   unpaid checkout holds. That is why non-members appeared in the Members admin
   as "Guest Pass / Annual".
3. **Entitlement by typed email.** A typed email address could reveal and
   partially exercise membership state (advisory hint / desk-rate routing).
4. **Misleading states.** "Booking confirmed" UI and confirmation emails for
   unpaid holds; refund checkbox that flipped status without a refund;
   `partially_refunded` written by webhooks but absent from the status model.

## Authoritative services

### `G2AB_Checkout_Policy` (includes/services/class-checkout-policy.php)
The only place that decides how a public booking may pay.

- `lane_entitlement( $user_id )` — structured entitlement snapshot, resolved
  through the `g2ab_lane_entitlement` filter (answered by Memberistic's
  `Entitlement_Service`). Fields: `user_id, membership_id, plan_id, plan_slug,
  plan_name, membership_status, eligible, reason, pricing_type
  (member_included|public_full_price), amount_due, allowed_gateway,
  checked_at`. Anonymous users and typed emails can never be eligible.
- `resolve_lane( $booking_type, $pricing, $entitlement, $requested_gateway )` —
  returns the checkout decision: `$0 → confirmed` only with a valid zero
  reason (`member_included` or a genuinely free booking type); payable →
  `payment_mode=full`, `initial_status=pending` (checkout hold), online
  gateway only. Rejects every offline gateway (`pay_in_store, cash, terminal,
  comp, manual, offline, check, invoice`) on payable public requests.
- `public_payment_methods()` — what anonymous callers may see: online
  gateways only.

### Memberistic `Entitlement_Service`
(includes/integrations/class-entitlement-service.php in
memberistic-membership-solutions)

- Included plan slugs: option `memberistic_lane_included_plan_slugs`, default
  `defender, patriot, guardian`. `guest-pass` is force-stripped even if a site
  adds it.
- Eligible statuses: option `memberistic_lane_eligible_statuses`, default
  `active, comped`. Trial, past_due, suspended, expired, cancelled and
  needs_review never qualify unless the documented option is widened.
- Linked/family members qualify only through their own authenticated account
  (people row `wp_user_id` match + active person status).

### `G2AB_Booking_Transitions` (includes/services/class-booking-transitions.php)
Central state machine. Every status change flows through
`transition( $booking_id, $to, $context )`:

- Explicit allowed-transition map (`refunded` is terminal; `expired → pending`
  exists only for safe payment resume).
- `paid` requires a successful ledger row (right booking, currency, amount —
  or the successful-rows SUM covering the total for split desk payments).
- `confirmed` requires `$0` total or a verified successful payment.
- `refunded` / `partially_refunded` require a gateway refund on the ledger or
  an explicitly recorded offline refund (amount + method), which is persisted
  as an `offline_refund` ledger transaction.
- Audit log rows and `g2ab_booking_status_changed` always carry the previous
  status.

### `G2AB_Booking_Visibility` (includes/class-booking-visibility.php)
The operational predicate consumed by **every** staff surface (bookings list +
CSV, calendar feed, dashboard KPIs and recent activity, reports, reminders,
front-desk roster):

- Operational: `confirmed`, `paid`, `completed`, `partially_refunded`,
  `no_show`, plus `reserved+in_store` rows **created by staff sources**
  (`admin, manual, frontdesk, pos, phone, staff, walk_in`).
- Never operational: `pending` checkout holds, `expired`, `cancelled`,
  `refunded`, and any public-web pay-at-store legacy row.
- Diagnostics: pending/failed/expired attempts live on **Checkout Attempts**
  (`G2A Booking → Checkout Attempts`).

### `G2AB_Email_Actions` (includes/services/class-email-actions.php)
Purpose-bound signed action tokens for email CTAs
(`?g2ab_action=pay|view|cancel|reschedule&g2ab_at=<token>`): HMAC-SHA256 over
a per-site secret, per-action expiry, site-wide version revocation, bound to
one booking UUID. The `pay` action is the stable "Complete payment" page — it
redirects to the live Stripe session or safely mints a fresh one (inventory
re-checked) when the original expired.

### `G2AB_Range_Guest_Service` (includes/services/class-range-guest-service.php)
Classifies paid non-member customers as the `range_guest` segment (user meta —
**never** a membership row) after verified payment, tracks first/last booking,
paid booking count and lifetime paid amount, and powers the
`G2A Booking → Range Guests` admin list + CSV export. A later real membership
wins automatically (segment derives live from entitlement) without losing
history.

## Idempotency

`create_booking` / `create_event_booking` fingerprint every material value:
actor scope (authenticated user id, or hashed client identity + email for
guests), booking kind, resource/occurrence, start time, party size/seats,
gateway, server-calculated amount + currency, plus a coarse 10-minute bucket.
Exact retries replay the original response; any changed value books fresh; a
collision with a **terminal** original returns a recoverable 409 instead of
replaying a dead booking. Stripe session creation keeps its own per-attempt
`Idempotency-Key` and DB lock.

## Event bookings

- Free events confirm immediately.
- Paid events are non-operational `pending` holds until webhook-verified
  payment; there is **no** public pay-in-store fallback (the old
  `g2ab_allow_event_pay_in_store_fallback` escape hatch is gone).
- Expired holds release seats (capacity is computed live from blocking rows).
- Failed checkout creation fails closed: hold expired, error returned, no
  roster entry, no account, no email.

## Webhooks

Signature validation preserved for all gateways. Stripe/PayPal/Authnet now all
use claim-based persistent dedup (`g2ab_webhook_event_claim`) — events are
marked processed only after success, so retries survive transient failures.
PayPal `CHECKOUT.ORDER.APPROVED` now triggers a capture instead of being
treated as paid; refund handling is partial-aware for PayPal and Fortis and
routes through the transition service.
