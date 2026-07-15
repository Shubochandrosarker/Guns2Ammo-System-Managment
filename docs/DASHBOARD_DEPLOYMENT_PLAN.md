# Dashboard Deployment Plan — app.guns2ammo.com on the WPistic VPS

**Decision (owner, 2026-07-15):** the dashboard is self-hosted on the owner's
VPS (`server.wpistic.cloud`) instead of Netlify/Vercel. This is the preferred
architecture: it enables the true same-origin API proxy, so the session
cookie runs `SameSite=Lax` with **zero CORS**.

## Architecture

```
Browser ── https://app.guns2ammo.com          (Cloudflare, proxied, Full-strict)
              │
              ▼
        VPS server.wpistic.cloud (nginx)
              ├── /                     → static SPA  (/var/www/g2a-dashboard/current/dist)
              ├── /wp-json/g2a/v1/*     → proxied to https://guns2ammo.com/wp-json/g2a/v1/*
              └── /wp-json/<anything else> → 404 (no open proxy)
```

Files in the repo:
- `dashboard-app/deploy/nginx-app.guns2ammo.com.conf` — full nginx vhost
  (TLS, security headers, API proxy with no-store, login rate limit,
  immutable asset caching, SPA fallback, /healthz).
- `dashboard-app/deploy/deploy.sh` — atomic-release deploy + rollback
  (typecheck → lint → tests → build → source-map/mock safety gates →
  upload → symlink switch → health check → auto-rollback on failure,
  keeps last 5 releases).

## One-time VPS setup

1. `sudo mkdir -p /var/www/g2a-dashboard/{releases,shared}` and create a
   low-privilege `deploy` user owning that tree (SSH key auth only).
2. Install the nginx vhost, install the TLS cert (Cloudflare **Origin
   Certificate** recommended with Full-strict at the edge), `nginx -t &&
   systemctl reload nginx`.
3. Cloudflare DNS: `app` A/AAAA record → VPS IP, **proxied**. SSL mode
   Full (strict). No "Cache Everything" rule on this host. Optional:
   Cloudflare rate-limiting rule on `app.guns2ammo.com/wp-json/g2a/v1/auth/*`.
4. On the WordPress origin: once the same-origin proxy is live, set the
   session cookie SameSite to `Lax` (filter `g2aba_session_samesite`) and
   remove `app.guns2ammo.com` from any CORS allowlist — same-origin needs
   none.

## Production environment (fixed)

```
VITE_G2A_API_BASE=/wp-json/g2a/v1   # relative — same origin through the proxy
VITE_G2A_USE_MOCKS=0                # hard-off; build refuses mocks in prod
```

## Each deploy

From `dashboard-app/`: `./deploy/deploy.sh deploy`
(SSH env: `DEPLOY_HOST=server.wpistic.cloud DEPLOY_USER=deploy`.)

The script enforces the spec's gates: locked installs (`npm ci`),
typecheck, lint, tests, production build, **fails if any source map or
mock marker is present in `dist/`**, uploads to a timestamped release,
switches the `current` symlink atomically, health-checks
`/healthz` for the new release id, and **rolls back automatically** if the
health check fails. `./deploy/deploy.sh rollback` restores the previous
release in seconds.

## Rollback rehearsal (required before first production use)

1. Deploy twice (two releases present).
2. Run `./deploy/deploy.sh rollback`; confirm /healthz shows the previous
   release id and the app loads.
3. Deploy again to return to latest.

## Out of scope on this host

The POS stays at pos.guns2ammo.com; regulated transaction workflows are
not part of this dashboard host. The WordPress origin (guns2ammo.com)
hosting is unchanged.
