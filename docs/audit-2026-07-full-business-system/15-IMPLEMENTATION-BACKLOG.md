# Implementation Backlog

Full evidence for every Critical/High/Medium/Low item lives in `04-CONFIRMED-DEFECTS.md` — reproduced here in backlog format with effort/risk/phase, not re-derived. New items (data integrity tools, operational infrastructure, growth features) that don't correspond to a confirmed defect are detailed fully here. Machine-readable mirror: `improvement-backlog.json`.

---

### G2A-CRIT-001 — Fix membership cancellation atomicity with Stripe
**Component:** Memberistic Membership Solutions
**Problem:** Local status flips to `cancelled` before remote Stripe confirmation; failure has no compensating action. See `04-CONFIRMED-DEFECTS.md`.
**Business impact:** Customer can be locally cancelled while Stripe keeps billing — direct financial/trust risk, the client's #1 reported issue.
**Proposed solution:** Add `cancel_pending`/`cancel_failed` states; attempt remote cancellation before or atomically with local write; surface failures in a dedicated queue, not just a per-record activity log.
**Dependencies:** None — isolated to Memberistic's cancellation path.
**Risk:** Low.
**Effort:** M
**Acceptance criteria:** Simulated Stripe failure during cancellation leaves the membership in a distinct, staff-visible failure state, never silently `cancelled`.
**Tests:** Automated failure-injection test; manual staging verification with an invalidated Stripe key.
**Suggested release phase:** 0

### G2A-CRIT-002 — Fix Lipsey's two-account resolution
**Component:** G2A POS Core
**Problem:** `findByCode()` always returns the lowest-id row for a shared `provider_code`; blank `account_number` lets one account's save overwrite another's. See `08-LIPSEYS-INTEGRATION-AUDIT.md`.
**Business impact:** One of the client's two Lipsey's accounts (firearms/accessories) is permanently unreachable via import.
**Proposed solution:** Account-aware lookup (`provider_code` + `account_number`); require non-blank account numbers for multi-account providers; add a uniqueness index reflecting the intended semantics.
**Dependencies:** Data cleanup — confirm/assign distinct account numbers on existing production wholesaler rows before deploying the new lookup logic.
**Risk:** Medium (upsert semantics change affects any single-account wholesaler).
**Effort:** M
**Acceptance criteria:** Both Lipsey's accounts import independently without credential collision.
**Tests:** Two-account import regression test (see `08-LIPSEYS-INTEGRATION-AUDIT.md` test plan item 1).
**Suggested release phase:** 0

### G2A-CRIT-003 — Apply Lipsey's category mapping during WooCommerce promotion
**Component:** G2A POS Core
**Problem:** `wc_category_id` mapping is configurable but never read/applied by `VendorProductPromoter`.
**Business impact:** Every Lipsey's product lands uncategorized in WooCommerce regardless of configured mapping.
**Proposed solution:** Read `wc_category_id` alongside the existing markup-percent lookup; call `wp_set_object_terms()`/`set_category_ids()` during promotion.
**Dependencies:** None.
**Risk:** Low.
**Effort:** S
**Acceptance criteria:** A product promoted after configuring its category mapping shows the correct `product_cat` term.
**Tests:** See `08-LIPSEYS-INTEGRATION-AUDIT.md` test plan item 2.
**Suggested release phase:** 0

### G2A-CRIT-004 — Enforce/audit `memberistic_people` email uniqueness
**Component:** Memberistic Membership Solutions
**Problem:** No `UNIQUE` constraint on `email`; `create()` doesn't check for existing rows; `get_by_email()` silently resolves to the most-recent duplicate.
**Business impact:** Direct, evidenced mechanism for "wrong people attached to memberships."
**Proposed solution:** Short-term: staff-facing audit report of existing email duplicates across membership_id (see `G2A-DATA-002` below). Medium-term: block/warn on duplicate-email creation; consider a conditional unique index once production data is clean.
**Dependencies:** Production data audit before any hard constraint (`SELECT email, COUNT(*) ... HAVING COUNT(*) > 1`).
**Risk:** Medium — schema change requires pre-cleanup.
**Effort:** M (audit report) + L (enforcement, pending data cleanup)
**Acceptance criteria:** Duplicate-email creation is flagged, not silent; existing duplicates are enumerated for staff review.
**Tests:** Attempt duplicate creation in staging; confirm new behavior.
**Suggested release phase:** 0 (audit report), 1 (enforcement)

