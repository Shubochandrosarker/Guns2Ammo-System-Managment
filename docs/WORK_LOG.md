# Guns 2 Ammo — Work log (chronological)

Top-down view of what shipped in each release on the
`claude/practical-hawking-LQW9g` branch. Each release is a single
zip + a PR commit; the section title links to the headline change.

---

## Memberistic 1.21.0 / POS Core 3.5.0 — August 13, 2026

**Headline:** repo audit follow-through — closed the two remaining
critical defects from the July 2026 full-system audit
(`docs/audit-2026-07-full-business-system/`) that were still open, plus a
new Discount Codes addon.

- **G2A-CRIT-001 (payment correctness) — closed.** The Stripe-first
  cancellation fix from `docs/MEMBERSHIP_CANCELLATION_STRIPE_SYNC.md` had
  two call sites still bypassing it: the generic `PATCH /memberships/{id}`
  REST endpoint, and `WooCommerce_Bridge::sync_refunded_order()`. Both now
  route through `Stripe_Service::cancel_remote_first()` like every other
  cancel path.
- **G2A-CRIT-004 (data integrity) — closed.** `memberistic_people.email`
  is now enforced unique. `People_Repository::create()` checks for an
  existing person by email before inserting (the sole insertion entry
  point, covering all 12 call sites at once). A new migration
  (`1.12.0`) non-destructively dedupes existing duplicate rows (keeps the
  highest id, clears — never deletes — the losing rows' email, logs every
  change) before converting the index to `UNIQUE`. `wp memberistic
  people-dedupe-audit` exposes the same dedupe as a dry-run-by-default CLI
  command for a pre-upgrade preview.
- **G2A-CRIT-003 (g2a-pos-core) — closed.** `VendorProductPromoter` now
  applies a wholesaler category's mapped WooCommerce category at promote
  time — previously the `wc_category_id` column existed but nothing ever
  wrote OR read it. Both gaps fixed: the Vendor Categories admin screen
  and REST endpoint now save the mapping, and promotion applies it via
  `wp_set_object_terms()` (best-effort, matches the image-mirroring
  pattern — never aborts the promotion).
- **New: Discount Codes addon** (Integrations tab, toggle: *Discount
  Codes*). Staff create percent/fixed coupon codes for membership plan
  checkout — scoped to specific plans and/or billing cycle, an active
  window, total and per-customer usage limits, and a duration (first
  cycle only / N months / forever) backed by a real Stripe Coupon +
  Promotion Code so recurring-discount duration is Stripe-confirmed, not
  hand-rolled. Full redemption log per code (who/when/plan/before/after
  amount), recorded only from the Stripe-confirmed webhook — idempotent
  per checkout session, matching the plugin's existing "verified payment
  evidence only" rule. New tables: `memberistic_discount_codes`,
  `memberistic_discount_redemptions` (migration `1.13.0`).

---

## Theme 1.24.0 / Booking 1.13.0 / Memberistic 1.45.0 / Messageistic 0.5.1 / POS Core 3.0.1 — CURRENT (June 11, 2026)

**Headline:** guest lane-booking fix (the bad-review bug), Integrations
toggle persistence fix, POS + SMS bridges, system-aware light/dark theme.

- CRITICAL: logged-out guests saw "No times available on this date." on
  every lane/date — stale `wp_rest` nonce baked into cached HTML was sent
  on public availability GETs and WP core 403'd the request before the
  route ran. Public GETs are now nonce-free; booking POST fetches a fresh
  nonce via the new `/session` endpoint (with one retry).
- Memberistic Integrations toggles (Verifyistic etc.) were stripped by the
  settings sanitizer on every save — now persisted; React Integrations tab
  renders all modules.
- New Memberistic modules: Waiver Manager (built-in waiver system card),
  POS Bridge (live member lookup at the POS counter + bookings feed), SMS
  Notifications via Messageistic. New `memberistic_membership_expiring` /
  `_expired` hooks.
- Messageistic vendored into the repo (0.5.1): dead Memberistic/booking
  integrations rewritten against the real hooks, Twilio webhook signature
  verification fixed, webhook routes restricted to the active provider.
- G2A POS Core vendored into the repo (3.0.1) with composer vendors
  (FPDF/FPDI) shipped; 4473 PDF render no longer fatals without them.
