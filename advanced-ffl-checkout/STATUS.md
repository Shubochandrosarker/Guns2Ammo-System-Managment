# Advanced FFL Checkout Solutions — Present Conditions

_Last updated: 2026-07-06 · Plugin version 1.9.0 · DB schema 1.4.0_

This file is the living reference for where the plugin actually stands —
what exists, what was just shipped, what's open, and what to pick up next
session. Update it whenever a phase lands or the gap list changes; don't
let it go stale.

---

## 1. Repository parity

Two repos carry this plugin:

- **`guns2ammo-complete-custom-business-system`** — source of truth. New
  features land here first.
- **`ffl-checkout--solutions`** — mirrored from the above. Every commit to
  `advanced-ffl-checkout/` in the source-of-truth repo gets copied here in
  the same session so the two never drift.

As of this update, `diff -rq` between the two repos' `advanced-ffl-checkout/`
trees returns **zero functional differences** — the only remaining diffs
are cosmetic comment-banner dash widths in 11 files, left over from past
copy/paste across editors, harmless.

Both repos develop on branch `claude/ffl-checkout-audit-9kdjub`.

---

## 2. Version timeline (this engagement)

| Version | What shipped | Status |
|---|---|---|
| 1.7.6 | Cross-repo parity fix — 3 files had silently drifted despite matching version headers (email-OTP 2FA, themed emails, ICS invites, an open-redirect fix) | Merged: ffl-checkout--solutions#4 |
| 1.8.0 | On-screen signature capture for the Form 4473 worksheet (buyer + dealer), append-only `signatures` table, GDPR eraser coverage extended | Merged: guns2ammo#59, ffl-checkout--solutions#5 |
| 1.9.0 | FFL Compliance Verification Hub — Phase A (certified-copy tracking, manual eZ Check log, ATF-sync validity check, manager review queue) | Open (draft): guns2ammo#60, ffl-checkout--solutions#6 |

---

## 3. Regulatory context driving the current work

