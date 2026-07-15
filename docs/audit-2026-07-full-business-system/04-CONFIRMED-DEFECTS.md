# Confirmed Defects

Every item below was independently reproduced by reading the exact source lines this session — not inferred from documentation, changelog claims, or prior audits. Grouped by severity. Cross-referenced to backlog IDs used in `15-IMPLEMENTATION-BACKLOG.md` and `improvement-backlog.json`.

---

## Critical

### G2A-CRIT-001 — Membership cancellation is not atomic with Stripe cancellation
- **Component:** Memberistic Membership Solutions
- **Files:** `memberistic-membership-solutions/includes/database/class-memberships-repository.php:517-550` (`change_status()`), `includes/payments/class-stripe-service.php:212-274` (`maybe_cancel_remote_subscription()`), `includes/rest/class-memberships-controller.php:1304-1322` (`cancel_membership()`)
- **Root cause:** `change_status()` calls `self::update($id, $update)` (writes local DB status) at line 535, *then* fires `do_action('memberistic_membership_status_changed', ...)` at line 546. The Stripe-side listener (`maybe_cancel_remote_subscription()`) runs after the local write has already committed. On Stripe API failure, the handler logs an Activity Repository entry titled "Stripe cancellation FAILED — subscription may still be billing" and calls `error_log()`, but never reverts `status` back from `'cancelled'` and never writes a distinct machine-readable failure state.
- **Business impact:** A Stripe outage, expired API key, network blip, or Stripe-side error at the moment of cancellation leaves the customer's local record reading `cancelled` while Stripe continues to charge their card every billing cycle. This is the exact behavior the client reported.
- **Reproduction:** Read the code path above; to reproduce behaviorally, invalidate the Stripe secret key in a staging environment, cancel a test membership with an active `stripe_subscription_id`, and observe: local `status` becomes `cancelled` immediately; Stripe subscription remains `active`; the only trace is one Activity Repository row.
- **Recommended fix:** Either (a) attempt the remote Stripe cancellation *first*, and only flip local status on confirmed success (with a documented fallback path for when Stripe is deliberately not configured), or (b) keep the current ordering but add a true compensating state machine: `cancel_pending` → remote attempt → `cancelled` (success) or `cancel_failed`/`requires_review` (failure, surfaced in a dedicated staff-visible queue, not just a per-membership activity row).
- **Test required after fix:** Automated test simulating a Stripe API failure during cancellation; confirm the membership does NOT read `cancelled` and appears in the failure queue. Automated test for the success path unchanged.
- **Deployment risk:** Low — this is a logic reordering / new status value, not a schema-breaking change. Existing `cancelled` rows are unaffected. If a new status value (`cancel_failed`) is added, any code that does exact-match `status = 'cancelled'` checks elsewhere in the plugin must be audited for the new value (recommend grep `=== 'cancelled'` / `== 'cancelled'` across the plugin before shipping).

### G2A-CRIT-002 — Lipsey's second wholesaler account is structurally unreachable
- **Component:** G2A POS Core
- **Files:** `g2a-pos-core/includes/Database/WholesalerRepository.php:22-33` (`findByCode()`), `includes/Wholesalers/WholesalerImportBridge.php:83-98` (`resolve_wholesaler_id()`), `includes/Wholesalers/Lipseys/LipseysProvider.php:13` (`const CODE = 'lipseys'`)
- **Root cause:** `findByCode(string $code)` runs `SELECT * FROM {$t} WHERE provider_code=%s ORDER BY id ASC LIMIT 1`. `LipseysProvider::CODE` is a single literal `'lipseys'` shared by every Lipsey's wholesaler row regardless of which of the client's two accounts (firearms / accessories) it represents. `resolve_wholesaler_id()` calls `findByCode($provider->code())` — it never considers an account number or any per-call context once at least one row for that provider code exists. The first-created account "wins" permanently; the second is unreachable through the live import path (`WholesalerImportBridge::mirror_csv()`), which is the only caller.
- **Secondary defect (same root cause family):** `WholesalerRepository::upsert()` (`:51-60`) treats a **blank** `account_number` as a match key: `WHERE provider_code=%s AND (account_number=%s OR (%s='' AND (account_number IS NULL OR account_number='')))`. If staff save a second Lipsey's account without filling in its account number, the save silently **updates** (overwrites) the first account's row — including its encrypted credentials — instead of creating a second row.
- **Business impact:** Whichever Lipsey's account (firearms or accessories) was configured first is the only one the system can ever actually sync. Explains the client's "API attempted, same result" and "categories didn't carry over" — the second account's catalog was never reachable to import in the first place.
- **Reproduction:** Configure two wholesaler rows with `provider_code = 'lipseys'` (distinct `account_number` values); trigger an import via `WholesalerImportBridge::mirror_csv()` for the second account's context; confirm `resolve_wholesaler_id()` returns the first row's id, not a new/second row's id.
- **Recommended fix:** Add an `account_number`-aware lookup — `findByCodeAndAccount(string $code, string $accountNumber)` — and require the import bridge (and any other `findByCode()` caller) to pass the specific account being synced. Add a `UNIQUE(provider_code, account_number)` index (with account_number normalized to a non-null sentinel for single-account providers) to make the upsert dedup match the intended semantics instead of matching on "blank equals blank."
- **Test required after fix:** Two wholesaler rows with the same provider_code and distinct account numbers; confirm each resolves independently and neither import overwrites the other's credentials.
- **Deployment risk:** Medium — changing the upsert match semantics could affect any single-account wholesaler currently relying on blank-account_number matching; audit all non-Lipsey's wholesaler configurations before deploying the index change.

