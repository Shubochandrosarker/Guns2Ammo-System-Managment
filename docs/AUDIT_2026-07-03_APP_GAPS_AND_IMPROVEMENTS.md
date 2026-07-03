# Guns2Ammo Business Control Center — App Audit

_Snapshot date: 2026-07-03_

_Scope: everything the client has said "yes, ship" to since Phase 1. This is a fact-based inventory (what's live, what's stubbed, what's missing) followed by a ranked list of advanced improvements._

## 1. What's shipped

### 1.1 `dashboard-app/` (React SPA at `app.guns2ammo.com`)

| Module | Live data | Actions | Notes |
| --- | --- | --- | --- |
| Dashboard Home | ✅ real from providers | none | Revenue + booking + membership + MRR + SEO + AI highlights + system health critical |
| Business Analysis | ✅ real | none | Channel bars, growth signals, category revenue, booking trend |
| Insightistic Analytics | ✅ real (GA4) | none | Sessions/engaged/revenue/bounce + sessions trend |
| Booking Revenue | ✅ real | none | Per-type + paid/unpaid + detail table |
| Membership Revenue | ✅ real | none | Plan performance + renewal picture |
| Woo Store Analytics | ✅ real | none | Category, brand, best-sellers, refunds, slow-movers |
| SEO Growth | ✅ real (GSC) | none | Clicks trend + rising/dropping + top queries |
| Shooter Insights | ⚠️ **static** | none | Segments + follow-up opportunities are hard-coded |
| Business Gaps | ✅ real | "Create task" button is a **stub** | 7 rules incl. 2 GBP rules |
| AI Insights | ✅ real (from cache) | Approve/Dismiss are **stubs** | Populated by hourly cron |
| Automation Center | ✅ real | Toggle is **live** (schedules WP-Cron) | 9 client-requested automations seeded |
| AI Agents | ✅ real | Run/History/Edit prompt are live | Prompt drawer loads + saves + shows version history |
| Email Management | ✅ real drafts | Send/Discard are live | Left-side category counts are static |
| BridGistic | ✅ real | ask/approve/reject are live | Deep-link `?q=` supported |
| AI Models & RAGs | ✅ real | Test button is a **stub** | RAG stores are hard-coded copy — no backend yet |
| Reports | ⚠️ **static** | Run now / Open latest are **stubs** | List is hard-coded copy |
| Ops Queue | ✅ real | Mark completed / Drop live; Audit log tab live | |
| System Health | ✅ real | Re-run checks is a **stub** | |
| Settings | ⚠️ **static** | Danger-zone buttons are **stubs** | Displays connection state, not editable |

### 1.2 `g2a-business-api/` WordPress plugin

32 REST routes across 11 controllers; 184 PHPUnit tests / 406 assertions all passing.

Integrations shipped: WooCommerce, G2A Booking Engine, Memberistic Membership Solutions, WPistic Contact Form, GSC (service-account), GA4 (service-account), GBP (service-account, allowlist-gated), Anthropic Messages API, Messageistic (as a trigger consumer), wp_mail.

Handlers shipped: 9 (weekly report, low stock, SEO drop, membership renewal, booking reminder, waiver reminder, abandoned inquiry, ladies upsell, churn risk). All **draft-only** — the audited `Email_Sender` is the single point at which mail leaves the system, gated by category-scoped opt-outs + rate-limited unsubscribe endpoint + RFC 8058 one-click headers.

Ops surfaces: `Email_Draft_Store`, `Cancellation_Queue`, `Audit_Log`, `Opt_Out_Store`, `Rate_Limiter`, 3 WP-admin pages (Settings, Email Drafts, Cancellations, Opt-outs, so actually 4).

Agent runtime: 6 seeded agents (SEO, Analyst, Booking, Membership, Inventory, Reports); Anthropic-backed, per-agent history ring, editable prompt templates with version history.

## 2. Gaps (things the app claims to do but doesn't yet)

Ranked by severity — user-visible correctness first, then invisible-but-important second, then polish.

### 2.1 Correctness gaps

1. **Shooter Insights page renders hard-coded segments.** There is no `/analytics/shooters` route and no customer-segment provider. Users can't act on the numbers because they aren't real.
2. **Reports page is hard-coded.** No backing store for report configs, no report generator (only the weekly report handler exists — no bookkeeping of past runs).
3. **"Approve / Dismiss" on AI Insights don't do anything.** Frontend-only click handlers with no server round-trip. Insights don't get filtered or converted to tasks.
4. **"Create task" on Business Gaps is a stub.** No task store.
5. **"Test" on AI Models & RAGs POSTs, but the RAG store panel below is static copy.** No RAG-index backend exists.
6. **`Settings` module renders read-only text.** Editing anything on that page still requires WP-admin.
7. **Automation Center's toggle live** but "New automation" button in the header opens nothing.
8. **`Email Management` category counts on the left are static** (they were hardcoded in Phase 1). Only the "Pending owner approval" panel is real.

### 2.2 Invisible but important

9. **No dashboard-side audit log paging.** `GET /audit-log?limit=100` is the whole surface; older entries are unreachable from the UI.
10. **No CSV export** for audit log, email drafts, cancellations, opt-outs. Compliance-adjacent workflows will want this.
11. **No dashboard view of the opt-out list.** Owners only have the WP-admin page. If the operator lives in the SPA, they don't see who is opted out.
12. **No pagination or search on any of the ops queues (drafts, cancellations, audit log).** Fine at low volume; will bite at 1000+ entries.
13. **BridGistic action queue is client-transient.** History resets on page reload (the fetch is only for `/actions?status=pending`, not history).
14. **No dashboard-side rate-limit awareness.** Owners hitting the ops endpoints too fast will see a 429 with no explanation.
15. **No PHP-side test for the Public_Controller rate limiter integration** — the underlying `Rate_Limiter` class is tested; the controller call path isn't.
16. **`Insight_Generator` never records skipped runs or throttles cost.** A misconfigured operator can burn Anthropic credit hourly with no dashboard signal.
17. **No CSP / X-Frame-Options / Permissions-Policy headers configured** at the dashboard host. Vite dev server + production static host both default to permissive.
18. **No i18n scaffold.** Strings are baked into JSX. Growing to a bilingual staff would require a rewrite.

### 2.3 Polish

19. **Recharts is imported as one dependency** — bundle is 645 KiB / 184 KiB gzip. Splitting the chart-heavy pages would drop the initial bundle by ~40%.
20. **No skeleton loaders.** Every page shows the spinner for the full duration of the fetch — perceived perf will feel slow on cold cache.
21. **No inline empty states with next-step CTA** on freshly-installed sites. First-run experience is a wall of zeros.
22. **No keyboard shortcuts.** Power users can't jump between modules.
23. **No favicon variant for dark mode** — the current SVG shows OK on either but wasn't optimised.
24. **No storybook / component doc.** Fine at current size; would help onboarding.
25. **Dark mode not wired.** Tailwind config declares `darkMode: 'class'` but no dark styles are actually written (**being addressed in this PR**).
26. **Responsive quirks on tables** — some pages let a wide table overflow past `overflow-x-auto` because the parent doesn't have `min-w-0` (**being addressed in this PR**).

## 3. Advanced improvement roadmap

Grouped by theme. Rough T-shirt sizes: **S** = 1 focused PR, **M** = 2–3 PRs, **L** = its own project.

### 3.1 Data + intelligence

- **[M] Customer segment engine** — real `Shooter_Insights_Provider` with segments defined declaratively (SQL + Woo + Memberistic + Booking joins). Ship the frontend rendering against real data.
- **[S] AI Insights → tasks** — new `Tasks_Store` + task controller, wire the Approve/Dismiss buttons.
- **[M] Cross-module cohort analysis** — "shooters who took CCW and bought ammo in the same month," "members who used lanes in month 1 vs those who didn't" retention curves.
- **[M] Real RAG index** — pgvector or Chroma-backed store, ingester scans product / plan / FAQ pages, agents get RAG context injected into prompts.
- **[M] Ahrefs integration** — the MCP server is already in the toolchain. Wire it as an SEO source alongside GSC + GA4 (backlinks, referring-domain trend, keyword universe).
- **[S] Anthropic prompt caching** — the insight generator is a natural fit; would cut cost by 50–80%.
- **[S] Cost + usage panel** — surface token spend per agent per day (Anthropic returns usage on every response — we discard it today).

### 3.2 Actions + workflow

- **[M] Real per-automation handlers for the remaining runtime actions** — booking cancel via BridGistic today writes a request; add a "one-click confirm and refund via Stripe" flow gated by owner approval + audit log.
- **[M] Multi-step BridGistic flows** — chained actions (e.g. "email a renewal offer AND schedule a follow-up 3 days later").
- **[S] Draft edit before send** — currently the sender uses the draft's stored body verbatim; expose the body/subject in the WP-admin + dashboard Send flow so operators can edit inline.
- **[M] Slack / Discord notifications** — audit-log events push to a webhook so staff channels see BridGistic activity in real time.
- **[S] SMS via Twilio** for waiver + booking reminders (opt-in, not default).
- **[S] Public unsubscribe landing page** — currently the endpoint is REST-only. Ship a static confirmation page on the WordPress side.

### 3.3 Security + compliance

- **[S] CSP + security headers on the dashboard** — starter policy tailored to the current asset host.
- **[S] Content-security-policy nonce for inline scripts** — Vite has an official plugin.
- **[S] Fine-grained caps** — `g2a_dashboard_read` per-module so an analyst can be denied Email Management.
- **[M] Audit log export + retention policy** — 500-entry ring is fine short-term; long-term needs a table with retention.
- **[S] Owner-approval required for prompt edits** on `needs_review` agents.
- **[M] Data-subject deletion** — GDPR-style "delete everything for `email@x`" that removes drafts, cancellations, opt-out entries, and agent-history references.

### 3.4 Frontend UX + accessibility

- **[S] Skeleton loaders** replacing spinners across every page.
- **[S] Keyboard shortcuts** — `g h` → Home, `g s` → SEO, `⌘K` → BridGistic ask.
- **[S] Command palette** — a `⌘K` overlay with fuzzy nav + BridGistic ask blended.
- **[M] Chart library split** — dynamic `import()` for Recharts pages so the initial route is ~350 KiB smaller.
- **[S] Accessibility pass** — WCAG 2.1 AA on colours, focus rings, table roles, form labels.
- **[M] i18n scaffold** — react-intl + string extraction.
- **[S] Server-Sent Events** for the ops queues — the WP plugin exposes an SSE endpoint, drafts + cancellations update live instead of on refresh.
- **[M] Rich text preview** on email drafts — currently plain text.
- **[S] Print styles** for reports.

### 3.5 Reliability + observability

- **[M] Structured logging** on the plugin side — replace `error_log` calls with a `Logger` class that writes to a bounded table.
- **[M] Health probe REST route** — `/wp-json/g2a/v1/probe` returns 200 with a machine-readable status blob so an uptime service can poll it.
- **[S] Dashboard error boundary** — React `ErrorBoundary` around each route with a friendly recovery UI.
- **[S] Failing-cron dashboard tile** — Automation Center already has counters; a "last-run stale > 2 intervals" indicator makes it operational.
- **[S] Anthropic + Google retry with jittered backoff** — currently a single-shot request.
- **[M] Full request tracing** — a `g2aba_request_id` set on every REST hit and echoed on responses, used by the audit log entries too.

### 3.6 Testing

- **[M] Playwright end-to-end** — 1 spec per module walking through the happy path against the mock backend.
- **[S] Vitest for `api.ts`** — mock the `fetch` layer.
- **[S] WPUnit integration tests** — the current PHPUnit tests are pure-logic. Adding a small WPUnit lane would cover activator + REST-registration paths.
- **[S] Rate-limiter DoS test** — simulate 1000 hits from spread IPs, assert bucket integrity.

### 3.7 The other half of the system

- **[L] `pos.guns2ammo.com`** — separate Vite/React app, its own auth, cart + checkout state machine, receipt printing, offline queue, Stripe Terminal SDK.
- **[L] Staff mobile app** — waiver kiosk + booking check-in on a tablet.
- **[M] Public shop/UI** — the WP theme covers this; a headless React storefront pulling from Woo would give the marketing team a faster surface.

## 4. Not in scope for this audit

- POS-side code (separate app).
- The other plugins in the repo that predate this control center (booking engine, memberistic, waiver, FFL checkout, contact form, verifyistic). Each has its own repo history and is out of scope for this doc.
