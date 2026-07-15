# Waiver System Audit — Real Gaps in the Ottertext Replacement, Dashboard UI Audit, and Build Plan

_Audit date: 2026-07-15 · Audited from repo source (see the repo/live drift caveat in Gap G4)_

**Scope.** Three questions, answered in three parts:

1. What does the system have today to replace Ottertext/OtterWaiver, and where
   are the **real gaps**?
2. What is the state of the **admin dashboard UI** (`dashboard-app`), especially
   for waiver operations?
3. What is the **action plan** to build a complete Guns2Ammo waiver management
   system?

---

## Part 1 — What exists today (inventory)

Ottertext is retired (`docs/OTTERTEXT_REMOVAL.md`). The replacement is not one
plugin — waiver logic is spread across **four plugins plus the theme scrubber**:

### 1.1 Memberistic — the waiver core

| Piece | Where | What it does |
|---|---|---|
| `Waiver_Service` | `memberistic-membership-solutions/includes/waivers/class-waivers.php` | Tokenized public sign page (`/?memberistic_waiver=TOKEN`), guest sign page (`?memberistic_waiver=guest`), editable waiver text (`memberistic_waiver_text` option), validity window (`memberistic_waiver_validity_days`, default 365), daily auto-expiry, admin console, CSV export, printable per-signature page. |
| `memberistic_waiver_signatures` table | `includes/database/class-schema.php:204-224` | Immutable signature records: signer name/email, source, signed/expires, IP, user-agent, **full waiver-text snapshot + `text_hash`**, optional attachment. |
| `Waivers_Archive` | `includes/waivers/class-waivers-archive.php` | The Ottertext import archive (`memberistic_waivers_archive`, schema `class-schema.php:243-272`): 1,922 rows → 1,793 people, mirrored PDFs, `find_on_file()/has_on_file()` lookup with validity cutoff. |
| Ottertext importer | `includes/waivers/class-waiver-import.php` (insert at `:174`) | CSV import + PDF mirroring (WP-CLI + admin upload). **The only caller of `Waivers_Archive::insert()`.** |
| `Waiver_Booking_Bridge` | `includes/waivers/class-waiver-booking-bridge.php` | Answers the Booking Engine's `g2ab_waiver_satisfied` filter from `Waivers_Archive::has_on_file()`. (The "not yet wired" note in `docs/WAIVER_IMPORT.md` is **stale** — this bridge exists.) |
| `Documents` | `includes/waivers/class-documents.php` | Hardened private file store (`uploads/memberistic-private/`, random 40-char names, permission-gated download endpoint), linkable to a signature. |
| People fields | `memberistic_people.waiver_status / waiver_signed_at / waiver_expires_at` | Per-member status stamped on signing; used by corporate check-in and staff surfaces. |

### 1.2 G2A Booking Engine — enforcement points

