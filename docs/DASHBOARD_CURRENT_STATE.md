# Dashboard Stack — Current State Inventory

Audit date: 2026-07-15 (read-only audit; no code was modified).
Scope: `dashboard-app/` (React SPA) and `g2a-business-api/` (WordPress plugin, REST namespace `g2a/v1`).
All paths below are relative to `/home/user/Guns2Ammo-System-Managment/` unless absolute.

---

## 1. dashboard-app (React 18 + TypeScript + Vite 5 + Tailwind 3)

### 1.1 App structure

| Area | Location | Notes |
|---|---|---|
| Entry | `dashboard-app/src/main.tsx` | Mounts `<App/>` |
| Router/auth gate | `dashboard-app/src/App.tsx:29-101` | `BrowserRouter`; session state gates all routes |
| Layout | `dashboard-app/src/components/layout/{AppLayout,Sidebar,TopBar}.tsx` | |
| UI kit | `dashboard-app/src/components/ui/` | Card, EmptyState, ErrorState, PageHeader, Spinner, StatCard |
| API client | `dashboard-app/src/lib/api.ts` (876 lines) | Single transport for ALL network calls |
| Env config | `dashboard-app/src/lib/env.ts` | |
| Data hook | `dashboard-app/src/lib/hooks.ts:15-41` | `useAsync` — no react-query/redux |
| Formatting | `dashboard-app/src/lib/format.ts` | `formatCurrency` divides cents by 100 (line 4-11) |
| Theme | `dashboard-app/src/lib/theme.tsx` | persists `theme` key in localStorage |
| Types | `dashboard-app/src/types/analytics.ts` (461 lines) | Source of truth for on-wire shapes |
| Mocks | `dashboard-app/src/mocks/data.ts` (652 lines) | Bundled fixtures (see §1.6) |
| Pages | `dashboard-app/src/pages/` (24 files) | |

Dependencies (`dashboard-app/package.json:14-20`): react, react-dom, react-router-dom 6, recharts, clsx. No state-management or HTTP library — `fetch` + `useState`/`useAsync` only.

### 1.2 Routes / pages (`dashboard-app/src/App.tsx:59-95`)

| Route | Page component |
|---|---|
| `/login` | `Login.tsx` |
| `/` | `DashboardHome.tsx` |
| `/website-content` | `WebsiteContent.tsx` |
| `/business-analysis` | `BusinessAnalysis.tsx` |
| `/insightistic` | `InsightisticAnalytics.tsx` |
| `/booking-revenue` | `BookingRevenue.tsx` |
| `/membership-revenue` | `MembershipRevenue.tsx` |
| `/woo-store-analytics` | `WooStoreAnalytics.tsx` |
| `/seo-growth` | `SEOGrowth.tsx` |
| `/shooter-insights` | `ShooterInsights.tsx` |
| `/business-gaps` | `BusinessGaps.tsx` |
| `/ai-insights` | `AIInsights.tsx` |
| `/automation-center` | `AutomationCenter.tsx` |
| `/ai-agents` | `AIAgents.tsx` |
| `/email-management` | `EmailManagement.tsx` |
| `/leads` | `Leads.tsx` |
| `/bridgistic` | `BridGistic.tsx` |
| `/ai-models` | `AIModels.tsx` (`AIModelsRAGs`) |
| `/reports` | `Reports.tsx` |
| `/ops-queue` | `OpsQueue.tsx` |
| `/tasks` | `Tasks.tsx` |
| `/system-health` | `SystemHealth.tsx` |
| `/settings` | `Settings.tsx` |
| `*` | redirect to `/` (authed) or `/login` |

### 1.3 State management

- No global store. Session is a single `useState` in `App.tsx:30` seeded from `readSession()` (localStorage).
- Per-page data via `useAsync` (`src/lib/hooks.ts:15`), each page owns its fetches.
- On every mount `App.tsx:36-55` calls `GET /auth/me` to validate the stored token; failure clears localStorage and bounces to `/login`.

### 1.4 Environment variables (`dashboard-app/src/lib/env.ts`)

| Var | Where | Behavior |
|---|---|---|
| `VITE_G2A_API_BASE` | `env.ts:18` | WP origin (e.g. `https://guns2ammo.com`); client appends `/wp-json/g2a/v1` (`api.ts:81-82`). **Production build throws at module load if unset and mocks off** (`env.ts:28-34`). |
| `VITE_G2A_USE_MOCKS` | `env.ts:19-26` | Dev: mocks ON unless `'0'`. Prod: mocks OFF unless explicitly `'1'` ("CI demo" escape hatch). |
| `G2A_WP_URL` | `vite.config.ts:17` | Dev-server-only: Vite proxies `/wp-json` to this target (default `https://guns2ammo.com`). Not exposed to client code. |

