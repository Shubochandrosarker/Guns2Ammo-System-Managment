# Guns2Ammo Business Control Center — WebApp

The centralized business dashboard that will live at **app.guns2ammo.com**.
This is the owner/manager brain of the business: analytics, AI insights,
automation, agents, model connections, and the BridGistic command bridge.

> The Point-of-Sale interface (**pos.guns2ammo.com**) is intentionally **separate**.
> Do not add selling-workflow UI here.

## Stack

- React 18 + TypeScript
- Vite 5
- Tailwind CSS 3
- React Router 6
- Recharts (for time-series and bar charts)

## Run locally

```bash
cd dashboard-app
npm install
cp .env.example .env
npm run dev
```

The dev server starts at http://localhost:5173. With
`VITE_G2A_USE_MOCKS=1` (the default), every page renders against bundled
sample data so the whole app is browsable without a live WordPress.

To hit real data:

```
VITE_G2A_USE_MOCKS=0
# Full REST base, including /wp-json/g2a/v1 (leave unset in dev to use the
# relative default that rides the Vite proxy):
VITE_G2A_API_BASE=https://guns2ammo.com/wp-json/g2a/v1
```

## Modules

The sidebar matches the frontend naming the client asked for:

| Sidebar label            | Route                    | Notes |
| ------------------------ | ------------------------ | ----- |
| Dashboard Home           | `/`                      | Revenue + booking + membership + SEO + AI highlights |
| Business Analysis        | `/business-analysis`     | Where revenue comes from + growth signals |
| Insightistic Analytics   | `/insightistic`          | Combined Insightistic feed |
| Booking Revenue          | `/booking-revenue`       | Range / class / event bookings |
| Membership Revenue       | `/membership-revenue`    | Members, MRR, churn, plan performance |
| Woo Store Analytics      | `/woo-store-analytics`   | Categories, brands, best-sellers, slow-movers |
| SEO Growth               | `/seo-growth`            | GSC clicks, top/dropping pages, queries |
| Shooter Insights         | `/shooter-insights`      | Customer segments (frontend naming) |
| Business Gaps            | `/business-gaps`         | Evidence-backed problems + fixes |
| AI Insights              | `/ai-insights`           | AI-generated recommendations |
| Automation Center        | `/automation-center`     | Triggers, actions, runs |
| AI Agents                | `/ai-agents`             | Agent department cards |
| Email Management         | `/email-management`      | Classified inbox + draft-and-approve |
| BridGistic               | `/bridgistic`            | Natural-language command bridge |
| AI Models & RAGs         | `/ai-models`             | Provider connections + RAG stores |
| Reports                  | `/reports`               | Scheduled and on-demand reports |
| System Health            | `/system-health`         | Plugins, APIs, cron, webhooks, AI, security |
| Settings                 | `/settings`              | Roles, connections, defaults |

## API contract

The dashboard talks to the **[`g2a-business-api`](../g2a-business-api/README.md)**
WordPress plugin mounted at `/wp-json/g2a/v1/*`. See `src/lib/api.ts` for the
exact routes each page expects. Domain types live in `src/types/analytics.ts` —
treat that file as the source of truth for on-wire shapes.

Money is always transmitted and stored in **USD cents** (integer). Only the
UI divides by 100 when rendering.

### Auth against a live WordPress

**This section previously described Basic auth with an application password
kept in `localStorage`. That is not what the app does, and it is the opposite
of what it does.** The implemented contract (see the header of
`src/lib/api.ts`) is:

- The session credential is an **HttpOnly cookie** the server sets on
  `POST /auth/session/login`. JavaScript never reads, writes or stores it —
  every request simply carries it via `credentials: 'include'`.
- A per-session **CSRF token** comes back in the login/session JSON and is
  held **in module memory only** — never `localStorage`, never
  `sessionStorage`. Every non-GET request sends it as `X-G2A-CSRF`.