| Piece | Where |
|---|---|
| Per-booking-type `requires_waiver` + form checkbox + `g2ab_waiver_satisfied` filter | `includes/rest/class-bookings-controller.php:696-702` (lanes) and `:1305-1308` (events) |
| `waiver_signed` flag on bookings | `includes/class-installer.php:287` |
| Check-in `waiver_verified` flag + `g2ab_waiver_verified` action | `includes/services/class-checkin-service.php` |
| Front-desk `POST /frontdesk/verify-waiver` | `includes/rest/class-frontdesk-controller.php:53,381` |
| Self check-in (waiver banner, `g2a_waiver_incomplete` action with a `waiver_link` to Memberistic's guest page) | `includes/rest/class-range-controller.php:362-402`, `includes/frontend/class-self-checkin.php` |

### 1.3 g2a-business-api + dashboard-app

- One waiver automation: `Waiver_Reminder_Handler`
  (`g2a-business-api/includes/automation/handlers/class-waiver-reminder-handler.php`)
  drafts "sign before you come in" emails. **It is broken — see G2.**
- **No `g2a/v1` REST endpoints expose waivers at all** (controllers: ops,
  bridgistic, site-health, namespaces, agents, routing, models, system,
  automations, public — no members/waivers controller).
- **The React dashboard has no waiver UI.** The word "waiver" appears only as
  an automation category chip (`dashboard-app/src/pages/AutomationCenter.tsx:13`),
  a type union (`src/types/analytics.ts:145`), and a mock row (`src/mocks/data.ts:250`).

### 1.4 Legacy

`guns2ammo-waiver-manager/` — ApproveMe WP E-Signature + PMPro kiosk-user
provisioning. Superseded; not in the install path. Its only lasting ideas worth
keeping are the **kiosk role concept** and hands-free walk-in provisioning.

---

## Part 2 — The REAL gaps (ranked)

### Critical — correctness of the replacement itself

**G1 · Split-brain waiver truth: new signatures never reach the store the
booking gate reads.**
There are **three** waiver stores: `memberistic_waiver_signatures` (new
e-signs), `memberistic_waivers_archive` (Ottertext imports), and
`memberistic_people.waiver_*` (member status). `Waiver_Booking_Bridge::satisfy()`
checks **only the archive** — and `Waivers_Archive::insert()` is called **only
by the Ottertext importer** (`class-waiver-import.php:174`). Consequence: a
walk-in guest who signs the new guest waiver today is **not found** at their
next booking and is asked to re-sign; a member who signed via their tokenized
link is likewise invisible to the bridge unless they were in the 2026-05 import.
The replacement currently "works" mostly on the strength of the historical
import, which decays as it ages past the 365-day validity window — **from
~2027-05 the archive satisfies nobody and every returning customer re-signs.**

**G2 · The waiver-reminder automation queries a column that doesn't exist.**
`Waiver_Reminder_Handler::run()` filters on `waiver_signed_at IS NULL` in
`g2ab_bookings`, but the installer creates `waiver_signed TINYINT`
(`class-installer.php:287`). The SQL errors and the handler drafts nothing —
the dashboard shows the automation as "active" while it has never produced a
reminder.

**G3 · Self check-in can't auto-satisfy from the archive.**
`class-range-controller.php:363` applies `g2ab_waiver_satisfied` with an
**empty `$fields` array**; the bridge needs `customer_email`/`customer_name`
to match, so it always returns the incoming value. A customer with a valid
archived waiver whose booking row has `waiver_signed = 0` is told to see the
front desk anyway.

**G4 · Repo/live drift on the exact surfaces being audited.**
`docs/SYSTEM_WORKFLOW_v1.12.2.md` documents a staff waiver search
(`rest/class-staff-controller.php:198`), a staff console shortcode, and member
QR self check-in — **none of these files exist in the repo's booking engine**
(`includes/rest/` has no staff controller; `includes/frontend/` has no
staff-console or member-checkin shortcode). Either the live plugin is ahead of
the repo (the same "downgrade trap" already hit with Memberistic, per
`docs/WAIVER_IMPORT.md`) or the doc describes unshipped work. Until reconciled,
any deploy from this repo risks **deleting the staff waiver lookup in
production**, and no audit of those features is possible from source.

### Major — functional parity with OtterWaiver

**G5 · New signings capture far less data than the Ottertext waivers did.**
Guest signing collects **typed name + email + checkbox only**
(`class-waivers.php:588-606`). No DOB, no phone, no drawn signature, no
emergency contact — yet the archive schema (and the old Ottertext CSV) carries
`dob`, `phone`, `minor_name/minor_age`, `emergency_name/emergency_phone`. The
new system is legally and operationally thinner than what it replaced, and DOB
absence weakens `find_on_file()` name+DOB matching for everyone signed after
the migration.

**G6 · No minor/guardian flow at all.** The archive stores imported minor data,
but there is no way for a parent to sign for a minor on the new sign pages —
a routine, high-liability scenario for a shooting range.

**G7 · No generated PDF artifact.** New signatures produce a DB row plus an
optional printable page ("browser → Save as PDF", `class-waivers.php:1015`).
Nothing automatically produces and files a signed PDF the way OtterWaiver did;
guests can't attach anything (uploads are disabled on the unauthenticated
surface, reasonably). Long-term evidence quality rests on the DB row alone.

**G8 · No real kiosk mode.** The guest URL is public with only an 8/hour/IP
throttle (`class-waivers.php:596-605`). There is no station-token binding (the
check-in flow already has HMAC station tokens — the pattern exists in-house),
no attract screen, no staff PIN, no auto-reset between guests beyond a "Next
guest" link.

**G9 · Waiver text versioning exists at the data layer only.** Signatures
snapshot text + `text_hash`, but nothing manages versions: no version list, no
"text changed → who must re-consent?" logic, no effective dates.

**G10 · No SMS delivery.** Messageistic (the in-house SMS engine) is not wired
to waiver links or reminders; the only reminder channel is drafted email — and
G2 means even that never fired.

### Dashboard / operations

