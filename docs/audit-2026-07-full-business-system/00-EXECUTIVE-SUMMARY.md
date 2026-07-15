# Executive Summary — Guns 2 Ammo Full Business System Audit

**Audit date:** 2026-07-14
**Branch:** `claude/guns2ammo-audit-roadmap-2o1a9q` (requested branch name `audit/g2a-full-business-system-2026-07` was not available in this session — see note in PR)
**Scope:** Full repository — theme, 10 plugins, dashboard-app, cloudflare-rag-worker
**Method:** Static source audit (no production access). Every finding below is grounded in an exact file/line/function reference, independently re-derived from source — not copied from prior audit docs. Prior docs (`docs/CLIENT~1.MD`, `docs/RANGE_~1.MD`, `Guns 2 Ammo Business System.pdf`) were used as a starting hypothesis list only; every claim in them was re-verified against current code, and several were found to be *more* precisely wrong or right than previously stated (see `03-CLIENT-REQUIREMENTS-GAP-MATRIX.md`).

**Read this first:** this repository is not a "missing features" project. Nearly every capability the client is asking about has real, often well-engineered code behind it. The dominant failure pattern across this audit is **narrow, single-point defects in otherwise solid systems** — one `ORDER BY id LIMIT 1`, one capability grant that only fires on plugin activation, one missing `UNIQUE` index, one silent early-return — each of which fully explains a client-reported symptom without indicting the surrounding architecture. That is a *good* news/bad news situation: the fixes are small and targeted, but until they land the client's lived experience is correctly "this doesn't work," and no amount of surrounding code quality changes that.

---

## Scores (0–100, evidence-based — see individual deliverables for justification)

