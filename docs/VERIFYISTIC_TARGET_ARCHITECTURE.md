# Verifyistic Target Architecture — Canonical Verification + Waiver Platform

Date: 2026-07-15
Companion: `docs/VERIFYISTIC_CURRENT_STATE.md` (all file:line evidence lives there).
Principle: **reuse-first** — Memberistic's Waiver_Service is today's most complete engine; Verifyistic contributes the security substrate (tokens, rate limiting, protected storage, webhook fan-out). The target moves the *canonical* records and APIs into Verifyistic while siblings become thin consumers.

---

## 1. Canonical customer identity model

**New build** (nothing UUID-based exists for people today; only `memberships.membership_uuid` exists at the membership level).

### Tables

```
{prefix}verifyistic_persons
  id BIGINT PK, person_uuid CHAR(36) UNIQUE,
  first_name, last_name, email (indexed, lowercased), phone_e164 (indexed),
  date_of_birth DATE NULL, is_minor TINYINT, guardian_person_id BIGINT NULL,
  created_at, updated_at, merged_into_person_id BIGINT NULL  -- tombstone on merge

{prefix}verifyistic_identity_links
  id PK, person_id FK, source_system VARCHAR(32), source_id VARCHAR(64),
  match_level TINYINT, matched_by VARCHAR(32), verified_by_user_id BIGINT NULL,
  created_at, UNIQUE(source_system, source_id)
```

`source_system` enum: `wp_user`, `woo_customer`, `memberistic_person`, `booking_row`, `pos_profile`, `range_waiver`, `ffl_transfer`, `verifyistic_log`, `otter_import` — one link row per record in each of the 8 fragmented stores (current-state §3).

### Deterministic match levels (auto-link policy)

| Level | Rule | Action |
|---|---|---|
| L1 | exact email + exact DOB | auto-link |
| L2 | exact email + exact normalized full name | auto-link |
| L3 | exact email only | auto-link, flag `matched_by=email_only` |
| L4 | normalized name + DOB (no email) | create link with status `requires_review` — never auto-satisfies anything |
| L5 | name only | **never links** (this outlaws the current `Waivers_Archive::has_on_file()` bare-name behavior used by the waiver-booking bridge) |

Manual merge/split UI records `verified_by_user_id`. All sibling plugins resolve people through `Verifyistic_Identity_Service::resolve( email, name, dob, source_ref )` — no plugin does its own matching anymore.

**Reuse:** `memberistic_people` stays the membership-domain projection (it keeps `wp_user_id`, roles, membership linkage); it gains nothing but a link row. Woo/Stripe/POS ids already on `memberships` seed L1–L3 links during backfill.

## 2. Waiver record schema + status model

**New table, but the design is a promotion of Memberistic's Waiver_Service** (365-day validity, expiry cron, signature-event rows all exist there and carry over).

```
{prefix}verifyistic_waivers
  id PK, waiver_uuid CHAR(36) UNIQUE, person_id FK,
  template_id FK, template_version_id FK,
  status ENUM(not_started, started, pending_signature, signed, expired,
              revoked, voided, import_pending, unmatched, requires_review),
  provider VARCHAR(24),          -- native | otter_import | approveme_legacy | staff_attested
  provider_ref VARCHAR(128) NULL, evidence_level TINYINT,
  signed_at DATETIME NULL, expires_at DATETIME NULL,
  document_key VARCHAR(255) NULL,   -- opaque 'private:...' key, never a URL
  audit JSON,                    -- ip, ua, consent text hash, signer typed name
  created_at, updated_at, voided_by_user_id NULL, void_reason NULL
```

