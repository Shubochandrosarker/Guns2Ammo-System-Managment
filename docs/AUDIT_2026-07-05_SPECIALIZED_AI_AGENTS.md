# Specialized AI agents for Guns2Ammo — Phases A–D (2026-07-05)

Follow-up to the POS/AI deep audit (`AUDIT_2026-07-05_POS_AI_DEEP_AUDIT.md`).
The business asked for department-specific AI agents (Email Manager,
Sales Agent, Store Manager, Booking Agent, …), each with its own
trained knowledge and updated day by day. This is what shipped, in
four phases (PRs #52–#55).

## Before

The dashboard had 6 agents that all analyzed the exact same generic
30-day business snapshot regardless of department, with no persistent
knowledge, no lead categorization, and no automatic cadence — every
run required a manual click.

## Phase A — Shared knowledge brain + leads pipeline (PR #52)

- **Shared RAG brain.** `g2a-pos-core`'s existing knowledge brain
  (chunk → embed → cosine retrieval, optional Cloudflare Vectorize
  backend) is now callable from `g2a-business-api` in-process via a
  stable `BrainFacade` contract (`Brain_Client` degrades gracefully to
  `{ok:false}` when the POS plugin isn't active — never throws).
  Retrieval/stats gained an optional `scope` filter.
- **Categorized leads table** (`wp_g2aba_leads`). A deterministic
  categorizer (no LLM — fast, auditable) sorts every enquiry/booking/
  membership signup into `lane_booking, ccw_class, range_enquiry,
  new_member, ffl_transfer, nfa, membership, event_booking, general`.
  Fed by native WordPress action hooks the other plugins already fire
  (`wpistic_formistic_submission_captured`,
  `g2ab_booking_created/status_changed/cancelled/no_show`,
  `memberistic_membership_created/activated`) — no HTTP webhooks, all
  in-process, all try/catch-guarded so a listener failure can never
  break the source plugin's own request.

## Phase B — Specialized agents (PR #53)

8 agents now (was 6):

| Agent | What's new |
|---|---|
| **Email Manager** *(new)* | Drafts up to 5 real replies per run for new Formistic enquiries, grounded in the shared brain + the specific enquiry, and enqueues each into the existing owner-approval draft queue (nothing sends without a human clicking approve) |
| **Sales Agent** *(new)* | Computes real conversion rate + stale-lead count from the leads table; names the one lead most worth following up today |
| **Store Manager** *(renamed from generic Inventory agent)* | Store analytics + restock/bundle recommendations grounded in product/pricing knowledge |
| **Booking Agent** | Now sees real open leads (lane/CCW/range/event) + retrieved pricing/policy facts, not just generic numbers |
| **Membership Agent** | Same upgrade — real membership/new-member leads + plan knowledge |
| SEO / Business Analyst / Report Agent | Unchanged in this phase (Report Agent upgraded in Phase C) |

Prompt templates gained `{{leads}}` and `{{knowledge}}` placeholders
alongside the existing `{{snapshot}}` — fully backward compatible.

## Phase C — Daily cadence + AI-narrated weekly report (PR #54)

- **Hourly automation** refreshes the Email Manager (prompt replies).
- **Daily automation** refreshes Analyst/Booking/Membership/Store/
  Sales/SEO — the "updated day by day" cadence the business asked for.
  Paused agents are skipped; one agent failing doesn't stop the rest.
- **Self-healing reseed** (same version-gated pattern as the Phase A
  leads-table migration) so these new automations actually take effect
  on an already-activated install, not only a fresh one.
- **The weekly business report is now AI-written.** The Report Agent
  sees the 30-day snapshot *and* every other department agent's latest
  finding, and synthesizes across departments instead of restating raw
  numbers. Falls back to the original deterministic report if the AI
  connection isn't configured, so the weekly report is never empty.

## Phase D — Dashboard UI (PR #55)

- **New Leads page** — category pills with live counts, status
  filtering (spam hidden by default), paginated table, detail drawer
  with status + agent-assignment controls. This is the "store all
  booking leads separately by category" piece.
- **Business Knowledge Brain card** on the AI Agents page — live brain
  stats (with an honest "not connected" state), a form to add new
  facts, and a quick search tool to sanity-check retrieval. This is
  the "single place to update business info that propagates
  everywhere" piece.
- New `POST /brain/ingest` endpoint (the one write route the UI needed
  that Phase A hadn't shipped yet, which only exposed reads).

## What the owner needs to do to turn this on

1. **Set an OpenRouter (or Anthropic) API key** under Settings → G2A
   Business API — without it every agent records "Anthropic connection
   not configured" instead of running, and the weekly report falls
   back to the deterministic template.
2. **Keep `g2a-pos-core` active** — it's what actually hosts the
   shared knowledge brain; without it `Brain_Client` degrades and the
   dashboard's Business Knowledge card shows a "connect the in-store
   AI plugin" message.
3. **Seed the Guns2Ammo default knowledge pack** (POS → AI Brain →
   "Seed Guns 2 Ammo defaults", from the earlier POS/AI audit) so
   agents have real business facts to ground replies in from day one.
4. Nothing else — the hourly/daily automations and the leads pipeline
   are self-healing and start working the moment the plugins are
   active on the live site.

## Verification (final, across all four phases)

- `g2a-business-api` PHPUnit: **473 tests, 1173 assertions — OK**
- `g2a-pos-core` PHPUnit: **277 tests, 991 assertions — OK**
- `dashboard-app`: `tsc --noEmit` clean, `npm run build` succeeds
- `php -l` clean on every touched file across all four phases

## Known limitations / natural next steps

- Model routing only executes Anthropic connections today (its only
  executor) — non-Anthropic routes fall back safely rather than
  running on the wrong provider.
- The leads table has no "not equal" status filter server-side; the
  dashboard's default "All" tab hides spam client-side.
- The Cloudflare-backed brain (if configured) is scope-blind — scope
  filtering only applies to the local storage backend.
- Sales Agent's context omits knowledge-brain retrieval by design
  (funnel numbers matter more than FAQ grounding for that department);
  easy to add later if follow-up messaging needs it.
