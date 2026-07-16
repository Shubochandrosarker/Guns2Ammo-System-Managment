# Verifyistic Target Architecture — Canonical Verification + Waiver Platform

Date: 2026-07-15 (original) — reconciled 2026-07-16.
Companion: `docs/VERIFYISTIC_CURRENT_STATE.md` (original file:line audit evidence — still accurate for the age-gate/Verifyistic plugin itself; waiver evidence below supersedes it).
Original principle: **reuse-first** — Memberistic's Waiver_Service was the most complete engine even before this plan; Verifyistic would contribute the security substrate (tokens, rate limiting, protected storage, webhook fan-out) and become the canonical home for records/APIs.

---

## Reconciliation note (2026-07-15/16)

**This plan was never built as a separate "Verifyistic waiver platform."** While it sat unbuilt, a different working session (branch `claude/guns2ammo-waivers-audit-0ke6mt`, PRs #83/#85/#88/#89/#92/#93, 2026-07-15) independently designed and shipped its own plan — `docs/AUDIT_2026-07-15_WAIVER_SYSTEM_GAPS_AND_ACTION_PLAN.md` — and built a native e-signature engine **inside Memberistic** (`memberistic-membership-solutions/includes/waivers/*`) plus a read-only waiver surface in **g2a-business-api** and the **dashboard-app**, and SMS hooks in **Messageistic**. It reused Memberistic's existing `memberistic_people`/`memberistic_waivers_archive` model rather than building the `verifyistic_persons`/`verifyistic_waivers` tables this document specified. Verifyistic the plugin (age-gate) was untouched by that work.

**What that means for this document:** most of §§2–7 below described tables, capabilities, and a REST namespace that do not exist. Rather than deleting the plan, this rewrite marks each piece BUILT / PARTIALLY BUILT / NOT BUILT against what actually shipped, keeps the parts of the design that are still live options, and removes decision points the other session already closed (stating what they decided).

**Genuine conflicts / gaps found:**
1. **Bearer-token unsafe path is still open, unchanged.** `Waiver_Public::get_or_create_token()` (`memberistic-membership-solutions/includes/waivers/class-waivers.php:91-99`, `TOKEN_META = 'memberistic_waiver_token'` at `:46`) still mints one 40-char token per user, stores it forever in user meta, and never rotates or burns it on signature. This is exactly unsafe path #7 from `VERIFYISTIC_CURRENT_STATE.md` §2.1 — the new engine (drawn signatures, PDFs, versioning, kiosk HMAC tokens) was built *around* this token without fixing it. Native signing sessions with single-use, expiring, hash-at-rest tokens (§3 below) were never built.
2. **Third waiver archive (`g2a_range_waivers` in POS) was not reconciled or frozen.** "Phase 0: unify waiver stores" (commit `9ff6342`) unified Memberistic's *own* two stores (native e-sign + Otter import) into one `memberistic_waivers_archive`, but did not touch `g2a-pos-core`'s independent `g2a_range_waivers` table or its own `OtterWaiverCsvImporter` (`g2a-pos-core/includes/Range/OtterWaiverCsvImporter.php`). The dashboard's `/waivers` API and Waivers page read **only** `memberistic_waivers_archive` — POS-imported Otter rows in `g2a_range_waivers` are invisible there. The dual-archive problem flagged in current-state §4 is still real, just narrowed from "two overlapping Otter imports" to "two overlapping Otter imports, one of which is now also the canonical live e-sign store."
3. **No conflicting table/capability names** — the new work added `memberistic_waiver_versions` (new) and extended `memberistic_waivers_archive`/`memberistic_waiver_signatures` (existing), all under Memberistic's existing `edit_memberistic_members`/`manage_memberistic_members`/`view_memberistic_dashboard`/`manage_memberistic_settings` capabilities. No `verifyistic_*` capability namespace was created, so there's nothing to collide with.
4. **REST envelope mismatch is real but not caused by the waiver work specifically.** `g2a-business-api`'s new `/waivers*` routes (`class-waivers-controller.php`) return raw `WP_REST_Controller::ok()` payloads, not the canonical `Response_Envelope` `{success,data,meta}` shape used by `/dashboard/overview` and `/analytics-detail/*`. Investigation shows the envelope is actually a minority pattern in this codebase — only `class-dashboard-controller.php`, `class-analytics-detail-controller.php`, and `class-health-controller.php` use it (introduced by the separate, roughly-concurrent "Phase C"/"Phase D" work, commits `bbd3cde`/`27fb6c8`); every other controller including the main `/analytics/*` controller uses the unenveloped pattern the waiver routes also use. So the waiver API is consistent with the codebase's *majority* shape, just inconsistent with the *newer* canonical one — an open migration, not a waiver-specific bug.
5. **Bare-name archive match (unsafe path #4) is fixed, and consistently so.** `Waiver_Booking_Bridge::satisfy()` (`memberistic-membership-solutions/includes/waivers/class-waiver-booking-bridge.php:26-47`) now matches by email only, with an explicit comment explaining the earlier name-fallback vulnerability. This matches the crossmatch session's Phase 1 security fix — no conflict, the waiver-audit session's Phase 0 work built on top of it correctly.
6. **Thank-you-URL stamp (unsafe path #1) stays disabled by default,** consistent with the crossmatch session's fix — `guns2ammo-waiver-manager/guns2ammo-waiver-manager.php:115-142` gates it behind an opt-in option/filter defaulting to `false`, and the plugin now carries a retirement banner (`:17-29`) pointing admins at Memberistic. No conflict.
7. **SMS automation (Phase 5) integrates correctly through Messageistic**, not a parallel path — see §8.

---

## 1. Canonical customer identity model — **NOT BUILT** (deliberately deferred, not attempted)

No `verifyistic_persons` / `verifyistic_identity_links` tables, no `person_uuid`, no cross-system resolver exist anywhere in the repo (checked: `memberistic-membership-solutions`, `g2a-business-api`, `g2a-pos-core`, `g2a-booking-engine`, `verifyistic`). The only UUID in the system remains `memberships.membership_uuid` (`includes/database/class-schema.php:47`), which is membership-scoped, not person-scoped.

**Fragmentation from current-state §3 is unchanged in kind, changed in degree:**
- `memberistic_people` is still the closest thing to a canonical person record, and it is now also the backing store for waiver status, versioning, and the archive mirror — i.e. it got *more* central to the waiver domain without gaining a UUID or a cross-system link table.
- `g2a_range_waivers` (POS) remains a separate person shape with its own dedupe key (`UNIQUE(source, unique_ref)`), not linked to `memberistic_people`.
- `verifyistic_logs` (age-gate events) remains unlinked to anyone.
- `g2ab_bookings` still carries ad-hoc customer_name/email/phone with no person table.

**Still-open decision:** whether to build the identity spine at all, given the other session shipped a working waiver product without it by matching on email. The original design (deterministic match levels L1–L5, tombstone-on-merge) is still sound if this is pursued later, but it is speculative work now, not a gap blocking anything that shipped.

## 2. Waiver record schema + status model — **PARTIALLY BUILT**, on Memberistic's tables, not new Verifyistic ones

**Built, in `memberistic_waivers_archive` + `memberistic_waiver_signatures` + `memberistic_people`:**
- Unified single "waiver on file" archive per person (email-keyed dedupe, `is_current` flag): `Waivers_Archive::insert()`/`set_current_newest()` (`class-waivers-archive.php`), extended in Phase 0 (`9ff6342`) to mirror every native e-signature, not just Otter imports.
- Per-signature audit data: DOB, phone, emergency contact, minors JSON, IP, `text_hash` (SHA-256 of the signed text) — `class-waivers.php` signature insert path (~`:974-1030` for signature capture) and `class-waiver-pdf.php:39-60` (`Waiver_PDF::build()`, consumes `sig['text_hash']`, `sig['ip']`, minors_json).
- Expiry: validity-days driven by `Waiver_Service::validity_days()`, daily `expire_due` cron (pre-existing, still present).
- Void: `POST /waivers/{id}/void` in `g2a-business-api/includes/rest/class-waivers-controller.php` (~line for `void_waiver()`), which flips `is_current = 0` and sets the linked person's `waiver_status = 'needs_review'`.
- Re-consent: `Waiver_Versions::publish( ..., $requires_reconsent = true )` (`class-waiver-versions.php:116-158`) flips every currently-signed person to `needs_review` and moves the "on file" cutoff forward via `reconsent_boundary()`.

**Not built:** the original enum (`not_started/started/pending_signature/signed/expired/revoked/voided/import_pending/unmatched/requires_review`), `evidence_level`, `provider`/`provider_ref` columns, a single `Waiver_Records::transition()` chokepoint, or a `verifyistic_waiver_events` append-only log. What exists instead is Memberistic's older, simpler model (`waiver_status` on `memberistic_people`: signed/needs_review/expired/none) plus the `is_current` flag on archive rows — functional, but not the unified state machine this doc specified.

## 3. Native signing-session lifecycle + token security — **PARTIALLY BUILT**, and the specific security goal of this section was NOT achieved

**Built — a genuinely new native e-signature capture UI:** `class-waivers.php` renders a `<canvas id="mw-sigpad">` (`:933`), captures the drawn signature as base64 JPEG into `signature_data` (`:936`, POST handling `:974-1012`, JPEG size cap via `memberistic_signature_image_max_bytes` filter), and `Waiver_PDF::build()` (`class-waiver-pdf.php`) generates a dependency-free PDF 1.4 document embedding the drawn signature, signer fields, and audit trail — this is a real, new PDF generator (not a third-party e-sign vendor), replacing the ApproveMe dependency for native signing.

**Not built — the specific problem this section exists to solve:** there is no `verifyistic_signing_sessions` table, no hashed/rotating/single-use token. The signing flow still runs on `Waiver_Public::get_or_create_token()` (`class-waivers.php:91-99`), the same **persistent, never-rotating 40-char bearer token** flagged as unsafe path #7 in the current-state audit — confirmed still present, unchanged, in the post-rebuild code. Anyone holding a leaked signing link can still re-sign/re-view for that member indefinitely. This is the single biggest unresolved item from the reconciliation.

**Kiosk mode did get the HMAC station-token treatment this section called for** — see §6 below; that part of the "session security" goal was achieved, just not the per-signature session token.

## 4. Signature provider interface — **NOT BUILT** as an abstraction, but the adapters it describes now functionally exist

No `Verifyistic_Signature_Provider` interface exists. In practice:
- **Native**: built (§3) — `class-waivers.php` + `class-waiver-pdf.php`.
- **Otter_Import**: built, as originally described — `class-waiver-import.php` (dry-run `report()`, WP-CLI `wp memberistic import-waivers --dry-run`, `:469-485`) remains the read-only historical importer, now feeding the same unified `memberistic_waivers_archive`.
- **ApproveMe_Legacy**: retired rather than wrapped — `guns2ammo-waiver-manager.php` is now an explicitly-retired archival plugin (admin notice `:22-29`) rather than a live transitional adapter. Its ApproveMe kiosk hook (`esig_stand_alone_document_after_invite_fired`) still exists in the retired plugin file for archival reference but the live kiosk flow now runs through Memberistic's own `Waiver_Kiosk`/`Waiver_Public` (§6), not ApproveMe.
- **OtterText SMS as delivery channel, not signer**: confirmed unchanged — Messageistic's SMS integration (§8) sends links/confirmations, never signs anything itself.

## 5. Template / versioning model — **BUILT**, on Memberistic, not as a Verifyistic CPT

`memberistic_waiver_versions` table (new, `class-waiver-versions.php:25`, `Waiver_Versions` class) gives immutable, content-hashed versions (`text_hash = hash('sha256', $body)`), `effective_from` scheduling, and per-version `requires_reconsent`. `publish()` (`:116-158`) is the single write path; `maybe_seed_initial()` backfills version 1 from the legacy `memberistic_waiver_text` option so existing signatures aren't retroactively invalidated. This satisfies the spirit of the original §5 (versioned, hash-provable text, re-consent policy) without a CPT — a plain table was used instead, which is a reasonable simplification.

**Not built:** per-template `min_age`/`requires_guardian`/`jurisdiction`/`validity_days` fields (Memberistic still has one global validity-days setting, not per-template), and there is only one waiver template/document, not multiple named templates.

## 6. Protected document storage pattern — **BUILT**, matches the target's "standardize, don't invent" instruction

`class-documents.php` (`Documents` class, `memberistic-membership-solutions/includes/waivers/class-documents.php:1-40`) is exactly the pattern this section asked for: `uploads/memberistic-private/` with `.htaccess` + `web.config`, 40-char random on-disk filenames, chmod-hardened, gated download endpoint (`?memberistic_doc=ID`) requiring nonce + owner-or-staff (`STAFF_CAP = 'edit_memberistic_members'`) + realpath guard, never inline. Generated waiver PDFs and drawn-signature images are filed through this same store (per-file comment at `class-documents.php:14-16`). The `g2a-business-api` PDF-streaming route (`Waivers_Controller::pdf()`) adds its own `realpath`+prefix containment check before `readfile()`, consistent with the hardening pattern. Verifyistic's own separate ID/selfie private tree (current-state §1.6) is untouched and still independent — the two protected-storage trees were not unified, but both independently follow the same hardening pattern, which was the actual goal.

## 7. Staff dashboard + capabilities — **PARTIALLY BUILT**, no new capability namespace

No `verifyistic_*` capabilities were created (confirmed: zero references repo-wide). Instead:
- **Dashboard:** `dashboard-app/src/pages/Waivers.tsx` — a genuinely new consolidated staff screen: stats tiles (on-file/current/expiring/expired), a "needs attention" today strip cross-referencing bookings without a waiver, search, status-filtered list, per-record detail with full signature history, PDF view, void action, send-link action. This is a real, new, unified queue view — built on Memberistic's data via `g2a-business-api`'s `/waivers*` routes, gated by `Capabilities::current_user_can_read()`/`current_user_can_admin()` (`g2a-business-api/includes/class-capabilities.php`), not `manage_options`.
- **Capabilities used:** all pre-existing Memberistic caps (`edit_memberistic_members`, `manage_memberistic_members`, `view_memberistic_dashboard`, `manage_memberistic_settings`) plus g2a-business-api's own read/admin capability check. No new role/capability design was introduced — the original §7 capability table (`verifyistic_view_waivers`, `verifyistic_void_waiver`, etc.) remains unbuilt and, given the dashboard already works through existing caps, is now a "nice to have" rather than a blocker.

## 8. Integration service API (Memberistic / Booking / POS) — **PARTIALLY BUILT**, no Verifyistic REST namespace; hook-based integration instead

**No `verifyistic/v1` REST namespace, no `Verifyistic_Service::waiver_status()` PHP API, no `waiver.signed`/`waiver.expired` webhook events** were built. What exists instead:

- **g2a-business-api `/waivers*` (its own namespace, not `verifyistic/v1`)**: `GET /waivers`, `/waivers/stats`, `/waivers/today`, `/waivers/{id}`, `/waivers/{id}/pdf`, `POST /waivers/send-link`, `POST /waivers/{id}/void` — all in `class-waivers-controller.php`, reading `memberistic_waivers_archive` directly (documented in the file's own header comment as "reads Memberistic's unified waiver archive"). This is the closest thing to the "service API" the target doc wanted, but it's a read/ops surface for the dashboard, not a cross-plugin status-resolution service other plugins call into.
- **Booking engine bridge — fixed as this doc's Phase 0 asked, done by the *other* session's Phase 0, not this plan:** `Waiver_Booking_Bridge::satisfy()` now matches email-only (see Reconciliation note item 5). Auto-waiver-from-age-gate-cookie and the cookie-`'1'` acceptance bug (unsafe paths #2/#3) were **not** re-verified in this pass — they live in `g2a-booking-engine`'s Verifyistic module, which neither session's waiver work touched; treat as still open per the original audit unless re-checked.
- **PHP action hooks replace the planned webhook events:** `do_action( 'memberistic_waiver_signed', $sig_id, $row )` (`class-waivers.php:315`) and `do_action( 'memberistic_waiver_renewal_due', [...] )` (`class-waivers.php:~545`) are the real integration points other plugins hook — see §8's Messageistic consumer below. This is a simpler, WordPress-idiomatic substitute for the target's REST+webhook design and it works today.
- **Age gate decoupling:** unchanged/unverified in this pass — still worth checking that age verification never satisfies a waiver requirement, per original design intent.

## 9. Otter migration pipeline — **BUILT (engine), NOT reconciled against POS's parallel importer**

The Memberistic importer (`class-waiver-import.php`) remains the reuse target this doc specified: dry-run `report()`, WP-CLI `wp memberistic import-waivers --dry-run/--fresh/--limit`, PDF mirroring, dedupe. It now feeds the same unified `memberistic_waivers_archive` that native e-signatures also write to (Phase 0), so acceptance criteria 1–4 and 6 from the original §9 are effectively met for the Memberistic-side pipeline.

**Acceptance criterion 5 — "`memberistic_waivers_archive` and `g2a_range_waivers` counts each reconcile against canonical records (no third archive created)" — is NOT met.** `g2a-pos-core`'s `OtterWaiverCsvImporter` → `g2a_range_waivers` still exists, untouched, unreconciled, and invisible to the new dashboard Waivers page. This is the clearest concrete follow-up: either retire the POS importer/table in favor of the Memberistic pipeline + a POS-side read of the canonical archive, or build the reconciliation report the original doc called for.

## 10. Reconciled exists/new/status table

| Piece | Original plan | Actual status | Evidence |
|---|---|---|---|
| Anti-abuse token/rate-limit/honeypot stack (age gate) | EXISTS — reuse | UNCHANGED, not reused by waivers | `verifyistic/includes/class-verifyistic-security.php` |
| Protected private storage + signed streaming | EXISTS — standardize | BUILT for waivers on the Memberistic pattern (not unified w/ Verifyistic's) | `memberistic-membership-solutions/includes/waivers/class-documents.php` |
| Webhook fan-out with HMAC/retry | EXISTS — extend events | NOT extended to waivers; hooks used instead | `verifyistic/includes/class-verifyistic-webhooks.php` (unchanged) |
| Waiver engine (validity, expiry cron, admin bulk ops, guest/kiosk pages) | EXISTS — promote/consume | KEPT AND EXTENDED in place (not promoted to Verifyistic) | `class-waivers.php` |
| Otter import w/ dry-run + WP-CLI | EXISTS — wrap as provider | KEPT, extended to feed unified archive; POS's parallel importer NOT reconciled | `class-waiver-import.php`; `g2a-pos-core/includes/Range/OtterWaiverCsvImporter.php` |
| POS range check-in + caps + redaction | EXISTS — keep, point at service | UNCHANGED; still a separate store | `g2a-pos-core` |
| Identity spine (persons, links, match levels, merge UI) | NEW | **NOT BUILT** — deliberately not attempted | — |
| Canonical waiver table + full status enum + void/revoke | NEW | **PARTIALLY BUILT** on existing tables; void built, full enum not | `class-waivers-archive.php`, `class-waivers-controller.php` |
| Signing sessions + native e-sign capture + PDF generation | NEW | **PDF/capture BUILT; session-token security NOT BUILT** (still the never-rotating bearer token) | `class-waiver-pdf.php`, `class-waivers.php:91-99` |
| Template versioning | NEW | **BUILT** (table + re-consent), simpler than spec (no per-template fields) | `class-waiver-versions.php` |
| `verifyistic_*` capabilities + unified dashboard | NEW | Dashboard **BUILT** on existing caps; new capability namespace **NOT BUILT** | `dashboard-app/src/pages/Waivers.tsx`, `g2a-business-api/includes/class-capabilities.php` |
| Service API + REST + event keys | NEW | **PARTIALLY BUILT** as `g2a-business-api` `/waivers*` (read/ops) + WP action hooks (not `verifyistic/v1`, not webhooks) | `class-waivers-controller.php` |
| Kiosk mode (HMAC station tokens, session reset) | NEW (Phase 4C) | **BUILT**, matches spec | `class-waiver-kiosk.php` |
| SMS automation on waiver lifecycle | not in original plan | **BUILT**, correctly wired through Messageistic (not a duplicate path) | `messageistic/includes/Integrations/Kiosk/Kiosk_Integration.php`, `messageistic/includes/WorkflowPacks/Firearm_Workflow_Pack.php` |

---

## Kiosk mode detail (target §4C comparison) — matches spec

`memberistic-membership-solutions/includes/waivers/class-waiver-kiosk.php` (`Waiver_Kiosk` class):
- **HMAC station tokens**: `mint_token()`/`verify_token()` (`:56-96`) — `base64url("station|expires").hmac`, `hash_equals()` constant-time compare, default 12h TTL (`memberistic_kiosk_token_ttl` filter), explicitly modeled on the Booking Engine's own check-in station pattern (per file header comment).
- **Session reset**: attract-screen mode (`Waiver_Public::render_kiosk_attract()`) resets between guests; expired tokens degrade gracefully to the public throttled guest page.
- **No persistent customer data on the station**: the station token itself carries no customer PII (just a station label + expiry); each signature still goes through the normal signing/storage path, not a station-local cache.
- **Gap vs. spec**: no optional staff-PIN-gated exit is a *new* addition beyond the original ask (a plus, not a gap); the original design's "session reset" was specified at the *signing-session* level (§3) which, per above, doesn't exist as a distinct token — kiosk mode reuses the same never-rotating guest token underneath the station wrapper.

## SMS automation detail (target §8 extension, not in original doc)

Confirmed real, not a duplicate/competing SMS path:
- Memberistic fires `do_action('memberistic_waiver_signed', ...)` (`class-waivers.php:315`) and `do_action('memberistic_waiver_renewal_due', [...])` (`class-waivers.php:~545`, alongside the existing renewal email, sharing the once-per-cycle guarantee).
- Messageistic's `Kiosk_Integration` (`messageistic/includes/Integrations/Kiosk/Kiosk_Integration.php`) listens for both hooks (`:19-20`) plus `g2a_waiver_incomplete`/`g2a_kiosk_checkin_completed`, upserts a Messageistic contact by phone, and fires the plugin's own internal `messageistic_trigger` event — the standard Messageistic automation path, not a bespoke SMS sender.
- `Firearm_Workflow_Pack` (`messageistic/includes/WorkflowPacks/Firearm_Workflow_Pack.php:30-32`) ships preset SMS templates for `waiver.incomplete`/`waiver.signed`/`waiver.renewal_due`, each with a `Reply STOP to opt out` compliance footer.
- `g2a-business-api`'s `Waiver_Reminder_Handler` is a **separate, email-only** draft-queue automation (24h-ahead booking reminders) — it does not send SMS and does not overlap with Messageistic's trigger-based SMS; the two are complementary channels on different triggers (booking-proximity email vs. lifecycle-event SMS), not a duplicate.
- Not independently re-verified in this pass: the "weekly digest" piece of Phase 5's PR title — not located under `messageistic/includes/` in this search; flag as unconfirmed rather than BUILT.

---

## Open decision points (updated)

**Decisions already made by the other session (moot, listed for history):**
- ~~Where do canonical waiver records live — new Verifyistic tables or promoted Memberistic tables?~~ **Decided: stayed on Memberistic's tables** (`memberistic_waivers_archive`, `memberistic_waiver_signatures`, new `memberistic_waiver_versions`). No migration to Verifyistic occurred or is in progress.
- ~~Should templates be a CPT or a table?~~ **Decided: a plain versioned table** (`memberistic_waiver_versions`), not a CPT.
- ~~Should kiosk mode use HMAC station tokens?~~ **Decided: yes**, matching the Booking Engine's existing station-token pattern.
- ~~Should SMS ride through Verifyistic's webhook engine or a plugin-native trigger?~~ **Decided: Messageistic's own trigger/workflow system**, fed by plain WordPress action hooks from Memberistic. No webhook involved.

**Still genuinely open:**
1. **Signing-session token security** (§3) — the never-rotating 40-char bearer token is a live, unresolved unsafe path. Fixing it doesn't require the full identity-spine/REST-namespace rebuild this doc originally proposed; it could be a narrow patch (hash-at-rest, TTL, burn-on-sign) inside Memberistic's existing `Waiver_Public` class. This is the highest-priority remaining item from either audit.
2. **POS's `g2a_range_waivers` archive vs. Memberistic's `memberistic_waivers_archive`** — reconcile into one, or explicitly document why two exist and keep the dashboard/reminder logic aware of both. Currently anything read through `g2a-business-api`'s `/waivers*` routes or the dashboard Waivers page silently misses POS-imported Otter records.
3. **Whether to ever build the identity spine** (§1) — no longer blocking (the shipped system works on email-keyed matching), but the fragmentation it would fix (8 person shapes, no UUID) is unchanged. Worth a deliberate "not now" decision rather than silent deferral, given it keeps getting deferred across two separate audits.
4. **REST envelope adoption** (`{success,data,meta}`) — whether `/waivers*` and the rest of the un-enveloped majority migrate to `Response_Envelope`, or whether the envelope stays scoped to the Phase C/D aggregation endpoints. Not urgent; purely a consistency call for API consumers.
5. **Booking-engine cookie-`'1'` acceptance and age-gate-auto-waiver defaults** (unsafe paths #2/#3 from current-state §2.1) — not touched by either the crossmatch session or the waiver-audit session as far as this reconciliation could confirm; re-audit `g2a-booking-engine/includes/modules/verifyistic/` directly before assuming these are still live risks or have been quietly fixed.
6. **Verifyistic plugin itself** — still purely an age-gate plugin (§1 of current-state, unchanged). Whether it ever becomes home to a `verifyistic_*` capability namespace or REST API is now a much lower-priority question given a working waiver product exists without it.