- Theme: system-aware light/dark mode with header toggle (no-FOUC
  bootstrap), guest profile icon in header (was an empty circle), mobile
  drawer rebuilt (staggered links + CTA footer), preloader capped at
  400 ms, booking widget restyled via new token-inheriting `site` skin +
  rose-gold `ladies` skin for Ladies Tuesday, countdown banner redesigned.

See docs/RELEASE_2026-06-11_GUEST_BOOKING_INTEGRATIONS_THEME.md for the
full write-up.

---

## Theme 1.18.0 / Memberistic 1.20.1 (May 27, 2026)

**Headline:** comprehensive SEO/AEO + UX cleanup.

- Cart UPDATE CART button + coupon row alignment rebuild (single
  horizontal row at ≥720 px, stacked column on phones).
- Checkout terms-and-conditions checkbox: 22 px brass-bordered,
  brass-filled when checked, with a visible white ✓ mark.
- Checkout select2 (Country / State) dropdown text no longer
  overflows; consistent 46 px height + ellipsis on overflow;
  dark-themed dropdown menu.
- Live Open/Closed pill now uses `America/Phoenix` (Mesa) time
  regardless of visitor TZ. Suffix " MST" added for clarity.
- New `/sitemap.xml` — branded master sitemap index +
  per-type sub-sitemaps (pages, posts, products, product-cats,
  faqs).
- New `/llms.txt` + `/llms-full.txt` — AI-friendly content
  summaries (short index + full page-text dump for grounding).
- New `/robots.txt` — explicit allow for GPTBot, ClaudeBot,
  anthropic-ai, PerplexityBot, Google-Extended,
  Applebot-Extended, OAI-SearchBot, ChatGPT-User, CCBot,
  Bingbot, YouBot. Blocks /account/, /cart/, /checkout/, /login/.
  Sitemap + llms.txt linked at the bottom.
- New `/faqs/` page — auto-created on theme activation, 25 Q&As
  grouped by topic, smooth `<details>` dropdown, FAQPage JSON-LD
  for rich results.
- New redirect map (`guns2ammo/inc/redirects.php`): 16 legacy /
  typo URLs 301'd to live equivalents — covers every URL flagged
  in the May 2026 broken-links audit (church-security-training-
  mesa-packages, get-support, contact-us, machine-gun-packages,
  hello-world, the membership-checkout?pmpro_level=N pattern,
  etc.).
