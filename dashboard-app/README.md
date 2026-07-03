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
VITE_G2A_API_BASE=https://guns2ammo.com
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

Sign-in expects a **WordPress application password** (created from a WP user's
profile → Application Passwords screen). The frontend stores
`base64(email:appPassword)` in `localStorage` and sends it as
`Authorization: Basic …` on every request. The plugin validates the
credential and requires the user to hold the `g2a_dashboard` capability.

The regular WP account password is deliberately NOT accepted — application
passwords are individually revocable without disturbing the operator's main
login.

## Build order (matches the plan)

1. Foundation (this drop) — layout, routing, API client, auth, mocks.
2. Business Analysis wire-up to real endpoints.
3. AI Insights + Automation Center wire-up.
4. AI Agents run/history + BridGistic action bridge.
5. Reports + System Health real checks.
6. POS app (`pos.guns2ammo.com`) — separate codebase.
