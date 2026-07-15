# G2A Business API

WordPress plugin that serves the **Guns2Ammo Business Control Center** dashboard
(`app.guns2ammo.com`) at the REST namespace `/wp-json/g2a/v1/*`.

WordPress is the source of truth. This plugin aggregates data from the other
plugins in this repo (WooCommerce, G2A Booking Engine, Memberistic, Waiver
Manager) and exposes a single, permission-checked, versioned API for the
dashboard.

## Install / activate

Drop the directory into `wp-content/plugins/` and activate. Activation:

- Grants `g2a_dashboard` to `administrator` + `shop_manager`
- Grants `g2a_dashboard_admin` to `administrator`
- Seeds empty option rows for automations / agents / models

## Auth

### Cookie sessions (0.2.0+, preferred)

`POST /wp-json/g2a/v1/auth/session/login` — accepts `{username, password}`
(real WP password or an application password, via `wp_authenticate()`).
Rate-limited: 10/15min per IP and 5/15min per username (429 +
`Retry-After` on breach). On success the server creates a row in
`{prefix}g2aba_sessions` and sets the session cookie:

```
g2aba_session=<64-hex token>; HttpOnly; Secure; Path=/wp-json/g2a/v1; SameSite=None
```

(`SameSite` is filterable via `g2aba_session_samesite`; the default is
`None` because app.guns2ammo.com is cross-origin today.) The response body
is `{user: {id, displayName, email, capabilities}, csrfToken}` — the raw
cookie token never appears in a body, and only its SHA-256 is stored.

- **Lifetimes**: 12h absolute (`g2aba_session_lifetime` filter) + 2h
  sliding inactivity (`g2aba_session_idle_timeout`); `last_seen_at` is
  refreshed at most once per 5 minutes.
- **CSRF**: every non-GET/HEAD/OPTIONS `g2a/v1` request authenticated via
  the cookie must send `X-G2A-CSRF: <csrfToken>`; enforced centrally on
  `rest_pre_dispatch`, failure = 403 `g2aba_csrf_failed`. Basic-auth
  requests are exempt (not CSRF-forgeable).
- **`GET /auth/session`** — envelope for the current cookie session (SPA
  boot / CSRF re-sync); 401 without one.
- **`POST /auth/session/logout`** — revokes the session server-side and
  clears the cookie.
- **`POST /auth/session/revoke-all`** — revokes every session of the
  current user.
- Expired/revoked rows are purged after 7 days by the daily
  `g2aba_sessions_cleanup` cron event.
- Audit-log events: `session_login_success`, `session_login_failed`,
  `session_logout`, `session_revoked_all`, `legacy_basic_login`.

### Legacy Basic-auth handshake (deprecated)

`POST /wp-json/g2a/v1/auth/login` — accepts `{email, password}` where the
password is a **WordPress application password** (WP 5.6+). Returns
`{token, displayName, role, deprecated: true}`. Subsequent requests are
authenticated by the WP application-password Basic header the browser
holds. **Deprecated since 0.2.0** — it now shares the session login's
rate-limit buckets and every use is audit-logged (`legacy_basic_login`)
so the cutoff can be planned; migrate clients to `/auth/session/login`.

All other routes require `g2a_dashboard` (read) or `g2a_dashboard_admin`
(mutations).

## Routes

