# Verifyistic Current State — Waiver / Verification Stack Audit

Date: 2026-07-15
Scope: read-only audit of `verifyistic/`, `guns2ammo-waiver-manager/`, `memberistic-membership-solutions/` (waivers + verifyistic bridge), `g2a-booking-engine/includes/modules/verifyistic/`, `g2a-pos-core/`, the `guns2ammo` theme, `g2a-business-api`, `advanced-ffl-checkout`, and all Otter references repo-wide.
Purpose: baseline for upgrading Verifyistic into the canonical customer-verification + waiver platform with a native e-signature engine (replacing the age-gate-only role and the external Otter Waiver service).

---

## 1. Verifyistic plugin — what it actually is today

Verifyistic v1.4.4 is an **age-gate popup plugin only**. It has no waiver concept, no person records, no CPTs, no REST routes, and no custom capabilities. Maturity: a hardened, single-purpose age gate — a good security substrate, but ~0% of a waiver platform.

### 1.1 Database tables

| Table | Created at | Columns (key) |
|---|---|---|
| `{prefix}verifyistic_logs` | `verifyistic/includes/class-verifyistic-db.php:16-36` | id, verify_token (32-char), verify_type (dob/yes_no/id_face), first_name, last_name, dob, age_at_verify, status (passed/failed/declined), ip_address, user_agent, page_url, id_file, selfie_file, webhook_sent, verified_at |
| `{prefix}verifyistic_webhook_deliveries` | `verifyistic/includes/class-verifyistic-webhooks.php:43-62` | connection_id/name, log_id, event, url, payload, status (pending/success/failed), attempts, last_code, last_error, next_attempt |

Both created on activation (`verifyistic/verifyistic.php:39-44`) and self-healed on version bump (`verifyistic.php:112-116`). `uninstall.php` drops `verifyistic_logs` (note: it does **not** drop the webhook-deliveries table or the `verifyistic_webhooks` option — minor uninstall gap).

### 1.2 CPTs / REST routes / capabilities

- **CPTs:** none.
- **REST routes:** none registered by the plugin itself. (The booking engine registers `/verifyistic/*` routes in its own namespace — see §8.2.)
- **Capabilities:** no custom capabilities. Every admin screen and admin AJAX action gates on `manage_options` (`verifyistic/includes/class-verifyistic-admin.php:95,107,116,125` for menus; `:364,372,384,392,400` for AJAX; `serve_protected_file` at `:49`). There is **no `verifyistic_*` capability namespace yet** — staff cannot be given waiver access without full admin.

### 1.3 Endpoints (admin-ajax)

Registered in `verifyistic/includes/class-verifyistic-ajax.php:7-14` and `class-verifyistic-admin.php:10-15`:

| Action | Auth | Purpose |
|---|---|---|
| `verifyistic_verify` (priv+nopriv) | nonce + rate limit + honeypot + timing token | age verification submit (`class-verifyistic-ajax.php:61-166`) |
| `verifyistic_decline` (priv+nopriv) | nonce | log decline, safe redirect (`:171-190`) |
| `verifyistic_token` (priv+nopriv) | deliberately nonce-free; per-IP mint cap 30/15min (`:32-56`) | fresh nonce+timing-token for cached pages |
| `verifyistic_save_settings`, `_delete_log`, `_clear_logs`, `_export_csv`, `_test_webhook` | admin nonce + `manage_options` | admin ops |
| `verifyistic_protected_file` | login + `manage_options` + HMAC-signed, user-bound, 5-min URL | streams private ID/selfie files (`class-verifyistic-admin.php:48-86`) |

### 1.4 Hooks

- Fires: cron `verifyistic_webhook_retry` (`class-verifyistic-webhooks.php:24,292`). No public actions/filters for siblings — the booking-engine module integrates by reading verifyistic's **options and cookie**, not an API.
- Outbound webhooks: multi-connection fan-out with HMAC `X-Verifyistic-Signature`, SSRF guard (private-IP/localhost rejection, `class-verifyistic-webhook.php:20-45`), 5-attempt capped backoff (`class-verifyistic-webhooks.php:271-293,298-301`). **Outbound only — no inbound public API.**

### 1.5 Security layers on the age gate (substrate worth reusing)

