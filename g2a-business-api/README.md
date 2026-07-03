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

`POST /wp-json/g2a/v1/auth/login` — accepts `{email, password}` where the
password is a **WordPress application password** (WP 5.6+). Returns
`{token, displayName, role}`. The token is opaque to the frontend; subsequent
requests are authenticated by the WP application-password auth header the
browser holds.

All other routes require `g2a_dashboard` (read) or `g2a_dashboard_admin`
(mutations).

## Routes

| Method | Path                                        | Cap    |
| ------ | ------------------------------------------- | ------ |
| POST   | `/auth/login`                               | public |
| GET    | `/analytics/overview?from=&to=`             | read   |
| GET    | `/analytics/bookings?from=&to=`             | read   |
| GET    | `/analytics/memberships?from=&to=`          | read   |
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
| GET    | `/system/health`                            | read   |
| POST   | `/bridgistic/ask`                           | read   |
| GET    | `/bridgistic/actions?status=pending`        | read   |
| POST   | `/bridgistic/actions/{id}/approve`          | admin  |
| POST   | `/bridgistic/actions/{id}/reject`           | admin  |

## Google Search Console

The SEO page reads real data when a service-account credential is stored:

```php
// One-off, run from wp-cli or a small admin snippet:
WordPressistic\G2ABA\Secrets::put(
    'gsc:service-account',
    file_get_contents('/path/to/service-account.json')
);
update_option( 'g2aba_gsc_site_url', 'sc-domain:guns2ammo.com' );
```

Add the service-account's `client_email` as a Restricted-access user of the
GSC property. The client caches access tokens for `expires_in − 60s`.

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

## BridGistic action bridge

`POST /bridgistic/ask` classifies a natural-language command into:

- **read** — no state change, returned immediately.
- **draft** — a preview to review before sending; nothing goes out.
- **action** — enqueued for owner approval.

The classifier is **deterministic** (a rule-based list of verbs) — it does
not call an LLM. That way a prompt-injection attempt cannot talk the
classifier into treating a destructive command as a read.

Approving an action fires `do_action('g2aba_bridgistic_action_approved', $entry)`
so other plugins can hook the actual execution. The bridge itself never
performs the underlying action — it only owns the approval state machine.

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