| Method | Path                                        | Cap    |
| ------ | ------------------------------------------- | ------ |
| POST   | `/auth/login` (deprecated)                  | public (rate-limited) |
| POST   | `/auth/session/login`                       | public (rate-limited) |
| GET    | `/auth/session`                             | session cookie |
| POST   | `/auth/session/logout`                      | session cookie + CSRF |
| POST   | `/auth/session/revoke-all`                  | session cookie + CSRF |
| GET    | `/analytics/overview?from=&to=`             | read   |
| GET    | `/analytics/bookings?from=&to=` (0.4.0 envelope) | read |
| GET    | `/analytics/memberships?from=&to=` (0.4.0 envelope) | read |
| GET    | `/analytics/woocommerce?from=&to=` (0.4.0)  | read   |
| GET    | `/analytics/waivers` (0.4.0)                | read   |
| GET    | `/analytics/store?from=&to=`                | read   |
| GET    | `/analytics/seo?from=&to=`                  | read   |
| GET    | `/ai/insights`                              | read   |
| GET    | `/insights/business-gaps`                   | read   |
| GET    | `/automations`                              | read   |
| POST   | `/automations/{id}/toggle`                  | admin  |
| GET    | `/agents`                                   | read   |
| POST   | `/agents/{id}/run`                          | admin  |
| GET    | `/model-connections`                        | read   |
| POST   | `/model-connections/{id}/test`              | admin  |
| GET    | `/analytics/insightistic?from=&to=`         | read   |
| GET    | `/dashboard/overview?from=&to=`             | read   |
| GET    | `/system/health`                            | read   |
| POST   | `/bridgistic/ask`                           | read   |
| GET    | `/bridgistic/actions?status=pending`        | read   |
| POST   | `/bridgistic/actions/{id}/approve`          | admin  |
| POST   | `/bridgistic/actions/{id}/reject`           | admin  |
| GET    | `/email-drafts?status=pending`              | read   |
| POST   | `/email-drafts/{id}/send`                   | admin  |
| POST   | `/email-drafts/{id}/discard`                | admin  |
| GET    | `/cancellations?status=awaiting`            | read   |
| POST   | `/cancellations/{id}/mark-completed`        | admin  |
| POST   | `/cancellations/{id}/drop`                  | admin  |
| GET    | `/agents/{id}/history`                      | read   |
| POST   | `/agents/{id}/prompt`                       | admin  |
| GET    | `/audit-log?limit=100`                      | read   |
| GET    | `/agents/{id}/prompt`                       | admin  |
| POST   | `/public/opt-out`                           | public |
| GET    | `/system/namespaces`                        | read   |
| GET    | `/system/site-health`                       | read   |
| GET    | `/content/posts?per_page=&page=&search=&status=` | read |
| GET    | `/content/pages?per_page=&page=&search=&status=` | read |
| GET    | `/content/media?per_page=&page=&search=`    | read   |
| GET    | `/content/categories?per_page=&page=&search=` | read |
| GET    | `/content/tags?per_page=&page=&search=`     | read   |

## Aggregation layer (0.3.0+, canonical envelope)

`GET /dashboard/overview`, `GET /system/health`, and (0.4.0) the four
`/analytics/{bookings|memberships|woocommerce|waivers}` detail routes return
the canonical envelope:

```json
{
  "success": true,
  "data": { ... },
  "meta": {
    "requestId": "<uuid4>",
    "generatedAt": "<ISO-8601 UTC>",
    "timezone": "<wp_timezone_string()>",
    "currency": "USD",
    "source": ["g2a-booking-engine", "..."],
    "freshness": { "status": "fresh|stale|unavailable", "lastUpdatedAt": "<ISO UTC|null>" }
  }
}
```

Errors are `{"success": false, "error": {"code": "<stable_snake_case>",
"message": "<safe>", "requestId": "<uuid4>"}}` with the matching HTTP status
(`invalid_date_format` / `invalid_date_range` / `date_range_too_long` = 400).
All money is integer USD cents; all datetimes in `data` are ISO-8601 UTC.
Freshness roll-up: every module fresh → `fresh`; every module unavailable →
`unavailable`; mixed → `stale`.

### `GET /dashboard/overview?from=YYYY-MM-DD&to=YYYY-MM-DD` (read)

Defaults to the last 30 days; the window is validated and bounded at 366
days. `data`:

- `revenue` — `{bookingsCents, membershipsCents, wooCents, totalCents}`
  (bookings = net of refunds; memberships = completed Memberistic payments
  in range; woo = net of refunds over completed+processing orders).