### G2A-CRIT-003 — Lipsey's category mapping is configured but never applied to WooCommerce products
- **Component:** G2A POS Core
- **Files:** `g2a-pos-core/includes/Database/Migrator.php:449` (`wc_category_id BIGINT UNSIGNED NULL` column), `includes/Database/WholesalerCategoryRepository.php:61` (field is in the savable `$allowed` list), `includes/Wholesalers/Promotion/VendorProductPromoter.php` (full file — zero hits for `wp_set_object_terms`, `set_category_ids`, or `wc_category_id`)
- **Root cause:** The data model supports mapping a Lipsey's `vendor_category` string to a WooCommerce `product_cat` term id, and the value is savable via the repository (presumably through an admin screen). But `VendorProductPromoter.php`, the only code that actually creates/updates a WooCommerce product from a vendor row, reads the per-category row (`$categoryRepo->all($wholesalerId)`, line 57) exclusively to look up `markup_percent` for **pricing** (line 158-160). It never reads `wc_category_id` and never calls the WooCommerce API to assign a category term.
- **Business impact:** Every promoted Lipsey's product lands in WooCommerce uncategorized (or in whatever WooCommerce's default fallback category is), regardless of what mapping staff configure. Precisely matches "product categories didn't seem to carry over."
- **Reproduction:** Configure a `wc_category_id` mapping for a vendor category; promote a product in that category; inspect the resulting WooCommerce product's `product_cat` terms — will be empty/default.
- **Recommended fix:** In `VendorProductPromoter`, after resolving `$vendorCategory` (line 158), look up its `wc_category_id` from the same `$categories` array already loaded, and call `wp_set_object_terms($productId, [$wcCategoryId], 'product_cat')` (or `$product->set_category_ids([$wcCategoryId])` if working through `WC_Product`) during product creation/update, before saving.
- **Test required after fix:** Promote a product with a configured category mapping; assert the WooCommerce product has the correct `product_cat` term.
- **Deployment risk:** Low — additive change to existing promotion logic; run once against a staging catalog import before production to confirm no unexpected category collisions with manually-managed WooCommerce categories.

