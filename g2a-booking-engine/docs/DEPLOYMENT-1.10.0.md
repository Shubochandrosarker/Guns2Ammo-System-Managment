# Deploying the 1.10.0 payment-policy release

Coordinated release: **G2A Booking Engine 1.10.0** and **Memberistic 1.19.0**
must ship together (the booking engine consults Memberistic's entitlement
service; Memberistic stops auto-creating Guest Pass memberships). The monorepo
carries identical copies of both plugin trees (CI job `plugin-sync` fails when
they diverge).

## Database changes

No destructive schema changes. New/changed storage:

| Item | Type | Notes |
| --- | --- | --- |
| `g2ab_email_log` table | new (created on demand via dbDelta) | email delivery log + idempotency keys |
| `g2ab_action_token_secret` option | new | per-site HMAC secret for email action links (autoload off) |
| `g2ab_action_token_version` option | new | bump to revoke all issued email action links |
| `g2ab_review_url` option | new | validated Google-review URL; CTA hidden when empty |
| `memberistic_lane_included_plan_slugs` option | new | default `defender, patriot, guardian` |
| `memberistic_lane_eligible_statuses` option | new | default `active, comped` |
| `booking_page_id` (Memberistic settings) | new | booking page used by email URL resolver |
| `bookings.status = 'partially_refunded'` | new enum value in use | column is VARCHAR; no migration needed |
| user meta `g2ab_customer_segment`, `g2ab_first_booking_at`, `g2ab_last_booking_at`, `g2ab_paid_booking_count`, `g2ab_lifetime_paid_amount`, `g2ab_tracked_booking_ids` | new | Range Guest classification + stats |
| `g2ab_require_public_prepay` option | retired | ignored by code; safe to leave in place |
| `g2ab_allow_event_pay_in_store_fallback` option | retired | ignored by code |

Backward compatibility: existing rows keep their statuses. Legacy public
pay-at-store rows (`reserved` + `in_store` + `source=web`) become
**non-operational** by policy — see “Legacy pay-at-store rows” below before
deploying.

## Rollout order

1. **Backup the database** (all `g2ab_*` and `memberistic_*` tables at
   minimum).
2. Deploy both plugins to **staging**.
3. Run the Guest Pass audit dry-run and attach the report to the release:
   `wp memberistic guest-pass-audit --format=csv` (dry-run is the default).
   Review the `ambiguous` bucket by hand.
4. Stripe test mode: complete one member ($0) lane booking, one non-member
   paid lane booking, one paid event booking; fire signed test webhooks
   (`stripe listen --forward-to <site>/wp-json/g2a-booking/v1/webhooks/stripe`)
   including a duplicate delivery of `checkout.session.completed`.
5. Verify every critical email CTA on staging (complete payment, view,
   cancel, reschedule) — the links are `?g2ab_action=…&g2ab_at=…` and must
   validate server-side; also verify an EXPIRED session resume mints a fresh
   Stripe session.
6. Test mobile (320px) and desktop booking flows, plus two concurrent checkout
   attempts on the same slot (second must get `g2ab_slot_full` or hold-block).
7. Deploy to production (both plugins in one deploy window).
8. Run the Guest Pass audit `--apply` after reviewing the dry-run.
9. Post-deploy reconciliation (see queries below).

## Legacy pay-at-store rows

Rows created by the old public flow (`status=reserved`, `payment_mode=in_store`,
`source=web`) no longer appear on operational rosters. These are real
customers who were promised "pay at the desk". Before deploy, export them and
decide per row: collect payment at the desk (Front Desk → Collect payment,
which transitions them to `paid`) or cancel with notice.

```sql
SELECT id, uuid, customer_name, customer_email, start_at, total_amount
  FROM wp_g2ab_bookings
 WHERE status = 'reserved' AND payment_mode = 'in_store' AND source = 'web'
   AND start_at >= NOW();
```

## Post-deploy reconciliation queries

```sql
-- Checkout holds that should expire (watch this stay near zero):
SELECT COUNT(*) FROM wp_g2ab_bookings
 WHERE status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 2 HOUR);

-- Paid bookings without a successful ledger row (must be zero):
SELECT b.id FROM wp_g2ab_bookings b
 LEFT JOIN wp_g2ab_payments p
   ON p.booking_id = b.id AND p.status IN ('succeeded','captured','paid','completed')
 WHERE b.status = 'paid' AND p.id IS NULL;

-- Guest Pass memberships still active after the audit:
SELECT COUNT(*) FROM wp_memberistic_memberships m
 JOIN wp_memberistic_plans pl ON pl.id = m.plan_id
 WHERE pl.slug = 'guest-pass' AND m.status = 'active';

-- Email failures in the last 24h:
SELECT * FROM wp_g2ab_email_log
 WHERE status = 'failed' AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY);

-- Webhook events stuck in failed state:
SELECT * FROM wp_g2ab_webhook_events WHERE status = 'failed'
 ORDER BY id DESC LIMIT 50;
```

## Rollback

1. Re-deploy the previous plugin versions (booking engine 1.9.9.20,
   Memberistic 1.18.6) — file replacement only.
2. No schema rollback is required (new tables/options are ignored by the old
   code).
3. If the Guest Pass audit was applied and must be reversed:
   `wp memberistic guest-pass-audit --rollback --apply` restores the previous
   membership statuses from the audit log.
4. Bookings created while 1.10.0 was live are valid under the old code
   (`pending` holds will be expired by the existing cron; `paid`/`confirmed`
   rows are ordinary bookings).
5. Bump `g2ab_action_token_version` if issued email action links must be
   revoked during rollback.

## Monitoring

- Watch `G2A Booking → Checkout Attempts` for a spike in `failed`/`expired`.
- Watch webhook health notices (existing admin notice) and the
  `payment_manual_review` log events.
- KPI sanity: Dashboard bookings count should now match the calendar and the
  bookings CSV (all consume the same operational predicate).