- Nonce (`class-verifyistic-ajax.php:62`), per-IP rate limit (15 fails/15 min, `class-verifyistic-security.php:20-21`), honeypot (`class-verifyistic-ajax.php:73`, field rendered in `templates/frontend/popup.php:30`), HMAC-signed timing token with jti replay-burn only after success (`class-verifyistic-security.php:29-116`, `class-verifyistic-ajax.php:92-103,153`), server-side age recompute with hard 18+ floor (`class-verifyistic-ajax.php:106`), strict mode rejecting yes/no clicks (`:112-117`), trusted-proxy-aware client IP (`class-verifyistic-security.php:218-236`), MySQL advisory-locked atomic counters (`:157-212`).
- Verified state = cookie `verifyistic_verified` holding the **server-minted 32-char token**; JS refuses to set the cookie unless the server returned a real ≥16-char token (`assets/js/frontend.js:252-264`), and the server-side popup suppressor requires a ≥16-char `[A-Za-z0-9_-]+` value (`class-verifyistic-frontend.php:26-31`). A prior bug that trusted literal `"1"` was fixed **in this plugin** — but not in the booking-engine module (§2.1).
- Bot UA bypass is display-only (`class-verifyistic-frontend.php:54-65`); the AJAX endpoint still enforces the gate.

### 1.6 ID/selfie document storage (already protected)

`id_face` uploads go **outside the web-readable tree**: `uploads/private/verifyistic-ids/Y/m/` with deny-all `.htaccess` + index stub, MIME sniffing, 10 MB cap, random 32-char filenames, chmod 0640, DB stores opaque `private:` keys, admin access only via the signed streaming endpoint (`class-verifyistic-ajax.php:260-342`, `class-verifyistic-admin.php:25-86`). This is the storage pattern to reuse for signed waivers.

---

## 2. Every code path that can set waiver-signed status (system-wide)

Legend: 🔴 = unsafe/forbidden pattern, 🟡 = weak evidence or risky default, 🟢 = authenticated + capability-gated.

### 2.1 UNSAFE / FLAGGED paths

1. 🔴 **Thank-you-URL stamp (guns2ammo-waiver-manager).** `guns2ammo-waiver-manager/guns2ammo-waiver-manager.php:71-88`: a `template_redirect` handler stamps `update_user_meta($user->ID,'waiver_signed_date',time())` for **any logged-in user whose request URI contains** `/waiver-thank-you-members/` or `/waiver-thank-you-kiosk/`. No signature evidence, no nonce, no capability — page navigation alone marks the waiver signed for a year (`guns2ammo_has_valid_waiver`, `:91-99`). The file's own comment (`:66-70`) acknowledges this and says it should be removed once the ApproveMe hook is confirmed. Companion frontend `assets/js/waiver-success.js:3-5` shows a success popup off a bare `?waiver_signed=true` query param (cosmetic only, but part of the same URL-driven pattern).
2. 🔴 **Age-gate cookie auto-signs the booking waiver (booking engine verifyistic module).** `g2a-booking-engine/includes/modules/verifyistic/` (module default ACTIVE, options default ON): on `g2ab_booking_created` for public bookings, merely holding the `verifyistic_verified` cookie auto-sets `g2ab_bookings.waiver_signed = 1`. The age gate (a DOB popup) is being treated as a signed liability waiver.
3. 🔴 **Cookie value `'1'` still accepted in the booking module.** The booking module treats a literal `'1'` cookie as verified (`verify_type = cookie_only`) — the check the core plugin hardened (§1.5) was never ported, so `document.cookie="verifyistic_verified=1"` passes there.
4. 🔴 **`g2ab_waiver_satisfied` filter satisfied by bare-name match.** Memberistic's `class-waiver-booking-bridge.php` hooks the booking engine's `g2ab_waiver_satisfied` filter (applied on public `POST /bookings` and `/events/book`) and returns true when `Waivers_Archive::has_on_file()` matches the archive by **email OR bare full name**. A common name in the 1,793-row imported archive silently satisfies a stranger's waiver requirement.
5. 🟡 **ApproveMe kiosk hook.** `guns2ammo-waiver-manager.php:122-179`: `esig_stand_alone_document_after_invite_fired` (doc id from option `g2a_waiver_kiosk_document_id`, `:30-33`) creates/updates a WP user with role `kiosk` and stamps `waiver_signed_date`. Legitimate signal but tied to the third-party ApproveMe plugin and the fragile user-meta status model.
6. 🟡 **Admin manual booking `skip_waiver` defaults CHECKED** in the booking-engine admin UI; also set programmatically by the FFL checkout bridge (`advanced-ffl-checkout/includes/class-wpistic-ffl-g2a-booking-bridge.php:241`). Staff-only, but a silent default.
7. 🟡 **Memberistic per-user waiver token page.** `[memberistic_guest_waiver]`-adjacent tokenised signing page (`memberistic-membership-solutions/includes/waivers/class-waivers.php`, Waiver_Public): POST + nonce, but the nonce is derived from the same 40-char per-user token carried in the URL. The token is a **persistent, never-rotating bearer credential** — anyone holding a leaked link (email forward, logs, referer) can sign for that member.
8. 🟡 **Require-verification gate fails open.** The booking module's verification requirement is skipped entirely if the verifyistic plugin is unloaded; freshness window defaults to ~1 year (effectively no recency requirement).

