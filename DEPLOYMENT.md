# Deployment — app.guns2ammo.com

The dashboard ships as **two independently-deployable pieces** talking over
WordPress' REST API:

1. **`g2a-business-api/`** — the WordPress plugin. Runs on the same origin
   as WordPress (`guns2ammo.com`). Everything sensitive (API keys, GA4
   service accounts, cron scheduling, RAG stores) lives here.
2. **`dashboard-app/`** — the React SPA served from `app.guns2ammo.com`.
   No secrets. Every write goes through the WP REST API, authenticated by
   an HttpOnly session cookie plus a per-session CSRF header.

## 1. Install the plugin on WordPress

A production-clean build is already in [`dist/`](dist/) — use it rather than
hand-zipping, so what you install matches what CI verified:

```
dist/g2a-business-api-0.4.3.zip
```

To rebuild every plugin ZIP from the current tree:

```bash
scripts/build-release-zips.sh
```

Then in WordPress:

- Plugins → Add New → Upload Plugin → pick the zip → Install → Activate.
- On activation, the plugin:
  - Seeds `g2aba_automations` (nine automations) and their WP-Cron hooks.
  - Registers custom capabilities `g2a_dashboard` and `g2a_dashboard_admin`.
  - Registers the `g2a/v1` REST namespace under `/wp-json/g2a/v1/`.

Grant `g2a_dashboard` (or the `g2a_dashboard_admin` variant) to each user
who should sign in. `manage_options` (site administrators) is
automatically treated as `g2a_dashboard_admin` — no extra assignment
needed.

### How sign-in works

The dashboard uses **server-managed cookie sessions**, not Basic auth and
not a token in `localStorage`:

- `POST /wp-json/g2a/v1/auth/session/login` takes a username (or email) and
  password, and on success sets the `g2aba_session` cookie —
  `HttpOnly; Secure; Path=/wp-json/g2a/v1; SameSite=…`. JavaScript never
  reads, writes or stores it.
- The same response carries a per-session **CSRF token**, held in module
  memory only. Every non-GET request sends it as `X-G2A-CSRF`.
- `GET /auth/session` re-hydrates the user and the CSRF token on page load.
  `POST /auth/session/logout` revokes the session server-side.

A WordPress **application password** (Users → Profile → Application
passwords) can be pasted into the password field instead of the account
password, and is the better option for a shared workstation — but it is no
longer the mechanism the dashboard relies on. See
`dashboard-app/src/lib/api.ts` for the full contract.

### Secrets

AI provider API keys and any other sensitive credentials are set from
**WP-admin → Guns2Ammo Business API → Settings**. They are stored
AES-256-GCM encrypted at rest, derived from `AUTH_KEY`. They never leave
PHP — the REST API only exposes a masked view (`sk-****abcd`).

## 2. Serve the app and the API from the same origin

This is the decision everything else follows from, so it is worth stating
plainly: **the session cookie is same-origin**. `app.guns2ammo.com` does not
call `guns2ammo.com` directly — nginx on the app host proxies
`/wp-json/g2a/v1/` through to WordPress, and the SPA talks to a **relative**
API base. Point the app at an absolute origin instead and the browser will
simply not send the cookie: every screen renders empty with no error that
says why.

Two files in `dashboard-app/deploy/` are the deployable config:

| File | Install to |
| ---- | ---------- |
| `nginx-security-headers.conf` | `/etc/nginx/snippets/g2a-security-headers.conf` |
| `nginx-app.guns2ammo.com.conf` | `/etc/nginx/sites-available/app.guns2ammo.com` (then symlink into `sites-enabled/`) |

