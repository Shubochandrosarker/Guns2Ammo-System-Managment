# Advanced System Roadmap

Organized into the five bands the audit brief specifies. Each item cross-references its backlog ID where one exists (`15-IMPLEMENTATION-BACKLOG.md`, `improvement-backlog.json`). This roadmap assumes the phase sequencing in `15-IMPLEMENTATION-BACKLOG.md` for *when* — this file focuses on *what* and *why*, organized by ambition level rather than strict calendar order.

## Band 1 — Stabilization

The seven Critical findings in `04-CONFIRMED-DEFECTS.md`, plus:
- Production version reconciliation (`G2A-CRIT-007`) — the precondition for trusting every other fix
- A repo-wide sweep for the `G2A-CRIT-005` bug class (activation-only capability provisioning) in every other plugin, not just Booking Engine
- Staging environment + deploy manifest (closes the "live site, no chance to test" operating reality)

**Why first:** every other band assumes the business can trust its own data and deploys. Building a Customer 360 view on top of an identity model with silent duplicate-creation, or automating campaigns on top of unverified consent enforcement, would compound the existing risk rather than reduce it.

## Band 2 — Operational unification

- **Unified counter workspace** — determine how much of the client's counter-workflow context-switching `dashboard-app` can already eliminate (per `10-COUNTER-WORKFLOW-AND-STAFF-HANDOFF.md`'s recommended live test), then close the remaining gaps rather than building a new interface from scratch
- **Unified failed-operations queue / health dashboard** (`12-PERFORMANCE-RELIABILITY-OBSERVABILITY.md`) — aggregates the good per-component logging that already exists rather than replacing it
- **Customer Identity Reconciliation Tool** (`06-DATA-INTEGRITY-AND-RECONCILIATION.md`) — detect-and-flag only, staff-approved merges
- **Restricted content-manager role + page correction register** (`G2A-HIGH-002`) — unblocks client content self-service
- **`MembershipGatewayInterface`** (`07-PAYMENTS-AND-MERCHANT-SERVICE-PLAN.md`) — structural refactor, no behavior change, but the precondition for every subsequent payment-related improvement
- **Otter Text technical identification + acceptance checklist run** — live-verification task, not a build task, but operationally blocking a vendor decision

## Band 3 — Revenue automation

- **Segmentation query layer** on top of the data already confirmed to exist (`13-SEO-CRO-REVENUE-GROWTH.md`) — active/expired member, range-visitor-no-purchase, lapsed, class-attendee, etc.
- **Campaign automation** for the specific flows the client named, built on Formistic/Messageistic's existing consent/delivery infrastructure
- **Campaign-to-revenue attribution**
- **Loyalty/retention features** on top of the already-present `LoyaltyRepository`/`GiftCardRepository` schema (verify what's already built here before greenfielding — see `05-MISSING-AND-INCOMPLETE-FEATURES.md`'s explicit "unverified, not confirmed-missing" flag)
- **Review-request automation**, **abandoned-booking/signup recovery**

## Band 4 — Advanced intelligence

- **AI operations assistant** — a daily/weekly summary drawing on the unified operations log from Band 2 ("3 cancellations need review, 2 waiver imports had unmatched records, Lipsey's sync failed twice this week")
- **Duplicate-contact suggestions** — surfacing candidates for the Band 2 reconciliation tool's review queue automatically rather than requiring a manual scan
- **Failed-payment prioritization** — ranking the failed-cancellation/failed-payment queue by dollar value or customer tenure so staff triage the highest-impact issues first
- **Product-category-mapping assistance** for Lipsey's (and future wholesalers) — suggesting `wc_category_id` mappings for new vendor categories using the existing AI gateway infrastructure (`AiController`/`BrainService`) already built in `g2a-pos-core`
- **Predictive inventory / demand forecasting**, **margin analysis**, **MAP compliance monitoring** — natural extensions of the wholesaler/POS data already being captured

All AI features in this band must route any irreversible action (payment, membership status change, compliance record change, deletion) through human approval — consistent with this repo's own existing design discipline (the reconciliation tool's "never auto-merge" rule, the waiver PDF's defense-in-depth pattern) and the audit brief's explicit constraint.

## Band 5 — Platformization

- **Unified Customer 360** — the culmination of Band 1's identity-integrity fixes and Band 2's reconciliation tool; not attemptable safely before those land
- **Multi-location readiness** — this audit found no explicit single-location assumption baked into the schema (e.g., no hardcoded location_id absence flags found), but also did not verify multi-location readiness positively; flagged as unknown, not confirmed-ready
- **Reconciliation center** (payments) and **Data quality center** (identity) as permanent staff-facing operational surfaces, not one-time cleanup projects — the tools built in Band 1/2 should be designed from the start to be run repeatedly, not thrown away after the first cleanup pass

## Sequencing logic

This roadmap deliberately does not put "advanced" features first even though they're individually higher-visibility. The evidence gathered this session shows a consistent pattern: **this system's engineers already know how to build the sophisticated version of most of these things** (advisory-locked idempotency, HMAC replay protection, encrypted credential storage, a genuine Stripe-drift reconciliation job for expiry) — the gaps are almost all in *applying* that same discipline to one or two more state transitions or connection points, not in inventing new capability. Stabilization band items are disproportionately cheap relative to their risk reduction for exactly this reason: they're small, targeted fixes to systems that are otherwise already well-engineered, not new subsystems.
