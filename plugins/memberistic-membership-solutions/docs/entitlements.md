# Lane-booking entitlements

`Entitlement_Service` (includes/integrations/class-entitlement-service.php) is
the single authority answering: *does this authenticated user's membership
include free range-lane reservations?* The booking engine consumes it through
the `g2ab_lane_entitlement` filter and never grants $0 lanes any other way.

## Business rules

- **Included plans** (stable slugs): `defender`, `patriot`, `guardian`.
  Documented setting: `memberistic_lane_included_plan_slugs` (array of plan
  slugs). `guest-pass` and `range-guest` are force-removed even if a site adds
  them — a Guest Pass is a sellable product but never includes free lane time.
- **Eligible statuses**: `active`, `comped`. Documented setting:
  `memberistic_lane_eligible_statuses`. `trial`, `past_due`, `suspended`,
  `expired`, `cancelled`, `needs_review` and anything else are excluded unless
  the setting explicitly authorises them.
- **Authenticated only.** Entitlement resolves from a logged-in user id.
  A typed email address never grants — and never reveals — membership.
  A logged-out member either logs in to use the benefit or pays the public
  price as a guest.
- **Linked/family members** qualify through their own associated account: a
  `memberistic_people` row with their `wp_user_id` and `status=active` whose
  membership passes the same plan/status/renewal checks.
- **Expiry**: a `renewal_date` in the past (end of that day, site timezone)
  disqualifies; empty/zero renewal dates mean non-expiring.

## Result shape

```php
[
  'user_id'           => 123,
  'membership_id'     => 45,
  'plan_id'           => 3,
  'plan_slug'         => 'defender',
  'plan_name'         => 'Defender',
  'membership_status' => 'active',
  'eligible'          => true,
  'reason'            => 'member_included',   // stable reason codes below
  'pricing_type'      => 'member_included',    // or 'public_full_price'
  'amount_due'        => 0.0,                  // null when the engine prices it
  'allowed_gateway'   => 'member_included',    // or 'online'
  'checked_at'        => '2026-08-08 12:00:00',
]
```

Reason codes: `member_included`, `not_authenticated`, `no_membership`,
`status_not_eligible`, `plan_not_eligible`, `membership_expired`,
`linked_person_inactive`.

## What changed for Guest Pass

Automatic Guest Pass enrollment from bookings and WooCommerce product
purchases is removed. The explicit `[memberistic_guest_pass]` registration
form remains the only way to issue one, and even a sold Guest Pass never
zeroes a lane. Paid non-member bookers are classified by the booking engine as
the `range_guest` customer segment (user meta + booking stats — no membership
row). Existing auto-created rows are handled by
`wp memberistic guest-pass-audit` (dry-run by default; see
docs/guest-pass-audit.md).