### G2A-CRIT-005 — Idempotent capability/role provisioning on upgrade
**Component:** G2A Booking Engine (pattern should be checked repo-wide)
**Problem:** Capability grants only happen in `register_activation_hook`; no version-triggered re-provisioning; client's zip-overwrite deploy never re-fires activation.
**Business impact:** Most probable root cause of "no calendar for classes on the backend" — likely affects the entire admin menu for the same account.
**Proposed solution:** Compare stored `g2ab_plugin_version` to `G2AB_VERSION` on `admin_init`/`plugins_loaded`; re-run `register_roles_and_caps()` on mismatch. Apply the same pattern check to every other plugin.
**Dependencies:** None for the immediate fix. Immediate unblock (no code change): deactivate/reactivate the plugin once on production.
**Risk:** Low.
**Effort:** S (Booking Engine) + M (repo-wide sweep + fix if the same bug exists elsewhere)
**Acceptance criteria:** Capabilities are re-granted after a simulated version bump without reactivation.
**Tests:** See `04-CONFIRMED-DEFECTS.md`.
**Suggested release phase:** 0

### G2A-CRIT-006 — Identify the actual Otter Text replacement chatbot
**Component:** Cross-cutting / live verification
**Problem:** No visitor-facing chatbot exists in this repository; the client's replacement is very likely, by the same pattern as Otter Text itself, an externally-injected widget this audit cannot see.
**Business impact:** Blocks a confident Otter Text cancellation decision.
**Proposed solution:** Live investigation (view-source, Plugins list, header/footer-script injector content, GTM config) to identify the actual implementation; then build and run the 8-point acceptance checklist against it.
**Dependencies:** Production access.
**Risk:** N/A (investigation, not a code change).
**Effort:** S (investigation) — checklist build/run effort depends on what's found.
**Acceptance criteria:** The chatbot's technical implementation is identified and named; the 8-point checklist (desktop+mobile widget, lead capture, conversation storage, Formistic contact creation, staff notification, human handoff, business-hours behavior, AI knowledge grounding, failure fallback, message history, analytics, consent handling, error logging) is run against it with a PASS/FAIL per item.
**Tests:** N/A until identified.
**Suggested release phase:** 0

### G2A-CRIT-007 — Establish production version ground truth
**Component:** Cross-cutting
**Problem:** No confirmed clean read of production; `INSTALL.md` and in-repo version constants both show drift.
**Business impact:** Every other fix needs live confirmation before it can be trusted as "done."
**Proposed solution:** Fix Bridgistic/WAF blocking or get direct wp-admin/SFTP access; generate the production manifest described in `01-SYSTEM-INVENTORY.md`.
**Dependencies:** Client-provided access.
**Risk:** N/A.
**Effort:** S (once access is available)
**Acceptance criteria:** A dated manifest of every deployed component's version exists and matches (or explicitly reconciles against) this repository.
**Tests:** N/A.
**Suggested release phase:** 0

### G2A-HIGH-001 — Fix waiver-import match-statistics accuracy
**Component:** Memberistic Membership Solutions
**Problem:** `members_matched` counter increments regardless of whether `stamp_member()` actually wrote a status update.
**Business impact:** Import summary can misrepresent how many waivers were actually applied; account-page waiver status can be stale.
**Proposed solution:** Return a success/failure signal from `stamp_member()`; track a distinct "matched but not stamped" counter; produce an itemized (not just aggregate) unmatched/unstamped report.
**Dependencies:** None.
**Risk:** Low.
**Effort:** S
**Acceptance criteria:** A fixture row with a WP-user match but no Memberistic person record is reported in a distinct counter, not folded into `members_matched`.
**Tests:** See `09-WAIVER-CONTACT-MEMBERSHIP-MIGRATION.md`.
**Suggested release phase:** 0

