# Advanced FFL Checkout Solutions — Present Conditions

_Last updated: 2026-07-15 · Plugin version 1.15.1 · DB schema 1.11.0_

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

As of the 1.9.0 update, `diff -rq` between the two repos' `advanced-ffl-checkout/`
trees returned **zero functional differences** apart from cosmetic
comment-banner dash widths in 11 files.

**v1.10.0 (Exhibits 01-05), v1.11.0 (Exhibits 06-10), v1.12.0 (multi-item
transfers + Verification Hub Phase B), v1.13.0 (gaps #11-15 — test
coverage, charting, dealer self-service login, fraud scoring, Lipsey's
drop-ship), and v1.14.0 (see below) were all built directly in
`ffl-checkout--solutions` only** — guns2ammo wasn't in scope for any of
these sessions, so the two repos drifted well past the "zero functional
differences" point above. **v1.14.0 closed that drift in one direction**:
guns2ammo (still at plugin v1.9.4 / schema 1.4.1 as of this check — a
separate "system audit" PR #73 there had bumped patch versions with no
matching changelog/STATUS.md entries) turned out to have **real,
independent fixes ffl-checkout--solutions was missing**: a server-side
Verifyistic age-verification gate at checkout, rate-limiting + a
notes-column leak on the public dealer-detail REST endpoint,
federal-holiday-aware NICS/multi-sale business-day math, a theme
Customizer key bug (business email/phone silently always empty), an iOS
zoom CSS fix, and atomic (not check-then-act) race guards on transfer
creation and two status-advance paths. All of that is now in
`ffl-checkout--solutions` too, adapted to this repo's newer multi-item
architecture where the two conflicted (see §4 and §7). **Porting the
v1.10.0-v1.14.0 feature work forward into guns2ammo is still open** — now
that ffl-checkout--solutions is a strict superset of guns2ammo's real
work, that port is a clean forward-copy rather than a delicate merge. Pick
up in §6.

