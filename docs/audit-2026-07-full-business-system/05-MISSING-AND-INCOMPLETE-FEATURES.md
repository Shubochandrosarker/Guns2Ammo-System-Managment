# Missing and Incomplete Features

Classified per the audit brief's taxonomy. "Completely missing" means no code found repo-wide. Everything else means partial code exists but the workflow is not complete end-to-end.

---

## Completely missing

- **Visitor-facing chatbot implementation.** Confirmed absent from this repository (`G2A-CRIT-006`). Not a WordPress-plugin gap — it simply is not here, by the same externally-injected pattern as Otter Text itself.
- **Customer Identity Reconciliation Tool.** No cross-plugin duplicate-detection or merge tooling exists (client item 18). Design in `06-DATA-INTEGRITY-AND-RECONCILIATION.md`.
- **Membership-linking integrity audit tool.** No report or staff review screen for detecting people misattached to memberships (client item 19), beyond what this audit's schema-level finding (`G2A-CRIT-004`) now makes possible to build.
- **Restricted content-manager WordPress role.** No capability-scoped role for safe client self-service content editing exists anywhere in the repository.
- **Page-by-page content correction register.** No tracked artifact (CPT, table, or otherwise) for logging outstanding per-page corrections with an approved/completed/accepted status.
- **`MembershipGatewayInterface` abstraction.** Memberistic's Stripe integration is direct API calls throughout `class-stripe-service.php`, with no interface boundary a second gateway could implement.
- **Passwordless / magic-link login.** No email-a-login-link flow found for older/less tech-comfortable customers.
- **Staff "Send Login Link" tool.** No staff-initiated account-recovery assist tool found.
- **Local image mirroring for Lipsey's** (or any wholesaler) — images are hotlinked only (`G2A-HIGH-003`).
- **Segmentation/campaign logic for the specific personas the client named** (range-visitor→retail, lapsed-member, class-attendee→repeat, etc.) — Formistic/Messageistic provide contact/consent/delivery plumbing; the segmentation queries and campaign definitions themselves do not exist.
- **Failed-operations queue (cross-cutting).** No single admin view aggregates failed Stripe cancellations, failed waiver PDF mirrors, failed Lipsey's syncs, etc. Each failure is logged in its own component's activity/error log only.
- **Staff training mode.** No sandboxed/fake-data mode for onboarding new counter staff without touching real customer records, payments, or messaging.
- **Loyalty/retention features** (points, streaks, referral rewards, birthday offers) — `g2a-pos-core` has a `LoyaltyRepository` and `GiftCardRepository` (schema-level presence confirmed), but this pass did not verify whether customer-facing loyalty UX is built on top of them — treat as **unverified**, not confirmed-missing, and prioritize a follow-up read of `g2a-pos-core/includes/Database/LoyaltyRepository.php` and its consumers before assuming either way.

## Built but not wired

- **Lipsey's category mapping** (`G2A-CRIT-003`) — schema column, repository support, and (presumably) an admin UI to set it all exist; the product-promotion code path never reads it.
- **Waiver import member-matching statistics** (`G2A-HIGH-001`) — the stamping logic exists and mostly works; its own success/failure reporting is wired incorrectly, undermining trust in the tool's own output.
- **Lipsey's second account** (`G2A-CRIT-002`) — the data model supports multiple wholesaler accounts per provider; the one code path that actually performs an import (`WholesalerImportBridge`) cannot address a specific one.
- **Booking Engine role/capability provisioning** (`G2A-CRIT-005`) — fully correct logic, wired to the wrong lifecycle event for a zip-overwrite deploy process.

## UI exists but action is incomplete

- Not independently confirmed this pass beyond the above — a full click-through UI audit of every admin screen was out of scope given the session's verification budget. Recommend the dashboard-app and wp-admin screens both get a dedicated "does every button's action complete end-to-end" pass as a Phase 1/2 follow-up (see `15-IMPLEMENTATION-BACKLOG.md`).

## Backend exists but is not surfaced

- **The class/booking Calendar** (`G2A-CRIT-005`) — this is the client's own framing, and this audit's finding sharpens *why*: not a UI-discoverability problem, a capability-provisioning problem. From the client's chair these look identical ("I don't see it"), which is exactly why the prior docs correctly flagged it as needing live verification rather than more UI work.
- **g2a-pos-core's Loyalty/Gift Card/Consignment/Trade-In subsystems** — schema and repository-level presence confirmed (`LoyaltyRepository.php`, `GiftCardRepository.php`, `ConsignmentRepository.php`, per `01-SYSTEM-INVENTORY.md`); whether these are exposed anywhere in the POS admin UI or dashboard-app was not verified this pass.