### G2A-HIGH-002 — Restricted content-manager role + correction register
**Component:** guns2ammo theme, G2A Theme Control
**Problem:** No capability-scoped content role or tracked correction artifact exists.
**Business impact:** Client cannot self-serve content edits; no visibility into outstanding per-page issues.
**Proposed solution:** New `content_manager` role scoped to page content only; a correction-register content type with approved/completed/accepted status; a recorded editing walkthrough.
**Dependencies:** None.
**Risk:** Low.
**Effort:** M
**Acceptance criteria:** Client successfully edits and republishes a real page correction using the new role, without admin access.
**Tests:** See `03-CLIENT-REQUIREMENTS-GAP-MATRIX.md` row 4/5.
**Suggested release phase:** 1

### G2A-HIGH-003 — Mirror Lipsey's images locally
**Component:** G2A POS Core
**Problem:** Images hotlinked to Lipsey's CDN, never mirrored.
**Business impact:** Product photos break with no fallback if the CDN is unreachable, renames files, or blocks hotlinking.
**Proposed solution:** Download-and-attach to media library on first promotion, reusing the waiver-PDF-mirroring pattern already proven in this codebase; batch/cron for full-catalog re-syncs.
**Dependencies:** Storage/bandwidth planning for a full catalog.
**Risk:** Low.
**Effort:** M
**Acceptance criteria:** Promoted products use a local media attachment as their featured image.
**Tests:** See `08-LIPSEYS-INTEGRATION-AUDIT.md` test plan item 3.
**Suggested release phase:** 1

### G2A-HIGH-004 — Synchronize theme version identifiers
**Component:** guns2ammo theme
**Problem:** `G2A_VERSION` constant (asset cache-buster) stuck at 1.27.5 across 8+ style.css releases.
**Business impact:** Stale CSS/JS caching risk across those releases.
**Proposed solution:** Single source for both (read `wp_get_theme()->get('Version')` at runtime, or a build step that stamps both).
**Dependencies:** None.
**Risk:** Low (one-time forced cache invalidation on next deploy — expected).
**Effort:** S
**Acceptance criteria:** Both version identifiers agree at all times going forward.
**Tests:** Manual verification post-deploy.
**Suggested release phase:** 1

### G2A-MED-001 — Verify nonce on waiver export/print routes
**Component:** Memberistic Membership Solutions
**Problem:** Nonce URL generated but never verified server-side; capability check alone gates access.
**Business impact:** Low — CSRF-hygiene gap on an already capability-gated, read-only action.
**Proposed solution:** Add `check_admin_referer()`/`wp_verify_nonce()` alongside the existing capability check.
**Dependencies:** None.
**Risk:** None.
**Effort:** S
**Acceptance criteria:** Request without a valid nonce is rejected even with a valid capability.
**Tests:** Manual request with stale/missing nonce.
**Suggested release phase:** 1

### G2A-LOW-001 — Migrate dashboard-app to ESLint flat config
**Component:** dashboard-app
**Problem:** `npm run lint` fails — no `eslint.config.js` for ESLint 9+.
**Business impact:** None functional; lint has been unenforceable with current tooling.
**Proposed solution:** Add flat config or pin ESLint to `^8`.
**Dependencies:** None.
**Risk:** None.
**Effort:** S
**Acceptance criteria:** `npm run lint` exits 0 (or with real, addressed findings, not a config error).
**Tests:** Run `npm run lint`.
**Suggested release phase:** 1