**v1.11.0 also touched a THIRD repo directly: `g2a-booking-engine`.**
Exhibit 09 originally called that plugin's tables/classes directly from
here (idempotent, by `external_ref`) needing zero code changes on its side.
**As of v1.11.1 that direct-table approach was replaced**: g2a-booking-
engine now ships a proper first-party `ffl-checkout` addon module
(`G2AB_Module_Ffl_Checkout`, that repo's v1.9.9.4+) with a stable public
API — `sync_dealer_resource()`, `ensure_pickup_booking_type()`,
`is_real_open_slot()` — and `class-wpistic-ffl-g2a-booking-bridge.php`
calls that instead of writing `g2ab_resources` / `g2ab_booking_types` /
`g2ab_availability_rules` directly. `is_real_open_slot()` also closes a
real gap: the admin-bookings creation path applies no business-hours/
lead-time/blackout validation of its own, so a forged/stale `start_at`
previously only had to survive the race-safety capacity check, not a real
availability check. **Scheduling now requires g2a-booking-engine 1.9.9.4+**
— older versions fall back to the `.ics` invite, same as when the plugin
isn't installed at all.

**Four more sibling repos got matching addon-integration passes this
round**, each as its own PR against its own repo (not mirrored into this
one): `g2a-pos-solutions` (write-path audit-log bridge for dealer-to-dealer
consignments), `memberistic-membership-solutions` (read-only member
FFL-transfer-history dashboard bridge), and `messageistic` (fixed a
pre-existing dead FFL SMS integration — wrong hook name/arity, wrong
status-condition values, so it had never actually fired). `formistic` was
evaluated and no changes landed there — see §6.

Both repos develop on branch `claude/ffl-checkout-audit-9kdjub` (guns2ammo)
/ `claude/artifact-audit-exhibits-7j80iw` (this repo, both updates).

---

## 2. Version timeline (this engagement)

| Version | What shipped | Status |
|---|---|---|
| 1.7.6 | Cross-repo parity fix — 3 files had silently drifted despite matching version headers (email-OTP 2FA, themed emails, ICS invites, an open-redirect fix) | Merged: ffl-checkout--solutions#4 |
| 1.8.0 | On-screen signature capture for the Form 4473 worksheet (buyer + dealer), append-only `signatures` table, GDPR eraser coverage extended | Merged: guns2ammo#59, ffl-checkout--solutions#5 |
| 1.9.0 | FFL Compliance Verification Hub — Phase A (certified-copy tracking, manual eZ Check log, ATF-sync validity check, manager review queue) | Open (draft): guns2ammo#60, ffl-checkout--solutions#6 |
| 1.10.0 | FFL Checkout Solutions audit dossier, Exhibits 01-05: background-check provider registry, A&D bound-book ledger, real 4473 PDF generation, Form 3310.4 multi-sale watcher, filterable/versioned state-rules feed + excise-tax hook | ffl-checkout--solutions only this round — see §1 |
| 1.11.0 | FFL Checkout Solutions audit dossier, Exhibits 06-10: real license activation, NMI payment gateway, EasyPost rate-shop + label purchase, real pickup scheduling via g2a-booking-engine, ID/age verification gated by state | ffl-checkout--solutions only this round — see §1 |
| 1.11.1 | Exhibit 09 becomes a real g2a-booking-engine addon module (`G2AB_Module_Ffl_Checkout`, that repo's v1.9.9.4) instead of direct table writes; closes the unvalidated-slot security gap via `is_real_open_slot()`. Paired with separate addon-integration PRs on `g2a-pos-solutions`, `memberistic-membership-solutions`, and `messageistic` (each its own repo/PR — see §1) | This repo + 3 sibling repos, each its own PR — see §1 |
| 1.12.0 | Checkout now creates one `transfers` row per FFL unit in an order (not just the first line item) — fixes the known undercount in the A&D ledger, the Form 3310.4 watcher, and rate-shopping/label-buying for multi-firearm carts; `G2A_Status_Bridge` and the Ops Tools LTV lookup updated to match. Verification Hub Phase B: certified-copy expiration reminders (60/30/7/0 days), a WP dashboard widget, CSV/PDF audit export, and a new 📅 Regulatory Watch page (real Federal Register API sweep, alert-only) | ffl-checkout--solutions only this round — see §1 |
| 1.13.0 | Remaining gaps from the original 15-gap audit: PHPUnit test suite (Brain Monkey mocks, GitHub Actions), vendored dependency-free canvas charts on the FFL Dashboard + Portal analytics pages + fixed the hardcoded `coming_soon` phone-calls stub, persistent dealer portal login (`ffl_dealer` role + `[ffl_dealer_portal]` shortcode, alongside the unchanged magic-link flow), buyer-side fraud/straw-purchase rules-based risk scoring (🚩 Fraud Review queue), and a real Lipsey's distributor drop-ship client (📦 Distributor page) | ffl-checkout--solutions only this round — see §1 |
| 1.14.0 | Backported real, independent fixes found in guns2ammo (still at plugin 1.9.4 there) during a cross-repo reconciliation check: checkout-time Verifyistic age-verification enforcement, dealer-detail REST endpoint rate-limit + a public `notes`-column leak fix, federal-holiday-aware NICS/multi-sale business-day math, a theme Customizer key bug (business email/phone always empty), an iOS zoom CSS fix, and atomic (DB-enforced, not check-then-act) race guards on multi-item transfer creation + two status-advance paths | ffl-checkout--solutions only this round — see §1 |
| 1.15.1 | Security: Lipsey's dealer credentials encrypted at rest (AES-256-GCM keyed from AUTH_KEY); legacy plaintext re-encrypted transparently on first read | Both repos |
| 1.15.0 | Cross-repo unification (crossmatch): verified file-by-file that 1.14.0 was already a strict functional superset of the guns2ammo copy (1.9.4), ported the one remaining cosmetic monorepo difference (standard 🖨️ printer emoji on the scorecard Print button), and wrote the identical unified tree to BOTH repos — parity restored, no schema change | Both repos, byte-identical this round |

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
  generic top-up for the rest (all 50 states + DC). As of v1.10.0 these
  live in an admin-editable CRUD page (Advanced FFL → 🗺️ State Rules)
  instead of requiring a code deploy, and the seed itself is filterable
  (`wpistic_ffl_state_rules_seed` / `_seed_topup`) for a future maintained
  legal-data-feed integration. Still not a live external feed — still not
  legal advice. Checkout-blocking by default (strict mode).
- NICS: 3-business-day rule math + admin alert on lapse. As of v1.10.0, a
  `Background_Check_Provider` registry (Advanced FFL → 🔫 Background Check)
  mirrors the carrier-provider pattern: manual entry stays the default/
  fallback, and an HMAC-verified push webhook lets a dealer's own NICS
  E-Check integration or a licensed vendor report results. **No live NICS
  API exists to call outbound** — same constraint as ATF's FFL eZ Check —
  so this is push-only, never an outbound client. **As of v1.14.0**, the
  3-business-day math also excludes federal holidays (`wpistic_ffl_
  federal_holidays` filter), not just weekends — a fix backported from
  guns2ammo's independent work. The Form 3310.4 watcher's 5-business-day
  window reuses the same holiday table.
- Form 4473: "DRAFT — NOT FOR ATF SUBMISSION" worksheet with **on-screen
  signature capture** (v1.8.0) and, as of v1.10.0, a **real PDF export**
  (vendored FPDF) alongside the existing browser print-to-PDF, plus admin
  UI entry points that previously didn't exist anywhere.
- **FFL Compliance Verification Hub** (v1.9.0): certified-copy
  upload/expiration tracking, manual eZ Check log, ATF-sync validity
  check, manager review queue. Scoped to dealers this store has actually
  shipped to. **Phase B (v1.12.0, new)**: 60/30/7/0-day certified-copy
  expiration reminder emails, a WP dashboard widget summarizing missing/
  expiring documents and the review queue, and CSV/PDF audit export of
  the dealer verification directory. Paired with a new standalone 📅
  Regulatory Watch page — a nightly Federal Register API sweep for ATF
  documents matching FFL-licensee-verification terms, alert-only, never
  changes the policy-mode setting automatically.
- **Acquisition & Disposition (bound book) ledger** (v1.10.0, new):
  serial-level `ad_ledger` table auto-populated on receipt/disposition,
  "Needs Serial Number" queue, ATF-format CSV export. Models the
  *receiving* FFL's bound-book obligation only — excluded from GDPR
  erasure (20-year ATF retention).
- **Form 3310.4 multiple-sale watcher** (v1.10.0, new): flags 2+ handgun
  transfers to the same buyer within a rolling 5-business-day window,
  immediate admin email (same-day filing deadline), review queue with
  "Mark Filed." As of v1.12.0, `Checkout` creates one `transfers` row per
  FFL unit in an order (not just the first line item), so this now also
  catches 2+ handguns purchased in a single order/cart, not just repeat
  orders.
- **Pittman-Robertson excise-tax line item** (v1.10.0, new): opt-in per
  product (manufacturer/importer flag + 10%/11% rate), `woocommerce_cart_
  calculate_fees` hook. No store-wide toggle. Not tax advice.

### Carrier / shipping
- EasyPost: real API calls for tracking (pull + push webhook). Shippo /
  ShipStation / AfterShip: webhook-receive only, no outbound API calls to
  them. As of v1.11.0, EasyPost also **rate-shops automatically** when a
  transfer is created and exposes a "Buy Label" admin action — real
  weight/dimensions from the linked WC product when set, sensible handgun/
  long-gun defaults otherwise. Rate-shopping is free/automatic; buying a
  label is always an explicit admin click.

### Distributor drop-ship
- **New (v1.13.0)**: `G2A_Lipseys` (Advanced FFL → 📦 Distributor) — a real
  client against Lipsey's documented dealer API (api.lipseys.com),
  confirmed against the public reference client
  (github.com/Lipseys/LipseysApiIntegrationPhp) rather than guessed:
  `POST integration/authentication/login`, a custom `Token` auth header
  (not `Authorization: Bearer`), `GET integration/items/CatalogFeed`,
  `POST integration/order/DropShipFirearm`. Catalog sync is free/automatic
  on click; drop-ship order submission (real wholesale $) is always an
  explicit admin click scoped to one transfer's own dealer FFL, same
  pattern as EasyPost's "Buy Label." **Ships un-smoke-tested** — no
  approved Lipsey's dealer account was available in-session; verify
  Login/CatalogFeed/DropShipFirearm against a live account before trusting
  it with a real order.

### Payments
- **NMI gateway adapter** (v1.11.0, new): Collect.js client-side
  tokenization (PCI SAQ A-EP, no raw card data server-side), sale +
  refund support. Targets NMI's generic Direct Post contract since most
  real firearms-friendly high-risk processors (PaymentCloud, Durango,
  Easy Pay Direct) are white-labeled on top of it — Collect.js URL and
  the transaction API URL are both admin-configurable per-reseller.
  Compliance audit flags Stripe/PayPal/Square if one of them is the
  active gateway.

### Dealer network & portal
- ATF dealer database: real monthly sync, chunked/resumable, ~80k dealers.
- Dealer portal: single-use magic link per transfer (Received / Report
  Issue / Not My Shipment), 2FA options none/last-4-license/email-OTP.
  **As of v1.13.0**, an opt-in persistent-login alternative also exists
  (Advanced FFL → 🔑 Dealer Logins): a real WP user account (`ffl_dealer`
  role, no wp-admin access) linked via new `dealers.wp_user_id`, with a
  `[ffl_dealer_portal]` front-end shortcode. The magic-link flow is
  unchanged and stays the default for every dealer who isn't invited.
- Saved/quick-pick dealers for repeat customers.
- Dealer onboarding shortcode (lead-capture only, no auto-activation).

### Notifications
- `wp_mail()`-based, SMTP-plugin-dependent. Themed HTML frame, "View
  Transfer Status" CTA, built-in mail log, status-alias map. As of
  v1.11.0, the "arrived at dealer" email links to a real pickup-scheduling
  page (g2a-booking-engine, real availability) when that plugin is active,
  falling back to the old fixed-guess ICS invite otherwise.
- SMS depends entirely on the separate Verifyistic plugin — no direct
  carrier API in this plugin.

### Admin / ops
- Dashboard, Transfers, Dealers, Portal analytics, Diagnostics, Ops
  Tools (bulk fees, customer LTV, dealer health alerts), Compliance &
  Security audit (~16 checks), Activity Log, Carriers, Webhooks Out,
  Verification Hub, Regulatory Watch, **Dealer Logins (new), Fraud
  Review (new), Distributor (new)**.

### Security / auth
- Admin TOTP 2FA (from-scratch RFC 6238 implementation) — QR code is
  rendered via the public third-party `api.qrserver.com` image endpoint,
  which means the otpauth secret URI is sent to a third party. Flagged,
  not yet fixed.
- HMAC-signed single-use portal tokens, rate limiting, trusted-proxy-gated
  IP resolution, CORS scoped to the plugin's own REST namespace.
- **As of v1.14.0**: `Compliance::validate_age_verification()` blocks
  checkout server-side for any FFL-required cart item unless the visitor
  has passed Verifyistic age verification — closes a real gap where the
  Verifyistic popup was only a UI prompt, not an enforced gate. The public
  `GET /dealers/{id}` REST endpoint now has the same 30/min rate limit
  `/dealers/search` already had (previously unlimited — scrapable), and
  both endpoints no longer leak the dealer `notes` column (manager-only
  staff commentary) in their public response. All three backported from
  guns2ammo's independent work — see §1.
- Transfer creation and two status-advance paths (`G2A_Status_Bridge`,
  EasyPost auto-advance-on-delivered in `G2A_Carrier_Providers`) now use
  DB-enforced atomicity (a unique constraint / compare-and-swap UPDATE)
  instead of a check-then-act pattern, closing a race where two
  near-simultaneous webhook/hook firings for the same order could
  double-process it. Also backported from guns2ammo — see §7.

### Licensing / SaaS
- `WPISTIC_FFL_UNLIMITED = true` still unconditionally unlocks everything
  — unchanged, and still the right call for this single-client Guns2Ammo
  build. As of v1.11.0, `License::activate()` is a real client: signed
  HTTP request to `wordpressistic.com/wp-json/wpistic-licenses/v1/
  activate`, cached entitlements, weekly revalidation cron with a 7-day
  grace window if unreachable. **The server side of that URL is not
  known to exist yet** — Memberistic (this account's other membership
  plugin) was investigated as a candidate and ruled out (it's Guns2Ammo's
  shooting-range membership system, unrelated product). Until a real
  server exists at that URL, activation will honestly fail with a
  connection error rather than silently succeeding.

### Identity / age verification
- **New (v1.11.0)**: optional per-state gate (Advanced FFL → 🪪 ID
  Verification) — checkout notice + post-order photo-ID upload with
  manual staff review, plus an HMAC-verified webhook route for a future
  connected hosted provider (Persona/Jumio/ID.me-class). No proprietary
  vendor API was hardcoded — same honesty-about-what's-real pattern as
  the background-check provider registry. New `_wpistic_ffl_age_restricted`
  product flag covers ammunition/accessories that ship without an FFL
  transfer, which the existing FFL-required flag alone would miss.

### Fraud / risk
- **New (v1.13.0)**: `G2A_Fraud_Score` (Advanced FFL → 🚩 Fraud Review) —
  buyer-side rules-based risk scoring computed once per transfer: order/IP
  velocity, first-time buyer + high-value order, dealer/buyer state
  mismatch (an ATF-documented straw-purchase indicator), disposable email
  domains, correlation with an open Form 3310.4 multi-sale flag, and rapid
  dealer-switching. All weights/thresholds filterable. No fraud vendor
  wired in (no credentials to test one against — same rationale as the
  NICS/ID-verification registries). Recommendation only, same
  no-auto-anything rule as the rest of this plugin.

### Analytics
- Two self-contained systems (portal funnel analytics, business analytics
  REST endpoints — the latter consumed by the separate app.guns2ammo.com
  dashboard, not rendered inside this plugin). **As of v1.13.0**, the two
  WP-admin-rendered surfaces (the main FFL Dashboard and the Portal
  analytics page) got real charts via a small vendored, dependency-free
  canvas renderer (`assets/js/wpistic-ffl-charts.js`, no CDN) — transfers
  by status, 30-day daily trend, and tokens-issued-vs-confirmed. The
  hardcoded `coming_soon: true` "Phone Calls" stub in the leads-analytics
  REST response is gone, replaced with `wpistic_ffl_phone_call_count`/
  `wpistic_ffl_phone_call_source_label` filters — the row only appears
  once a site actually connects call-tracking.

### Testing
- **As of v1.13.0**, a PHPUnit suite exists (`tests/`, `composer.json`,
  `phpunit.xml.dist`, a GitHub Actions workflow across PHP 8.1-8.3) — 38
  tests using Brain Monkey to mock WordPress functions, since there's no
  live WP/WooCommerce/MySQL environment available in this session or in
  CI. Covers pure-logic methods only (WC-status mapping, multi-item
  quantity expansion, CSV formula-injection guards, the verification-
  reminder threshold picker, NICS business-day math) — no integration or
  DB-backed test coverage yet.

---

## 5. Gap status vs. the original 15-gap market audit

| # | Gap | Status |
|---|---|---|
| 01 | No live NICS / background-check verification | **Closed (infra) — v1.10.0.** `Background_Check_Provider` registry + push webhook ship; manual entry remains the default since no live outbound NICS API exists to call — same category of constraint as ATF eZ Check. Genuinely "live" verification still depends on a dealer or vendor connecting their own provider. |
| 02 | No Acquisition & Disposition (bound book) ledger | **Closed — v1.10.0.** Serial-level `ad_ledger`, auto-populated, ATF-format export. Models the receiving FFL's bound-book side only. |
| 03 | Form 4473 is a print worksheet, no signature capture | **Closed — v1.8.0** (signature capture) **+ v1.10.0** (real PDF export via vendored FPDF, plus the admin-UI entry point the page never had). |
| 04 | No multiple-sale (5-day / 3310.4) detection | **Closed — v1.10.0, undercount fixed v1.12.0.** Watcher + review queue + immediate admin alert; as of v1.12.0 it also catches 2+ handguns in a single order (see §4), not just repeat orders. |
| 05 | State law engine is static text, no excise-tax automation | **Closed — v1.10.0.** State rules now admin-editable + filterable/versioned seed; Pittman-Robertson excise-tax line item added (opt-in per product). |
| 06 | Licensing / SaaS gating is an unimplemented stub | **Closed (client) — v1.11.0.** Real signed HTTP client, weekly revalidation, 7-day grace. The actual `wordpressistic.com` server side isn't known to exist yet — see §7. |
| 07 | No firearms-friendly payment gateway integration | **Closed — v1.11.0.** First-party NMI gateway (Collect.js tokenization), targets the gateway most real firearms-tolerant processors are white-labeled on. |
| 08 | Carrier integration is tracking-only, no rate-shop/label buy | **Closed — v1.11.0.** Auto rate-shop on transfer creation + admin "Buy Label" action, same EasyPost key. |
| 09 | Pickup "scheduling" not wired to the existing booking engine | **Closed — v1.11.0, hardened v1.11.1.** Real availability query + race-safe booking via g2a-booking-engine's first-party `ffl-checkout` addon module, in-process; candidate booking times are now re-validated against real availability server-side before booking. |
| 10 | No identity / age verification API | **Closed (infra) — v1.11.0.** Checkout notice + manual photo-ID review shipped as the real default, same honest provider-registry pattern as #01 (no proprietary vendor hardcoded). |
| 11 | No distributor drop-ship integration | **Closed (client) — v1.13.0.** Real Lipsey's dealer API client (`G2A_Lipseys`) — catalog sync + explicit-click order submission. Ships un-smoke-tested against a live account — see §4 and §7. |
| 12 | Dealer portal has no persistent login or self-service | **Closed — v1.13.0.** Opt-in `ffl_dealer` WP role + `[ffl_dealer_portal]` shortcode, alongside the unchanged magic-link flow. |
| 13 | No fraud / straw-purchase risk scoring | **Closed — v1.13.0.** `G2A_Fraud_Score` — rules-based, no vendor, recommendation-only review queue (🚩 Fraud Review). |
| 14 | Zero automated test coverage on the plugin itself | **Closed (unit) — v1.13.0.** PHPUnit + Brain Monkey, 38 tests, GitHub Actions. Pure-logic coverage only — no integration/DB-backed tests yet (still genuinely open as a *further* improvement, see §6). |
| 15 | No charting / BI layer, one hardcoded "coming soon" stub | **Closed — v1.13.0.** Vendored dependency-free canvas charts on the FFL Dashboard + Portal analytics pages; `coming_soon` stub replaced with a real filter-based extension point. |
| — | *(new)* FFL dealer verification workflow | **Closed (Phase A) — v1.9.0. Closed (Phase B) — v1.12.0**: Federal Register regulatory watcher, expiration reminders, dashboard widget, CSV/PDF export. Phase C (premium-tier gating, tied to #06) remains open. |
| — | *(new)* Multi-firearm orders undercounted A&D ledger / 3310.4 / rate-shopping | **Closed — v1.12.0.** `Checkout` now creates one `transfers` row per FFL unit in an order; `G2A_Status_Bridge` and Ops Tools LTV updated to match. |

---

## 6. Due / next phase

The original 15-gap audit is now closed at least at an infra/client
level (see §5) — this list is what's left, roughly in priority order:

1. **Port v1.10.0 through v1.14.0 forward into `guns2ammo-complete-custom-
   business-system`** (repo `Shubochandrosarker/guns2ammo-system-managment`
   — added to this session; the "complete-custom-business-system" name in
   this doc is a nickname, not the literal repo name). **In progress /
   next up.** v1.14.0 (above) already backported guns2ammo's own
   independent fixes into ffl-checkout--solutions, which is now a strict
   superset of guns2ammo's real work — the remaining step is a clean
   forward-copy of the new features (not a delicate merge, since nothing
   would be lost). Also port the g2a-booking-engine module + the
   sibling-repo addon PRs from §1 into that ecosystem's copies, if mirrored
   anywhere. Re-confirm with `diff -rq` when done.
2. **Smoke-test the two un-tested-in-session live API clients before
   trusting either with real money/orders**: NMI (Collect.js callback,
   transact.php parsing, a refund) and, new this round, Lipsey's
   (`Login` → `CatalogFeed` → `DropShipFirearm`, ideally with a cheap
   test SKU first). Both were built to the real, stable, documented
   contract but neither has run against a live account in this
   environment.
3. **Stand up the actual `wordpressistic.com` license server**, or point
   `License::API_BASE` at wherever it really lives. The v1.11.0 client is
   real and correct but has nothing to talk to yet — `activate()` will
   honestly fail with a connection error until this exists. Once it does,
   gate Verification Hub Phase C behind `License::can()` as planned.
4. **Expand test coverage past pure-logic units.** v1.13.0's PHPUnit suite
   (38 tests) deliberately covers only what's testable without a live
   WP/WooCommerce/MySQL environment — no test exercises an actual DB
   round-trip, a real WC checkout flow, or an admin AJAX handler. If a
   WP test harness (wp-env, wp-browser, WP_UnitTestCase) becomes available
   in a future session, that's the next real jump in coverage, not more
   Brain Monkey unit tests.
5. **Formistic addon was evaluated, not built.** Research concluded the
   integration point belongs on this plugin's side (calling
   `formistic_capture_contact()` / `do_action( 'formistic_capture', ... )`
   from FFL forms — ID-verification submission, "request different
   dealer," dealer onboarding) rather than inside `formistic` itself. Not
   implemented this round; pick up if lead-capture on those forms becomes
   a priority.
6. **Check PR status first** — guns2ammo#60 and ffl-checkout--solutions#6
   (Verification Hub v1.9.0) were open drafts as of the v1.9.0 update.
   Confirm merged before starting new schema changes on top.
7. **v1.12.0's regulatory-watch search terms are a starting curation, not
   exhaustive** (`G2A_Regulatory_Watch::TERMS`) — revisit if ATF proposes a
   rule using different phrasing than "eZ Check"/"licensee verification."
   The Federal Register API's `conditions[term]` is a full-text search, so
   a too-broad term risks noise and a too-narrow one risks silence; there's
   no feedback loop yet to tell which failure mode is happening.
8. **v1.13.0's fraud-score weights/thresholds are starting defaults, not
   tuned against real data** (`wpistic_ffl_fraud_score_weights` and the
   two threshold filters) — revisit once a store has enough real order
   history to tell whether they're too noisy or too quiet. Same caution
   applies to `G2A_Fraud_Score::DEFAULT_DISPOSABLE_DOMAINS`, a starting
   list, not exhaustive.
9. Everything else in the original gap table (§5) is now closed at least
   at an infra/client level — remaining depth work is captured in the
   items above, not a fresh gap.

---

## 7. Standing constraints (do not re-litigate these)

- **Never trust a repo's "last known version" without re-running
  `diff -rq`.** guns2ammo's own STATUS.md said "plugin 1.9.0 / schema
  1.4.0" and claimed zero functional differences from ffl-checkout--
  solutions at that point — both true when written. By the time this was
  checked again for v1.14.0, guns2ammo's actual code was at plugin 1.9.4 /
  schema 1.4.1 with real, undocumented independent fixes (a separate
  "system audit" PR #73 had bumped versions with no matching changelog or
  STATUS.md entries). A stale doc claim is not a substitute for diffing
  the actual trees before assuming which direction a "port" needs to go.
- **Atomic race guards, not check-then-act, for anything two WC/webhook
  events can both reach for the same row.** v1.14.0 fixed this for
  transfer creation (`UNIQUE KEY uidx_order_item_unit` + insert-then-
  recover) and two status-advance paths (`G2A_Status_Bridge`,
  `G2A_Carrier_Providers` — compare-and-swap UPDATE with a status-matching
  WHERE clause and an affected-rows check). Follow the same pattern for
  any new code that mutates a `transfers` row from more than one possible
  trigger — a PHP-side SELECT-then-decide is never sufficient on its own.
- **No ATF eZ Check API exists.** Do not build a client that submits
  license numbers to `fflezcheck.atf.gov` and scrapes the result.
- **No public NICS API exists either** (same constraint class as eZ Check
  — real access requires a direct FBI vendor agreement). The v1.10.0
  `Background_Check_Provider` registry is push-only (inbound webhook);
  don't build an outbound client against a NICS endpoint.
- **No auto-approval anywhere in compliance flows.** Every check —
  address match, ATF sync validity, eZ Check log, dealer/buyer state
  mismatch, background-check provider result, multi-sale flag — produces a
  recommendation for a logged staff decision, never an automatic transfer
  approval, denial, or a silent policy-mode change. A "delayed" NICS result
  is the one exception allowed to auto-advance status, because starting
  the mandatory 3-day clock is a timer, not a compliance decision — same
  class of automation the carrier webhook already performs for "delivered."
- Certified-copy collection is the compliance baseline until ATF finalizes
  something different. Don't build or message an "eZ Check replaces
  certified copy" mode.
- **Form 3310.4 filing is never automatic.** The multi-sale watcher only
  detects and alerts; a human files with ATF and marks it filed in-system.
- **The A&D ledger is not GDPR-erasable.** ATF's 20-year bound-book
  retention requirement means `ad_ledger` rows are deliberately excluded
  from the personal-data eraser — don't add it there.
- **No hardcoded ID-verification vendor.** Same rationale as the NICS
  provider registry — Persona/Jumio/ID.me-class services all have real,
  well-documented APIs, but none was picked and hardcoded since this
  plugin has no credentials to test against one. `wpistic_ffl_id_
  verification_providers` is the extension point; manual review is the
  real, working default.
- **The license server's existence is not assumed.** `License::API_BASE`
  points at a documented URL from the code's own long-standing plan
  comment, but nothing in this codebase confirms `wordpressistic.com`
  actually hosts that REST namespace today. Don't "fix" `activate()`'s
  honest connection-error by making it silently succeed.
- **NMI gateway ships un-smoke-tested against a live processor.** The
  Direct Post / Collect.js contract it targets is real and stable, but no
  sandbox credentials were available in-session to run an actual
  transaction. Test with real (sandbox) credentials before going live.
- **Regulatory Watch (v1.12.0) is alert-only, same as every other check in
  this hub.** A new Federal Register match emails the admin and gets
  logged; it never flips `wpistic_ffl_verification_settings.policy_mode`
  on its own. A human reads the document and decides whether the policy
  needs to change.
- **Fraud scoring (v1.13.0) never blocks, holds, or cancels anything.**
  `G2A_Fraud_Score` produces a score + a review-queue row and, above the
  review threshold, one admin email — same "recommendation for a logged
  staff decision" rule as every other check in this list. Don't wire a
  score threshold to an automatic order-cancel or NICS-hold.
- **No hardcoded fraud-detection vendor.** Same rationale as NICS/ID-
  verification — Sift/Signifyd/Kount-class services all have real APIs,
  but none was picked since this plugin has no credentials to test
  against one. The rules-based scorer is the real, working default;
  `wpistic_ffl_fraud_score_weights`/`_threshold_review`/`_threshold_medium`
  are the extension points.
- **Lipsey's client (v1.13.0) ships un-smoke-tested against a live
  account.** The endpoint paths, the `Token` auth header, and the
  `DropShipFirearm` request shape are confirmed against Lipsey's own
  public reference client, not guessed — but no approved Lipsey's dealer
  account was available in-session to run an actual login or order
  through it. Verify against a real account before trusting it with a
  real order, same posture as the NMI gateway.
- **Dealer self-service login (v1.13.0) is additive, not a replacement.**
  The single-use magic-link `Portal` flow is unchanged and stays the
  default for every dealer who isn't explicitly invited to the
  `ffl_dealer` role — don't remove or gate the magic-link path behind
  "has a login" logic.