**G11 · Staff have no waiver operations UI in the Business Control Center.**
No waiver list/search/detail page, no PDF viewing, no expiry report, no
check-in waiver banner — staff must juggle WP-admin (Memberistic → Waivers /
Waivers on File) plus the WP front-end front-desk console plus the React
dashboard. No `g2a/v1` waiver endpoints exist to build on (Part 1.3).

**G12 · PDF exposure path needs verification.** `Waivers_Archive::pdf_url()`
(`class-waivers-archive.php` end) prefers `wp_get_attachment_url()` — a plain
media-library URL — and falls back to the dead `media.otterwaiver.com` link.
The hardened streaming path described in `WAIVER_IMPORT.md` should be the only
way a waiver PDF is ever served; confirm the mirrored files live in the
`.htaccess`-denied directory *and* are never linked via raw attachment URLs
(Nginx hosts ignore `.htaccess`).

---

## Part 3 — Admin dashboard UI audit (`dashboard-app`)

**Stack:** React 18 + TypeScript + Vite + Tailwind, react-router v6, 23 pages,
a small shared UI kit (`Card`, `EmptyState`, `ErrorState`, `PageHeader`,
`Spinner`, `StatCard`), dark/light theming (`src/lib/theme.tsx`), a typed API
client with a mock-mode short-circuit (`src/lib/api.ts`).

**What's good:** clean typed API layer; consistent loading/error/empty
primitives exist; theming is centralized; no TODO/FIXME debt markers; the
route→page mapping is documented (`docs/DASHBOARD_CURRENT_STATE.md`).

**Findings:**

1. **Analytics-heavy, operations-light.** 23 routes (`src/App.tsx:68-91`) and
   not one is an operational member/waiver/check-in screen. The "Business
   Control Center" cannot answer "does this person have a waiver?" — the
   single most common front-desk question.
2. **No code splitting.** Every page is imported eagerly in `src/App.tsx:5-27`
   (no `React.lazy`); the whole app ships as one bundle. Fine at 23 pages,
   worth fixing before adding an ops module.
3. **Session token in `localStorage`** (`src/lib/api.ts:56-65`) — XSS-readable;
   `docs/DASHBOARD_AUTH_SECURITY_PLAN.md` already plans the fix; it remains
   unimplemented in the app.
4. **Mock-mode short-circuit is env-driven** (`VITE_G2A_USE_MOCKS`,
   `src/lib/api.ts:4-8`) with realistic-looking data (`src/mocks/data.ts`) and
   no persistent visual indicator — a misconfigured build shows plausible fake
   business numbers. The mock automation list even shows the waiver reminder as
   healthy (`data.ts:250`) while the real handler is broken (G2).
5. **Pattern duplication:** 8 hand-rolled `<table>` implementations across
   pages, no shared `DataTable`; `alert()` used for error surfacing
   (`src/pages/AIModels.tsx:72`) instead of a toast system; page monoliths up
   to ~530 lines (`AIAgents.tsx`, `AIModels.tsx`) mixing data, state, and
   markup.
6. **Accessibility is thin:** only 7 files contain any `aria-` attribute;
   icon-only buttons and status color-pills mostly lack labels/text
   alternatives.
7. **Known-issues docs are ahead of the code** — the same drift problem as G4:
   treat `DASHBOARD_CURRENT_STATE.md` as intent, not state, until each claim is
   verified against source.

---

## Part 4 — Action plan: a complete Guns2Ammo waiver system

Design principle: **don't add a fourth waiver store — unify the three that
exist.** Memberistic stays the system of record; the Booking Engine stays the
enforcement point; the dashboard becomes the operations surface.

### Phase 0 — Stop the bleeding (correctness fixes, ~1 day of changes)

| # | Fix | Where |
|---|---|---|
| 0.1 | **Reconcile repo vs live** (G4): export the live booking-engine + memberistic plugins, diff against repo, commit the missing staff-console/waiver-lookup code (or port it). Nothing else ships before this — otherwise the next deploy can downgrade production. | ops + repo |
| 0.2 | Fix `Waiver_Reminder_Handler` to use `waiver_signed = 0` (or join the Memberistic lookup) and add a failing-SQL guard + surfaced error state (G2). | `g2a-business-api/.../class-waiver-reminder-handler.php` |
| 0.3 | Feed new signatures into the on-file lookup (G1): either have `record_signature()` also upsert a `waivers_archive` row (source `esign`), **or** extend `find_on_file()` to query `waiver_signatures` + `people.waiver_status` as a UNION. One lookup function, all three stores. | `class-waivers.php` / `class-waivers-archive.php` |
| 0.4 | Pass `customer_email`/`customer_name` from the booking row into the `g2ab_waiver_satisfied` filter at self check-in (G3). | `class-range-controller.php:363` |
| 0.5 | Verify + close the PDF exposure path (G12): mirrored PDFs only in the hardened dir, `pdf_url()` returns the gated streaming URL, never a raw attachment or otterwaiver.com URL. | `class-waivers-archive.php`, `class-documents.php` |