- `series` (0.4.0) — dense daily revenue series for the requested range,
  `[{date: "YYYY-MM-DD", bookingsCents, membershipsCents, wooCents,
  totalCents}]`, missing days zero-filled, one GROUP-BY-date pass per
  provider (cached under distinct `-series` keys). Per-module sums equal the
  `revenue` block, so the home revenue-trend chart can retire the legacy
  `/analytics/overview` route (which remains registered and untouched).
- `bookings` — contract keys `count/paid/unpaid/revenueCents` plus
  `revenue{gross,refund,net}Cents`, `byStatus`, `avgBookingValueCents`,
  `reconciliation{warnings, paidBookingsWithoutPayment, unknownStatusPayments}`.
- `memberships` — `active/new/expired/mrrCents` plus full lifecycle
  `counts`, `cancelledInRange`, `revenueCents`, `planBreakdown[]`
  (`planId`, `name`, `count`, `revenueCents`).
- `woocommerce` — `orders/revenueCents` plus `statusesIncluded`,
  `revenue{...}`, `aovCents`, `truncated`.
- `waivers` — counts only, never PII: `signed/pending` plus
  `counts{current, expiring30d, expired, missing}`, `archive{total, current}`,
  `stores{people, archive}`.
- `alerts[]` — `{code, severity: critical|error|warning|info, message}`:
  `<module>_unavailable`, `waivers_expiring_spike`,
  `stripe_cancel_failures` (count-only read of the
  `memberistic_stripe_cancel_failures` option), and
  `booking_reconciliation_warnings`.
- `modules` — per module `{available, source: [plugin-slug], freshness}`.