## Documentation-only claims

- **"Category and inventory mapping reach WooCommerce" (implied by prior audit docs' framing of Lipsey's as largely complete).** This audit found the category half does not reach WooCommerce (`G2A-CRIT-003`) despite `docs/` describing a complete-sounding Lipsey's subsystem with dedicated unit tests (`LipseysCatalogMapperTest.php` etc.) — the unit tests likely cover the pure-function mapping logic correctly (that part IS correct) without covering the missing final "apply the mapping to the WC product" step, which is a gap in test coverage as much as a gap in the feature.
- **Otter Text replacement being "the chatbot… taken the place of Otter Text"** — a documentation/communication-only claim from the client's own side that this audit could not locate a technical referent for in source. Not a criticism of the client — it likely genuinely exists, just not in this repository.

## Configuration-dependent

- **Business-info correctness in emails** (client item 2) — the code fix is real and correctly designed (`g2ab_business_address()`'s placeholder-aware fallback), but its effectiveness depends entirely on what value is currently saved in the `g2ab_business_address` WordPress option on production, which cannot be read from this repository.
- **Cloudflare login/account cache bypass** (client item 8) — the origin-side `nocache_headers()` fix is real; whether Cloudflare's edge actually honors it depends on live Cloudflare page-rule/cache-level configuration.
- **Stripe webhook configuration** — signature verification code is correct, but whether the webhook endpoint is actually registered and pointed at the right URL in the live Stripe Dashboard is unverifiable from source (this is explicitly flagged as an open item in the client's own prior status doc — "Stripe Dashboard access... for a ~10-minute webhook configuration check").

## Production-version-unknown

Every finding in this audit describes the repository. See `G2A-CRIT-007` — no live production read was available this session. Treat every "confirmed defect" above as "confirmed present in the version of the code in this repository" until cross-checked against what's actually deployed.

## Data-migration requirements

- Any fix to `memberistic_people.email` uniqueness requires a pre-migration duplicate audit (`06-DATA-INTEGRITY-AND-RECONCILIATION.md`).
- Any fix to `g2a_wholesalers` account-resolution requires confirming existing production wholesaler rows have correct, distinct `account_number` values before the new lookup logic ships (a blank account_number on either Lipsey's account today means the new logic needs that filled in as part of the fix, not just the code change).
- Re-running the Otter Waiver import with the corrected `stamp_member()` reporting should be done as a **new** run against the fixed code, not assumed to have been retroactively applied to whatever the last import run actually stamped.

## Operational/training gaps

- Range counter live walkthrough (checklist delivered, walkthrough outstanding — client items 9/11).
- No confirmed staff training on the corrected counter workflow once verified live.
- No confirmed process for who owns "check `INSTALL.md` matches deployed versions" going forward.

## Technical debt

- Two independent AI configuration subsystems (`g2a-pos-core`'s `AiController`/`BrainService` and `g2a-business-api`'s AI agents) — worth a consolidation review to confirm they're not solving the same problem twice with divergent config surfaces.
- Two independent booking-admin menu registration patterns visible in `g2a-booking-engine/includes/class-admin.php` (top-level menu + duplicate submenu registrations at lines 34-44) alongside a parallel set of standalone admin classes (`class-calendar.php`, `class-bookings-list.php`, etc.) each self-registering their own submenu at `add_action('admin_menu', ..., 11)`. Functionally this works (WordPress merges submenus registered against the same parent slug regardless of source file), but it means the admin menu structure is defined in at least two places that must be kept in sync by convention, not by a single registry.
- `guns2ammo`'s dual version identifiers (`G2A-HIGH-004`).

## Client-decision required

- Merchant-service provider selection (client items 12/20) — cannot proceed with `07-PAYMENTS-AND-MERCHANT-SERVICE-PLAN.md`'s Phase 2 without this.
- Otter Text and Otter Waiver cancellation timing — client's own call once acceptance checks pass (client items 7/15/17).
- Whether existing duplicate `memberistic_people`/customer records found by a future reconciliation pass should be merged, and by what tie-breaking rule when they disagree (client item 18).