### Phase 1 — Signing parity with OtterWaiver (the "complete waiver" form)

- Extend the sign pages (member + guest) to capture: **DOB, phone, emergency
  contact, drawn signature** (canvas → PNG stored via `Documents`, linked by
  `signature_id`), and explicit initials checkboxes per clause if desired.
- **Minor/guardian flow** (G6): "I am signing for a minor" → guardian identity +
  per-minor name/DOB rows; store minors on the signature record (the archive
  schema already models this).
- **Server-side PDF generation** (G7): render the snapshotted waiver text +
  signer data + signature image to PDF (dompdf/mpdf) on signing; file it in
  `memberistic-private/`; link as the signature's attachment. Every waiver —
  imported or new — then has exactly one canonical PDF.
- Schema: add `dob`, `phone`, `emergency_name/phone`, `minors_json`,
  `waiver_version_id` columns to `memberistic_waiver_signatures` (migration in
  `class-migrations.php`).

### Phase 2 — Waiver versioning + lifecycle (G9)

- `memberistic_waiver_versions` table (id, title, body, effective_from,
  created_by); the settings editor creates a new version instead of mutating
  the option; signatures FK the version they signed.
- Policy switch: on new version, choose "existing signatures remain valid until
  expiry" vs "require re-consent at next visit".
- Expiry engine already exists (`Waiver_Service::expire_due()`); add a
  30-days-before renewal email/SMS with the person's token link.

### Phase 3 — Kiosk mode (G8)

- `/?memberistic_waiver=kiosk&station=TOKEN`: reuse the Booking Engine's HMAC
  station-token pattern (12-hour expiry) so only front-desk devices can open
  it; full-screen attract UI, auto-reset after each signing, optional staff
  PIN to exit.
- On completion, fire the existing `g2a_waiver_incomplete`-complement signal so
  an in-progress check-in updates live.
- Retire `guns2ammo-waiver-manager` formally (uninstall notes + removal from
  health-provider plugin list) once kiosk mode ships.

### Phase 4 — API + dashboard operations UI (G11)

- New `g2a/v1` controller in g2a-business-api (`/waivers`):
  `GET /waivers?search=&status=&page=` (unified lookup across the three
  stores), `GET /waivers/{id}` (detail + gated PDF stream URL),
  `GET /waivers/stats` (total / current / expiring-30d / missing-for-upcoming-
  bookings), `POST /waivers/send-link` (email/SMS a sign link),
  `POST /waivers/{id}/void`. Same auth + permission model as existing
  controllers.
- New dashboard **Waivers** page: search-first layout, status badges
  (current / expiring / expired / missing / archived), detail drawer with PDF
  viewer + signature history, "send sign link" action, expiring-soon report.
- Surface waiver status on a new **Check-in / Today** ops page (today's
  bookings with waiver + payment flags — data already exists in
  `frontdesk` endpoints).
- While in there, pay the UI debt that blocks clean ops screens: shared
  `DataTable` + toast components, route-level `React.lazy`, kill `alert()`,
  add a persistent "MOCK DATA" banner when `VITE_G2A_USE_MOCKS` is set, and
  aria-labels on icon-only controls.

### Phase 5 — Automations + comms (G10)

- Re-point the (now fixed) waiver reminder at the unified lookup; add SMS via
  Messageistic with the tokenized link; wire `g2a_waiver_incomplete` to an
  immediate "sign now" SMS at self check-in.
- Weekly ops digest: waivers expiring, bookings-without-waiver, kiosk volume.

### Sequencing & effort (rough)

