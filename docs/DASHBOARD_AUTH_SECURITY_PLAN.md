# Dashboard Auth: Current State + Migration Plan (HttpOnly Cookie Sessions + CSRF)

Audit date: 2026-07-15. Read-only audit — this document proposes changes; none have been made.

---

## 1. Current auth flow — precise description

### 1.1 Credential creation and storage

1. User submits email + WordPress **application password** on `/login` (`dashboard-app/src/pages/Login.tsx:16-29`).
2. `api.auth.login` (`dashboard-app/src/lib/api.ts:172-208`) **mints the long-lived credential client-side before the server ever answers**: `const basic = btoa(`${email}:${password}`)` (api.ts:187) and immediately persists it via `writeSession` (api.ts:188).
3. Storage: `localStorage` key **`g2a.auth.token`** (api.ts:41), value `JSON.stringify({token, displayName, role})`. The `token` field IS the credential: `base64(email:appPassword)` — reversible encoding, not a hash.
4. `POST /auth/login` is then called (with that Basic header attached) so the plugin can validate the app password via `wp_authenticate_application_password` and check the `g2a_dashboard` capability (`g2a-business-api/includes/rest/class-auth-controller.php:97-153`). On failure the FE clears storage (api.ts:204-207). The server's response `token` (a throwaway `wp_generate_password(32)`, controller :148) is deliberately discarded by spread ordering (api.ts:195-201).

### 1.2 Request attachment

- Every request: `Authorization: Basic <token>` + `credentials: 'include'` (`api.ts:84-95`). WordPress core's application-password basic-auth path authenticates it.
- CORS: plugin echoes exact allowed origin + `Access-Control-Allow-Credentials: true`, answers OPTIONS preflights pre-auth, only for the `g2a/v1` namespace (`g2a-business-api/includes/class-cors.php:92-124`). Allow-list default `['https://app.guns2ammo.com']` (class-cors.php:36), editable at runtime via the dashboard Settings page → `g2aba_dashboard_settings.allowedOrigins`.

### 1.3 Session validation and logout

- On every app mount, `GET /auth/me` re-validates; 401/403 clears localStorage and redirects to `/login` (`App.tsx:36-55`).
- **Logout is purely client-side** (`api.ts:209-211` removes the localStorage key). The application password remains valid indefinitely on the server. The only server-side kill switch is the nuclear `POST /system/revoke-sessions`, which deletes ALL app passwords for every `g2a_dashboard` user (`includes/system/class-system-actions.php:62-84`).

### 1.4 Risk assessment of the current design

