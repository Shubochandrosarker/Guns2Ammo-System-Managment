# g2a-booking-engine tests

## Unit tests (PHPUnit 10, no WordPress required)

The unit suite in `tests/unit/` runs against plain PHP 8.1+ — `tests/bootstrap.php`
stubs the handful of WordPress functions the tested service classes use
(filters/options/sanitizers/`WP_Error`, …). No database, no live WordPress.

Covered:

- `G2AB_Checkout_Policy` — offline gateway blocklist, entitlement normalization,
  lane checkout resolution ($0 member lanes, fail-closed pricing, online-only
  public payments, no public deposits).
- `G2AB_Booking_Statuses` — `partially_refunded` in `all()`/`blocking()`/`color()`,
  terminal set stability.
- `G2AB_Booking_Visibility` — `is_operational()` matrix and `operational_sql()`
  string contract (staff-source clause, never plain reserved web rows).
- `G2AB_Booking_Transitions::is_allowed()` — transition matrix (DB-touching
  `transition()` is not unit-tested).
- `G2AB_Email_Actions` — token issue/validate round trips, wrong purpose,
  expiry, version revocation, tamper rejection (endpoint handlers are not
  unit-tested).
- `G2AB_REST_Bookings_Controller::booking_idempotency_key()` — fingerprint
  contract via reflection (same inputs → same key; changed material value or
  actor scope → new key).

### Run

```bash
composer install          # installs phpunit/phpunit ^10.5 (dev only)
composer test             # = vendor/bin/phpunit -c phpunit.xml
```

Or with a phar (note: `phar.phpunit.de` may be blocked by an egress proxy in
sandboxed environments — Composer/Packagist is the reliable path):

```bash
curl -sSLo tests/bin/phpunit-10.phar https://phar.phpunit.de/phpunit-10.phar
php tests/bin/phpunit-10.phar -c phpunit.xml
```

## E2E tests (Playwright, live WordPress required)

`tests/e2e/` contains Playwright specs that encode the required browser flows
(member $0 confirm, non-member pay CTA + checkout hold, `pay_in_store`
rejected over REST, logged-out no member reveal, expired-link resume).

They only run when a live site is provided; without `WP_BASE_URL` every spec
skips so CI stays green:

```bash
cd tests/e2e
npm install @playwright/test && npx playwright install chromium
WP_BASE_URL=https://staging.example.com \
  G2AB_MEMBER_USER=member G2AB_MEMBER_PASS=... \
  npx playwright test
```

See `tests/e2e/playwright.config.ts` for all recognized environment variables.
