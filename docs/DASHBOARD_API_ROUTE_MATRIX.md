# Dashboard ↔ g2a-business-api Route Matrix

Audit date: 2026-07-15.
Frontend source of truth: `dashboard-app/src/lib/api.ts` (every network call in the app goes through this file — a repo-wide search found **no other `fetch`/XHR/axios call sites** in `dashboard-app/src`). Types from `dashboard-app/src/types/analytics.ts` (T) or `api.ts` itself.
Backend: `g2a-business-api/includes/rest/*.php`, namespace `g2a/v1` (all paths below are relative to `/wp-json/g2a/v1`).

Conventions:
- **Perm**: `read` = `g2a_dashboard` cap, `admin` = `g2a_dashboard_admin` cap (both bypassed by `manage_options`) — `class-rest-controller.php:27-42`.
- **Money**: integer USD cents on the wire everywhere (`class-money.php`; frontend divides by 100 in `format.ts:4-11`). Column notes only exceptions.
- Mock-mode behavior omitted; every method short-circuits to fixtures when `VITE_G2A_USE_MOCKS` is on.

## 1. Frontend method → backend route

| Frontend method (api.ts line) | Verb | Path | Expected response type | Backend exists? | Handler (includes/rest/) | Perm | Pagination | Notes / mismatches |
|---|---|---|---|---|---|---|---|---|
| `auth.login` (172) | POST | `/auth/login` | `Session` sans token (typed `Omit<Session,'token'>`) | yes | class-auth-controller.php:97 | **public** (`__return_true`, :33) | — | Backend DOES return a `token` field (throwaway `wp_generate_password(32)`, :148) that the FE must not let overwrite the Basic token — handled by spread order (api.ts:195-201). Type and wire disagree. **No rate limiting on this route.** |
| `auth.logout` (209) | — | (none) | void | **no backend route** | — | — | — | Client-side localStorage clear only; app password stays valid. No server logout exists (see auth plan). |
| `auth.me` (212) | GET | `/auth/me` | `Omit<Session,'token'>` | yes | class-auth-controller.php:70 | read | — | Backend also returns `caps:{read,admin}` (:79-82) that the FE type omits (ignored). Backend never returns role `'staff'` though FE type allows it (api.ts:51 vs controller :155-163). |
| `analytics.revenueOverview` (222) | GET | `/analytics/overview?from&to` | T:`RevenueOverview` (:29) | yes | class-analytics-controller.php:58 → Revenue_Provider | read | — | Empty `from/to` sent as literal empty params; `Range::from_request` defaults to last 30d. 60s transient cache. |
| `analytics.bookings` (227) | GET | `/analytics/bookings` | T:`BookingAnalytics` (:41) | yes | :70 → Booking_Provider | read | — | |
| `analytics.memberships` (232) | GET | `/analytics/memberships` | T:`MembershipAnalytics` (:52) | yes | :82 → Membership_Provider | read | — | |
| `analytics.store` (237) | GET | `/analytics/store` | T:`StoreAnalytics` (:65) | yes | :94 → Store_Provider | read | — | |
| `analytics.seo` (242) | GET | `/analytics/seo` | T:`SeoAnalytics` (:97) | yes | :106 → SEO_Provider | read | — | Returns all-zero empty skeleton when GSC unconfigured (class-seo-provider.php:29-58) — UI shows zeros, not an error. |
| `analytics.insightistic` (247) | GET | `/analytics/insightistic` | T:`InsightisticAnalytics` (:88) | yes | :129 → Insightistic_Provider | read | — | Same empty-skeleton degradation for GA4. |
| `analytics.shooterInsights` (252) | GET | `/analytics/shooter-insights` | T:`ShooterInsights` (:79) | yes | :118 → Segments_Provider | read | — | No range params (FE sends none, BE takes none). |
| `gaps.list` (260) | GET | `/insights/business-gaps` | T:`BusinessGap[]` (:109) | yes | class-gaps-controller.php:31 → Gaps_Provider | read | — | |
| `gapActions.createTask` (281) | POST | `/insights/business-gaps/{id}/create-task` | `{ok:true, task:Task}` → `Task` (api.ts:755) | yes | class-gaps-controller.php:41 | admin | — | |
| `insights.list` (266) | GET | `/ai/insights` | T:`AIInsight[]` (:119) | yes | class-insights-controller.php:24 | read | — | Served from `g2aba_insights_cache` (hourly cron). |
| `insights.approve` (269) | POST | `/ai/insights/{id}/approve` | `{ok:true, task:Task}` | yes | :34 | admin | — | |
| `insights.dismiss` (274) | POST | `/ai/insights/{id}/dismiss` | (ignored) | yes | :44 | admin | — | |
| `tasks.list` (289) | GET | `/tasks[?status=open]` | `Task[]` (api.ts:755) | yes | class-tasks-controller.php:24 | read | none | Unpaginated full list. |
| `tasks.create` (293) | POST | `/tasks` | `Task` | yes | :37 | admin | — | |
| `tasks.resolve` (300) | POST | `/tasks/{id}/resolve` | `Task` | yes | :49 | admin | — | ID regex `task_[a-f0-9]+`. |
| `tasks.dismiss` (304) | POST | `/tasks/{id}/dismiss` | `Task` | yes | :59 | admin | — | |
| `automations.list` (311) | GET | `/automations` | T:`Automation[]` (:138) | yes | class-automations-controller.php:25 | read | none | |
| `automations.toggle` (314) | POST | `/automations/{id}/toggle` | (ignored) | yes | :35 | admin | — | |
| `automations.updateSchedule` (321) | PATCH | `/automations/{id}` | `Automation` | yes | :51 (PATCH/PUT) | admin | — | |
| `routing.get` (331) | GET | `/model-routing` | `ModelRoutingResponse` (api.ts:829) | yes | class-routing-controller.php:24 | read | — | |
| `routing.update` (341) | PUT | `/model-routing` | `{ok:true, routing}` | yes | :34 (PUT/POST) | admin | — | |
| `content.posts/pages/media/categories/tags` (352-356) | GET | `/content/{type}?per_page&page&search&status` | `WpContentItem[]` + `X-WP-Total(-Pages)` headers → `ContentPage` (T::457) | yes | class-content-controller.php:33-65 (in-process `wp/v2` proxy) | read | **yes** — header-based, relayed :131-137 | `status` only forwarded for posts/pages (:75-81); FE sends it for all types but BE drops it silently elsewhere. |
| `system.integrations` (360) | GET | `/system/integrations` | T:`IntegrationsStatus` (:263) | yes | class-health-controller.php:28 | read | — | |
| `system.namespaces` (365) | GET | `/system/namespaces` | T:`NamespacesStatus` (:395) | yes | class-namespaces-controller.php:25 | read | — | |
| `system.siteHealth` (370) | GET | `/system/site-health` | T:`SiteHealthSummary` (:408) | yes | class-site-health-controller.php:18 | read | — | |
| `system.rotateKeys` (375) | POST | `/system/rotate-keys` | `SystemActionResult` (api.ts:835) | yes | class-system-controller.php:26 | admin | — | Soft marker only — keys are not actually rotated (class-system-actions.php:34-48). |
| `system.revokeSessions` (382) | POST | `/system/revoke-sessions` | `SystemActionResult` | yes | :35 | admin | — | Deletes ALL app passwords for `g2a_dashboard` users, including the caller's — the response likely arrives, but the very next request 401s. UI should warn. |
| `system.rebuildRag` (389) | POST | `/system/rag/rebuild` | `SystemActionResult` | yes | :44 | admin | — | Stub — queues `g2aba_rag_rebuild` which has no subscriber yet. |
| `exports.tasksCsvUrl` (400) | GET (browser nav) | `/export/tasks.csv` | CSV file | yes | class-export-controller.php:30 | read | — | **BROKEN cross-origin: FE renders a bare `<a href>` (Tasks.tsx:63) — browser navigation sends no `Authorization: Basic` header, so the read permission check 401s.** Works only if a WP cookie session happens to exist on guns2ammo.com. Fix = cookie sessions (auth plan) or fetch-blob download. |
| `exports.auditCsvUrl` (403) | GET (browser nav) | `/export/audit-log.csv` | CSV | yes | :40 | read | — | Same 401 issue (OpsQueue.tsx:158). |
| `exports.reportTxtUrl` (406) | GET (browser nav) | `/export/reports/{id}.txt` | text | yes | :50 | read | — | Same 401 issue (Reports.tsx:174). |
| `agents.list` (412) | GET | `/agents` | T:`Agent[]` (:161) | yes | class-agents-controller.php:22 | read | — | |
| `agents.run` (415) | POST | `/agents/{id}/run` | (ignored) | yes | :32 (schedules cron +5s, :119) | admin | — | Async — FE polls list/history for result. |
| `agentHistory.list` (663) | GET | `/agents/{id}/history` | `AgentHistoryEntry[]` (api.ts:744) | yes | :42 | read | none | |
| `agentPrompt.get` (678) | GET | `/agents/{id}/prompt` | `{template, history:PromptVersion[]}` | yes | :52 (GET arm :63-68) | **admin** | — | **Perm asymmetry: read-only users can open the AIAgents page (read) but the prompt viewer (AIAgents.tsx:454) 403s.** Confirm intended. |
| `agentPrompt.set` (670) | POST | `/agents/{id}/prompt` | `{ok, record, placeholder}` | yes | :52 (POST arm) | admin | — | |
| `leads.list` (422) | GET | `/leads?category&status&source&search&date_from&date_to&page&per_page` | T:`LeadsPage {items,total}` (:311) | yes | class-leads-controller.php:21 → Leads_Repository | read | **yes** — `page`/`per_page` + `total` in body | FE camelCase → snake_case query params handled at api.ts:446-455. |
| `leads.stats` (458) | GET | `/leads/stats` | T:`LeadStats` (:317) | yes | :41 | read | — | |
| `leads.get` (461) | GET | `/leads/{id}` | T:`Lead` (:294) | yes | :51 (GET arm) | read | — | Numeric id. |
| `leads.updateStatus` (469) | PATCH | `/leads/{id}` `{status?, assigned_agent?}` | `Lead` | yes | :61 (PATCH/PUT arm) | admin | — | FE maps `assignedAgent`→`assigned_agent` (api.ts:473). |
| `brain.query` (479) | GET | `/brain/query?q&k&scope` | T:`BrainQueryResult` (:354) | yes | class-brain-controller.php:28 | read | — | `k` clamped to 20 server-side. Degrades to `{ok:false,reason}` when g2a-pos-core absent. |
| `brain.stats` (486) | GET | `/brain/stats?scope` | T:`BrainStats` (:375) | yes | :46 | read | — | |
| `brain.ingest` (491) | POST | `/brain/ingest` `{label,body,tags?,scope?}` | T:`BrainIngestResult` (:387) | yes | :59 | admin | — | body ≥ 10 chars enforced server-side. |
| `models.list` (501) | GET | `/model-connections` | T:`ModelConnection[]` (:185) | yes | class-models-controller.php:39 (GET arm) | read | — | Keys only ever exposed masked (`keyMasked`). |
| `models.create` (508) | POST | `/model-connections` | `ModelConnection` | yes | :49 | admin | — | |
| `models.update` (515) | PATCH | `/model-connections/{id}` | `ModelConnection` | yes | :56 (PATCH/PUT arm) | admin | — | |
| `models.remove` (522) | DELETE | `/model-connections/{id}` | (ignored) | yes | :66 | admin | — | |
| `models.setKey` (526) | POST | `/model-connections/{id}/key` `{apiKey}` | `{ok, keyMasked}` | yes | :73 | admin | — | Plaintext key transits request body once; stored encrypted (Secrets). |
| `models.test` (504) | POST | `/model-connections/{id}/test` | `ModelTestResult` (api.ts:775) | yes | :83 | admin | — | |
| `models.catalog` (533) | GET | `/model-connections/{id}/catalog` | `ModelCatalogResult` (api.ts:789) | yes | :93 | **admin** | — | **Perm asymmetry: AIModels page loads for read users but catalog fetch (AIModels.tsx:375) 403s for non-admins.** |
| `reports.list` (540) | GET | `/reports` | `ReportDefinition[]` (api.ts:808) | yes | class-reports-controller.php:24 | read | — | |
| `reports.runNow` (544) | POST | `/reports/{id}/run-now` | `ReportDelivery` (api.ts:819) | yes | :34 | admin | — | |
| `reports.latest` (548) | GET | `/reports/{id}/latest` | `ReportDelivery` | yes | :44 | read | — | 404 until first delivery. |
| `settings.get` (555) | GET | `/settings` | `DashboardSettings` (api.ts:865) | yes | class-settings-controller.php:22 (GET arm) | read | — | Includes `allowedOrigins` CORS list — editable from the dashboard itself (lock-out foot-gun; see auth plan §7 decision D8). |
| `settings.update` (559) | PUT | `/settings` | `{ok:true, settings}` | yes | :34 (PUT/POST) | admin | — | Allow-list sanitised server-side (Settings_Store::sanitise). |
| `health.checks` (570) | GET | `/system/health` | T:`SystemHealthCheck[]` (:205) | yes | class-health-controller.php:19 | read | — | |
| `bridgistic.ask` (576) | POST | `/bridgistic/ask` `{query}` | `BridGisticAskResult` (api.ts:692) | yes | class-bridgistic-controller.php:22 | **read** | — | POST that WRITES (enqueues pending action for `action` category). Deliberate approve-gate design, but a read user can flood the queue — no rate limit. |
| `bridgistic.pending` (602) | GET | `/bridgistic/actions?status=pending` | `BridGisticAction[]` (api.ts:700) | yes | :38 | read | none | |
| `bridgistic.approve` (606) | POST | `/bridgistic/actions/{id}/approve` | `BridGisticAction` | yes | :48 | admin | — | ID regex `act_[a-f0-9]+`. |
| `bridgistic.reject` (610) | POST | `/bridgistic/actions/{id}/reject` | `BridGisticAction` | yes | :58 | admin | — | |
| `emails.overview` (617) | GET | `/emails/overview` | T:`EmailOverview` (:239) | yes | class-email-overview-controller.php:24 | read | — | Aggregates Formistic/newsletter/Messageistic in-process; sections `active:false` when plugins absent. |
| `emailDrafts.list` (625) | GET | `/email-drafts[?status=pending]` | `EmailDraft[]` (api.ts:711) | yes | class-ops-controller.php:31 | read | none | |
| `emailDrafts.send` (630) | POST | `/email-drafts/{id}/send` `{to?,subject?,body?}` | `EmailDraft` | yes | :41 | admin | — | Sends via `wp_mail` + Messageistic trigger; opt-out checked. |
| `emailDrafts.discard` (637) | POST | `/email-drafts/{id}/discard` | `EmailDraft` | yes | :56 | admin | — | |
| `cancellations.list` (644) | GET | `/cancellations[?status=awaiting]` | `Cancellation[]` (api.ts:723) | yes | :66 | read | none | |
| `cancellations.markCompleted` (649) | POST | `/cancellations/{id}/mark-completed` `{notes}` | `Cancellation` | yes | :76 | admin | — | |
| `cancellations.drop` (656) | POST | `/cancellations/{id}/drop` | `Cancellation` | yes | :89 | admin | — | |
| `auditLog.list` (685) | GET | `/audit-log?limit=N` | `AuditLogEntry[]` (api.ts:767) | yes | :99 | read | limit only (no cursor/page) | |

