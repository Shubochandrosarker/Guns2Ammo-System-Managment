# P0 Incident — online bookings taken, Stripe never charged (2026-08-19)

Read-only forensic audit. No production system, database, Stripe object or
customer record was touched. No secret value was read or printed.

| File | What it is |
| --- | --- |
| [`ROOT-CAUSE-SUMMARY.md`](ROOT-CAUSE-SUMMARY.md) | One page. Read this first. |
| [`INCIDENT-AUDIT-2026-08-19-STRIPE-NOT-CHARGING.md`](INCIDENT-AUDIT-2026-08-19-STRIPE-NOT-CHARGING.md) | The full audit: timeline, architecture, root causes with file:line, the H1–H20 hypothesis ledger, and §15 — the exact read-only checks to run on production. |
| [`FIX-PLAN.md`](FIX-PLAN.md) | P0→P5 remediation, the test matrix, and the deployment + rollback procedure. |
| [`payment-reconciliation-2026-08-19.csv`](payment-reconciliation-2026-08-19.csv) | Reconciliation template. **Contains no production data** — see the header comments for how to populate it. |
| [`sql/`](sql/) | Nine `SELECT`-only forensic queries. Emails masked; secrets never printed. |

Regression tests live in
`plugins/g2a-booking-engine/tests/unit/PaymentIntegrityRegressionTest.php`
(`cd plugins/g2a-booking-engine && composer install && vendor/bin/phpunit`).

## The short version

Network egress to `guns2ammo.com` is blocked from the audit environment, so
**every finding here is derived from code, release ZIPs and Git history** and is
labelled CONFIRMED FROM CODE / CONFIRMED FROM REPOSITORY HISTORY / REQUIRES
PRODUCTION VERIFICATION / REQUIRES STRIPE VERIFICATION. Nothing is presented as
a production observation.

Stripe has no record of these bookings because **no Stripe API call was ever
made**. The booking engine reclassifies a payable public booking as
`payment_mode='in_store'`, `due_now=0`, `status='reserved'` whenever an online
gateway is unusable — and, from build `1.9.9.20` (2026-07-31), it does so
unconditionally. Four separate surfaces then report the booking as confirmed
and count its full price as revenue.

The fix already exists in this repository (`g2a-booking-engine 1.12.1` +
`memberistic 1.21.0`, both in `dist/`) and has never been deployed.

## Do not

Do **not** auto-charge, retry, or resend Checkout Sessions to any historical
customer. Generate the list, contact people, collect at the desk. Full list of
prohibited actions in the audit §22.