### G2A-CRIT-004 — No uniqueness enforcement on `memberistic_people.email`; membership-person linking can misattach
- **Component:** Memberistic Membership Solutions
- **Files:** `memberistic-membership-solutions/includes/database/class-schema.php:73-96` (table DDL — `email` is `KEY email (email)`, not `UNIQUE`), `includes/database/class-people-repository.php:40-61` (`create()` — no existing-row check before insert), `:96-106` (`get_by_email()` — `ORDER BY id DESC LIMIT 1`)
- **Root cause:** Nothing in the schema or the application layer prevents the same email address from being attached to person rows on two different `membership_id`s. `get_by_email()`, used by the waiver-stamping path (`Waiver_Import::stamp_member()`) and elsewhere, always resolves to whichever person row was created **most recently** for that email — silently picking a possibly-wrong membership when duplicates exist.
- **Business impact:** Directly matches "some members have the wrong people attached to their memberships." Any workflow that creates a person row without an existing-email check (manual staff add, corporate group invite re-acceptance, guest-to-member conversion) can produce a duplicate that then silently "steals" future email-keyed lookups (waiver stamping, matching, possibly others) away from the correct membership.
- **Reproduction:** Create two `memberistic_people` rows with the same email under two different `membership_id`s (nothing in the schema or `create()` prevents this); call `People_Repository::get_by_email()` and observe it returns the higher-id (more recent) row regardless of which membership is "correct."
- **Recommended fix:** Short-term: a staff-facing audit report (see `06-DATA-INTEGRITY-AND-RECONCILIATION.md`) that flags existing email duplicates across membership_id values for manual review — do NOT auto-merge. Medium-term: add an existing-row check in `create()` that either blocks, warns, or explicitly asks the caller to confirm intent when an email is already attached elsewhere; consider a partial/conditional unique index if the business rule allows at most one *active* person per email.
- **Test required after fix:** Attempt to create a duplicate-email person under a different membership; confirm the new behavior (block/warn/flag) fires as designed.
- **Deployment risk:** Medium — any schema uniqueness change must first be validated against production data (a `SELECT email, COUNT(*) FROM memberistic_people WHERE email <> '' GROUP BY email HAVING COUNT(*) > 1` dry run) since existing legitimate duplicates (if any) would block a hard unique index from being added without cleanup first.