**Revenue-status mapping** (documented in each provider's header):

- Booking engine `g2ab_payments.status`: `succeeded`, `captured`, `paid`,
  `completed`, `refunded`, `partial_refund` count toward gross (refund rows
  subtract their `refund_amount` into net); `pending`/`failed` are excluded;
  unknown statuses are excluded AND counted as reconciliation warnings.
- Memberistic `memberistic_payments.status`: only `completed`, recognised
  on `paid_at` (fallback `created_at`).
- WooCommerce: order statuses `completed` + `processing`, net of
  `get_total_refunded()`.

Every provider is capability-tier cached for 120s (filter
`g2aba_analytics_cache_ttl`), does bounded/aggregate queries only, checks
table existence before touching a sibling plugin's schema, and degrades to
an explicit `available: false` payload instead of fataling.

### Analytics detail endpoints (0.4.0, canonical envelope)

Four per-module detail routes behind the same read gate, range validation
(defaults last 30 days, bounded at 366), envelope, and provider plumbing as
`/dashboard/overview`. Each payload is the module's overview block PLUS the
detail below; every payload is cached for 120s under a distinct
`<provider>-detail` key. A missing source is signalled the same way the
overview signals it — `meta.freshness.status: "unavailable"` with an empty
`meta.source` — while `data` keeps every detail key present and zeroed/null
(no separate `data.available` boolean on these routes).
`/analytics/bookings` and `/analytics/memberships` supersede the pre-0.4.0
bare-payload routes at the same paths (the legacy `/analytics/overview`,
`/analytics/store`, `/analytics/seo`, `/analytics/insightistic` routes are
untouched).

All three revenue-bearing routes share the same `trends` block:
`{previous: {count, revenueCents, netCents}, deltas: {countPct,
revenuePct}}`, computed against the immediately-preceding period of equal
length with the identical status mappings; a delta is `null` whenever the
previous value is zero (an all-zero previous period yields all-null deltas).
All `series` are dense (missing days zero-filled) and built from single-pass
`GROUP BY` date aggregates — never per-day queries.

- **`GET /analytics/bookings?from=&to=`** — overview payload plus
  `byType[]` (`{typeId, name, count, revenueCents}` — counts from bookings
  created in range, revenue = net recognised payments joined to the
  booking's type), `series[]` (`{date, count, revenueCents}`), `trends`.
- **`GET /analytics/woocommerce?from=&to=`** — overview payload plus
  `topProducts[]` (top 10 by net line revenue, `{productId, name, qty,
  revenueCents}`), `byCategory[]` (top 10 `product_cat` terms, `{term,
  name, revenueCents, qty}`), `series[]` (`{date, count, revenueCents}` —
  count = orders that day), `trends`. Everything derives from the same
  5000-order capped scan as the overview: when the cap trips, `truncated`
  and `topProductsTruncated` are `true` (top products come from the capped
  set).
- **`GET /analytics/memberships?from=&to=`** — overview payload plus
  `newInRange`, `renewalsInRange`, `failedRenewals`, `churn`, daily
  `series[]` (`{date, newMembers}`), `trends` (count = new memberships;
  Memberistic tracks no partial refunds, so `netCents` = `revenueCents`),
  and `planBreakdown[]` rows additionally carry `active` (active
  memberships on the plan) and `mrrCents` (per-plan monthly run-rate, same
  annual/12 normalisation as the top-level `mrrCents`) alongside the
  overview `count`/`revenueCents` keys.
  **Renewal rule** (from the Memberistic schema): the
  `memberistic_activity` log is the source of truth —
  `renewalsInRange` counts `activity_type = 'membership_renewed'` rows in
  range (written exactly once when a membership's `renewal_date` is
  advanced and it re-activates); `failedRenewals` counts
  `activity_type = 'payment_past_due'` rows (the past_due transition logged
  on a failed auto-renewal charge). The activity table ships in the same
  Memberistic schema as the memberships table; if it is somehow absent both
  counters report 0.
  `churn` = `{rate, activeAtStart, calculation:
  "cancelled_in_range/active_at_start"}` — `rate` is the PERCENT form of
  cancelledInRange / activeAtStart (5.0 = 5%), where `activeAtStart` is
  reconstructed from current rows (created before the window AND currently
  active/past_due, or cancelled/expired only after the window began);
  `rate` is `null` when `activeAtStart` is null or zero.
- **`GET /analytics/waivers`** — counts only, never PII. Overview payload
  plus `expiring{d30, d60, d90}` (cumulative windows: signed roster waivers
  expiring within 30/60/90 days) and `signings{archive: [{month:
  "YYYY-MM", count}], people: [...]}` — signings per calendar month for the
  last 12 months, oldest first,
  labelled per source (`memberistic_waivers_archive.signed_at` vs
  `memberistic_people.waiver_signed_at`; the stores overlap, so they are
  never summed). The route is called without range params (the payload is
  point-in-time); `from`/`to` are still accepted for envelope/cache
  symmetry.

### `GET /system/health` (read, envelope — replaced the pre-0.3.0 checklist payload)

`data`: `plugins[]` (`{slug, name, active, version}` for booking engine,
Memberistic, Verifyistic, WooCommerce, FFL checkout, Messageistic, POS
core), `cron[]` (`{hook, nextRunAt|null}` for the Memberistic dailies,
`g2aba_sessions_cleanup`, `g2aba_generate_insights`, booking-engine
cleanup), `sessions{table, activeCount}`, `audit{recentFailures24h}`,
`integrations{stripe{configured}, woo{active}}`. Booleans/counts/versions
only — no credentials, secrets, or filesystem paths.

## Reconciliation CLI

```
wp g2a-business reconcile <bookings|memberships|woo> [--from=YYYY-MM-DD] [--to=YYYY-MM-DD] [--verbose]
```

Registered only when `WP_CLI` is defined. Independently recomputes revenue
from the source table/service for the window and diffs it against the
dashboard provider's uncached result for the same range. Prints: source
table/service, source row count, included count, excluded count with
per-status reasons (the status-mapping table), gross/refund/net cents for
both sides, the difference, and **PASS/FAIL** (PASS = zero unexplained
difference). Use it after imports, migrations, or webhook incidents to
prove the dashboard numbers still tie out.

`--verbose` (0.4.0) additionally prints the provider's uncached daily
net-revenue series — one line per day plus the SUM — the same figures the
`/analytics/<source>` and `/dashboard/overview` `series` blocks report, so
per-day endpoint numbers can be cross-checked directly.

## System discovery & website content

- **`GET /system/namespaces`** — calls WP core's own `rest_get_server()->get_namespaces()`
  in-process (no HTTP round-trip) and returns every REST namespace actually
  registered on the live install, plus a `detected` object
  (`rankMath`/`redirection`/`elementor`/`wooCommerce` booleans computed from
  that real list). Nothing is hardcoded — a plugin only shows up as
  "detected" if its namespace is genuinely registered.
- **`GET /content/{posts|pages|media|categories|tags}`** — a thin in-process
  proxy onto WP core's own `wp/v2` endpoints via `rest_do_request()`, so the
  dashboard reads website content through the same `g2a/v1` auth/CORS
  surface as every other route instead of needing a second cross-origin
  story against `wp/v2`. Forwards `per_page`/`page`/`search` (and `status`
  for posts/pages) and relays `X-WP-Total`/`X-WP-TotalPages` for pagination.
- **`GET /system/site-health`** — prefers WP core's own Site Health "direct"
  tests (background updates, loopback requests, HTTPS status, auth header),
  run in-process via `rest_do_request('/wp-site-health/v1/tests/...')`.
  Degrades to a constants-only summary (WP/PHP version, active plugin
  count, debug flags, disk-free space) — flagged with `degraded: true` —
  whenever Site Health internals aren't available; this endpoint never
  fatals.

## Operational review screens

Two owner-only sub-pages under **Settings**:

- **Settings → G2A · Email Drafts** — every draft BridGistic created. Reviewer
  can add/adjust the recipient, then Send (via `wp_mail`, which any installed
  mailer plugin — Messageistic, WP Mail SMTP, Postmark, … — intercepts) or
  Discard.
- **Settings → G2A · Cancellations** — BridGistic never cancels a booking
  automatically. Every approved `cancel_booking` action lands here for a
  human to finalize in the Booking Engine, then mark completed.

Every send / discard / mark-completed / drop writes an entry to the
`g2aba_audit_log` option so the owner has a "who did what, when" record.

## Google Business Profile

`GBP_Client` reuses the shared `Google_Service_Account`. It reads listing
performance (search + maps impressions, calls, direction requests, website
clicks) and — best-effort — recent reviews. Setup:

```php
update_option( 'g2aba_gbp_location_id', 'locations/1234567890' );
```

GBP APIs require per-account allowlisting by Google, so the client is
guarded by `is_configured()`; without a location id + service-account,
`GBP_Provider` returns zeroes and the rest of the plugin keeps working.

Two new gap rules consume GBP data:

- **`gap-gbp-actions`** — ≥ 1000 listing views but < 10 calls + directions +
  website clicks combined = "seen but not acted on."
- **`gap-gbp-reviews`** — ≥ 20 direction requests but < 3 total reviews =
  install a post-visit review-ask flow.

## Configuration

Owners store credentials in **Settings → G2A Business API** (WP-admin). No
WP-CLI required. The plugin never rendrs stored plaintext keys — only a
"configured" / "not set" indicator.

The screen holds:

- **Google service-account JSON** — shared by GSC + GA4. Paste the whole JSON
  key that Google's Cloud Console gives you.
- **GSC site URL** — the exact identifier from your GSC property
  (`sc-domain:guns2ammo.com` or `https://guns2ammo.com/`).
- **GA4 property id** — numeric id from GA4 Admin → Property Settings.
- **Anthropic API key** — for the insight generator.

Grant Google service-account access separately:

- **GSC**: the service-account email → Restricted user of the property.
- **GA4**: the service-account email → viewer on the property.

Access tokens are cached per scope for `expires_in − 60s` so we don't
mint a fresh JWT on every request.

## AI insight generator

An hourly WP-Cron event (`g2aba_generate_insights`) collects a 30-day
analytics snapshot, sends it to Anthropic with a strict JSON-only prompt,
validates + normalizes the result, and stores it in `g2aba_insights_cache`.
`/ai/insights` only reads that option — the LLM is never on the request
hot path.

The connection id defaults to `anthropic-primary`. Store the API key with:

```php
WordPressistic\G2ABA\Secrets::put( 'model:anthropic-primary', 'sk-ant-…' );
```

## Automations (real WP-Cron)

**`Automation_Store`** owns the persistent list of scheduled actions
(booking reminder, waiver reminder, membership renewal 30/7/1-day,
weekly report, low-stock alert, SEO click-drop alert, …). Each record
carries a stable slug that doubles as the WP-Cron hook name and the
interval the scheduler uses.

**`Cron_Scheduler::apply(record)`** is the only code that touches
`wp_schedule_event` / `wp_unschedule_event`. Toggling an automation via
`POST /automations/{slug}/toggle` mutates the store then re-applies the
scheduler — flipping enabled actually changes the WP-Cron state.

`Automation_Store::seed_defaults()` is idempotent: running it preserves
each record's `status`, `lastRun`, and `runsLast7d` counters, so
reactivating the plugin doesn't re-enable something a human paused.

### Handlers (safe by default)

Concrete handlers subscribe to their per-slug hook and are wired via
`Handler_Base::register()`. Every handler in this plugin writes to
`Email_Draft_Store` — it does **not** auto-send. Sending still goes
through the existing owner-approved `Email_Sender` path (audit-logged).

- **`Weekly_Report_Handler`** — Monday weekly cadence. Builds a
  compact text summary (revenue, bookings, memberships, store, SEO)
  and drafts one email.
- **`Low_Stock_Handler`** — twice-daily. Walks WooCommerce products at
  or under their per-product low-stock threshold (site-wide fallback
  when no per-product override is set) and drafts one email listing
  up to 20 SKUs. Skips gracefully when Woo is absent.
- **`SEO_Drop_Handler`** — daily. Compares GSC top-page clicks vs the
  previous 7 days and drafts an alert for any page down ≥ 25%.
- **`Membership_Renewal_Handler`** — daily. Finds Memberistic
  memberships expiring in exactly 30 / 7 / 1 day and drafts one
  personalised renewal email per member.
- **`Booking_Reminder_Handler`** — hourly. For any booking whose
  `start_at` is inside the next 24h window, drafts a friendly
  reminder addressed to the booking's email (or the linked user's).
- **`Waiver_Reminder_Handler`** — hourly. Same window as
  Booking_Reminder but scoped to bookings whose `waiver_signed_at`
  is null. Drafts a "please sign before you visit" nudge.
- **`Abandoned_Inquiry_Handler`** — hourly. Reads the WPistic
  Contact Form submissions table for entries older than 48h with no
  `replied_at`. Drafts one internal-staff summary email to the
  `admin_email`.
- **`Ladies_Upsell_Handler`** — hourly. For Ladies Tuesday bookings
  created in the last hour, drafts two follow-ups per attendee:
  "bring a friend" and "graduate to CCW class".
- **`Churn_Risk_Handler`** — daily. Reads Memberistic for active
  memberships expiring in the next 14 days and drafts a personalised
  "we miss you" email per match.

## Opt-out compliance

- **`Ops\Opt_Out_Store`** — persistent list keyed by lowercased
  email. Case-insensitive lookup + insert. Bounded ring of 5,000
  entries.
- **`Ops\Opt_Out_Signer`** — HMAC-SHA256 over `email + '|' + expires`
  keyed by a derivation of `AUTH_KEY`. Tokens are base64url-encoded,
  90-day TTL by default, verified with `hash_equals` (constant-time).
- **`Email_Sender`** — gate: any recipient in the opt-out store is
  refused before wp_mail is called. The draft transitions to
  `failed` with `Recipient has opted out`, and an
  `email.suppressed_opt_out` audit entry lands.
- Every outgoing message adds the `List-Unsubscribe` header +
  `List-Unsubscribe-Post: List-Unsubscribe=One-Click` (RFC 8058) and
  a plain-text unsubscribe footer with the signed link.
- **`POST /public/opt-out`** (no auth) validates `{email, expires, token}`
  against the signer, records the opt-out, and writes an audit entry.

## Agent prompt history

Every `set_prompt` that actually changes the template pushes the
previous template into a bounded per-agent version log
(`g2aba_agent_prompt_history_<id>`, capped at 10). `GET
/agents/{id}/prompt` (admin cap) returns the current template + the
history so operators can review and restore.

## AI Agents (real runtime)

**`Agent_Store`** persists agent metadata + a per-agent `promptTemplate`
that gets `{{snapshot}}` substituted at run time. Seed is idempotent —
operator prompt edits survive re-seeds.

**`Agent_Runner`** subscribes to the `g2aba_run_agent` cron hook.
`POST /agents/{id}/run` schedules a single event so the REST call
doesn't block on the model roundtrip. The runner:

1. Short-circuits with a helpful message if Anthropic isn't configured
   (before spending queries on a snapshot).
2. Builds a snapshot from the analytics providers.
3. Substitutes `{{snapshot}}` into the agent's prompt.
4. Calls the connected model.
5. Records the output as `lastOutput` on the agent + appends to
   **`Agent_History`** (bounded ring per agent, 50 entries).

`GET /agents/{id}/history` returns the ring for one agent.

## BridGistic action bridge

`POST /bridgistic/ask` classifies a natural-language command into:

- **read** — no state change, returned immediately.
- **draft** — a preview to review before sending; nothing goes out.
- **action** — enqueued for owner approval.

The classifier is **deterministic** (a rule-based list of verbs) — it does
not call an LLM. That way a prompt-injection attempt cannot talk the
classifier into treating a destructive command as a read.

Approving an action fires `do_action('g2aba_bridgistic_action_approved', $entry)`.
The plugin's built-in **`Executor`** listens on that hook, routes the query
through an **`Intent_Router`** (deterministic verb/noun rules), and delegates
to a specific handler:

- `send_email`   → drafts the message into `g2aba_bridgistic_email_drafts`
                   with `status = pending_send`. Nothing goes out over SMTP
                   without a second human step.
- `create_task`  → appends to `g2aba_bridgistic_tasks`.
- `cancel_booking` → extracts a booking id from the query, records a
                   cancellation *request* in `g2aba_bridgistic_cancel_queue`.
                   The plugin will not auto-cancel — cancellations touch
                   money and are always finalized manually.
- `unknown`      → records "no handler" against the action's result.

Adding a real destructive handler is a follow-on PR with the specific safety
rules for that handler.

Money on the wire is always **USD cents** (integer). Date-range params are
ISO `YYYY-MM-DD`; both optional; default is the trailing 30 days in site
timezone.

## Secrets

`Secrets` (`includes/class-secrets.php`) stores AI provider API keys with
AES-256-GCM using a key derived from `AUTH_KEY`. `Models_Controller` never
serializes plaintext keys — it replaces them with a static mask.

Rotating `AUTH_KEY` invalidates every stored secret. This is intentional:
Rotating a WP encryption key should force re-entry of provider keys, not
silently keep decrypting them.

## Development

```bash
composer install
composer test
```

Tests are pure PHP — they stub the tiny surface of WP the classes touch, so
you don't need a full WP install. Integration tests belong in a separate WP
develop-lib suite (out of scope here).

## Not in this plugin (by design)

- No POS state. That lives in `pos.guns2ammo.com` and never talks to this API.
- No live GA4 / GSC OAuth wiring — the `SEO_Provider` returns an empty
  skeleton until a service-account refresh token is added in Phase 3.
- No LLM calls in the hot path. `/ai/insights` reads a cache; generation is
  scheduled and rebuilt out-of-band.