Example files: `dashboard-app/.env.example` (mocks on, empty API base), `dashboard-app/.env.production.example` (sets `VITE_G2A_API_BASE=https://guns2ammo.com`). No real `.env`/`.env.production` is committed.

### 1.5 Build config (`dashboard-app/vite.config.ts`)

- **`build.sourcemap: true` (`vite.config.ts:25`) — production sourcemaps are generated and, with the current Netlify/Vercel configs, published to the world.** Recommend `false` or `'hidden'` for production.
- `outDir: 'dist'`, `target: 'es2020'`, alias `@ → src`.
- Dev proxy for `/wp-json` (`vite.config.ts:15-21`) — dev runs same-origin, so CORS issues do not show up locally (they will in prod).
- `npm run build` = `tsc -b && vite build` (`package.json:9`).

### 1.6 Mock data wiring ("blast radius")

- Single import site: `src/lib/api.ts:8` — `import * as mock from '@/mocks/data'` is **unconditional**, so the full 652-line fixture file ships inside every production JS bundle even when `VITE_G2A_USE_MOCKS` is off (the switch is runtime `env.useMocks`, not build-time dead-code elimination via a constant the bundler can fold — `env.useMocks` is a computed boolean, so Rollup cannot tree-shake the mock module).
- Every one of the ~50 API methods in `api.ts` has an `env.useMocks ?` branch (e.g. `api.ts:222-256` analytics, `api.ts:421-457` leads). Mutating methods throw `'... unavailable in mock mode'`.
- Mock login (`api.ts:173-181`): any email/password mints a fake `owner` session.
- Additional mock-only constants live in `api.ts:840-863` (`MOCK_PURPOSES`, `MOCK_ROUTING_LABELS`, `MOCK_ROUTING`).
- Fixture content of note (`src/mocks/data.ts`):
  - Fictional customer PII-shaped records: names/emails/phones in `emailOverview.recentSubmissions` (lines 419-423), `leads` (lines 460-515, e.g. "Priya Sharma / priya@example.com / 480-555-0142"), `auditLog` (line 349). All emails use `example.com` / `spamdomain.example`; phones are 555-prefixed fakes. Not real PII, but they render as if real in any deploy with mocks on.
  - `"card ending in 4128"` in a mock email body (line 312) — fake, but looks like payment data in screenshots.
  - Masked fake API keys `sk-ant-****abcd`, `sk-****9f12`, `AIza****kL7q`, `sk-or-****771a` (lines 275-279) — not usable secrets.
  - `ownerEmail: 'owner@guns2ammo.com'` (line 405).
  - No `Math.random()` anywhere in `src/` — trend series are deterministic `Math.sin` curves (`data.ts:34-41`), pinned to a fixed date `2026-07-02` (line 29).

### 1.7 Authentication implementation (frontend)

Full detail in `DASHBOARD_AUTH_SECURITY_PLAN.md`. Summary of the code facts:

- Credential = `base64(email:applicationPassword)`, minted client-side with `btoa` (`api.ts:187`) **before** the `/auth/login` handshake, stored in `localStorage` under key `g2a.auth.token` (`api.ts:41,54-66`) inside a JSON `Session {token, displayName, role}`.
- Attached to every request as `Authorization: Basic <token>` (`api.ts:91`) plus `credentials: 'include'` (`api.ts:94`).
- Logout is client-side only — `writeSession(null)` (`api.ts:209-211`); the WP application password remains valid.
- `Session.role` union includes `'staff'` (`api.ts:51`) which the backend never emits (backend produces `owner|manager|analyst`, `class-auth-controller.php:155-163`) — dead value.
- Export downloads are plain `<a href>` URLs with **no** Authorization header (`api.ts:398-409`; used at `src/pages/Tasks.tsx:63`, `src/pages/OpsQueue.tsx:158`, `src/pages/Reports.tsx:174`) — see route matrix for why these 401 in production.

### 1.8 Deployment config

- `dashboard-app/netlify.toml` — base `dashboard-app`, publish `dashboard-app/dist`, Node 20, SPA fallback redirect, immutable cache on `/assets/*`, security headers (`X-Frame-Options SAMEORIGIN`, `X-Content-Type-Options nosniff`, `Referrer-Policy`). Comments target `app.guns2ammo.com` via CNAME to Netlify.
- `dashboard-app/vercel.json` — parallel config for Vercel (same rewrites/headers). **Two deploy targets are configured; the actual production host is undecided/ambiguous.**
- `dashboard-app/public/_redirects` — Netlify SPA fallback duplicate.
- No `Content-Security-Policy` header is configured on either host config.
- No proxy of `/wp-json` is configured on either host — production runs cross-origin against `guns2ammo.com` (CORS mode).