## 2. Tallies

- **Frontend methods with NO backend route: 0** of ~57 network methods. (Only `auth.logout` has no wire call — it is intentionally client-side, but that is itself an auth gap.) The route table in `g2a-business-api/README.md` is stale/partial; the code is the superset and matches the client.
- **Backend routes with NO frontend consumer: 1** — `POST /public/opt-out` (class-public-controller.php:25). Consumed by HMAC-signed unsubscribe links in outbound emails, not the dashboard. Correctly public + rate-limited (20/h/IP).
- Functional mismatches to fix (all flagged above):
  1. Export downloads 401 in production (bare `<a href>`, no auth attached) — api.ts:398-409.
  2. `GET /agents/{id}/prompt` and `GET /model-connections/{id}/catalog` are admin-gated while their pages are read-visible — 403s for `shop_manager` users.
  3. `POST /bridgistic/ask` writes to the action queue under the read cap with no rate limit.
  4. `/auth/login` response includes a decoy `token` the FE type says doesn't exist (fragile — guarded only by spread ordering, api.ts:195-201).
  5. FE `Session.role` `'staff'` is unreachable (backend emits `owner|manager|analyst`).
  6. FE sends `status` on all `/content/*` list calls; backend forwards it only for posts/pages (harmless, silently dropped).
- Money format: consistent integer USD cents end-to-end; no per-route exceptions found.
- Pagination: only `/leads` (page/per_page + body total) and `/content/*` (X-WP-Total headers) paginate. `/tasks`, `/automations`, `/agents`, `/email-drafts`, `/cancellations`, `/bridgistic/actions` return unbounded lists; `/audit-log` caps by `limit` param only — acceptable now, revisit at scale.