| # | Risk | Severity |
|---|---|---|
| R1 | Durable, reversible credential in `localStorage` — any XSS (or malicious npm dependency, or browser extension) exfiltrates a credential equivalent to the user's app password, valid until manually revoked in wp-admin. | **High** |
| R2 | No logout revocation, no expiry, no idle timeout. Stolen token works forever. | **High** |
| R3 | `POST /auth/login` has `permission_callback => '__return_true'` (class-auth-controller.php:33) and **no rate limiting** (the plugin's `Rate_Limiter` is used only by `/public/opt-out`) — online brute force of app passwords is unthrottled at the app layer. | High |
| R4 | `credentials: 'include'` (api.ts:94) sends WP auth cookies alongside Basic auth. WP cookie auth on REST without a nonce yields user 0, so no CSRF hole today — but it makes the eventual cookie migration observable and means the CORS layer must already run in credentials mode (no `*`). | Low |
| R5 | CORS allow-list is editable through the API it protects (Settings page). An admin-level compromise can add an attacker origin; a typo can lock the dashboard out. | Medium |
| R6 | Production sourcemaps (`vite.config.ts:25`) reveal the exact auth implementation to attackers. | Low/Medium |
| R7 | If `AUTH_KEY` is undefined, model API keys are encrypted with a hardcoded fallback key (`includes/class-secrets.php:62`). | Medium |
| R8 | Export links carry no credential at all (see route matrix) — evidence the header-based scheme doesn't fit browser-native flows. | Functional |

---

## 2. Target architecture: server-managed HttpOnly cookie session + CSRF, same-origin via proxy

Two coupled moves:

1. **Same-origin proxy**: serve the API to the SPA at `https://app.guns2ammo.com/wp-json/g2a/v1/*`, proxied to `https://guns2ammo.com/wp-json/g2a/v1/*`. This makes the session cookie first-party (`SameSite=Lax` works, no third-party-cookie blocking, CORS layer becomes dead code for the dashboard path).
2. **Cookie session**: plugin-issued opaque session token in an HttpOnly cookie, validated by a `rest_authentication_errors`/`determine_current_user` hook, with CSRF protection on unsafe methods.

### 2.1 New/changed REST endpoints (all under `g2a/v1`)

| Endpoint | Verb | Perm | Behavior |
|---|---|---|---|
| `/auth/login` | POST | public + **rate-limited** (reuse `Ops\Rate_Limiter`, e.g. 10/15min/IP and 5/15min/account) | Validate email+password (app password initially; owner decides D2 whether real WP password + 2FA later). On success: create server session record, set cookie `g2a_session=<token>; HttpOnly; Secure; SameSite=Lax; Path=/wp-json/g2a/v1; Max-Age=<ttl>`, return `{displayName, role, csrfToken}`. **Never return the credential.** |
| `/auth/logout` | POST | authenticated + CSRF | Delete the server session record, expire the cookie. (New — no server logout exists today.) |
| `/auth/session` | GET | authenticated (cookie) | Replaces `/auth/me` semantics: `{displayName, role, caps, csrfToken, expiresAt}`. Also the CSRF re-sync point after a reload. Keep `/auth/me` as an alias during migration. |
| `/auth/refresh` | POST | authenticated + CSRF | Sliding renewal: rotate the session token (new cookie + new csrfToken), extend expiry. Called by the client when `expiresAt` is near, or piggyback automatic sliding renewal server-side on any authenticated request older than N minutes (simpler; then `/auth/refresh` is optional — decision D4). |
| `/auth/sessions` (optional, phase 2) | GET/DELETE | admin | List/revoke the current user's active sessions (device management). Makes `/system/revoke-sessions` scalpel-capable instead of nuclear. |

### 2.2 Server session storage

Recommendation: **custom table** `{prefix}g2aba_sessions` (the plugin already owns the table pattern via `Leads_Installer`, `includes/leads/class-leads-installer.php` — copy it):

```
id BIGINT PK, user_id BIGINT, token_hash CHAR(64) (sha256 of the cookie token — never store raw),
csrf_hash CHAR(64), created_at DATETIME, last_seen_at DATETIME, expires_at DATETIME,
ip VARCHAR(45), user_agent VARCHAR(255), revoked_at DATETIME NULL
KEY (token_hash), KEY (user_id), KEY (expires_at)
```

- Token: 32 random bytes (`random_bytes`), base64url in the cookie; DB stores only sha256. Lookup via `hash_equals` on the hash.
- Why not user meta: per-user meta rows holding arrays serialize poorly under concurrent logins, can't be indexed for expiry sweeps, and make "list all active sessions" O(users). Why not WP's native `WP_Session_Tokens`: it's bound to wp-login cookie auth and `LOGGED_IN_KEY` cookie names on the WP origin; the dashboard needs a path-scoped cookie for `/wp-json/g2a/v1` on the app origin via proxy. (Owner may overrule — decision D3.)
- Expiry: idle timeout (e.g. 12h since `last_seen_at`) + absolute cap (e.g. 14 days since `created_at`). Hourly cron sweep of expired rows (plugin already has cron plumbing, `Cron_Scheduler`).
- Auth hook: `determine_current_user` filter — if a valid `g2a_session` cookie is present on a `g2a/v1` route, resolve the user; also re-check `g2a_dashboard` cap per request (cap revocation takes effect immediately).

### 2.3 CSRF mechanism

Cookie auth makes the API CSRF-forgeable; pick **per-session CSRF token** over WP REST nonces:

- WP `X-WP-Nonce` is tied to WP's cookie session (`wp_create_nonce('wp_rest')` keyed by user + session token) and expires in 12–24h with awkward refresh semantics; our sessions are not WP cookie sessions.
- Design: at login, generate a second 32-byte token; return it **in the JSON body** (not a cookie); store its hash in the session row. Client keeps it in memory (module variable — NOT localStorage) and sends `X-G2A-CSRF: <token>` on every non-GET request. Server rejects unsafe methods (POST/PUT/PATCH/DELETE) whose header doesn't hash-match the session row. GET/HEAD/OPTIONS exempt (all GET routes are read-only today — the matrix confirms no state-changing GETs).
- After a page reload the SPA re-fetches `/auth/session` to re-obtain the CSRF token (safe: only an already-authenticated cookie holder can read it; an attacker's cross-site request cannot read responses).
- Defense-in-depth: also validate `Origin`/`Referer` header ∈ {app origin, WP origin} on unsafe methods — cheap and catches header-stripping edge cases.

### 2.4 Rate limiting

- `/auth/login`: `Rate_Limiter('auth.login', 10, 900)` per IP **and** a per-account bucket (`auth.login.user:<user_id>`, 5/900s) so a distributed attacker can't hammer one account. Return 429 + `Retry-After` (pattern already exists at `class-public-controller.php:44-54`).
- `/bridgistic/ask`: add `Rate_Limiter('bridgistic.ask', 30, 3600)` per user — it enqueues rows under the read cap (route matrix mismatch #3).
- `/auth/refresh` + `/auth/session`: light limit (e.g. 60/h/IP) to prevent token-oracle probing.
- Note the limiter trusts `CF-Connecting-IP`/`X-Forwarded-For` (`includes/ops/class-rate-limiter.php:123-136`) — with the proxy in front, ensure only the proxy can reach origin (see 2.6) or an attacker can spoof the IP header directly against origin.

### 2.5 React client changes

1. `api.ts`: delete `AUTH_KEY`/`readSession`/`writeSession` localStorage persistence and the `Authorization: Basic` header (api.ts:41-66, 91); keep `credentials: 'include'`.
2. `login()`: stop calling `btoa`; POST credentials once; hold `{displayName, role}` in React state and `csrfToken` in a module-scoped variable; add `X-G2A-CSRF` header in `httpRaw` for non-GET.
3. `logout()`: call `POST /auth/logout`, then clear in-memory state.
4. Session bootstrap (`App.tsx:36-55`): call `/auth/session` on mount; 401 → login screen (existing behavior, now cookie-driven). Persist nothing about auth in localStorage (a non-sensitive `hasSession` hint is acceptable to skip a login-screen flash).
5. Export links (Tasks.tsx:63, OpsQueue.tsx:158, Reports.tsx:174) start working as-is once the cookie exists — this migration fixes route-matrix mismatch #1 for free.
6. Env: `VITE_G2A_API_BASE` becomes empty/same-origin (`''` → the client already builds `${base}/wp-json/g2a/v1`, api.ts:81-82, so empty base = relative URL). `env.ts:28-34`'s "throw if empty in prod" check must be updated to allow explicit same-origin mode.
7. 401-handling: add a single response interceptor in `httpRaw` — on 401, clear session state and route to `/login` (today only the mount-time `/auth/me` check does this).

### 2.6 Same-origin proxy: app.guns2ammo.com → guns2ammo.com/wp-json/g2a/v1/*

Only `/wp-json/g2a/v1/*` needs proxying (the SPA calls nothing else). Two workable shapes:

**Option A — Cloudflare Worker (fits Netlify/Vercel static hosting; recommended if DNS is already on Cloudflare):**

```js
// Route: app.guns2ammo.com/wp-json/*  (Worker "g2a-api-proxy")
export default {
  async fetch(request) {
    const url = new URL(request.url);
    if (!url.pathname.startsWith('/wp-json/g2a/v1/')) {
      return new Response('Not found', { status: 404 });
    }
    url.hostname = 'guns2ammo.com';           // WP origin
    const upstream = new Request(url, request); // preserves method/body/cookies
    upstream.headers.set('Host', 'guns2ammo.com');
    upstream.headers.set('X-Forwarded-Host', 'app.guns2ammo.com');
    // Shared secret so origin can refuse direct non-proxy traffic on g2a/v1:
    upstream.headers.set('X-G2A-Proxy-Key', PROXY_SHARED_SECRET); // Worker secret binding
    const res = await fetch(upstream);
    // Strip any CORS headers — same-origin now; and never cache authed responses.
    const out = new Response(res.body, res);
    out.headers.delete('Access-Control-Allow-Origin');
    out.headers.delete('Access-Control-Allow-Credentials');
    out.headers.set('Cache-Control', 'no-store');
    return out;
  }
}
```

(Netlify alternative: a `[[redirects]] from="/wp-json/*" to="https://guns2ammo.com/wp-json/:splat" status=200` proxy rule in `netlify.toml` does the same job with less control; cookie `Domain` must NOT be set by WP so the cookie sticks to app.guns2ammo.com — since the g2a plugin sets its own cookie via the REST response this is under our control.)

**Option B — nginx (if app.guns2ammo.com is served from a VPS):**

```nginx
server {
  listen 443 ssl http2;
  server_name app.guns2ammo.com;
  root /var/www/g2a-dashboard/dist;

  location /wp-json/g2a/v1/ {
    proxy_pass https://guns2ammo.com/wp-json/g2a/v1/;
    proxy_ssl_server_name on;
    proxy_set_header Host guns2ammo.com;
    proxy_set_header X-Forwarded-Host app.guns2ammo.com;
    proxy_set_header X-Forwarded-For $remote_addr;
    proxy_set_header X-G2A-Proxy-Key "<shared-secret>";
    proxy_pass_request_headers on;   # cookies + X-G2A-CSRF flow through
    proxy_buffering off;
    add_header Cache-Control "no-store" always;
  }

  location / { try_files $uri /index.html; }  # SPA fallback
  add_header X-Frame-Options SAMEORIGIN always;
  add_header X-Content-Type-Options nosniff always;
  add_header Referrer-Policy strict-origin-when-cross-origin always;
  # TODO: add Content-Security-Policy (none exists today in netlify.toml/vercel.json)
}
```

Origin hardening in the plugin: when `X-G2A-Proxy-Key` is configured, reject `g2a/v1` requests lacking it except from allow-listed cases (wp-cron, admin) — this closes the rate-limiter IP-spoofing hole (2.4) and forces all dashboard traffic through the proxy. After cutover, the entire `class-cors.php` credential-mode surface and the `allowedOrigins` setting can be retired (or kept only for a transition window).

### 2.7 Migration order (each step shippable)

1. Add rate limiting to `/auth/login` (no client change).
2. Ship sessions table + cookie issuance alongside Basic auth (server accepts either).
3. Stand up the proxy; point a staging build at same-origin.
4. Ship the React client change (cookie + CSRF); confirm exports and all admin actions work.
5. Turn off Basic-auth acceptance for `g2a/v1` (filter `application_password_is_api_request` off for the namespace or check+reject in a `rest_authentication_errors` hook), revoke existing app passwords (`/system/revoke-sessions`), disable sourcemaps, remove CORS allow-list from the Settings UI.

---

## 3. Blocking decisions needing the owner's input

| # | Decision | Options / default recommendation |
|---|---|---|
| D1 | **Production host for app.guns2ammo.com** — both `netlify.toml` and `vercel.json` exist. Proxy choice (Worker vs Netlify redirect vs nginx) depends on this. | Recommend: pick one, delete the other config. |
| D2 | **Login credential after migration**: keep WP application passwords as the thing typed into the login form, or accept the real WP password (+ optional TOTP 2FA)? App passwords are phishing-resistant-ish but users must generate them in wp-admin; real passwords need 2FA to be acceptable. | Recommend: real WP password + rate limit now, TOTP phase 2. |
| D3 | **Session store**: custom `g2aba_sessions` table (recommended) vs WP user-meta vs native `WP_Session_Tokens`. | Table. |
| D4 | **Refresh model**: explicit `/auth/refresh` endpoint vs automatic sliding renewal on any request. Also: idle timeout (12h?) and absolute lifetime (14d?) values. | Sliding renewal + 12h/14d. |
| D5 | **Kill Basic auth when?** Hard cutoff date for step 5 (until then the localStorage risk R1 persists), and whether `shop_manager` users exist who must be migrated/notified. | Owner must schedule. |
| D6 | **Role/permission cleanup**: should read-only users see agent prompts and model catalogs (currently admin-only routes behind read-visible UI — route-matrix mismatches #2), and should `bridgistic/ask` require more than read? | Owner call. |
| D7 | **Sourcemaps**: confirm turning off `build.sourcemap` (or `'hidden'` + private upload) for production (`vite.config.ts:25`). | Yes. |
| D8 | **Retire the in-dashboard CORS `allowedOrigins` setting** after same-origin cutover (it is both a lock-out foot-gun and an escalation vector, R5)? | Yes, retire. |
| D9 | **PII retention**: `g2aba_leads` (customer name/email/phone) survives plugin uninstall (`uninstall.php` doesn't drop it) and audit-log/options grow unbounded — define retention/purge policy. | Owner call. |
| D10 | **`AUTH_KEY` fallback** (`class-secrets.php:62`): change to hard-fail if `AUTH_KEY` is missing, and confirm real salts are set on production wp-config. | Yes, hard-fail. |