```bash
sudo cp dashboard-app/deploy/nginx-security-headers.conf /etc/nginx/snippets/g2a-security-headers.conf
sudo cp dashboard-app/deploy/nginx-app.guns2ammo.com.conf /etc/nginx/sites-available/app.guns2ammo.com
sudo ln -sf /etc/nginx/sites-available/app.guns2ammo.com /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

Install **both**. The snippet carries every security header (HSTS, CSP,
`X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`,
`Permissions-Policy`) and is `include`d in each `location` block, because
nginx's `add_header` does not accumulate across levels — a location with any
`add_header` of its own inherits none of the parent's. If the snippet is
missing, `nginx -t` fails, the reload is refused, and the previous vhost
keeps serving; `deploy.sh` probes the live headers after each deploy and
warns when that has happened.

The vhost also:

- proxies **only** the `g2a/v1` namespace and returns `404` for every other
  `/wp-json/` path, so this host is not an open proxy into WordPress;
- rate-limits `/auth/session/login` to 10/min per IP with a burst of 5, and
  answers `429` (which the login screen has a specific message for) rather
  than nginx's default `503`. Deliberately scoped to the login endpoint
  alone — `GET /auth/session` fires on every page load, and a shop's staff
  share one NAT address;
- serves `/assets/` immutable for a year and everything else through the SPA
  fallback to `index.html`.

Expected server layout (created by `deploy.sh`):

```
/var/www/g2a-dashboard/releases/<release-id>/dist/
/var/www/g2a-dashboard/current -> releases/<release-id>
```

Note the `root` is `…/current/dist`, not `…/current`.

### DNS and TLS

Point `app.guns2ammo.com` (`A`/`AAAA`) at the VPS. HTTPS is required: the
session cookie is `Secure`, so over plaintext HTTP it is never sent and
sign-in cannot work at all.

## 3. Build and deploy

```bash
cd apps/dashboard-app
./deploy/deploy.sh deploy      # ten steps, atomic release, auto-rollback
./deploy/deploy.sh rollback    # instant, to the previous release
```

`deploy.sh` sets the production environment itself —
`VITE_G2A_API_BASE=/wp-json/g2a/v1` (relative, through the proxy above) and
`VITE_G2A_USE_MOCKS=0` — so there is **no `.env.production` to maintain** for
the normal path. `dashboard-app/.env.production.example` documents the
variables for a manual build.

What it does, in order: preflight (lockfile present, API base set and of a
valid shape) → `npm ci` → typecheck → lint → tests → production build →
`scripts/verify-build.sh` → upload → atomic symlink switch → health check →
API-proxy probe → security-header probe → prune old releases.

`scripts/verify-build.sh` is the gate worth knowing about, because CI
(`.github/workflows/dashboard.yml`) runs the identical script on every pull
request. It refuses a bundle that contains source maps, that contains the dev
mock fixtures, that does not have the API base compiled in, that lost its
`noindex` directive or `robots.txt`, or that added an inline `<script>` the
CSP would block.

If the health check fails after the switch, the script rolls back on its own
and exits non-zero.

### Manual build (only if you are not using `deploy.sh`)

```bash
cd apps/dashboard-app
npm ci
VITE_G2A_API_BASE=/wp-json/g2a/v1 VITE_G2A_USE_MOCKS=0 npm run build
./scripts/verify-build.sh dist /wp-json/g2a/v1
```

`src/lib/env.ts` refuses to build a mock-mode production bundle, and a
missing `VITE_G2A_API_BASE` throws at module load rather than lazy-failing on
the first fetch.

## 4. Verify the live deployment

Once DNS + certs are live, walk through this list:

1. Open `https://app.guns2ammo.com/` — the login screen renders.
2. Check the response headers:
   `curl -sI https://app.guns2ammo.com/ | grep -iE 'content-security|strict-transport|x-frame'`
   All three must be present. If they are not, the snippet was not installed
   and nginx is still serving the old vhost.
3. `curl -s https://app.guns2ammo.com/robots.txt` returns `Disallow: /`, and
   the page source carries `<meta name="robots" content="noindex, …">`. This
   is a private surface; it must not be indexable.
4. Sign in with your WordPress username/email + password (or an application
   password). You land on Dashboard Home with real numbers from
   GA4/WooCommerce/Booking.
5. Hard-refresh a deep page (e.g. `/reports`). The SPA fallback should keep
   you on Reports, not bounce to a WP 404.
6. Sign out, then open `https://app.guns2ammo.com/waivers` directly. You get
   the login screen — and after signing in you land on **Waivers**, not on
   the home screen.
7. Open **AI Models & RAGs**, click **Test** on a connection — you get a
   provider-specific latency badge, not the generic Bearer smoke test.
8. Open **Automation Center**, change one automation's interval — the Weekly
   Report should now list the new interval, and
   `wp_next_scheduled('g2aba_run_weekly_report')` in wp-cli should show the
   new cadence.
9. Download `tasks.csv` from the Tasks page and open it in Excel — RFC 4180
   CRLF terminators + escaped quotes.
10. Sign out. Re-open the app — `GET /auth/session` fires, and because the
    session is gone you bounce cleanly to `/login`.

## Rollback

```bash
cd apps/dashboard-app && ./deploy/deploy.sh rollback
```

Releases are atomic symlink switches and the last five are kept on the
server, so a rollback is a symlink flip and a health check — no rebuild, no
re-upload. The plugin has no schema migrations (every store uses
`update_option` with defensive read-time hydration), so reactivating the
previous plugin version keeps your data intact.

### After a deploy, open tabs recover themselves

Each release gets new fingerprinted asset filenames and old releases are
pruned, so a tab that was already open is running a bundle whose chunks no
longer exist on the server. `src/lib/lazy.ts` detects that specific failure
and reloads the page once to pick up the new bundle; a second failure is
shown in an error boundary rather than reloading forever. Nobody has to be
told to hard-refresh.