### G2A-DATA-001 — Customer Identity Reconciliation Tool
**Component:** New, cross-cutting (likely lives in Memberistic or a new lightweight plugin)
**Problem:** No cross-plugin duplicate-detection/merge tooling exists.
**Business impact:** Client-requested ("cleanup of contacts already in the system and a check for duplicates"); also the long-term mitigation for `G2A-CRIT-004`'s duplicate-creation risk.
**Proposed solution:** See full design in `06-DATA-INTEGRITY-AND-RECONCILIATION.md` — detection rules, confidence scoring, staff review screen, never-auto-merge, reversible soft-delete.
**Dependencies:** `G2A-CRIT-004`'s data audit; ideally a cross-plugin identity map extended beyond what this pass verified (POS/Formistic/Messageistic customer stores).
**Risk:** Medium (merge operations touch multiple tables' FKs — must be transactionally safe).
**Effort:** L
**Acceptance criteria:** A seeded duplicate is correctly flagged, reviewed, and merged with full audit trail; a non-duplicate near-match is correctly NOT auto-merged.
**Tests:** Seeded-duplicate integration test; false-positive test with a deliberately similar-but-distinct pair.
**Suggested release phase:** 1

### G2A-DATA-002 — Membership-linking integrity audit report
**Component:** Memberistic Membership Solutions
**Problem:** No report exists for detecting people misattached to memberships.
**Business impact:** Client-requested ("some members have the wrong people attached to their memberships").
**Proposed solution:** See audit rules in `06-DATA-INTEGRITY-AND-RECONCILIATION.md` — duplicate primary members, seat-count vs. plan-limit mismatches, waiver-status-vs-archive drift.
**Dependencies:** None to build the report; resolution actions depend on `G2A-DATA-001`'s detach/reassign UI.
**Risk:** Low (read-only report).
**Effort:** M
**Acceptance criteria:** Report correctly flags a seeded "two primary members on one membership" and "person waiver_status disagrees with archive" case.
**Tests:** Seeded-data report accuracy test.
**Suggested release phase:** 1

### G2A-OPS-001 — Unified failed-operations queue / health dashboard
**Component:** New, cross-cutting
**Problem:** Each component logs failures independently; no aggregated view.
**Business impact:** Staff can't currently answer basic reliability questions without knowing which component's log to check — see `12-PERFORMANCE-RELIABILITY-OBSERVABILITY.md`'s question table.
**Proposed solution:** Aggregation layer over existing per-component logs (Activity Repository, Email Logs Repository, Stripe event-processed tracking, wholesaler sync repositories) — not a replacement logging system.
**Dependencies:** `G2A-CRIT-001`'s new failure states are a natural first feed.
**Risk:** Low (read-only aggregation).
**Effort:** L
**Acceptance criteria:** A failed Stripe cancellation, a failed waiver stamp, and a failed Lipsey's sync all appear in one staff-facing view within N minutes of occurring.
**Tests:** Seeded-failure-per-source visibility test.
**Suggested release phase:** 1

### G2A-OPS-002 — Staging environment + deploy manifest
**Component:** Cross-cutting / infrastructure
**Problem:** No staging clone; no production manifest; deploys are direct zip overwrites.
**Business impact:** Root enabler of most other findings — the client cannot currently test before hard-deploying to a live business.
**Proposed solution:** See `01-SYSTEM-INVENTORY.md`'s canonical manifest design; stand up a staging clone (hosting-dependent, outside this repository's scope to implement directly).
**Dependencies:** Hosting/infrastructure decision, client budget/timeline.
**Risk:** N/A (process, not code).
**Effort:** L
**Acceptance criteria:** A code change can be deployed to staging, verified, then promoted to production via a repeatable process.
**Tests:** N/A.
**Suggested release phase:** 0