---

## 2. g2a-business-api (WordPress plugin)

### 2.1 Plugin structure

- Bootstrap: `g2a-business-api/g2a-business-api.php` (v0.1.1, namespace constant `G2ABA_REST_NAMESPACE = 'g2a/v1'`, line 27).
- `includes/class-plugin.php:15-62` wires: REST router, CORS, leads installer, lead ingestion, automation reseed, cron registration for insights/agents/11 automation handlers, BridGistic executor, and 4 admin screens (Settings, Email Drafts, Cancellations, Opt-Outs).
- `includes/class-router.php:47-73` registers 23 REST controllers (listed in §2.2).
- Base controller `includes/rest/class-rest-controller.php:16-57`: shared `read_permissions_check` / `admin_permissions_check` and `ok()` helper (adds `Cache-Control: private, max-age=15`).
- Capabilities `includes/class-capabilities.php`: `g2a_dashboard` (read) and `g2a_dashboard_admin` (admin); `manage_options` bypasses both (lines 35-41). Activation grants read+admin to `administrator`, read to `shop_manager` (lines 22-33).
- Money: **USD cents (int) on the wire everywhere** — `includes/class-money.php`.
- Response cache: transient-backed `includes/class-cache.php` (60s analytics, 300s SEO/insightistic).
- Uninstall `g2a-business-api/uninstall.php`: deletes core options, removes caps from administrator/shop_manager/editor. Note: it does **not** drop the `g2aba_leads` table nor delete `g2aba_dashboard_settings`, `g2aba_tasks`, `g2aba_audit_log`, `g2aba_email_drafts`, `g2aba_cancellations`, opt-out records — data (incl. lead PII) survives uninstall.

### 2.2 REST routes and permission_callback per route

Every route uses the two capability-checked callbacks from the base controller **except two public routes** (flagged in bold). Full frontend↔backend mapping is in `DASHBOARD_API_ROUTE_MATRIX.md`.

| Controller (includes/rest/) | Route(s) | Verb | permission_callback |
|---|---|---|---|
| class-auth-controller.php:27 | `/auth/login` | POST | **`__return_true` (public — by design, but has NO rate limiting; see auth plan §5)** |
| class-auth-controller.php:48 | `/auth/me` | GET | read |
| class-analytics-controller.php:45 | `/analytics/{overview,bookings,memberships,store,seo,insightistic,shooter-insights}` | GET | read |
| class-insights-controller.php:24-50 | `/ai/insights`; `/ai/insights/{id}/approve`; `/ai/insights/{id}/dismiss` | GET; POST; POST | read; admin; admin |
| class-gaps-controller.php:31-47 | `/insights/business-gaps`; `.../{id}/create-task` | GET; POST | read; admin |
| class-automations-controller.php:25-57 | `/automations`; `/automations/{id}/toggle`; `/automations/{id}` | GET; POST; PATCH/PUT | read; admin; admin |
| class-agents-controller.php:22-67 | `/agents`; `/agents/{id}/run`; `/agents/{id}/history`; `/agents/{id}/prompt` | GET; POST; GET; GET+POST | read; admin; read; **admin for GET too** |
| class-models-controller.php:39-99 | `/model-connections` (GET,POST); `/{id}` (PATCH/PUT,DELETE); `/{id}/key` (POST); `/{id}/test` (POST); `/{id}/catalog` (GET) | | read,admin; admin,admin; admin; admin; **admin (GET)** |
| class-routing-controller.php:24-36 | `/model-routing` | GET; PUT/POST | read; admin |
| class-namespaces-controller.php:25 | `/system/namespaces` | GET | read |
| class-health-controller.php:19-33 | `/system/health`; `/system/integrations` | GET | read |
| class-site-health-controller.php:18 | `/system/site-health` | GET | read |
| class-system-controller.php:26-50 | `/system/rotate-keys`; `/system/revoke-sessions`; `/system/rag/rebuild` | POST | admin |
| class-brain-controller.php:28-79 | `/brain/query`; `/brain/stats`; `/brain/ingest` | GET; GET; POST | read; read; admin |
| class-bridgistic-controller.php:22-64 | `/bridgistic/ask`; `/bridgistic/actions`; `.../{id}/approve`; `.../{id}/reject` | POST; GET; POST; POST | **read (ask WRITES to the action queue** — deliberate: execution still requires admin approval, but a read-only user can fill the queue); read; admin; admin |
| class-content-controller.php:33-44 | `/content/{posts,pages,media,categories,tags}` | GET | read (in-process proxy of `wp/v2`, whitelisted params, relays `X-WP-Total*` headers) |
| class-email-overview-controller.php:24 | `/emails/overview` | GET | read |
| class-ops-controller.php:31-105 | `/email-drafts`; `.../{id}/send`; `.../{id}/discard`; `/cancellations`; `.../{id}/mark-completed`; `.../{id}/drop`; `/audit-log` | GET;POST;POST;GET;POST;POST;GET | read;admin;admin;read;admin;admin;read |
| class-public-controller.php:25 | `/public/opt-out` | POST | **`__return_true` (public — HMAC-token-verified + rate-limited 20/h/IP, class-public-controller.php:42-71)** |
| class-reports-controller.php:24-50 | `/reports`; `/reports/{id}/run-now`; `/reports/{id}/latest` | GET; POST; GET | read; admin; read |
| class-settings-controller.php:22-36 | `/settings` | GET; PUT/POST | read; admin |
| class-tasks-controller.php:24-65 | `/tasks` (GET,POST); `/tasks/{id}/resolve`; `/tasks/{id}/dismiss` | | read,admin; admin; admin |
| class-export-controller.php:30-56 | `/export/tasks.csv`; `/export/audit-log.csv`; `/export/reports/{id}.txt` | GET | read |
| class-leads-controller.php:21-63 | `/leads`; `/leads/stats`; `/leads/{id}` (GET, PATCH/PUT) | | read; read; read,admin |