Status semantics: `import_pending` = imported row awaiting person match; `unmatched` = import exhausted match levels; `requires_review` = L4 match or conflicting data; `revoked` = customer withdrawal; `voided` = staff correction (audited). Only `signed` (and unexpired) satisfies any downstream gate. Transitions are enforced in one place (`Waiver_Records::transition()`), logged append-only to `{prefix}verifyistic_waiver_events` (mirrors Memberistic's `memberistic_waiver_signatures` event pattern).

**Exists already:** per-member status + expiry cron (`memberistic_people.waiver_status/expires_at`, `expire_due`), signature-event rows, CSV export with formula-injection guard. **New:** the unified status enum (today there is only signed/expired/none + POS 'active'), void/revoke, evidence levels, per-record template version.

## 3. Native signing-session lifecycle + token security

**New build.** Explicitly replaces (a) Memberistic's persistent 40-char bearer URL token that never rotates, and (b) the thank-you-URL stamp in guns2ammo-waiver-manager.

```
{prefix}verifyistic_signing_sessions
  id PK, session_uuid, waiver_id FK, person_id FK,
  token_hash CHAR(64),            -- SHA-256 of the URL token; raw token never stored
  status ENUM(created, sent, opened, signed, expired, voided),
  expires_at DATETIME, single_use TINYINT DEFAULT 1,
  channel ENUM(email, sms, kiosk, staff), created_by_user_id NULL,
  opened_ip, opened_ua, completed_at
```

Token rules: 128-bit random, HMAC-signed envelope reusing `Verifyistic_Security::issue_form_token()` patterns (already has jti + burn + timing checks), **72h TTL default, burned on signature**, hash-at-rest, re-issue generates a new token (old invalidated). Signing POST requires: valid session token + nonce + the existing rate-limit/honeypot/timing stack (`Verifyistic_Security`) + server-side re-render of the exact template version hash the signer saw. Completion returns a signed, expiring receipt URL — **status is set by the POST handler, never by visiting any page or query param.** Kiosk mode = staff-initiated session on a shared device with auto-expiring screen.

**Reuse:** the whole anti-abuse stack (§1.5 of current state) and the `verifyistic_token` cache-safe mint endpoint pattern.

## 4. Signature provider interface

```php
interface Verifyistic_Signature_Provider {
    public function create_session( Waiver_Record $w, array $opts ): Signing_Session;
    public function get_status( string $provider_ref ): string;
    public function fetch_document( string $provider_ref ): ?Stored_Document;
    public function verify_callback( WP_REST_Request $r ): bool|WP_Error; // HMAC etc.
}
```

Adapters:
- **Native** (new): renders template, captures drawn/typed signature + consent checkbox, generates PDF, stores audit hash.
- **Otter_Import** (adapter over existing code): read-only historical provider wrapping the Memberistic importer (`class-waiver-import.php`) — its records get `provider=otter_import`, `evidence_level` per match level; it never creates sessions.
- **ApproveMe_Legacy** (transition only): wraps the `esig_stand_alone_document_after_invite_fired` hook currently in guns2ammo-waiver-manager so legacy kiosk signatures land as canonical records until ApproveMe is retired.

Messageistic's OtterText SMS provider remains a *delivery channel* (send signing links), not a signature provider.

## 5. Template / versioning model

**New build.** CPT `verifyistic_waiver_tpl` (revision-friendly, staff-editable) + immutable snapshot table:

```
{prefix}verifyistic_template_versions
  id PK, template_id, version INT, content_hash CHAR(64), body LONGTEXT,
  min_age TINYINT, requires_guardian TINYINT, validity_days INT,  -- replaces global 365
  jurisdiction VARCHAR(8), published_at, published_by_user_id
```

Signatures reference `template_version_id`; publishing a new version can (per-template policy) flip existing `signed` records to `expired` (re-consent) or leave them valid until natural expiry. The `content_hash` goes into each waiver's audit JSON so we can prove what text was signed.

## 6. Protected document storage pattern

**Exists — standardize, don't invent.** Adopt the union of the two proven implementations:

- Location: `uploads/private/verifyistic-waivers/Y/m/` (same private tree as `verifyistic-ids`, `class-verifyistic-ajax.php:317-342`), deny-all `.htaccess` **and** `web.config` (Memberistic's `class-documents.php` adds IIS coverage), index stub, random ≥32-char filenames, chmod 0640.
- DB stores opaque `private:` keys only; delivery exclusively through the signed, capability-gated streaming endpoint pattern (`Verifyistic_Admin::serve_protected_file`, `class-verifyistic-admin.php:25-86`) but gated on `verifyistic_view_waiver_docs` instead of `manage_options`, and additionally allowing owner self-access (Memberistic's owner-or-staff + realpath model).
- Migration: mirror the Otter PDFs already sitting in `uploads/memberistic-waivers/` into the canonical tree, then freeze the old path. Fixture/PII hygiene per `docs/PII_PURGE_PLAN_2026-07-15.md` (synthetic fixtures only, purge history).

## 7. Staff dashboard + capabilities

**New capability namespace** (today: `manage_options` in Verifyistic, `edit/manage_memberistic_members` in Memberistic, `g2a_pos_*` in POS):

| Capability | Grants |
|---|---|
| `verifyistic_view_waivers` | list/search waiver + person records (PII-redacted columns) |
| `verifyistic_view_sensitive` | unmask DOB/address/minor data (mirrors `g2a_pos_view_waiver_sensitive_data`) |
| `verifyistic_view_waiver_docs` | stream signed PDFs / ID images |
| `verifyistic_create_session` | start/send signing sessions (front desk, kiosk) |
| `verifyistic_attest_waiver` | staff walk-in attest / mark_one equivalents (audited) |
| `verifyistic_void_waiver` | void/revoke with reason |
| `verifyistic_manage_templates` | edit/publish template versions |
| `verifyistic_run_import` | Otter/legacy imports + reconciliation reports |
| `verifyistic_manage_settings` | plugin settings, webhooks, providers |

Suggested roles: front-desk (view/create_session/attest/checkin-support), range manager (+void, +sensitive, +docs), admin (all). Dashboard reuses Memberistic's Waiver_Admin_Page UX (bulk ops, email-missing, CSV export) and the POS Waivers view aggregation, consolidated into one Verifyistic screen: queues for `pending_signature`, `requires_review`, `unmatched`, expiring-soon; person 360 view showing linked identities.

## 8. Integration service API (Memberistic / Booking / POS)

**New service layer replacing option/cookie sniffing and the `g2ab_waiver_satisfied` bare-name filter.**

PHP: `Verifyistic_Service::waiver_status( $person_uuid | ['email'=>…,'dob'=>…] ): { status, signed_at, expires_at, evidence_level }` and `::age_verified( $person_uuid, $max_age_days )`.

REST (`verifyistic/v1`):
- `GET /status?person_uuid=…` — staff/service capability-gated; **no public endpoint ever answers "is this name signed?"** Public callers get only their own session's status.
- `POST /sessions` (`verifyistic_create_session`), `POST /sessions/{uuid}/complete` (token-authenticated signer POST), `POST /callbacks/{provider}` (HMAC-verified).
- Outbound events reuse the existing multi-connection webhook engine (`class-verifyistic-webhooks.php`) with new event keys: `waiver.signed`, `waiver.expired`, `waiver.voided`, `session.created`.

Consumer changes:
- **Booking engine:** verifyistic module stops writing `waiver_signed` from the age-gate cookie; it calls the service and stores `waiver_uuid` on the booking. Fail **closed** (waiver required at check-in) when the service is unavailable, replacing today's fail-open. `skip_waiver` remains staff-only, default **unchecked**, and records who skipped.
- **Memberistic:** Waiver_Service becomes a consumer/projection — `mark_signed` is triggered by `waiver.signed` events instead of its own token page; the tokenised page is replaced by native signing sessions. The waiver-booking bridge drops its email-or-name archive match and asks the service (L1–L3 only).
- **POS:** `g2a_range_waivers` reads join to canonical records via identity links; range check-in consults the service; its own check-in endpoint/caps stay as-is (already sound).
- **Age gate stays** as-is for site entry but is formally decoupled: age verification NEVER satisfies a waiver requirement (it may pre-fill a session and set `age_verified_at` on the person).

## 9. Otter migration pipeline

**Reuse the Memberistic importer as the engine** (dry-run `report()`, WP-CLI, dedupe, PDF mirroring already exist); retire the POS `OtterWaiverCsvImporter` as a separate source of truth (its table becomes a projection or is backfilled + frozen).

Pipeline: CSV/API export → staging rows (`import_pending`) → identity resolution (L1–L5, §1) → canonical waiver records (`signed` for L1/L2, `requires_review` for L3-conflict/L4, `unmatched` for L5/none) → PDF mirror to protected storage → reconciliation report.

**Acceptance criteria:**
1. Row accounting reconciles exactly: input rows = unique + duplicate + rejected (baseline: 1,922 → 1,793 unique on the validated run), with a persisted report artifact.
2. Every imported record has a status from the §2 enum and a recorded match level; zero silent drops.
3. Idempotent re-runs (UNIQUE on `(source_system, source_id)` / `(source, unique_ref)`) produce zero new rows.
4. 100% of referenced PDFs mirrored to protected storage with opaque keys; zero remaining public/Otter-hosted URLs in canonical records.
5. `memberistic_waivers_archive` and `g2a_range_waivers` counts each reconcile against canonical records (no third archive created).
6. No real PII in fixtures/tests; dry-run report matches wet-run outcome on the same input.
7. Unmatched/requires_review queue visible in the dashboard with a manual-resolve workflow; policy decision recorded on whether unmatched rows block check-in.

## 10. Exists vs. new, and phased order

| Piece | Status |
|---|---|
| Anti-abuse token/rate-limit/honeypot stack | EXISTS (verifyistic) — reuse |
| Protected private storage + signed streaming | EXISTS (verifyistic + memberistic) — standardize |
| Webhook fan-out with HMAC/retry | EXISTS (verifyistic) — extend events |
| Waiver engine (validity, expiry cron, events, admin bulk ops, guest/kiosk pages) | EXISTS (memberistic) — promote/consume |
| Otter import w/ dry-run + WP-CLI | EXISTS (memberistic) — wrap as provider |
| POS range check-in + caps + redaction | EXISTS — keep, point at service |
| Identity spine (persons, links, match levels, merge UI) | NEW |
| Canonical waiver table + full status enum + void/revoke | NEW |
| Signing sessions + native e-sign capture + PDF generation | NEW |
| Template versioning | NEW |
| `verifyistic_*` capabilities + unified dashboard | NEW |
| Service API + REST + event keys | NEW |

**Phases (realistic order):**
- **Phase 0 — stop the bleeding (no schema):** remove/disable the thank-you-URL stamp (`guns2ammo-waiver-manager.php:71-88`); port the ≥16-char token check into the booking module (kill cookie `'1'`); default auto-waiver-from-age-gate OFF; default `skip_waiver` unchecked; restrict `g2ab_waiver_satisfied` bridge to email(+DOB) matches; make require-verification fail closed.
- **Phase 1 — identity spine:** persons + identity_links tables, resolver service, backfill from the 8 stores, review queue.
- **Phase 2 — canonical waiver records + service API:** new tables, status enum, event log, REST + PHP service, webhook events; Memberistic/Booking/POS read through it (writes still legacy).
- **Phase 3 — native e-signature engine:** templates + versions, signing sessions, capture UI, PDF + audit, kiosk mode; replace Memberistic token page and PMPro redirect target.
- **Phase 4 — Otter migration:** unified pipeline per §9; freeze both legacy archives; Otter Waiver subscription cancelable at acceptance.
- **Phase 5 — dashboard + capabilities:** verifyistic_* caps, role mapping, consolidated staff dashboard, business-api reminder pointed at canonical status.
- **Phase 6 — decommission:** retire guns2ammo-waiver-manager and ApproveMe dependency (kiosk role migration), remove booking-module direct cookie reads; age gate remains as the site-entry popup only.