ATF published a direct final rule ("Licensee eZ Check Verification for
Transfers") on **2026-05-06** that would have let FFLs verify another FFL
via the public **FFL eZ Check** tool instead of collecting a certified
license copy. It received adverse comments and **ATF withdrew it on
2026-07-06** — the rule will not take effect. **Certified-copy collection
remains the required default.**

**API reality, checked directly (not assumed):**
- The **Federal Register API** (`federalregister.gov/developers/documentation/api/v1`)
  is real, public, documented, and requires no key — legitimately usable
  for watching whether ATF revives or finalizes a similar rule.
- **ATF's FFL eZ Check** (`fflezcheck.atf.gov`) has **no published API**.
  It's a manual web form (enter partial license digits, click Submit).
  Any vendor claiming an "eZ Check API integration" is not calling
  anything ATF publishes.
- This plugin already owns the same underlying FFL data eZ Check exposes,
  via the monthly ATF dealer-list sync (`Sync` class → `dealers` table,
  ~80k rows: license number, business name, premises address, expiration).

This is why Phase A's "automated" check validates against the plugin's
own synced data rather than calling anything external — that's the one
piece that's genuinely free to automate. See the phase-plan artifact from
this session for the full reasoning and Phases B/C.

---

## 4. Feature inventory by subsystem (current state)

### Compliance / ATF forms
- State compliance rules: hand-tuned for CA/NY/NJ/MA/MD/IL/HI/CO/WA + a
  generic top-up for the rest (all 50 states + DC) — **static PHP strings**,
  not a maintained legal feed. Checkout-blocking by default (strict mode).
- NICS: 3-business-day rule math + admin alert on lapse only. **No live
  background-check API integration exists or is available** to build against.
- Form 4473: browser-print "DRAFT — NOT FOR ATF SUBMISSION" worksheet,
  now with **on-screen signature capture** (v1.8.0) embedded into the same
  printable page.
- **FFL Compliance Verification Hub** (v1.9.0, new): certified-copy
  upload/expiration tracking, manual eZ Check log, ATF-sync validity
  check, manager review queue. Scoped to dealers this store has actually
  shipped to.
- No Acquisition & Disposition (bound book) ledger. No Form 3310.4
  multi-sale detection. No excise-tax automation.

### Carrier / shipping
- EasyPost: real API calls for tracking (pull + push webhook). Shippo /
  ShipStation / AfterShip: webhook-receive only, no outbound API calls to
  them. **No rate-shopping or label purchase** — tracking only.

### Dealer network & portal
- ATF dealer database: real monthly sync, chunked/resumable, ~80k dealers.
- Dealer portal: single-use magic link per transfer (Received / Report
  Issue / Not My Shipment), 2FA options none/last-4-license/email-OTP.
  **No persistent dealer login or self-service.**
- Saved/quick-pick dealers for repeat customers.
- Dealer onboarding shortcode (lead-capture only, no auto-activation).

### Notifications
- `wp_mail()`-based, SMTP-plugin-dependent. Themed HTML frame, ICS pickup
  invite, "View Transfer Status" CTA, built-in mail log, status-alias map.
- SMS depends entirely on the separate Verifyistic plugin — no direct
  carrier API in this plugin.

### Admin / ops
- Dashboard, Transfers, Dealers, Portal analytics, Diagnostics, Ops
  Tools (bulk fees, customer LTV, dealer health alerts), Compliance &
  Security audit (~16 checks), Activity Log, Carriers, Webhooks Out,
  **Verification Hub (new)**.

### Security / auth
- Admin TOTP 2FA (from-scratch RFC 6238 implementation) — QR code is
  rendered via the public third-party `api.qrserver.com` image endpoint,
  which means the otpauth secret URI is sent to a third party. Flagged,
  not yet fixed.
- HMAC-signed single-use portal tokens, rate limiting, trusted-proxy-gated
  IP resolution, CORS scoped to the plugin's own REST namespace.

### Licensing / SaaS
- `License::activate()` is an unimplemented stub —
  `WP_Error('not_implemented', …)`. The free/Pro/Agency tier map exists in
  code but is permanently unlocked via a hardcoded
  `WPISTIC_FFL_UNLIMITED = true` constant. No real remote activation
  exists yet.

### Analytics
- Two self-contained systems (portal funnel analytics, business analytics
  REST endpoints). **No charting library anywhere** — every "dashboard" is
  HTML KPI tiles and tables. One hardcoded `coming_soon: true` stub
  ("Phone Calls") ships in the production leads-analytics response.

### Testing
- **No `tests/` directory in this plugin at all**, unlike sibling plugins
  in the same monorepo (`g2a-pos-core`, `g2a-business-api`), both of which
  ship real PHPUnit suites.

---

## 5. Gap status vs. the original 15-gap market audit

| # | Gap | Status |
|---|---|---|
| 01 | No live NICS / background-check verification | **Open** — no API exists to build against |
| 02 | No Acquisition & Disposition (bound book) ledger | Open |
| 03 | Form 4473 is a print worksheet, no signature capture | **Closed — v1.8.0** |
| 04 | No multiple-sale (5-day / 3310.4) detection | Open |
| 05 | State law engine is static text, no excise-tax automation | Open |
| 06 | Licensing / SaaS gating is an unimplemented stub | Open |
| 07 | No firearms-friendly payment gateway integration | Open |
| 08 | Carrier integration is tracking-only, no rate-shop/label buy | Open — cheapest remaining win, same EasyPost key already configured |
| 09 | Pickup "scheduling" not wired to the existing booking engine | Open — cheapest remaining win, booking engine already exists in-repo |
| 10 | No identity / age verification API | Open |
| 11 | No distributor drop-ship integration | Open |
| 12 | Dealer portal has no persistent login or self-service | Open |
| 13 | No fraud / straw-purchase risk scoring | Open (dealer-side verification now exists via the Hub; buyer-side fraud scoring does not) |
| 14 | Zero automated test coverage on the plugin itself | Open |
| 15 | No charting / BI layer, one hardcoded "coming soon" stub | Open |
| — | *(new, this session)* FFL dealer verification workflow | **Closed (Phase A) — v1.9.0.** Phases B (Federal Register regulatory watcher, expiration reminders) and C (premium-tier gating, tied to #06) remain open. |

---

## 6. Due / next phase

Pick up in roughly this order:

1. **Check PR status first** — guns2ammo#60 and ffl-checkout--solutions#6
   (Verification Hub v1.9.0) were open drafts as of this update. Confirm
   merged before starting new schema changes on top.
2. **Verification Hub Phase B** (from the phase-plan artifact): Federal
   Register API watcher for ATF rule changes (real, buildable — never
   auto-changes the policy setting, only alerts), expiration reminders at
   60/30/7/0 days reusing `G2A_Scheduler`, missing-document dashboard
   widgets, CSV/PDF audit export.
3. **Remaining Quick Wins from the original audit** — #08 (carrier
   rate-shop + label purchase) and #09 (wire pickup scheduling to
   `g2a-booking-engine`) — both reuse infrastructure already in place.
4. **Verification Hub Phase C** once #06 (real license activation) is
   built — gate the Hub behind `License::can()` as a Pro/Agency feature.
5. Everything else in the gap table above, roughly in severity order.

---

## 7. Standing constraints (do not re-litigate these)

- **No ATF eZ Check API exists.** Do not build a client that submits
  license numbers to `fflezcheck.atf.gov` and scrapes the result.
- **No auto-approval anywhere in compliance flows.** Every check —
  address match, ATF sync validity, eZ Check log, dealer/buyer state
  mismatch — produces a recommendation for a logged staff decision, never
  an automatic transfer approval or a silent policy-mode change.
- Certified-copy collection is the compliance baseline until ATF finalizes
  something different. Don't build or message an "eZ Check replaces
  certified copy" mode.