No route is missing a `permission_callback`. The only `__return_true` routes are `/auth/login` and `/public/opt-out`, both intentional; `/auth/login` lacks brute-force protection (the plugin's `Rate_Limiter` is only used by `/public/opt-out`).

### 2.3 Persistence

- **Custom table (only one):** `{prefix}g2aba_leads` — `includes/leads/class-leads-installer.php:58-78`; dbDelta versioned via `g2aba_leads_db_version`, installed from both activation and `Plugin::run()`. Columns include `contact_name`, `contact_email`, `contact_phone` (real customer PII once live).
- **Everything else is `wp_options`-backed:** `g2aba_agents`, `g2aba_automations`, `g2aba_models`, `g2aba_secrets` (encrypted), `g2aba_dashboard_settings`, `g2aba_tasks`, `g2aba_audit_log`, `g2aba_email_drafts`, `g2aba_cancellations`, `g2aba_bridgistic_queue`, `g2aba_insights_cache`, `g2aba_opt_outs`, `g2aba_reports*`, `g2aba_agent_history_*`, `g2aba_agent_prompt_history_*`, `g2aba_key_rotations`, `g2aba_rag_state`, `g2aba_gsc_site_url`, `g2aba_ga4_property_id`, `g2aba_gbp_location_id`, `g2aba_*_last_error` markers. Unbounded audit/history growth in options is a scale risk.

### 2.4 Cron jobs

| Hook | Schedule | Registered at |
|---|---|---|
| `g2aba_generate_insights` | hourly (self-scheduling) | `includes/ai/class-insight-generator.php:32-39`; manual kick from admin settings page (`class-settings-page.php:226`) |
| `g2aba_run_agent` | single event, +5s after `POST /agents/{id}/run` | `includes/agents/class-agent-runner.php:48-58`; scheduled in `class-agents-controller.php:119` |
| `g2aba_run_booking_reminder`, `g2aba_run_waiver_reminder`, `g2aba_run_membership_renewal`, `g2aba_run_abandoned_inquiry`, `g2aba_run_low_stock`, `g2aba_run_seo_drop_alert`, `g2aba_run_weekly_report`, `g2aba_run_ladies_upsell`, `g2aba_run_agent_churn_risk`, `g2aba_run_agent_refresh_hourly`, `g2aba_run_agent_refresh_daily` | per-automation interval (hourly/twicedaily/daily/weekly), managed by `Cron_Scheduler::apply()` (`includes/automation/class-cron-scheduler.php:23-43`); defaults seeded in `class-automation-store.php:178-308` |
| `g2aba_rag_rebuild` | single event +30s from `/system/rag/rebuild` | `includes/system/class-system-actions.php:98-99` — **the rebuild worker is a stub; nothing subscribes yet** |
| `g2aba_bridgistic_action_approved` | action hook (not cron) consumed by `includes/bridgistic/class-executor.php:28` | |

### 2.5 External integrations (`includes/integrations/`)

| Client | Purpose | Config/secret source |
|---|---|---|
| `class-anthropic-client.php` | Claude Messages API for insights + agents; fallback model pin `claude-opus-4-8` (line 28) | key in `Secrets` under `model:<connection-id>` (default `anthropic-primary`) |
| `class-ga4-client.php` | GA4 Data API (Insightistic page) | Google service account (below) + `g2aba_ga4_property_id` |
| `class-gsc-client.php` | Search Console (SEO page) | service account + `g2aba_gsc_site_url` |
| `class-gbp-client.php` | Business Profile performance + reviews | service account + `g2aba_gbp_location_id`; requires Google allowlisting (header comment lines 10-13) |
| `class-google-service-account.php` / `class-google-jwt-signer.php` | JWT-signed OAuth token minting; token cached in `g2aba_google_access_token_*` | service-account JSON pasted in admin settings, stored via `Secrets` |
| In-process (not HTTP): `Ai\Brain_Client` → g2a-pos-core `\G2A\POS\Ai\BrainFacade` (RAG store), `Email_Overview_Provider` → Formistic/Messageistic dashboards, providers → WooCommerce/Booking Engine/Memberistic data. All degrade to empty/inactive shapes when the sibling plugin is absent (`includes/ai/class-brain-client.php:27-40`). |

Secrets at rest: `includes/class-secrets.php` — AES-256-GCM, key derived from `AUTH_KEY`. **If `AUTH_KEY` is undefined it silently falls back to the hardcoded string `'g2aba-fallback-key-not-secure'` (`class-secrets.php:62`)** — worth converting to a hard failure.

### 2.6 AI agent modules

- `includes/agents/class-agent-store.php` — seeds/persists agent definitions (departments: seo, analyst, booking, support, email, sales, store, reports, automation, compliance…), prompt templates with `{{snapshot}}` placeholder + versioned prompt history.
- `includes/agents/class-agent-runner.php` — cron-side execution: routes department → purpose via `Model_Routing_Store` (`purpose_for_department`, lines 111-121), builds per-department context from providers/leads/brain (all wrapped in `safe_call`), one Anthropic call; `email` department drafts replies for up to 5 open enquiries into the Email_Draft_Store (owner approves sends).
- `includes/agents/class-agent-history.php` — per-agent run history.
- `includes/ai/class-insight-generator.php` — hourly business-insight generation into `g2aba_insights_cache`.
- `includes/bridgistic/` — NL command bridge: `class-classifier.php` (read/draft/action verb rules — mirrored client-side in `api.ts:578-595`), `class-action-queue.php`, `class-intent-router.php`, `class-executor.php` (runs only after owner approval).
- `includes/routing/class-model-routing-store.php` — purpose→model-connection map behind `/model-routing`.

### 2.7 Data realism / placeholder behavior (backend)

- Providers return **empty skeletons, not fake numbers**, when an integration is unconfigured or errors (e.g. `class-seo-provider.php:26-58` `empty_payload()`; errors recorded to `g2aba_gsc_last_error` for System Health). No `rand()`/`mt_rand()` synthetic metrics anywhere in `includes/`.
- `/system/rag/rebuild` is an honest stub (queues an event nothing consumes yet — `class-system-actions.php:88-107`).
- `/system/rotate-keys` is a soft marker (flags keys for rotation, does not delete) — `class-system-actions.php:34-48`.
- `/system/revoke-sessions` genuinely deletes all application passwords for users holding `g2a_dashboard` (`class-system-actions.php:62-84`) — this also kills the caller's own credential.

### 2.8 Secrets / credentials scan results (both codebases)

- **No hardcoded live credentials, API keys, or tokens found** in `dashboard-app/` or `g2a-business-api/` (searched for `sk-ant-`, `AIza`, `AKIA`, bearer tokens, `api_key => '...'`, password literals; also tests/).
- Findings worth tracking (locations only, no secret values exist to reproduce):
  1. `g2a-business-api/includes/class-secrets.php:62` — insecure hardcoded fallback key material if `AUTH_KEY` missing.
  2. `dashboard-app/src/mocks/data.ts:275-279` — fake masked provider keys (cosmetic only).
  3. `dashboard-app/src/mocks/data.ts:405` + `dashboard-app/src/pages/Login.tsx:60` — real-looking owner email `owner@guns2ammo.com` as fixture/placeholder.
  4. Fictional customer fixtures (names/emails/phones) in `dashboard-app/src/mocks/data.ts:419-423, 460-515, 349`; ships in the production bundle (§1.6).
  5. `dashboard-app/vite.config.ts:25` — `sourcemap: true` publishes full source (including mock fixtures and API-shape comments) with production deploys.
