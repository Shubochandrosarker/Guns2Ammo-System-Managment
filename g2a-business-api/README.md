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

| Method | Path                                    | Cap    |
| ------ | --------------------------------------- | ------ |
| POST   | `/auth/login`                           | public |
| GET    | `/analytics/overview?from=&to=`         | read   |
| GET    | `/analytics/bookings?from=&to=`         | read   |
| GET    | `/analytics/memberships?from=&to=`      | read   |
| GET    | `/analytics/store?from=&to=`            | read   |
| GET    | `/analytics/seo?from=&to=`              | read   |
| GET    | `/ai/insights`                          | read   |
| GET    | `/insights/business-gaps`               | read   |
| GET    | `/automations`                          | read   |
| POST   | `/automations/{id}/toggle`              | admin  |
| GET    | `/agents`                               | read   |
| POST   | `/agents/{id}/run`                      | admin  |
| GET    | `/model-connections`                    | read   |
| POST   | `/model-connections/{id}/test`          | admin  |
| GET    | `/system/health`                        | read   |

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
