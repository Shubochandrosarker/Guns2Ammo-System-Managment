# Deployment — app.guns2ammo.com

The dashboard ships as **two independently-deployable pieces** talking over
WordPress' REST API:

1. **`g2a-business-api/`** — the WordPress plugin. Runs on the same origin
   as WordPress (`guns2ammo.com`). Everything sensitive (API keys, GA4
   service accounts, cron scheduling, RAG stores) lives here.
2. **`dashboard-app/`** — the React SPA served from `app.guns2ammo.com`.
   No secrets. Every write goes through the WP REST API with WP
   application-password auth.

## 1. Install the plugin on WordPress

Zip the plugin directory and upload it via Plugins → Add New → Upload:

```bash
cd g2a-business-api
zip -r ../g2a-business-api.zip . -x "vendor/*" "tests/*" "*.log" "composer.lock"
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

### Application passwords

The dashboard authenticates with **native WordPress application
passwords** (WP 5.6+):

- Each user goes to **Users → Profile → Application passwords** and
  generates a new password labelled e.g. `dashboard-web`.
- The dashboard's login form takes the user's email and that
  application password (NOT the WP admin password).

### Secrets

AI provider API keys and any other sensitive credentials are set from
**WP-admin → Guns2Ammo Business API → Settings**. They are stored
AES-256-GCM encrypted at rest, derived from `AUTH_KEY`. They never leave
PHP — the REST API only exposes a masked view (`sk-****abcd`).

## 2. Build the dashboard

```bash
cd dashboard-app
cp .env.production.example .env.production
# Edit .env.production and set VITE_G2A_API_BASE to your WP origin:
#   VITE_G2A_API_BASE=https://guns2ammo.com
npm ci
npm run build   # writes to dist/
```

`env.ts` refuses to build a mock-mode production bundle — a missing
`VITE_G2A_API_BASE` throws at module load rather than lazy-failing on
the first fetch.

## 3. Host `dist/` behind `app.guns2ammo.com`

The build output is a static SPA. Any host that serves static files and
supports a client-side-routing fallback works. Three ready-made configs
ship with the repo:

- **Netlify**: `netlify.toml` — points `base = "dashboard-app"`, sets the
  SPA fallback, and pins Node 20. Set `VITE_G2A_API_BASE` under Site
  → Environment Variables before the first build.
- **Vercel**: `vercel.json` — rewrite everything to `/index.html`.
- **Netlify / Cloudflare Pages**: `public/_redirects` is emitted into the
  `dist/` root at build time.

For nginx behind your own reverse proxy:

```nginx
server {
  listen 443 ssl http2;
  server_name app.guns2ammo.com;

  root /var/www/g2a-dashboard;
  index index.html;

  location / {
    try_files $uri /index.html;
  }

  location /assets/ {
    expires 1y;
    add_header Cache-Control "public, immutable";
  }
}
```

### DNS

Point `app.guns2ammo.com` at the host (`CNAME` for Netlify/Vercel, `A`
for a self-hosted node). HTTPS is required — WP application-password
auth over plaintext HTTP will be rejected by modern browsers as an
insecure cross-origin request.

## 4. Verify the live deployment

Once DNS + certs are live, walk through this list:

1. Open `https://app.guns2ammo.com/` — the login screen renders.
2. Sign in with `owner@guns2ammo.com` + your app password. You land on
   Dashboard Home with real numbers from GA4/WooCommerce/Booking.
3. Hard-refresh a deep page (e.g. `/reports`). The SPA fallback should
   keep you on Reports, not bounce to a WP 404.
4. Open **AI Models & RAGs**, click **Test** on a connection — you get a
   provider-specific latency badge, not the generic Bearer smoke test.
5. Open **Automation Center**, change one automation's interval — the
   Weekly Report should now list the new interval, and
   `wp_next_scheduled('g2aba_run_weekly_report')` in wp-cli should show
   the new cadence.
6. Download `tasks.csv` from the Tasks page and open it in Excel —
   RFC 4180 CRLF terminators + escaped quotes.
7. Sign out. Re-open the app — `GET /auth/me` fires, and because the
   session is gone you bounce cleanly to `/login`.

## Rollback

Deploys of the frontend are stateless — reverting to the previous Netlify
/ Vercel deploy is one click and instant. The plugin has no schema
migrations (every store uses `update_option` with defensive read-time
hydration), so reactivating the previous plugin version keeps your data
intact. No manual rollback steps are needed.
