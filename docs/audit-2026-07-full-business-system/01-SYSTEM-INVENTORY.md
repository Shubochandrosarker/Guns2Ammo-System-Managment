# System Inventory

Repository snapshot as of this audit: 119 commits, spanning 2026-06-14 to 2026-07-15 — a young, heavily-iterated codebase (roughly one commit every 6 hours of elapsed calendar time over the past month, much of it structured as `audit → fix round → merge` cycles visible in `git log`).

All version numbers below were read directly from plugin/theme headers and `define()` version constants in source — not copied from README/changelog claims.

## Version drift check (source vs. docs)

| Component | Source header version | Version constant | `INSTALL.md` claims (per client's own July 15 doc) | Drift? |
|---|---|---|---|---|
| guns2ammo (theme) | 1.27.13 | `G2A_VERSION` = **1.27.5** (in `guns2ammo/functions.php` or equivalent) | not itemized | **Yes — header (1.27.13) and version constant (1.27.5) disagree with each other, inside the SAME theme.** This is a repo-internal drift, independent of any INSTALL.md staleness. |
| Advanced FFL Checkout | 1.15.0 | `WPISTIC_FFL_VERSION` = 1.15.0 | 1.9.4 (per client doc) | Yes, if INSTALL.md is unchanged |
| G2A Booking Engine | 1.9.9.12 | `G2AB_VERSION` = 1.9.9.12 | 1.9.9.11 | Yes |
| G2A POS Core | 3.2.0 | `G2A_POS_CORE_VERSION` = 3.2.0 (also seen: `'0.0.0-static-analysis'`, `'test'` in test fixtures — expected, non-production) | 3.1.9 | Yes |
| Memberistic Membership Solutions | 1.12.0 | `MEMBERISTIC_VERSION` = 1.12.0 | 1.10.7 | Yes |
| Messageistic | 0.7.0 | `MESSAGEISTIC_VERSION` = 0.7.0 | not itemized | Unknown |
| Formistic | 2.1.0 | `WPISTIC_FORMISTIC_VERSION` = 2.1.0 | not itemized | Unknown |
| Verifyistic | 1.4.4 | `VERIFYISTIC_VERSION` = 1.4.4 | not itemized | Unknown |
| G2A Business API | 0.1.1 | `G2ABA_VERSION` = 0.1.1 (also `'0.1.0-test'` in test fixtures) | not itemized | Unknown |
| G2A Theme Control | 1.0.0 | n/a | not itemized | Unknown |
| Guns2Ammo Waiver Manager | 1.4 | `G2A_WAIVER_VERSION` = 1.4 | not itemized | Unknown |

**Finding G2A-CRIT-007 detail:** the theme itself ships with two disagreeing version identifiers (style.css header 1.27.13 vs. the `G2A_VERSION` PHP constant at 1.27.5). Whatever code reads `G2A_VERSION` for cache-busting asset URLs, update checks, or telemetry is working from a number six patch releases stale relative to the actual code it's running. This is a **repository-internal** finding, independent of and in addition to the previously-known `INSTALL.md`-vs-plugins drift. Recommend a single build step that stamps both from one source value (see `12-PERFORMANCE-RELIABILITY-OBSERVABILITY.md` and backlog `G2A-HIGH-004`).

`releases/` contains multiple historical ZIPs per plugin (e.g., both 1.9.9.10, .11, and .12 for Booking Engine); no automated process in `scripts/` (`build-release-zips.sh`, `fetch-fonts.sh`) reconciles these against `INSTALL.md` or a manifest — see recommended canonical production manifest below.

---

## Component inventory

### guns2ammo (WordPress theme)
- **Version:** 1.27.13 (style.css) / 1.27.5 (`G2A_VERSION` constant — see drift note above)
- **Purpose:** Custom storefront theme; canonical business-info source (`inc/business-info.php`); SEO/schema (`inc/seo.php`, `inc/llms.php`); login/account cache-hardening (`inc/login.php`); Otter Text defensive cleanup (`inc/ottertext-cleanup.php`)
- **Size:** 76 PHP files, 15,314 LOC
- **Key files:** `inc/business-info.php` (`g2a_biz()` — single source of truth for NAP/hours), `page-templates/*` (public page templates), `woocommerce/` (WC template overrides)
- **Production role:** Front-end rendering, canonical business identity, SEO surface
- **Maturity:** High for business-info centralization (well-designed, documented, defensive); content-editing handoff tooling absent (see `10-COUNTER-WORKFLOW-AND-STAFF-HANDOFF.md` and gap matrix)

### Memberistic Membership Solutions
- **Version:** 1.12.0 (DB schema version 1.5.0)
- **Purpose:** Membership plans, billing (Stripe), family/corporate linking, in-house digital waivers, member dashboard
- **Size:** 70 PHP files, 27,789 LOC
- **REST namespace:** `memberistic/v1`
- **Key tables:** `memberistic_people`, `memberistic_payments`, `memberistic_waiver_signatures`, `memberistic_documents`, `memberistic_waivers_archive` (+ more not enumerated this pass — see `06-DATA-INTEGRITY-AND-RECONCILIATION.md`)
- **External services:** Stripe (subscriptions, webhooks, billing portal), optional CoreStore bridge, optional WooCommerce bridge
- **Production role:** System of record for membership status and billing
- **Maturity:** High engineering quality (webhook idempotency via MySQL advisory locks, HMAC + replay-window verification, a genuine Stripe-drift reconciliation job for expiry) undermined by the cancellation ordering defect and the people-dedup gap — see `G2A-CRIT-001`, `G2A-CRIT-004`

### G2A Booking Engine
- **Version:** 1.9.9.12 (DB schema version 1.5.2)
- **Purpose:** Lane/class/event booking, availability, front-desk check-in, payments (Stripe/Fortis/PayPal/Authorize.Net/pay-in-store), admin calendar
- **Size:** 101 PHP files, 28,964 LOC
- **REST namespace:** `g2a-booking/v1`
- **Custom roles:** `g2ab_staff`, `g2ab_instructor`; custom capabilities `manage_g2ab_bookings`, `delete_g2ab_bookings`, `manage_g2ab_resources`, `manage_g2ab_forms`, `manage_g2ab_payments`, `manage_g2ab_settings`, `view_g2ab_reports`
- **External services:** Stripe, Fortis, PayPal, Authorize.Net; FullCalendar (vendored locally, CDN fallback only)
- **Production role:** Primary booking + range check-in system
- **Maturity:** Individually well-built screens; provisioning gap (`G2A-CRIT-005`) is the dominant risk

### G2A POS Core
- **Version:** 3.2.0
- **Purpose:** Largest component in the repo — point of sale, wholesaler/distributor integration (Lipsey's + others), inventory, FFL bound-book/ATF compliance support, loyalty, gift cards, layaway, consignment, trade-ins, AI agent layer, KPI reporting
- **Size:** 316 PHP files + 74 JS/TS files, 49,631 LOC — **by far the largest and least-audited component this pass**
- **REST namespace:** `g2a-pos/v1`
- **Auth model:** JWT-based (`AuthController`), `g2a_pos_access` capability granted to the lowest custom role (`g2a_cashier`) and up
- **Key tables (partial — 40+ repositories found; not exhaustively enumerated):** `g2a_wholesalers`, `g2a_wholesaler_categories`, `g2a_map_rules`, `g2a_ffl_transfers`, `g2a_form_4473`, `g2a_bound_book`, `g2a_atf_reports`, `g2a_ai_conversations`, `g2a_loyalty`, `g2a_gift_cards`, `g2a_layaway`, `g2a_consignment`, `g2a_trade_ins`
- **External services:** Lipsey's (catalog/inventory API + CSV), FedEx/UPS shipping, Cloudflare Workers AI (via `cloudflare-rag-worker`), OpenRouter/OpenAI-compatible/Ollama (staff AI agents)
- **Production role:** POS terminal backend, wholesaler sync, FFL compliance records
- **Maturity:** UNKNOWN — this pass sampled security posture only (REST permission callbacks, JWT handling) and the Lipsey's integration specifically (client priority). The remaining ~90% of this plugin's surface (POS transactions, ATF/4473 compliance records, loyalty, gift cards, consignment, trade-ins) was **not functionally audited** and should be a dedicated follow-up given it is the largest, most compliance-sensitive component in the system.

### Advanced FFL Checkout Solutions — G2A Edition
- **Version:** 1.15.0
- **Purpose:** FFL transfer checkout flow, ID verification, background-check provider integration, state compliance rules, carrier/shipping provider integration
- **Size:** 71 PHP files, 26,588 LOC
- **REST namespace:** `wpistic-ffl/v1`
- **Production role:** Firearms-transfer checkout compliance layer
- **Maturity:** Not deeply audited this pass beyond a security spot-check (public REST routes for state-compliance reference data and HMAC-gated provider webhooks — both verified legitimate)

### Formistic
- **Version:** 2.1.0 (DB schema version 1.3.0)
- **Purpose:** Contact forms, newsletter, subscriber management, unsubscribe handling
- **Size:** 30 PHP files, 10,911 LOC
- **REST namespace:** `formistic/v1`
- **Production role:** Sole contact-form solution (WPistic Contact Form was formally retired per git history — `e214285`)
- **Maturity:** Client-confirmed working well; correctly reads the theme's canonical `g2a_biz()` for footer/sender identity

### Messageistic
- **Version:** 0.7.0 (DB schema version 1.3.0)
- **Purpose:** SMS/messaging, consent tracking, conversation notes, campaign/automation repositories, Memberistic integration bridge
- **Size:** 110 PHP files, 11,885 LOC
- **REST namespace:** `messageistic/v1`
- **Production role:** Messaging layer — NOT the visitor-facing chatbot (see `G2A-CRIT-006`); this is consent/SMS/notification infrastructure
- **Maturity:** Not deeply audited this pass beyond hook-wiring spot-checks

### G2A Business API
- **Version:** 0.1.1
- **Purpose:** Staff-facing dashboard backend — auth, AI agents, operations data for `dashboard-app`
- **Size:** 177 PHP files, 19,870 LOC
- **REST namespace:** `g2a/v1`
- **Production role:** Backend for the React staff dashboard
- **Maturity:** Not deeply audited this pass; version number (0.1.1) is the lowest in the system, suggesting this is the least mature component by the team's own versioning signal

### Verifyistic — Advanced Age Verification
- **Version:** 1.4.4
- **Purpose:** Age-gate popup + visitor data capture with multi-webhook delivery. **This replaced Otter Text's age-gate function specifically — it is not a general chatbot.**
- **Size:** 14 PHP files, 4,034 LOC
- **API style:** `wp_ajax`/`wp_ajax_nopriv` (not REST) — `verifyistic_verify`, `verifyistic_decline`, `verifyistic_token`
- **Production role:** Age verification only
- **Maturity:** Not deeply audited this pass

### G2A Theme Control
- **Version:** 1.0.0
- **Purpose:** Theme-level content control surface (smallest plugin in the system: 7 PHP files, 472 LOC)
- **Maturity:** Given its central role in the client's "who can edit the website" question, its small size (472 LOC) relative to the ambition of that requirement is itself a finding — see `05-MISSING-AND-INCOMPLETE-FEATURES.md`

### Guns2Ammo Waiver Manager
- **Version:** 1.4
- **Purpose:** Thin plugin (3 PHP files, 259 LOC) — legacy/companion to the waiver import logic that actually lives inside Memberistic (`includes/waivers/`)
- **Maturity:** Note the naming ambiguity: "the waiver system" is really split across this plugin AND `memberistic-membership-solutions/includes/waivers/` AND `memberistic-membership-solutions/includes/corporate/class-corporate-module.php` (corporate-group signature mirroring). Any future engineer should be pointed at Memberistic's `includes/waivers/` directory first — that is where the CSV importer, PDF mirroring, and matching logic actually live.

### dashboard-app (React/TypeScript)
- **Purpose:** Staff-facing SPA consuming `g2a-business-api` and `g2a-pos-core` REST APIs
- **Size:** 47 TS/TSX files, 7,889 LOC
- **Build status (verified this session):** `npm ci` ✅, `tsc -b --noEmit` (typecheck) ✅, `vite build` ✅ (produces a single 727 KB / 204 KB gzip JS chunk — no code-splitting), `eslint .` ❌ fails with "couldn't find eslint.config.js" — **environment/tooling drift, not a code defect**: the project's lint script targets ESLint 9's flat-config format but ships no `eslint.config.js` (only, presumably, a legacy `.eslintrc.*` or none at all)
- **Production role:** Intended primary staff interface per the audit brief's framing — see `03-CLIENT-REQUIREMENTS-GAP-MATRIX.md` for how much of the client's counter-workflow pain this could resolve if it became the actual single workspace

### cloudflare-rag-worker
- **Purpose:** Server-to-server Cloudflare Worker — embeds knowledge-base text chunks (Workers AI `@cf/baai/bge-m3`) into a Vectorize index and answers similarity queries for `g2a-pos-core`'s `BrainService` (`brain_backend = "cloudflare"`)
- **Size:** 1 file, 214 LOC (`src/index.ts`)
- **Auth:** Bearer token, timing-safe comparison, no CORS headers (correctly, since it's server-to-server only)
- **Production role:** Backend knowledge store for the **staff-facing** AI agents. **Confirmed NOT a visitor-facing chatbot backend** — no browser-reachable endpoint, no CORS, explicit code comment stating "browsers have no business calling this directly."

---

## REST namespace summary

| Namespace | Owner |
|---|---|
| `memberistic/v1` | Memberistic |
| `g2a-booking/v1` | G2A Booking Engine |
| `g2a-pos/v1` | G2A POS Core |
| `g2a/v1` | G2A Business API |
| `wpistic-ffl/v1` | Advanced FFL Checkout |
| `formistic/v1` | Formistic |
| `messageistic/v1` | Messageistic |
| *(none — AJAX-based)* | Verifyistic (`wp_ajax`/`wp_ajax_nopriv`) |

## Database footprint

Repo-wide `CREATE TABLE` statement search found **156 distinct table-creation statements** across all plugin schema files (this count includes some historical/migration-version duplicates of the same logical table — treat as an upper bound on custom tables, not an exact unique count). G2A POS Core alone owns 40+ of the underlying repository classes. No single cross-plugin ERD exists in the repository; see `02-ARCHITECTURE-AND-DATA-FLOWS.md` for the diagrams this audit was able to reconstruct.

## Custom capabilities

Repo-wide search for `g2a`/`g2ab`-prefixed custom capability strings found **~100 distinct capability names** across the plugin set. The pattern of one custom capability per admin screen (seen clearly in Booking Engine: `manage_g2ab_bookings`, `manage_g2ab_resources`, `manage_g2ab_forms`, `manage_g2ab_payments`, `manage_g2ab_settings`, `view_g2ab_reports`) is consistent and well-designed *in isolation* — the defect is not the capability model, it's that Booking Engine's provisioning of these capabilities only happens once, at activation (`G2A-CRIT-005`). Whether the same activation-only pattern repeats in other plugins was not exhaustively checked this pass — recommend the same grep (`register_activation_hook` vs. any `plugins_loaded`/`admin_init` version-triggered re-provisioning) be run against every plugin as a Phase-0 task.

## Canonical production manifest — recommended format

No such manifest exists today. Recommend a single `PRODUCTION-MANIFEST.md` (or a `wp-cli`-generated JSON) capturing, per deploy:

```text
component            | source_version | db_version | deployed_at | deployed_by | zip_sha256
guns2ammo (theme)     | 1.27.13        | n/a        | ...         | ...         | ...
memberistic           | 1.12.0         | 1.5.0      | ...         | ...         | ...
g2a-booking-engine    | 1.9.9.12       | 1.5.2      | ...         | ...         | ...
...
```
Generated by a `wp g2a manifest` CLI command (pattern already exists — see `Waiver_Import::register_cli()` for a precedent of WP-CLI commands in this codebase) run immediately after every production deploy, committed to a `deploys/` log. This directly closes the "source code / release ZIP / documentation / production deployment / database schema can drift independently" risk named in the audit brief.