### 2.2 Authenticated / gated paths (🟢)

Memberistic (`includes/waivers/class-waivers.php`, Waiver_Service + Waiver_Admin_Page; caps `edit_memberistic_members` / `manage_memberistic_members`): admin `bulk_sign` / `mark_one` / staff upload; REST `PUT /people/{id}` and `POST /memberships/bulk-waiver`; staff-dashboard walk-in create; Otter-import `stamp_member`; `mark_signed()` mirrors status to corporate repo + user meta. Guest waiver POST (unauthenticated, nonce + 8/hr/IP throttle) writes only `memberistic_waiver_signatures` rows and **cannot** flip a member's `waiver_status`. The corporate module's own public handler is defined but **never hooked** (dead code). Daily cron `expire_due` flips expired members. Booking engine: staff walk-in and front-desk verify-waiver actions; public QR self-check-in only mirrors an existing `waiver_signed` into `g2ab_checkins.waiver_verified` (cannot forge signed). POS: `POST /range/waivers/{id}/checkin` requires `g2a_pos_checkin_range_customer`; CSV import requires inventory/settings caps; **POS has no arbitrary "mark signed" path** and never writes sibling tables.

---

## 3. Customer identity fragmentation

**Eight distinct "person" record shapes** exist, with no shared UUID:

| # | Store | Key fields | Linkage |
|---|---|---|---|
| 1 | WP users (+ user meta `waiver_signed_date`, `waiver_source`) | email, login | canonical WP identity; `kiosk` role for walk-ins (`guns2ammo-waiver-manager.php:18-23`) |
| 2 | Woo customers | billing email/phone | = WP users + order meta |
| 3 | `memberistic_people` | membership_id, wp_user_id (nullable), full_name, email, phone, date_of_birth, waiver_status/signed_at/expires_at — **no UUID** | parent `memberships` table has `membership_uuid` UNIQUE + stripe/woo/pos customer ids |
| 4 | `g2ab_bookings` rows (booking engine) | customer_name/email/phone, user_id nullable, waiver_signed, waiver_id; DOB only inside form_data JSON — **no person table**; "Shooters CRM" is a read-only email aggregate |
| 5 | `g2a_customer_profiles` (POS) | keyed by WP user id; contact masked without `g2a_pos_view_customer_contact` |
| 6 | `g2a_range_waivers` persons (POS Otter import) | names, dob, email, phone, address, minor fields, `UNIQUE(source,unique_ref)`, `pos_customer_id` |
| 7 | `verifyistic_logs` | first/last name, dob per verification event — linked to nobody |
| 8 | FFL `transfers` customers (`advanced-ffl-checkout`) | customer_name/email/phone on transfer rows |

Matching between them today is ad-hoc: email-only (Otter import → users/people), email-or-bare-name (waiver booking bridge), email aggregate (shooters CRM), WP user id (POS profiles).

---

## 4. Otter import — current implementation (two overlapping ones)

**Note:** *OtterText* (SMS vendor) and *Otter Waiver* (e-sign vendor) are different products, conflated in naming. OtterText's chat/age-popup embed was retired (theme `ottertext-cleanup.php` scrubber + WP-CLI; see `docs/OTTERTEXT_REMOVAL.md`), but OtterText SMS is still a live Messageistic provider (HMAC-verified inbound webhook) and a live-but-fragile analytics call in `advanced-ffl-checkout` (`otter_summary()` hits guessed endpoint URIs; 404s are silently skipped so it can silently report zeros; marked legacy, Twilio replacement planned). Everything below concerns **Otter Waiver**.

1. **Memberistic importer** — `memberistic-membership-solutions/includes/waivers/class-waiver-import.php` + `class-waivers-archive.php`: Otter CSV → `memberistic_waivers_archive`. Matches by **email only** (`get_user_by('email')` → `People_Repository::get_by_email`); dedupes by email-or-(name+dob); mirrors PDFs into protected `uploads/memberistic-waivers/`; has a `report()` dry-run and WP-CLI `wp memberistic import-waivers`. Validated run: 1,922 rows → 1,793 unique. Matched members get `stamp_member` (waiver_status=signed). Unmatched rows sit in the archive with no status model.
2. **POS importer** — `g2a-pos-core` `OtterWaiverCsvImporter` → `g2a_range_waivers` (status default `'active'`, source default `'otterwaiver'`). Capability-gated import; sensitive fields redacted without `g2a_pos_view_waiver_sensitive_data`.

