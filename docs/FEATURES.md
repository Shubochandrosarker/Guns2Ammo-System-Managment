# Guns 2 Ammo — Feature Inventory (as of theme 1.18.0)

This is the live capability snapshot. Use it to brief the client,
auditors, or new developers in 10 minutes.

## 1. Public site (theme + page templates)

### Layout / brand
- Tactical-luxury dark palette (void / gunmetal / brass / ember).
- Self-hosted brand fonts (Bebas Neue display, Barlow + Condensed,
  DM Sans, Space Mono) — no Google Fonts request, no PII leak to
  third parties.
- Preloader with instant DOMContentLoaded handoff + 900 ms hard
  ceiling (was 4 s — caused visible stalls).
- Sticky nav with live Open/Closed pill driven by **Mesa, AZ
  (America/Phoenix)** business hours regardless of visitor TZ.
- Mobile drawer with ARIA + focus trap.
- Skip-to-content link inlined in `<head>` with screen-reader
  recipe (1 × 1 clipped box) so it never visually shows.
- Profile icon present on mobile (was hidden under 1100 px).

### Pages
- Home (`/`)
- Memberships + Pricing (`/memberships/`, `/pricing/`) with the
  full **per-tier guest pricing matrix** (Defender $15, Patriot
  $10, Guardian $10 per extra shooter; primary gets 1 hr free).
- Machine Gun (`/machine-gun/`) — admin-editable inventory
  repeater (image, caliber, RPM, magazine, category, price,
  description, hero-row flag, detail URL).
- Transfers (`/transfers/`) with FFL block (auto-hidden when the
  FFL license number is empty in Customizer).
- Sell Your Gun, Training, CCW (`/arizona-ccw-certification/`,
  `/california-ccw-shooting-qualification/`), Range Safety, About,
  Contact, Blog.
- **FAQs** (`/faqs/`) — new. Smooth-dropdown list grouped by
  topic + FAQPage JSON-LD for AI / rich-result eligibility.
- Member login (`/login/`), Account dashboard (`/account/`),
  Memberships shop, Booking, etc.

### SEO / AEO (theme-owned, RankMath-coexisting)
- LocalBusiness + SportsActivityLocation + Store JSON-LD on every
  page (hours, address, geo, rating, area-served, knowsAbout,
  membership offer catalog, sameAs).
- BreadcrumbList JSON-LD on every singular + archive.
- Article JSON-LD on blog posts.
- FAQPage JSON-LD on `/faqs/`.
- Open Graph + Twitter card on every page.
- Canonical link emitted from the configured site host (NOT the
  attacker-controllable `Host:` header).
- www → non-www 301 redirect at the PHP layer (catches anything
  the host config doesn't already canonicalise).
- 16+ legacy / typo URLs 301'd to live equivalents (built from the
  May 2026 broken-links audit; full list in
  `guns2ammo/inc/redirects.php`).
- `/sitemap.xml` — branded master sitemap index (mirrors the
  wordpressistic.com pattern) with sub-sitemaps for pages, posts,
  products, product categories, and FAQs.
- `/llms.txt` — short LLM-friendly summary (brand, hours, services,
  pricing, key links).
- `/llms-full.txt` — full text dump of every public page + FAQs +
  recent blog posts for AI grounding.
- `/robots.txt` — theme-owned. Explicitly allows GPTBot,
  ClaudeBot, anthropic-ai, PerplexityBot, Google-Extended,
  Applebot-Extended, OAI-SearchBot, ChatGPT-User, CCBot, Bingbot,
  YouBot. Blocks account/cart/checkout/login/admin from all bots.
  Sitemap + llms.txt linked at the bottom.
- **RankMath conflict guard** — when RankMath is active, theme
  disables RM's JSON-LD, OG, Twitter, canonical, and sitemap
  outputs. RM stays responsible for `<title>`, `<meta
  description>`, keywords, robots toggles, redirections module,
  404 monitor.

### WooCommerce
- Custom single-product, archive, cart, and checkout templates
  styled to the brand.
- Coupon row + Update Cart button align cleanly on cart page
  (was misaligned; disabled state was a "ghost grey blob").
- Checkout terms-and-conditions checkbox: 22 px brass-bordered
  with clear filled state (was nearly invisible).
- Checkout select2 dropdowns (Country / State) match input
  height + dark theme (was overflowing on Arizona).
- Per-template responsive: stacked cards under 620 px on cart,
  stacked columns under 980 px on checkout + single-product.
- Related-products card titles clamped to 2 lines (was huge multi-
  line headings on some installs).
- Quick-view modal mounted at footer.
- "Book Another Lane" CTA + confetti + recap card on booking
  confirmation.

## 2. Memberistic (membership plugin)

- 3 plans seeded: Defender / Patriot / Guardian (monthly + annual)
  with per-tier guest pricing wired into the data model.
- Member account dashboard (`/account/`) with tabs:
  Dashboard · Book a Lane · Membership Details · Billing & Payments
  · Additional Members · Booking History · Digital Member Card ·
  Sign Out. Mobile-responsive (2-up nav at ≤680 px, 1-up at ≤420 px).
- **Book A Lane in-dashboard modal**: clicking the dashboard tile,
  sidebar tab, or "Open Booking Form" CTA opens a full-width
  modal hosting the live booking-engine shortcode. Stacks the
  booking-engine's info card above the date / lane picker so the
  calendar gets the full row (no more Saturday/Sunday cut-off).
- **Member profile photo upload** (REST POST/DELETE
  `/memberistic/v1/profile/image`, 5 MB cap, MIME-checked). Photo
  shows on avatar and the downloadable digital card.
- **Dynamic verification QR**: card QR encodes
  `/?memberistic_verify=TOKEN`. Token resolves server-side to a
  branded verification card with the member's LIVE plan + status
  + photo. QR carries zero PII.