- `401` anywhere clears client auth state and routes to `/login`. A `403`
  with code `g2aba_csrf_failed` re-hydrates the token once via
  `GET /auth/session` and retries the request exactly once.

The cookie being same-origin is why nginx proxies `/wp-json/g2a/v1/` rather
than the app calling `guns2ammo.com` directly — a cross-origin request would
not carry it.

## Testing

```bash
npm test
```

`scripts/run-tests.mjs` bundles every `src/**/*.test.ts` with esbuild (already
a Vite dependency — no test framework is installed) and runs it. It exits
non-zero if it finds no test files, so an empty suite cannot masquerade as a
pass.

Current coverage:

- **`src/lib/api.ts`** — the envelope transport: a `200` that carries
  `success:false`, a `200` that is not an envelope at all, structured errors
  recovered from non-2xx bodies, and content-list pagination. That last one
  caught a live bug — `Number(null)` is `0`, so a missing `X-WP-Total` header
  was being read as "0 results" instead of falling back to the item count.
- **`src/lib/lazy.ts`** — which failures count as "the chunk this tab wants
  was deleted by the last deploy" (five real browser wordings), which do not
  (a `TypeError` from a bad API shape must not trigger a reload loop), and
  that recovery reloads exactly once.
- **`src/lib/redirect.ts`** — the post-sign-in redirect guard. The refusal
  cases matter most: an absolute URL, a protocol-relative `//host`, a
  backslash variant and a `javascript:` URL all fall back to `/` rather than
  turning the login screen into an open redirect.

## Production behaviour worth knowing

- **Surviving a deploy.** Releases get new fingerprinted filenames and old
  ones are pruned, so an already-open tab asks for chunks that are gone.
  `src/lib/lazy.ts` reloads the page once on that specific failure; anything
  else — and any second failure — goes to an `ErrorBoundary` instead.
- **Error boundaries.** One around the whole app, one inside `AppLayout`
  around the routed page, so a single screen throwing leaves the sidebar and
  every other screen usable rather than emptying `<div id="root">`.
- **Deep links.** A signed-out hit on `/waivers?status=active` remembers where
  it was going and lands there after sign-in.
- **No white flash.** `public/theme-boot.js` applies the saved theme in
  `<head>` before the first paint; `src/lib/theme.tsx` only re-applies it.
  Keep the two in sync.
- **Not indexable.** `public/robots.txt` plus a `noindex` meta tag; both are
  enforced by `scripts/verify-build.sh`.

## Deploying

```bash
./deploy/deploy.sh deploy      # ten steps, atomic release, auto-rollback
./deploy/deploy.sh rollback    # instant, to the previous release
```

Preflight runs before anything is built: lockfile present, API base set and
of a valid shape. After the build, `scripts/verify-build.sh` gates the
bundle — no source maps, no dev fixtures (via a tripwire token that exists
only in `src/mocks/data.ts`), API base actually compiled in, `noindex` and
`robots.txt` present, and no inline `<script>` that the CSP would block. CI
runs that same script on every pull request, so a regression fails on the PR
rather than mid-deploy.

After the atomic switch it checks `/healthz` for the new release id, probes
the API through the nginx proxy — a `404` there means every screen would be
empty even though the files are being served — and probes the live security
headers, which is how a refused `nginx -t` reload (still serving the old
vhost) gets noticed.

The two nginx files in `deploy/` both need installing; see
[`DEPLOYMENT.md`](../DEPLOYMENT.md#2-serve-the-app-and-the-api-from-the-same-origin).

## Build order (matches the plan)

1. Foundation (this drop) — layout, routing, API client, auth, mocks.
2. Business Analysis wire-up to real endpoints.
3. AI Insights + Automation Center wire-up.
4. AI Agents run/history + BridGistic action bridge.
5. Reports + System Health real checks.
6. POS app (`pos.guns2ammo.com`) — separate codebase.