### G2A-OPS-003 — Staff training mode
**Component:** New, cross-cutting
**Problem:** No sandboxed environment for onboarding counter staff.
**Business impact:** Client-stated: "I don't know enough to teach the employees how to use the range check-in."
**Proposed solution:** See `10-COUNTER-WORKFLOW-AND-STAFF-HANDOFF.md` — `training_mode` flag stubbing payment/email/SMS, seeded fake scenarios, completion tracking.
**Dependencies:** The live counter-workflow walkthrough (client item 9/11) should happen first — training mode should teach the *verified* workflow, not the as-designed one.
**Risk:** Low.
**Effort:** L
**Acceptance criteria:** A new hire completes every named scenario in training mode with zero real payment/email/SMS side effects, tracked to their staff account.
**Tests:** Manual scenario walkthrough; automated assertion that training-mode transactions never reach a real gateway.
**Suggested release phase:** 2

### G2A-PLAT-001 — `MembershipGatewayInterface` abstraction
**Component:** Memberistic Membership Solutions
**Problem:** Stripe integration is direct, not behind an interface.
**Business impact:** Blocks any safe merchant-service migration.
**Proposed solution:** See `07-PAYMENTS-AND-MERCHANT-SERVICE-PLAN.md` — mechanical extraction of `Stripe_Service`'s existing methods behind the named interface; no behavior change.
**Dependencies:** None to build; a second gateway adapter depends on the client's processor decision.
**Risk:** Low (structural refactor).
**Effort:** M
**Acceptance criteria:** All existing Memberistic Stripe behavior is unchanged after the refactor (regression-tested), with the concrete class now resolved via a setting rather than hardcoded.
**Tests:** Full existing Memberistic payment test suite must still pass; manual smoke test of checkout/cancel/renew.
**Suggested release phase:** 1

### G2A-GROWTH-001 — Customer segmentation query layer
**Component:** New, likely spans Memberistic/Booking/WooCommerce/Formistic/Messageistic
**Problem:** No segmentation logic exists for the named personas despite the underlying data being present.
**Business impact:** Directly enables the client's requested email/SMS/social growth push.
**Proposed solution:** See `13-SEO-CRO-REVENUE-GROWTH.md` segment table.
**Dependencies:** Consent-enforcement verification (quiet hours, STOP handling) should be confirmed before send volume increases.
**Risk:** Low (read-only queries) for the segmentation layer itself; Medium for send-volume/compliance once campaigns launch.
**Effort:** M
**Acceptance criteria:** Each named segment can be queried and its member count/list exported.
**Tests:** Per-segment query accuracy against seeded data.
**Suggested release phase:** 2

### G2A-GROWTH-002 — Campaign automation on Formistic/Messageistic
**Component:** Formistic, Messageistic
**Problem:** Delivery/consent plumbing exists; campaign trigger logic does not.
**Business impact:** Converts existing infrastructure investment into the actual growth outcome the client wants.
**Proposed solution:** Build the specific campaigns in `13-SEO-CRO-REVENUE-GROWTH.md`'s table on top of `G2A-GROWTH-001`'s segments.
**Dependencies:** `G2A-GROWTH-001`.
**Risk:** Medium (compliance risk scales with volume).
**Effort:** L
**Acceptance criteria:** At least one full campaign (e.g., lapsed-member recovery) runs end-to-end with delivery and consent correctly enforced.
**Tests:** Consent-boundary test (a STOP'd/unsubscribed contact never receives a campaign send).
**Suggested release phase:** 2-3

### G2A-PLAT-002 — Unified Customer 360
**Component:** New, cross-cutting
**Problem:** No single view stitches WP user, Memberistic person, WooCommerce customer, waiver, booking, POS history.
**Business impact:** The audit brief's own stated advanced-feature target; also the natural home for all reconciliation/segmentation output.
**Proposed solution:** Build only after `G2A-CRIT-004`/`G2A-DATA-001` establish a trustworthy identity model — a 360 view built on top of unreconciled duplicate data would just make the duplication more visible, not less harmful.
**Dependencies:** `G2A-CRIT-004`, `G2A-DATA-001`.
**Risk:** Medium.
**Effort:** XL
**Acceptance criteria:** A single staff-facing profile correctly aggregates all of a real (non-duplicate) customer's cross-system history.
**Tests:** Aggregation-accuracy test against a known-good seeded customer.
**Suggested release phase:** 4