- www → non-www 301 at the PHP layer (catches anything the host
  config doesn't already canonicalise).
- RankMath conflict guard — when RM is active, theme disables
  RM's JSON-LD, OG, Twitter, canonical, and sitemap outputs.
  RM keeps `<title>`, `<meta description>`, keywords, robots
  toggles, redirections module.

---

## Theme 1.17.0 / Memberistic 1.20.1 — May 27, 2026

**Headline:** post-login redirect + mobile profile icon + faster
preloader.

- Members redirected to `/account/` (was `/memberistic-account/`).
  Added every `memberistic-*` auto-created slug to the legacy
  list so URLs prefer branded slugs everywhere.
- `login_redirect` filter at priority 999 forces members to
  `/account/`; staff keep WP default.
- Mobile profile icon now visible (CSS was hiding `.g2a-profile`
  under 1100 px).
- Preloader dismiss on DOMContentLoaded + inline early-dismissal
  script in header (was waiting for `load` + 4 s safety; now ~
  first-paint with 900 ms hard ceiling).

---

## Theme 1.16.0 / Memberistic 1.20.0 — May 27, 2026

**Headline:** member login at /login/ actually works.

- `[memberistic_login]` form action switched to `site_url('wp-
  login.php', 'login_post')` so it bypasses the `login_url`
  filter and reaches the real auth handler.
- Theme `wp-login.php` guard loosened: ALL POSTs pass (intentional
  auth attempts); login-flow GET actions (lostpassword, rp,
  resetpass, postpass, confirm_action, register, logout,
  loggedout) pass; ?login=failed passes (error messages render).

---

## Memberistic 1.19.1 — May 27, 2026

**Headline:** booking form stacked layout inside the modal.

- Forced `.g2ab-shell` to single column inside the modal so the
  info card sits ABOVE the date/time/lane picker at full width.
  Calendar gets the full row (Saturday/Sunday no longer cut off).

---

## Memberistic 1.19.0 — May 27, 2026

**Headline:** "Book A Lane" is a full-width modal, not a tab.

- Tab now shows a "Reserve Your Lane" hero with a single CTA.
- Dashboard tile, sidebar nav, and CTA all open the same modal
  via `[data-open-booking]`.
- 1100 px max card on desktop, full-screen at ≤760 px. Backdrop +
  ESC + × close. Body scroll-locks while open.
- Auto-fill on open with 50 ms + 400 ms double-tap for lazy
  hydration.
- Removed the aggressive single-column rule that was squashing
  the booking form when embedded in the tab.

---

## Memberistic 1.18.0 — May 27, 2026

**Headline:** card download fix + mobile responsive + tile→tab.

- Card download rewritten as a self-contained popup window with
  inline cream / brass / dark-text styling — works regardless of
  browser "Background graphics" print setting. Auto-fires print().
- Dashboard "Book A Lane" tile switched from external nav to
  in-dashboard tab.
- Mobile responsive across .memberistic-acct: 2-up nav, single-
  column stats, scaled card, full-width inputs (16 px / 44 px
  touch targets).

---

## Memberistic 1.17.0 — May 27, 2026

**Headline:** branded URLs in emails + in-dashboard booking +
digital card logo.

- Email service prefers branded slugs (`account`, `book-a-lane`,
  `memberships`); never leaks `memberistic-*`.
- Settings → required pages auto-create branded pages on
  activation (My Account at /account/, etc.).
- New in-dashboard "Book A Lane" tab embedding the booking
  shortcode + auto-fill from logged-in user info.
- Digital card uses theme Custom Logo (Appearance → Customize →
  Site Identity), falling back to Memberistic logo_url setting,
  finally to brand-label text.

---

## Memberistic 1.16.0 — May 27, 2026

**Headline:** brand label / QR / print scope on the digital card.

- Installer default flipped from hard-coded "Memberistic" to
  `get_bloginfo('name')`. One-shot migration auto-flips existing
  installs with the legacy string.
- QR switched to api.qrserver.com (in-process SVG kept as
  `<img onerror>` fallback). Payload is the no-PII verify URL.
- `@media print` rules tightened so the card prints cleanly.

---

## Memberistic 1.15.0 / Theme 1.13.0 — May 27, 2026

**Headline:** member profile photo + dynamic verify QR + editable
machine-gun inventory.

- Verification utility: 32-char token, `/?memberistic_verify=...`
  endpoint, branded card with live status + photo.
- REST `/memberistic/v1/profile/image` (POST + DELETE).
- Dashboard avatar with upload/remove controls.
- Card QR encodes verify URL (no PII).
- G2A Theme Control: machine-gun inventory repeater with image
  picker + per-subfield sanitization. Hardcoded MP5/M16/AK-47
  removed from the template; cards render from the repeater.

---

## WPCF 1.5.0 / Theme 1.14.0 — May 27, 2026

**Headline:** working newsletter + per-tier guest pricing UI.

- New `WPISTIC_CF_Newsletter` class + `wp_WPISTIC_CF_subscribers`
  table.
- Public AJAX + REST endpoint + `[wpcf_newsletter]` shortcode.
- Admin "Newsletter" tab with search, filter, unsubscribe, CSV
  export.
- Contact-form opt-in checkbox auto-subscribes.
- Footer + blog newsletter forms wired live.
- Pricing + Memberships templates rewritten with per-tier guest
  pricing matrix.

---

## Earlier history

See git log on `claude/practical-hawking-LQW9g`:

```
git log --oneline --reverse claude/practical-hawking-LQW9g
```

Highlights from the first half of the engagement:
- Phase 1 security hardening (Memberistic content restriction
  server-side, Stripe constants, WP user creation deferral).
- Phase 2 Booking Engine payment idempotency + reminder cron fix.
- Theme accessibility pass (skip link, ARIA, focus trap).
- Brand sweep (Members Hub, FFL placeholder removal).
- WooCommerce + theme integration (cart/checkout, single-product,
  related products grid).