| Area | Score | Basis |
|---|---|---|
| Architecture | 68 | Clean plugin boundaries, hook-based integration, but two independent AI subsystems (POS + Business API) and two independent booking-calendar admin surfaces suggest drift between build phases |
| Code quality | 74 | 975/975 PHP files lint-clean; consistent use of repositories/services; comments frequently document *why*, a good sign of a disciplined team; some large files (2,600+ line corporate module) |
| Security | 71 | REST `permission_callback` usage is disciplined — spot-checked `__return_true` routes are legitimately public (read-only data, HMAC/token-authenticated actions, login/token issuance) rather than open holes; waiver PDFs are `.htaccess`-denied AND capability-gated; Stripe/WooCommerce webhooks HMAC-verified with replay windows. Not exhaustively swept — see `11-SECURITY-PRIVACY-COMPLIANCE.md` for sampling scope |
| Payment correctness | 48 | Stripe webhook idempotency and signature verification are genuinely strong; but the flagship client complaint (cancel doesn't propagate) is a **confirmed, reproducible-from-source defect** — see G2A-CRIT-001 |
| Data integrity | 52 | No `UNIQUE` constraint on `memberistic_people.email`; no dedupe-on-create; waiver-import success counter can be wrong; Lipsey's account resolution collapses two accounts into one |
| Membership correctness | 55 | Renewal-date math, corporate groups, and Stripe webhook consumption are well built; cancellation ordering and person-dedup are the two load-bearing defects |
| Booking reliability | 72 | Calendar/admin code is complete and defensively engineered (local FullCalendar vendoring specifically to avoid CDN-block failures); undermined by a capability-provisioning gap that can make the whole admin menu invisible after a zip-overwrite deploy |
| Waiver reliability | 66 | Import is idempotent, dedupes, protects PDFs correctly, and check-in gates re-derive from the archive (robust); account-page display can go stale due to a silent stamping failure |
| POS readiness | Not scored — outside the client's July 14 priority list; large surface (316 PHP files) sampled only for security, not functionally audited this pass. Flagged for a dedicated follow-up. |
| Lipsey's readiness | 41 | Category-mapping data model exists but is never applied to the promoted WooCommerce product; the two-account architecture is structurally unreachable through the code path actually used by the import bridge |
| FFL workflow readiness | Not scored this pass — sampled only for security (public routes verified legitimate); not functionally audited |
| Staff usability | 58 | Individual screens are well built; client's lived experience is "I can't see the calendar," which is a provisioning bug, not a missing screen |
| Customer usability | 60 | Login/account cache-busting is implemented correctly in code; passwordless/large-touch-target improvements for older customers are not yet built |
| Accessibility | Not scored — no dedicated audit pass this session (see Recommended Next Steps) |
| Performance | Not fully scored — one concrete finding (727 KB single-chunk dashboard bundle, no code-splitting); no Core Web Vitals measurement possible without a live site |
| Observability | 45 | Stripe cancel failures ARE logged to a per-membership activity timeline (better than raw error_log), but there is no cross-membership "failed cancellations" queue — a staff member has to know to look |
| Documentation | 55 | Extensive and often excellent (`docs/` has 34 files), but drifts from source — see `01-SYSTEM-INVENTORY.md` version table |
| Client handoff | 30 | No restricted content-editor role found; no page-by-page correction register; no recorded walkthrough artifact in-repo |
| SEO/AEO | Not scored this pass — `guns2ammo/inc/seo.php`, `inc/llms.php` exist and are structurally sound at a glance; no keyword/ranking data available without live tools |
| Conversion/marketing automation | 25 | Formistic/Messageistic provide the plumbing (contacts, consent, campaigns); no evidence of built segmentation logic for the specific personas the client named (range-visitor-to-retail, lapsed-member, etc.) |
| **Overall production readiness** | **52 / 100 — PARTIALLY ready.** Live, taking real transactions, with two Phase-0-grade defects (payment/membership state divergence risk, and a customer-identity integrity gap) that need to close before this can be called dependable. |

Scores reflect **what independent source-code verification actually showed**, not the size or sophistication of the codebase. A smaller, less ambitious system with these two fixes in place would score higher on "production readiness" than this system does today.

---

## Top 10 critical findings

1. **Membership cancellation writes local status before remote Stripe confirmation, with no compensating action on failure.** `memberistic-membership-solutions/includes/database/class-memberships-repository.php:535-546` (`change_status()`) updates the DB row, *then* fires the hook that calls Stripe. `includes/payments/class-stripe-service.php:212-262` (`maybe_cancel_remote_subscription()`) logs a failure to the Activity timeline but never reverts local status or sets a distinct `cancel_failed` state. **This is the exact defect the client described.** → `G2A-CRIT-001`
2. **Lipsey's has two merchant accounts but the code can only ever reach one.** `g2a-pos-core/includes/Database/WholesalerRepository.php:22-33` (`findByCode()`) does `ORDER BY id ASC LIMIT 1` on `provider_code`, and `LipseysProvider::CODE` (`includes/Wholesalers/Lipseys/LipseysProvider.php:13`) is a single fixed literal `'lipseys'` shared by both accounts. Once one account's wholesaler row exists, `WholesalerImportBridge::resolve_wholesaler_id()` (`includes/Wholesalers/WholesalerImportBridge.php:83-98`) always resolves to that same row — the second account is structurally unreachable through the live import path. → `G2A-CRIT-002`
3. **Lipsey's category mapping is built but never applied.** The `wc_category_id` column exists (`g2a-pos-core/includes/Database/Migrator.php:449`) and is savable via `WholesalerCategoryRepository` (`:61`), but `VendorProductPromoter.php` never reads it and never calls `wp_set_object_terms()`/`set_category_ids()` — grepped zero hits repo-wide. Every Lipsey's product promoted to WooCommerce lands with no category, regardless of what staff configure. → `G2A-CRIT-003`
4. **`memberistic_people.email` has no `UNIQUE` constraint, and `People_Repository::create()` never checks for an existing person by email before inserting** (`class-schema.php:79,93`; `class-people-repository.php:40-61`). `get_by_email()` (`:96-106`) does `ORDER BY id DESC LIMIT 1`, silently returning the *most recently created* duplicate. This is a direct, evidenced mechanism for the client's "wrong people attached to memberships" complaint. → `G2A-CRIT-004`
5. **Booking Engine capability grants only happen in `register_activation_hook`, with no version-triggered upgrade routine.** `g2a-booking-engine/includes/class-activator.php:104-141` grants `manage_g2ab_bookings` (gating the entire admin menu, including the class Calendar) to the `administrator` role only inside `activate()`. The client's confirmed deploy process is direct zip overwrite with no deactivate/reactivate (`docs/CLIENT~1.MD` item 13). WordPress never re-fires activation hooks on a file overwrite. This is the most probable, source-confirmed root cause of "no calendar for classes on the backend" — likely also explains missing Bookings/Resources/Payments/Reports menus if the same account is affected. → `G2A-CRIT-005`
6. **Waiver-import success counter can lie.** `memberistic-membership-solutions/includes/waivers/class-waiver-import.php:196-205` increments `stats['members_matched']` whenever a WordPress user email match is found, but `stamp_member()` (`:452-462`) silently no-ops if no Memberistic person record exists for that email. A completed import run can report "N members matched" while some of those N were never actually stamped `waiver_status = signed`. → `G2A-HIGH-001`
7. **No visitor-facing chatbot exists anywhere in this codebase**, and `guns2ammo/inc/ottertext-cleanup.php` explicitly documents that Otter Text itself was *never* part of this repository — always an externally-injected script (companion plugin / header-footer injector / GTM). The system the client says "replaced Otter Text" and wants to keep is therefore **not something this audit can locate, verify, or build an acceptance checklist against from source.** Both in-repo AI systems (`g2a-pos-core`'s `AiController`/`BrainService` and `g2a-booking-engine`'s AI Auto-Reply) are staff-facing/dashboard tools, not live chat. → `G2A-CRIT-006`, live verification required before any Otter Text cancellation
8. **No restricted content-editor role or page-correction tracking artifact exists in the repository.** The theme + G2A Theme Control expose content through normal WP screens, but there is no capability-scoped "content manager" role, no page-by-page correction register, and no recorded-walkthrough asset. Client cannot safely self-serve content edits today without full administrator access. → `G2A-HIGH-002`
9. **No production-vs-repository version reconciliation is possible from this session.** `INSTALL.md` is one release behind on 4 of 6 plugins per the client's own prior audit; Bridgistic could not get a clean read from the live site as of the last check on file. Every finding in this audit describes **what the repository contains**, not confirmed proof of what is currently deployed. → `G2A-CRIT-007`, blocks confident sign-off on everything else
10. **Lipsey's images are hotlinked to `lipseyscloud.com`, never mirrored locally**, with no local fallback (`g2a-pos-core/includes/Wholesalers/Media/LipseysImageUrls.php`). If Lipsey's CDN blocks hotlinking by referrer, rotates/removes an image, or is simply unreachable, the product photo breaks with no local backup — plausibly connected to the client's "images … might be helpful but I am not understanding why that API [is] not transferring over." → `G2A-HIGH-003`

---

## Top 10 highest-value improvements (beyond fixing the above)

1. **Reconciliation-first architecture for money and membership state** — a scheduled job (the pattern already exists for auto-expire at `class-scheduler.php:263` `reconcile_recurring_with_stripe()`) extended to catch cancel-failures, not just expiry drift.
2. **A single "failed operations" queue** surfaced in wp-admin (Stripe cancel failures, waiver PDF mirror failures, Lipsey's sync failures) instead of requiring staff to know which per-record activity timeline to check.
3. **A Customer Identity Reconciliation Tool** (staff-reviewed, never auto-merge) — see `06-DATA-INTEGRITY-AND-RECONCILIATION.md`.
4. **A `MembershipGatewayInterface` abstraction** ahead of any Stripe replacement — see `07-PAYMENTS-AND-MERCHANT-SERVICE-PLAN.md`.
5. **An upgrade-routine safety net pattern** applied to every plugin (not just Booking Engine) — compare stored `*_plugin_version` option to the running constant on `plugins_loaded`/`admin_init` and re-run capability/role provisioning idempotently. This single pattern would have prevented finding #5 above and likely several undiscovered siblings.
6. **A restricted `content_manager` WordPress role** with a curated capability set (edit specific page types, no plugin/user/settings access) plus a page-by-page correction register as a real content type or CPT, not a spreadsheet that goes stale.
7. **Local image mirroring for Lipsey's** (and any future wholesaler) into the media library, with the hotlink URL kept only as an audit-trail fallback.
8. **A true acceptance-checklist artifact for Otter Text**, built the moment the widget's actual technical identity is confirmed live — this audit provides the checklist shape (`05-MISSING-AND-INCOMPLETE-FEATURES.md`) but cannot fill in the PASS/FAIL column from source that doesn't exist.
9. **Unified Customer 360 view** stitching WP user, Memberistic person, WooCommerce customer, waiver, booking, and POS history — currently these are 8+ independently-keyed stores joined only by best-effort email matching.
10. **Segmentation-and-campaign layer on top of Formistic/Messageistic** for the specific personas the client named (range-visitor→retail, lapsed-member, class-attendee→repeat) — the contact/consent/delivery plumbing exists; the segmentation and campaign logic does not.

---

## Immediate recommendation

Do the following, in this order, before any further feature work or vendor cancellations:

1. **Get a clean, authenticated read of production** (fix the Bridgistic/WAF block, or get direct wp-admin/SFTP access). Nothing in this audit can be upgraded from "confirmed in source" to "confirmed in production" without this, and it blocks every other recommendation below from being executable with confidence.
2. **Ship the four G2A-CRIT fixes** (`001`–`005` above) — each is a small, isolated, testable change with a clear before/after behavior. None require an architecture change.
3. **Run (or re-run against a fresh export) the Otter Waiver import with the corrected member-matching**, and generate the unmatched-record report the client explicitly asked for, before any Otter Waiver cancellation conversation continues.
4. **Do not cancel Otter Text yet.** This audit could not identify what the client's replacement chatbot technically is. That has to be resolved live before an 8-point acceptance checklist can be run against it — see finding #7.
5. **Do not remove Stripe.** Build the `MembershipGatewayInterface` first (Phase 2 in the roadmap), prove a second gateway through full lifecycle on new signups only, and retire Stripe by attrition once its active-subscription count hits zero — exactly the sequencing the client's own prior plan already proposed, which this audit independently confirms is the only safe path.

See `14-ADVANCED-SYSTEM-ROADMAP.md` and `15-IMPLEMENTATION-BACKLOG.md` for the full sequencing and every backlog item with acceptance criteria.
