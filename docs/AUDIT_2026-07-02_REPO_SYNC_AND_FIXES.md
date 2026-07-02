# Guns2Ammo — Repo Cleanup, Audit & Stabilization (2026-07-02)

Senior full-stack audit of the complete Guns2Ammo system: theme + 9 custom
plugins. Goal was to reconcile the repo with the latest **live production**
theme/plugins (supplied as a zip export), audit every component, and fix
critical/high issues safely without breaking live workflows.

All PHP passes `php -l` under PHP 8.4. No database table names changed; no
destructive uninstall behavior added. Fixes were made in small, per-component
commits.

---

## Phase 1 — Repository Inventory & Version Reconciliation

The repo and the live site had **diverged in both directions** after
2026-06-14: development continued directly on the live server (POS, booking,
memberistic, theme) while the repo alone carried a newer FFL plugin that was
never deployed.

| Component | Repo (before) | Live upload | Action taken |
|---|---|---|---|
| g2a-pos-core | 3.1.2 | **3.1.4** | Synced repo → 3.1.4, then fixed → **3.1.5** |
| g2a-booking-engine | 1.14.6 | **1.9.9.1** (renumbered newer line: range console, self check-in, shooters, event booking, analytics, webhooks; contains repo's Cloudflare IP fix) | Synced → 1.9.9.1, then fixed → **1.9.9.2** |
| memberistic-membership-solutions | 1.46.0 | **1.9.9.4** (renumbered newer line: account provisioner, frontend auth) | Synced → 1.9.9.4 + checkout throttle fix |
| guns2ammo theme | 1.27.3 | 1.27.3 + live edits | Synced live edits, then fixed regressions → **1.27.4** |
| guns2ammo-waiver-manager | **absent** | 1.3 | Added to repo, then hardened → **1.4** |
| advanced-ffl-checkout | **1.7.5** | 1.7.3 | Kept repo (newer); fixed → **1.7.6** (flag for deploy) |
| messageistic | 0.5.1 | 0.5.1 | Webhook-signature fix |
| verifyistic | 1.4.0 | 1.4.0 | IP + CSS output fixes |
| wpistic-contact-form | 1.5.6 | 1.5.6 | No change (clean) |
| g2a-theme-control | 1.0.0 | 1.0.0 | No change (clean) |

**Repo cleanup:** removed 9 stale duplicate root-level zips (including
`WPistic-Theme-For-G2A-Version-1.8.9.zip`, 19 minor versions behind), added
missing `index.php` silence guards to 7 plugin roots. Canonical artifacts
remain in `releases/` via `scripts/build-release-zips.sh`.

> ⚠️ **Theme/booking coupling:** the live CCW and Ladies Tuesday templates
> call `[g2a_event_booking]`, which only exists in booking 1.9.9.x. Theme and
> booking engine must be deployed **together**.

---

## Phase 2 — Critical Issues Found

Priorities: **Critical** = fatal/security/data-loss/payment/POS/booking
failure · **High** = workflow/admin/API break · **Medium** =
perf/maintainability · **Low** = cleanup.

| Priority | Component | Issue | Status |
|---|---|---|---|
| Critical | POS | `POST /orders` trusts client-supplied `unit_price`/`line_total`/`grand_total` — a $2,000 firearm bookable and "paid" for $0.01 | **Fixed** (server recomputes totals) |
| Critical | POS | Client-supplied `compliance_state` finalizes a firearm sale with no NICS/4473 | **Fixed** (payload value ignored) |
| Critical | POS | Inventory import `csv_path` reads any server file (wp-config.php → auth-key leak) | **Fixed** (realpath-confined to uploads) |
| Critical | Waiver | Any logged-in user marks their waiver "signed" by visiting the thank-you URL | **Documented + partially mitigated** — needs owner input (see below) |
| Critical | FFL | `require_staff` REST gate passes ANY authenticated user → every customer can read transfer PII / revenue analytics / change transfer status | **Fixed** (real role required) |
| High | POS | Order flips to `paid` with zero tender reconciliation | **Fixed** (captured ≥ grand_total) |
| High | POS | Tender refund read-then-write race → double refunds | **Fixed** (atomic conditional UPDATE) |
| High | POS | Queue worker + messaging-flush cron never scheduled (`minute` interval unknown at activation) → jobs/reminders pile up forever | **Fixed** (register intervals + boot self-heal) |
| High | POS | SSRF via vendor image/PDF mirroring | **Fixed** (private-range block) |
| High | POS | Stripe secret stored plaintext; intent amount + refund unbound from order | **Fixed** (SecretStore + order binding/caps) |
| High | POS | `reduce_stock` fatals order creation when WC inactive; negative stock possible | **Fixed** (guards + clamp) |
| High | Booking | Woo bridge marks booking paid on order *status* alone, no `is_paid()`/amount check, and wrote to a nonexistent `amount` column (silent no-op) | **Fixed** (validate + `paid_amount`) |
| High | Theme | Ladies Tuesday banner guard checks wrong shortcode → banner permanently dead | **Fixed** |
| High | Theme | CCW page: unguarded `[g2a_event_booking]`/`[g2a_upcoming_events]`, native fallback form dropped, "Upcomin" typo | **Fixed** (shortcode_exists guards) |
| High | Theme | wp-login guard lets all POSTs through on the false belief WP core rate-limits logins | **Fixed** (per-IP throttle added) |
| High | FFL | Public `GET /dealers/{id}` returns `SELECT *` (dealer email + internal notes), enumerable | **Fixed** (column allowlist) |
| Medium | Booking | Guest booking limiter counts only failures → unbounded account creation + new-user mailbomb | **Fixed** (per-IP success cap) |
| Medium | Booking | Public `/range/lookup` + `/range/self-checkin` unthrottled PII enumeration | **Fixed** (rate limit) |
| Medium | Booking | Email automation str_replaces customer fields into HTML unescaped | **Fixed** (esc_html) |
| Medium | Booking | Reminder cron unbounded `SELECT *`; logs sent even on mail failure; expires paid bookings | **Fixed** |
| Medium | Memberistic | Public Stripe checkout endpoint reusable → pre-payment email/row spam | **Fixed** (per-IP throttle) |
| Medium | messageistic | Jasmin/SMSGate inbound webhook signature validation opt-in, default off → spoofable SMS/opt-in | **Fixed** (auto-on once secret set) |
| Medium | verifyistic | DB-layer client IP blindly trusts XFF (spoofable audit trail); stored-value CSS injection | **Fixed** |
| Medium | verifyistic | Age gate bypassable in default config (`yes_no` mode / UA-only gating) | **Documented** — enable `strict_mode` (config, not code) |
| Medium | FFL | ATF transfers CSV export vulnerable to formula injection | **Fixed** |
| Medium | Theme | www-canonical 301 reflects Host header (cache-poison/open-redirect) | **Fixed** (home_url host) |
| Medium | Theme | `G2A_VERSION` 3 releases stale → CSS/JS never cache-busts | **Fixed** (1.27.4) |
| Low | Booking/POS | Gateway secrets echoed into password-field values | **Fixed** |
| Low | Various | Hardcoded prod URLs, missing sanitizers | **Fixed** where applicable |

Components audited and found **clean**: wpistic-contact-form (1.5.6),
g2a-theme-control (1.0.0). Memberistic core (webhooks/access control) was
found defensively sound; only the public checkout throttle was added.

---

## Phase 3 — Fix Order

1. Repo cleanup + version sync (no behavior change)
2. POS (critical pricing/compliance/payment + file-read/SSRF/cron)
3. Booking + payment/customer workflow
4. Memberistic / waiver / FFL
5. Messaging / verification
6. Theme / login / redirects

---

## Phase 4 — Implemented Changes

See individual commits (each names files, rationale, and risk). Summary per
component in the version table above. Every touched PHP file was
`php -l`-verified; the POS sweep covered all 299 plugin PHP files.

---

## Phase 5 — Testing Checklist (manual, on staging)

- [ ] All plugins activate without fatal/notice (fresh + upgrade path)
- [ ] POS: create order — totals computed server-side; try tampered price → rejected
- [ ] POS: firearm order cannot complete until compliance approved via the compliance endpoint
- [ ] POS: split-tender + Stripe terminal charge/refund; refund cannot exceed captured
- [ ] POS: queue worker + messaging flush cron now appear in WP-Cron and run
- [ ] POS: inventory CSV import via upload works; `csv_path` outside uploads rejected
- [ ] Booking: guest booking, Woo-bridge paid order, deposit; mismatched amount logged not paid
- [ ] Booking: `/range/self-checkin` throttles after 10 attempts; CCW + Ladies Tuesday pages render event booking
- [ ] Membership: checkout, Stripe webhook activation, renewal; throttle after 8 attempts
- [ ] Waiver: kiosk signing creates user; PMPro post-checkout redirect stays on-domain
- [ ] FFL: staff API requires staff role (customer gets 403); public dealer search still works
- [ ] Contact form + messageistic inbound webhook (with secret) rejects unsigned
- [ ] Theme: mobile layout, footer support link, login throttle (20 fails → 429)
- [ ] PHP error log clean; browser console clean on key pages

## Phase 6 — Production Recommendation

- **Safe to stage now; deploy after the checklist passes on staging.** Every
  fix preserves existing workflows; the risky-by-nature changes (POS payment
  reconciliation, login throttle, webhook signature enforcement) are the ones
  to exercise on staging first.
- **Deploy theme 1.27.4 and booking 1.9.9.2 together** (shortcode coupling).
- **advanced-ffl-checkout 1.7.6 is repo-ahead of live** — it was never
  deployed; deploy it to pick up the transfer-bridge fixes + the staff-gate
  security fix.
- **Still needs manual testing:** POS end-to-end on real hardware (terminal,
  scanner), Stripe live-mode, and the ATF export formats.
- **Needs owner decision (not deployed as a hard fix):**
  1. **Waiver thank-you-URL self-certification** — the trustworthy fix is to
     stamp `waiver_signed_date` only from the ApproveMe signing callback. That
     requires the *membership* waiver document ID from the live site (the
     kiosk path already uses the signing hook). Until provided, the URL
     fallback remains with a documented warning.
  2. **verifyistic age gate** — enable `strict_mode` for this firearms site
     (configuration toggle) so `yes_no` mode can't be bypassed.
- **Highest next priority:** resolve the waiver self-certification with the
  live document ID, then run the full staging checklist.