Two importers → **two disconnected archives of the same source data** (`memberistic_waivers_archive` vs `g2a_range_waivers`). The POS `VerifyisticWaivers.php` view additionally re-aggregates `verifyistic_logs` + `memberistic_people` read-only. **PII incident:** the real 1,922-row Otter export (incl. minors) was committed as a POS test fixture (`tests/fixtures/range_waivers_otterwaiver.csv`); replaced with synthetic data on working branches, history purge planned (`docs/PII_PURGE_PLAN_2026-07-15.md`).

---

## 5. Age-gate implementation

The **theme has no age-gate code** — the verifyistic plugin is the age gate (popup rendered into `wp_footer`, `class-verifyistic-frontend.php:194-197`, template `templates/frontend/popup.php`). Pass = server-verified DOB/yes-no/ID+selfie → 32-char token cookie for `verifyistic_cookie_days` (default 30). It is display-suppressed for bots and excluded pages; enforcement lives server-side in the AJAX handler. There is **no page-level server enforcement** (content is in the HTML regardless — by SEO design, `class-verifyistic-frontend.php:47-53`).

## 6. Document storage for signed waivers

- Verifyistic ID/selfies: protected private tree (§1.6). 🟢
- Memberistic waiver docs: `class-documents.php` → `uploads/memberistic-private/` with `.htaccess` + `web.config`, random 40-char names, chmod 0640; download requires login + nonce + owner-or-staff + realpath guard. Imported Otter PDFs mirrored to protected `uploads/memberistic-waivers/`. 🟢
- Residual risk: original Otter-hosted PDF URLs in CSVs, and the fixture PII incident (§4). No signed-waiver PDFs are generated natively today (no native e-sign exists).

## 7. Existing e-signature code

No native e-signature engine anywhere in the repo. The only e-sign integration is the legacy **ApproveMe WP E-Signature** dependency in guns2ammo-waiver-manager (missing-plugin admin notice `:36-52`; kiosk hook §2.1.5). Memberistic's "signatures" (`memberistic_waiver_signatures`) record consent events (name/typed acknowledgment) but capture no signature image/document artifact.

## 8. Integration points by sibling plugin

1. **guns2ammo-waiver-manager** (legacy, to be replaced): PMPro checkout redirect to waiver page (`:55-63`); user-meta status model; users-list Waiver Status column (`:102-119`); ApproveMe + thank-you-URL setters (§2.1).
2. **Memberistic**: Waiver_Service is today's most complete waiver engine (tables `memberistic_people`, `memberistic_waiver_signatures`; 365-day validity; expiry cron; email_all_missing; CSV export with formula-injection neutralizer); shortcodes `[memberistic_guest_waiver]`, `[memberistic_kiosk]`; `class-verifyistic-bridge.php` + `class-waiver-booking-bridge.php` hook the booking engine's `g2ab_waiver_satisfied` filter.
3. **g2a-booking-engine**: `includes/modules/verifyistic/{module.php,class-verifyistic-integration.php,class-verifyistic-settings.php}` — reads verifyistic cookie/options directly; public `GET /verifyistic/me` (`__return_true` permission, by design, for form autofill); auto-waiver + fail-open behaviors (§2.1); `g2ab_bookings.waiver_signed/waiver_id`, `g2ab_checkins.waiver_verified`; `waiver_signed_at` column consumed by g2a-business-api.
4. **g2a-pos-core**: read-only consumer + range check-in writer (§2.2, §4); `g2a_range_waivers`/`g2a_range_checkins`; Waivers view aggregates verifyistic logs + memberistic people.
5. **g2a-business-api**: waiver-reminder automation drafts emails for bookings in the next ~24h with `waiver_signed_at IS NULL` (`includes/automation/handlers/class-waiver-reminder-handler.php:41-48`), pointing at `https://guns2ammo.com/waiver` (`:82`).
6. **advanced-ffl-checkout**: creates pickup bookings with `skip_waiver = true` (`class-wpistic-ffl-g2a-booking-bridge.php:241`).

## 9. Reference docs (drift notes)

`docs/WAIVER_IMPORT.md`, `docs/VERIFYISTIC_SETUP_G2A.md`, `docs/MEMBERISTIC_INTEGRATIONS_AND_VERIFYISTIC.md` describe the memberistic import, the G2A verifyistic configuration, and the bridge respectively; they largely match code, but none documents (a) the booking module's cookie-`'1'` acceptance, (b) the auto-waiver-from-age-gate default, or (c) the dual-archive Otter situation — treat those as undocumented behavior, not spec.