- **Digital card download**: opens a self-contained popup window
  with a print-ready light-themed card (cream background, brass
  stripe). Includes logo (from theme Customizer Site Identity),
  photo, name, plan, member ID, since/renews, QR. Auto-fires
  `window.print()` so the user gets Save-as-PDF immediately.
- **Branded URLs end-to-end**: emails and all internal redirects
  use `/account/`, `/memberships/`, `/book-a-lane/` — never the
  legacy `memberistic-*` slugs. Legacy slugs are caught by the
  page-URL helper's legacy-list and bounced.
- **Brand label auto-migration**: installs default to the WP site
  name (was hard-coded "Memberistic"); existing installs with the
  literal string "Memberistic" are auto-flipped on plugin upgrade.
- **Login form fix**: form POSTs to `wp-login.php` via the
  `login_post` site_url scheme so it bypasses the `login_url`
  filter that surfaces the branded `/login/` URL. Theme guard
  allows all POSTs + login-flow GET actions to wp-login.php.
- **Post-login redirect**: theme `login_redirect` filter at
  priority 999 forces members + non-staff to `/account/`. Staff
  keep WP default (wp-admin).
- **Email branding sweep**: activation, renewal, reminder, expired,
  payment-failed, cancelled — all use `/account/`, `/book-a-lane/`,
  `/login/`. No "memberistic-*" URLs in customer-facing email.
- Stripe webhook event-id idempotency; Stripe secret-key constant
  overrides; REST masking; `_locked_secrets` indicator.

## 3. G2A Booking Engine

- Lane booking via `[g2a_lane_booking]` shortcode (split + stacked
  layouts; theme uses stacked inside the member-dashboard modal).
- Resources + booking types seeded.
- Public REST read endpoints rate-limited (60/min/IP, filterable).
- Payment-row UNIQUE KEY `(gateway, transaction_id)` + race-safe
  upsert across Stripe / PayPal / Authnet.
- Email kill-switch (`G2AB_EMAIL_DISABLED`), recipient override.
- Reminder cron TZ math fix; cron unscheduled on deactivation.
- Schema migration v1.5.0 collapses pre-existing duplicate rows.

## 4. Formistic — contact forms, inbox, newsletter, AI auto-reply (2.1.0, DB 1.3.0)

The site's sole contact-form/inbox/newsletter solution (the older "WPistic
Contact Form" plugin has been retired and removed — see
`docs/FORMISTIC_G2A_SETUP.md`).

- Fixed-field contact form (`[wpistic_contact_form]`) and a full drag-free
  visual form builder (`[wpistic_form id="N"]`, 13 field types) with a
  branded admin inbox, unified per-sender "Threads" view, and reply
  templates.
- Auto-responder with a 1-hour-per-recipient transient throttle, now sent
  as a branded HTML email (rounded card, brand-underlined header, full NAP
  footer) instead of plain text.
- Email kill-switch (`WPISTIC_FORMISTIC_EMAIL_DISABLED`).
- Spam stack: honeypot, reCAPTCHA v3, Cloudflare Turnstile, Akismet, IP
  blocklist, and a MySQL-advisory-lock-guarded per-IP rate limiter (closes
  the lost-update race a plain transient counter has under a concurrent
  burst).
- GDPR consent capture/export/eraser + auto-purge, webhooks with HMAC
  signing, CSV/JSON export with CSV-injection neutralization, upload
  attachments with a double-extension smuggling guard.
- **Newsletter system**: dedicated subscribers table, public AJAX + REST +
  `[wpistic_formistic_newsletter]` shortcode. Admin "Newsletter" tab with
  search, Active/Unsubscribed filter, per-row unsubscribe, CSV export.
  Contact-form opt-in checkbox auto-subscribes. 60-second per-IP throttle.
  Resubscribe re-activates instead of erroring. A branded welcome/
  confirmation email is sent on subscribe, with a stateless HMAC one-click
  unsubscribe link and RFC 2369/8058 `List-Unsubscribe` headers (Gmail/
  Yahoo native unsubscribe affordance).
- Footer "GET RANGE UPDATES" + blog "Range Brief" forms wired live.
- AI layer: FAQ/knowledge-base seeding from the theme's live business facts,
  keyword auto-reply rules, smart-reply drafts + spam scoring + tagging via
  OpenRouter (with an SSRF-guarded URL text-source fetcher and a local
  rule-based fallback if the provider call fails).
- AI moderation hooks for spam.

## 5. G2A Theme Control

- Side metabox plugin used by the theme for per-page editable
  data (machine-gun inventory repeater, hero text, image fields,
  etc.).
- Repeater image fields use WP Media Library.
- Save handler sanitizes per subfield type. 200-row cap.

## 6. Security / hardening

- `/wp-login.php` hidden behind `/g2a-admin-login/`. Non-staff
  GETs to wp-login.php are bounced to `/login/`. POSTs are
  allowed through (so members can authenticate).
- `/wp-admin/` blocked for non-staff (subscribers + members get
  bounced to `/account/`).
- WP defaults removed from `<head>` (rsd, wlwmanifest, feed_links_extra,
  shortlink, rest_output_link).
- Stripe secret keys can be locked via wp-config constants
  (`MEMBERISTIC_STRIPE_*`).
- Memberistic content restriction via `the_posts` server-side
  redaction (no view-source / RSS / REST / OG / LD-JSON bypass).
- WP user creation deferred to Stripe webhook (no email-spray vector).

## 7. Performance

- Per-template CSS extracted from inline `<style>` to enqueued
  files for browser caching.
- Lazy-loaded images with fade-in.
- IntersectionObserver-based reveal-on-scroll.
- DOMContentLoaded preloader handoff (~ first-paint).