### G2A-CRIT-005 — Booking Engine role/capability grants only happen on plugin activation, with no upgrade-safety net
- **Component:** G2A Booking Engine
- **Files:** `g2a-booking-engine/g2a-booking-engine.php:177` (`register_activation_hook`), `includes/class-activator.php:14-141` (`activate()` → `register_roles_and_caps()`)
- **Root cause:** `register_roles_and_caps()` — which grants `manage_g2ab_bookings` and six sibling capabilities to the `administrator` role and creates the `g2ab_staff`/`g2ab_instructor` roles — is only ever called from `G2AB_Activator::activate()`, itself only reachable via `register_activation_hook()`. WordPress fires activation hooks exclusively on the transition from inactive→active plugin state; a file-overwrite deploy (confirmed as the client's actual deploy method — direct zip upload to production, no staging, per `docs/CLIENT~1.MD` item 13) never triggers this. There is no `plugins_loaded`/`admin_init` version-comparison routine anywhere in the plugin that re-provisions capabilities on upgrade.
- **Business impact:** Any capability introduced (or any role/capability grant that failed for any reason) after a site's very first plugin activation never reaches the live site's roles without a manual deactivate/reactivate cycle. Since the entire admin menu — Bookings, Calendar, Resources, Payments, Reports, Settings — is gated behind these capabilities, this can make some or all of it silently disappear for every staff account, matching the client's "no calendar for classes on the backend" complaint precisely, and plausibly explaining other "I don't see X in the admin" reports the client hasn't yet connected to this same root cause.
- **Reproduction:** On a site where the plugin was first activated at an older version, add a new capability requirement to a screen (as apparently happened for the Calendar, `@since 1.3.0` per its own doc comment) and deploy via file overwrite only, without reactivating; confirm the new capability is absent from `wp_options` → `administrator` role capabilities.
- **Recommended fix:** Add an idempotent provisioning check — compare a stored `g2ab_plugin_version` option against `G2AB_VERSION` on `admin_init` (or `plugins_loaded`), and re-run `register_roles_and_caps()` whenever they differ (this pattern is already half-built: `update_option('g2ab_plugin_version', G2AB_VERSION)` already exists in `activate()` at line 26 — it just needs a read-side counterpart outside the activation-only path).
- **Test required after fix:** Simulate an upgrade (bump `G2AB_VERSION`, do not reactivate) and confirm capabilities are re-granted on the next admin page load.
- **Deployment risk:** Low — purely additive; safe to ship immediately. **Recommend also shipping the same idempotent pattern as a repo-wide standard** — this exact bug class may exist in other plugins that were not checked this pass (see `01-SYSTEM-INVENTORY.md` recommendation).

### G2A-CRIT-006 — No visitor-facing chatbot exists anywhere in this codebase
- **Component:** Cross-cutting (theme, g2a-pos-core, cloudflare-rag-worker, messageistic)
- **Files:** `guns2ammo/inc/ottertext-cleanup.php` (full file), plus a repo-wide search for chat-widget REST routes, JS chat-bubble mounts, and CORS-enabled visitor-facing AI endpoints (zero hits)
- **Root cause:** `ottertext-cleanup.php`'s own doc comment states plainly that Otter Text "injects its chatbot/age-popup as an EXTERNAL script — it is not part of this theme or any bundled plugin," listing plausible injection points (a header/footer-scripts plugin, GTM, a theme-options custom-scripts box, a leftover companion plugin, or a pasted embed snippet) — none of which live in this repository. Both in-repo AI systems are staff/dashboard-facing: `g2a-pos-core`'s `AiController`/`BrainService`/`AgentService` (settings, sealed API keys, tool registry — an internal agent configuration layer) and `g2a-booking-engine`'s `class-autoreply-engine.php` (generates staff-facing draft replies via `POST /ai/draft`, explicitly "for the React dashboard / admin AJAX"). `cloudflare-rag-worker` requires a bearer token and emits no CORS headers by design, making it unreachable from a browser.
- **Business impact:** The client wants to cancel Otter Text "ASAP" but needs 100% confirmation the replacement is operational first. This audit cannot supply that confirmation because the replacement's implementation is not in this codebase — it is very likely, by the same pattern as Otter Text itself, ALSO an externally-injected widget/script that this repository has no visibility into.
- **Reproduction:** `grep -r "chat" --include=*.php --include=*.js --include=*.tsx` across the whole repo for visitor-facing (non-admin, non-CORS-restricted) chat surfaces returns nothing beyond the two staff-tools above.
- **Recommended fix:** This is not a code fix — it requires live investigation: view-source the production site for a chat-widget script tag/iframe, check the WordPress Plugins list for a chat-widget plugin, check any header/footer-script injector plugin's saved content, and check Cloudflare/GTM configuration if used. Once identified, build the 8-point acceptance checklist named in the audit brief and run it against the actual implementation (see `05-MISSING-AND-INCOMPLETE-FEATURES.md`).
- **Test required after fix:** N/A until the implementation is identified.
- **Deployment risk:** N/A — this is a live-verification blocker, not a deployable fix.

### G2A-CRIT-007 — No reliable way to confirm what production is actually running
- **Component:** Cross-cutting
- **Files:** `INSTALL.md` (documented as one release behind on 4 of 6 plugins per the client's own July 15 status doc); `guns2ammo/functions.php:10` vs `guns2ammo/style.css` (in-repo version drift — see `01-SYSTEM-INVENTORY.md`)
- **Root cause:** No staging environment, no deploy manifest, and (per the prior status document on file) no clean authenticated read of the live site was available as of the last check. Every finding in this audit describes the repository, not confirmed production behavior.
- **Business impact:** Every fix recommendation in this audit — including the four other Critical items above — needs a live-production verification step before anyone can respond to the client with certainty rather than "should be fixed now." This is the audit brief's own Phase 0, and it remains the single highest-leverage next step.
- **Recommended fix:** See `01-SYSTEM-INVENTORY.md` canonical production manifest design and `14-ADVANCED-SYSTEM-ROADMAP.md` Phase 0.
- **Deployment risk:** N/A — this is a process gap, not a code change.

---

## High

### G2A-HIGH-001 — Waiver-import "members matched" statistic can overcount actual stamps
- **Component:** Memberistic Membership Solutions
- **Files:** `memberistic-membership-solutions/includes/waivers/class-waiver-import.php:172,196-205` (`import_file()`), `:452-462` (`stamp_member()`)
- **Root cause:** `$matched_user = self::match_member($entry)` returns a WordPress **user** id (matched by exact email string via `get_user_by('email', ...)`). The caller increments `$stats['members_matched']++` whenever this is truthy, then calls `self::stamp_member($matched_user, $entry)`. But `stamp_member()` independently looks up a **Memberistic person** record by the same email (`People_Repository::get_by_email()`) and silently `return`s if none exists — it does not report failure back to the caller, and the caller's counter has already incremented.
- **Business impact:** A completed import can report "N members matched" in the WP-CLI/admin summary while some of those N were WordPress-user matches with no corresponding Memberistic person row to actually stamp — those waivers never show as signed anywhere in the member-facing UI, and nothing in the tool's own output reveals this happened.
- **Recommended fix:** Have `stamp_member()` return a bool/enum (`stamped` / `no_person_record` / `not_found`), and track a distinct counter (`person_not_found`) separate from `members_matched`. Surface both in the CLI/admin summary and add an itemized list (not just a count) of the emails that fell into the gap.
- **Test required after fix:** Import a fixture row whose email matches a WP user but no Memberistic person; confirm it is reported in the new distinct counter, not folded into `members_matched`.
- **Deployment risk:** Low.

### G2A-HIGH-002 — No restricted content-editor role or content-handoff tooling exists
- **Component:** Cross-cutting (guns2ammo theme, G2A Theme Control)
- **Files:** No matching role/capability found repo-wide; `g2a-theme-control` is 7 files / 472 LOC total
- **Business impact:** Client cannot self-serve page-copy corrections without full WordPress administrator access, and there is no tracked register of outstanding per-page corrections.
- **Recommended fix / test / risk:** See `10-COUNTER-WORKFLOW-AND-STAFF-HANDOFF.md`.

### G2A-HIGH-003 — Lipsey's product images are hotlinked, never mirrored locally
- **Component:** G2A POS Core
- **Files:** `g2a-pos-core/includes/Wholesalers/Media/LipseysImageUrls.php` (full file, 33 lines)
- **Root cause:** `cdnUrl()` builds a direct `https://www.lipseyscloud.com/images/<filename>` URL from the CSV/API `IMAGENAME` field. No download-and-attach-to-media-library step exists anywhere in the Lipsey's promotion path checked this session.
- **Business impact:** Product photos depend entirely on Lipsey's CDN staying reachable, keeping the exact filename stable, and allowing hotlink referrers from `guns2ammo.com`. Any of those failing breaks the image with no local fallback — a plausible explanation for the client's "images might be helpful but I am not understanding why that API [is] not transferring over."
- **Recommended fix:** On first promotion, download the image into the WordPress media library (pattern already exists elsewhere in this codebase — the waiver PDF mirroring in `class-waiver-import.php:394-415` is a directly reusable template) and store the attachment id; keep the hotlink URL only as a fallback/audit trail.
- **Test required after fix:** Promote a product; confirm a local media attachment exists and the product's featured image points to it, not the Lipsey's CDN URL.
- **Deployment risk:** Low — additive. Consider storage/bandwidth impact for a full catalog re-sync (thousands of images) — batch/cron it rather than doing it inline during a large CSV import (the waiver PDF mirror's deferred/cron-batched pattern at `class-waiver-import.php:220-283` is directly applicable here too).

### G2A-HIGH-004 — Theme version constant disagrees with theme header version
- **Component:** guns2ammo theme
- **Files:** `guns2ammo/functions.php:10` (`G2A_VERSION` = `'1.27.5'`), `guns2ammo/style.css` header (`Version: 1.27.13`)
- **Root cause:** `G2A_VERSION` is used as the cache-busting version string for every enqueued theme stylesheet and the main `chrome.js` script (`functions.php:74-104`). It was last bumped to 1.27.5 and has not moved across at least 8 subsequent style.css version bumps.
- **Business impact:** Asset URLs (`app.css?ver=1.27.5`, etc.) have not changed across 8 releases' worth of CSS/JS edits, meaning any browser or CDN cache (including Cloudflare, which is confirmed in use elsewhere in this system) that respects query-string cache-busting had no signal to fetch fresh assets across those releases.
- **Recommended fix:** Add a build step (or a simple `filemtime()`-based dynamic version in development, hardcoded on release) that keeps `G2A_VERSION` synchronized with `style.css`'s `Version:` header — or read one from the other at runtime via `wp_get_theme()->get('Version')` instead of maintaining two independent literals.
- **Deployment risk:** Low, but bumping the version will force a one-time full asset cache invalidation for all visitors on next deploy — expected and desired, not a risk.

---

## Medium

### G2A-MED-001 — Waiver export/print views rely on capability check only, not nonce verification
- **Component:** Memberistic Membership Solutions
- **Files:** `memberistic-membership-solutions/includes/waivers/class-waivers.php:954-970` (`maybe_handle_export()`)
- **Root cause:** `print_url()` (`:940-943`) builds a `wp_nonce_url()`, but `maybe_handle_export()` never calls `wp_verify_nonce()`/`check_admin_referer()` on the incoming request — it checks only `memberistic_current_user_can(self::CAP)` before serving the export/print/poster view (explicit `phpcs:ignore WordPress.Security.NonceVerification.Recommended` comments confirm this was a deliberate suppression, not an oversight, but the nonce is still never actually verified).
- **Business impact:** Low in practice (a GET request gated by a staff capability, serving data the requester is already authorized to see) but is a CSRF-hygiene gap: a logged-in staff member with the waiver capability could be induced via a crafted link to trigger an export/print action they didn't intend.
- **Recommended fix:** Add `check_admin_referer()`/`wp_verify_nonce()` alongside the existing capability check.
- **Deployment risk:** None.

---

## Low

### G2A-LOW-001 — ESLint configuration is incompatible with the installed ESLint version
- **Component:** dashboard-app
- **Evidence:** `npm run lint` fails: "ESLint couldn't find an eslint.config.(js|mjs|cjs) file… From ESLint v9.0.0, the default configuration file is now eslint.config.js." Verified this session — `npm ci` succeeded (177 packages), `typecheck` and `build` both passed cleanly.
- **Business impact:** None functional — this is pure tooling drift. But it means lint has not actually been enforced in CI/local dev for however long this drift has existed, so any lint-catchable issues introduced since then are unverified.
- **Recommended fix:** Add `eslint.config.js` (flat config) migrated from whatever legacy config exists, or pin ESLint to `^8` in `package.json` if flat-config migration is deferred.
- **Deployment risk:** None — dev-tooling only.

---

## Verified NOT to be defects (positive findings worth recording so they aren't re-litigated)

- **Stripe/WooCommerce webhook signature verification and idempotency are solid.** `class-stripe-service.php:596-625` (HMAC + 5-minute replay window) and `:671-703` (MySQL-advisory-locked idempotency key persisted outside the object cache, specifically engineered to survive a cache flush) are well-built and correctly reasoned in their own code comments.
- **The REST `permission_callback` pattern across the sampled plugins is disciplined.** Every `__return_true` route spot-checked this session (booking-engine public availability/resources/events/nonce/booking-status-by-UUID, POS `/auth/token`, FFL state-compliance reference data, Formistic newsletter unsubscribe) is legitimately public: read-only reference data, an HMAC/token-authenticated action, or the login/token-issuance endpoint itself. State-mutating routes use dedicated permission callbacks, not `__return_true`.
- **The `verifyistic/includes/class-verifyistic-db.php:134` query flagged during initial recon is safe** — it uses `sanitize_sql_orderby()` for the ORDER BY clause and `$wpdb->prepare()` for values; the `phpcs:ignore` comment there suppresses a static-analysis false positive, not a real gap.
- **Waiver PDF storage is genuinely protected**: `.htaccess` (`Require all denied` + legacy Apache `Deny from all`) plus a silent `index.php`, AND the only in-app viewing route additionally requires a dedicated capability check (`class-waivers.php:961`).
- **The booking check-in waiver gate does not depend on the buggy person-record stamping path** — `g2ab_waiver_satisfied` is answered by `Waiver_Booking_Bridge::satisfy()` (`class-waiver-booking-bridge.php`), which re-derives directly from `Waivers_Archive::has_on_file()` rather than trusting `People_Repository.waiver_status`. This means G2A-HIGH-001 above affects the **account-page display**, not actual range access.
- **All 975 PHP files in the repository lint clean** (`php -l`, zero syntax errors) — verified this session.
- **dashboard-app builds and typechecks cleanly** — `npm ci`, `tsc -b --noEmit`, and `vite build` all pass.
- **PHPUnit suites pass where present and installable**: g2a-business-api (497 tests / 1,221 assertions), messageistic (11 tests / 19 assertions), advanced-ffl-checkout (39 tests / 41 assertions) — see `12-PERFORMANCE-RELIABILITY-OBSERVABILITY.md` verification log for g2a-pos-core's result (composer install was environment-blocked by proxy timeouts on a dev-only dependency, not a code issue).