| Phase | Effort | Depends on |
|---|---|---|
| 0 — correctness | 1–2 days | live-plugin export for 0.1 |
| 1 — signing parity | 3–5 days | 0 |
| 2 — versioning | 2–3 days | 1 |
| 3 — kiosk | 2–3 days | 1 |
| 4 — API + dashboard | 4–6 days | 0 (API can start after 0.3's unified lookup) |
| 5 — automations | 1–2 days | 0.2, 4 |

**Definition of done for "Ottertext fully replaced":** a walk-in can sign at a
kiosk (minor included) and a PDF is filed automatically; a returning customer
never re-signs while valid — at booking, self check-in, or front desk; staff
answer "waiver on file?" from one dashboard page; text changes are versioned;
reminders actually send; and every PDF is served only through the gated
endpoint.

---

## Phase 0 status (updated 2026-07-15)

| Task | Status |
|---|---|
| 0.1 Repo/live reconcile | **Blocked — ops.** The site's management bridge returns HTTP 202 (security layer) on plugin listing, so live versions could not be verified from here. Before the next deploy: export the live `g2a-booking-engine` + `memberistic` plugins and diff against this repo (the staff waiver console documented in `SYSTEM_WORKFLOW_v1.12.2.md` is still absent from source). |
| 0.2 Waiver-reminder SQL bug | **Fixed.** Column-aware query (`waiver_signed = 0`, real `customer_email`/`customer_name` columns), skips people already on file, links to the real Memberistic guest sign page. |
| 0.3 Unify waiver stores | **Fixed.** `record_signature()` now mirrors every new e-signature into `memberistic_waivers_archive` (idempotent `sig:{id}` key); DB migration 1.6.0 backfills all pre-existing signatures. The booking/check-in on-file lookup now sees Ottertext imports **and** new signings. |
| 0.4 Self check-in matching | **Fixed.** `g2ab_waiver_satisfied` at self check-in now receives the booking's `customer_email`/`customer_name` so the bridge can match. |
| 0.5 PDF exposure path | **Fixed.** `Waivers_Archive::pdf_url()` now always returns the capability + nonce gated streaming endpoint (never a raw media URL or otterwaiver.com link); the endpoint streams linked attachments instead of exposing their public URLs. |

## Phase 1 status (updated 2026-07-15)

| Task | Status |
|---|---|
| Signing-parity fields (G5) | **Done.** Both sign pages (member token + guest) now capture phone, DOB (18+ enforced server-side), and emergency contact. New columns on `memberistic_waiver_signatures` via DB migration 1.7.0; all fields flow into the archive mirror, the CSV export, and the printable page. |
| Minor/guardian flow (G6) | **Done.** The adult signer can list up to 4 minors (name + DOB) they sign for as parent/guardian; stored as `minors_json` on the signature, mirrored into the archive's minor fields, shown on the PDF and printable page. |
| Drawn signature | **Done.** Canvas draw-to-sign pad (pointer events, mobile-friendly) on both forms; exported as JPEG, validated server-side (base64/JPEG magic/size cap), stored in the hardened private document store linked to the signature. |
| Server-side PDF (G7) | **Done.** New dependency-free `Waiver_PDF` emitter (`includes/waivers/class-waiver-pdf.php`) generates a canonical signed-waiver PDF (waiver-text snapshot, signer data, minors, audit trail, embedded signature image) on every signing; filed via `Documents::store_generated()` and linked as the signature's attachment. Verified by rendering the output with a real PDF engine. |
| `waiver_version_id` groundwork | Column added (null for now) — Phase 2 wires versioning. |

## Phase 2 status (updated 2026-07-15)

| Task | Status |
|---|---|
| Version table (G9) | **Done.** New `memberistic_waiver_versions` table + `Waiver_Versions` class (`includes/waivers/class-waiver-versions.php`). Publishing creates an immutable new version (title, body, hash, effective date, author); the legacy `memberistic_waiver_text` option stays synced for back-compat. Migration 1.8.0 seeds version 1 from the existing text, backdated so current signatures stay valid. Signatures now record `waiver_version_id`; the CSV export includes it. |
| Re-consent policy | **Done.** The admin "Publish new version" form has a **Require re-consent** switch. When on: all signed people flip to `needs_review` (every surface prompts a re-sign, and the existing weekly follow-up email targets them automatically), and a re-consent boundary raises the archive lookup cutoff so waivers on file stop satisfying booking/check-in. When off: wording tweaks leave existing signatures valid until normal expiry. `is_current()` also enforces the boundary. |
| Renewal reminders | **Done.** Daily scheduler pass emails members whose waiver expires within a configurable window (default 30 days, 0 disables — Waivers → schedule settings) using the new `waiver_renewal` email template (`{waiver_expires}` merge tag), once per signing cycle (tracked via `people.waiver_renewal_reminded_at`; re-signing re-arms it). |
| Admin console | Version history table (last 10, current flagged), publish form with version label, split schedule-settings card (validity days + reminder days). |
