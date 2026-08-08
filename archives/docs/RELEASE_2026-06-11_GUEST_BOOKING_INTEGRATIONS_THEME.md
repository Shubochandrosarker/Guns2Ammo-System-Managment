# Release 2026-06-11 — Guest booking fix, Integrations overhaul, POS + SMS bridges, Light/Dark theme

Components shipped in this release:

| Component | Version | Change type |
|---|---|---|
| Guns2Ammo theme (WPistic Theme) | 1.24.0 | Light/dark mode, header profile icon, mobile UX, preloader, booking-form skin |
| G2A Booking Engine | 1.13.0 | **Critical guest availability fix**, `site`/`ladies` form skins, banner redesign |
| Memberistic Membership Solutions | 1.45.0 | **Integrations toggle persistence fix**, POS Bridge, SMS module, Waiver Manager module |
| Messageistic | 0.5.1 | Integration hooks fixed (were dead), Twilio signature fix, webhook hardening — NEW in repo |
| G2A POS Core | 3.0.1 | Vendored PDF libs, graceful PDF errors — NEW in repo |

---

## 1. CRITICAL: guests saw "No times available on this date." on every date/lane

**Symptom (matches the 3-star Google review):** logged-out visitors on
`/book-a-lane/` got "No times available on this date." for *every* lane and
*every* date. Logged-in members saw time slots normally, and the same
member-blindness made it look like the membership wasn't recognized.

**Root cause:** the booking page is served from a page cache for guests
(logged-in users bypass the cache). The page bakes a `wp_rest` nonce into
its HTML, and the frontend attached that nonce as an `X-WP-Nonce` header to
**every** REST call — including the public `GET /availability`. WordPress
core rejects *any* REST request carrying an invalid/expired nonce with a
403 (`rest_cookie_invalid_nonce`) **before the route's own permission
callback even runs**. Once the cached page was older than the nonce
lifetime (12–24 h), every guest's availability request 403'd, and the
frontend rendered its catch-all "No times available on this date."
message. Logged-in users always got fresh HTML → fresh nonce → working
slots. That's exactly the reported pattern.

**Fixes (g2a-booking-engine 1.13.0):**

1. Public GETs (`/availability`, `/event-availability`) no longer send a
   nonce at all — they're public endpoints; the nonce added nothing but
   this failure mode.
2. New `GET /g2a-booking/v1/session` endpoint returns a fresh `wp_rest`
   nonce with hard no-cache headers. The booking submit now fetches a
   fresh nonce just-in-time before `POST /bookings` and retries once if
   the server still reports a nonce failure. Cached pages can no longer
   break guest bookings.
3. The payment-return handler (`assets/js/frontend.js`) stopped sending
   the stale page nonce to the token-gated status/confirm endpoints
   (same core-level 403 risk).
4. A failed availability request now shows "We couldn't load times just
   now — please refresh the page and try again." instead of masquerading
   as an empty day, so any future failure is visible instead of silently
   losing bookings.

**Verify:** open `/book-a-lane/` in a private window (logged out), pick
any lane + date → time slots appear; complete a booking as a guest.

---

## 2. Memberistic: Verifyistic toggle wouldn't stay ON (Integrations tab)

**Root cause:** `register_setting()` wires `sanitize_settings()` into the
`sanitize_option_memberistic_settings` filter, which runs on **every**
`update_option('memberistic_settings', …)` call — including the
Integrations page's own save handler. The sanitizer returned a fixed
allowlist that did not contain any `integration_*` key, so the Verifyistic
toggle (and friends) was stripped at the exact moment it was saved.

**Fixes (memberistic 1.45.0):**
- The sanitizer now persists every Integrations Registry toggle, the
  Verifyistic sub-options, `email_reply_to_address`,
  `verifyistic_max_age_days`, and passes through unknown scalar keys
  (other modules store keys in the same option — they were silently
  wiped on every unrelated settings save).
- The React Settings → Integrations tab (which previously showed only
  WooCommerce) now renders **every** module from a `_integrations`
  snapshot returned by `GET /memberistic/v1/settings`, with availability
  / coming-soon states and the Verifyistic sub-options.

## 3. Memberistic Integrations: new modules

- **Waiver Manager** (default ON) — the in-house waiver system that
  replaced the legacy PMPro/ApproveMe "Guns2Ammo Waiver Manager" plugin is
  now a first-class module card (was a "Waiver Provider — coming soon"
  placeholder). Tokenized member signing, guest + kiosk surfaces,
  immutable signature archive, expiry tracking; the toggle gates the
  booking-engine check-in mirror.
- **POS Bridge** (default OFF; requires G2A POS Core) —
  `includes/integrations/class-pos-bridge.php`:
  - Implements the POS `g2a_pos_membership_lookup` filter → cashiers see
    live Memberistic plan / status / expiry / benefits on the customer
    profile and at the counter. Memberistic becomes the POS membership
    provider of record (overrides PMPro/MemberPress auto-detect).
  - Implements `g2a_pos_membership_bookings` → the customer's upcoming
    range bookings (from the booking engine) appear in POS range ops.
  - Stamps `pos_customer_id` on membership rows on create/activate.
- **SMS Notifications (Messageistic)** (default OFF; requires
  Messageistic) — single switch that authorizes all membership + booking
  texting (see §4).
- New lifecycle hooks for add-ons:
  `memberistic_membership_expiring($id, $days_out)` — fired from the
  renewal-reminder scheduler at the 30/7/1-day windows (deduped with the
  email log), and `memberistic_membership_expired($id)` — fired on
  auto-expiry.

## 4. Messageistic 0.5.1 (new in repo) — local-gateway SMS engine

Audit found the plugin architecturally solid (provider adapters: local
Android **SMSGate** gateway, Jasmin, OtterText, Twilio, Testing; queue,
templates, automations, consent tracking) with three real defects, all
fixed:

1. **Dead integrations:** the Memberistic and G2A Booking adapters
   listened for hooks that don't exist (`memberistic_member_created`,
   `g2a_booking_created`) and expected array payloads the real hooks
   never pass. Rewritten to consume the actual hooks
   (`memberistic_membership_created/activated/expiring/expired`,
   `g2ab_booking_created/confirmed/cancelled/paid`), hydrate the
   membership/booking rows from the DB, upsert the contact, and emit
   `messageistic_trigger` events (`membership.*`, `booking.*`) with
   template merge vars (`{membership_name}`, `{booking_date}`,
   `{booking_time}`, …). Both adapters respect the Memberistic
   "SMS Notifications" module toggle.
2. **Twilio webhook signature verification** validated against WP REST's
   merged params (route vars + query + body) instead of the exact
   form-encoded `$_POST` fields Twilio signs → legitimate callbacks were
   rejected. Now verifies against `$_POST`.
3. **Webhook surface hardening:** only the *active* provider's webhook
   routes dispatch (filterable via
   `messageistic_webhook_allowed_providers`); each provider still
   verifies its own signature/shared secret (SMSGate: token + HMAC).

**Setup for booking/membership texts:** Messageistic → Settings →
provider = SMS Gateway (local Android app) → register device webhooks;
Memberistic → Integrations → enable "SMS Notifications (Messageistic)";
Messageistic → Automations → create automations on the
`membership.activated`, `membership.expiring_soon`, `booking.created`…
triggers with approved templates.

## 5. G2A POS Core 3.0.1 (new in repo)

Full audit: 80+ tables, 281 REST routes, compliance (4473/NICS/bound
book/NFA), CRM/loyalty, range ops, wholesalers, queue — production-grade.
Two defects fixed:

- `composer` vendors (`setasign/fpdf`, `setasign/fpdi`) were declared but
  not shipped → any 4473 PDF render fataled. **vendor/ is now vendored
  with the plugin** and `Form4473Pdf::render()` raises an actionable
  RuntimeException (caught by the REST controller → clean 422) instead of
  a fatal if the libraries are ever missing again.
- Membership integration is now configured: with the Memberistic POS
  Bridge enabled, `RangeMembership::status_for_customer()` resolves via
  the filter override (priority 1 in its chain) — no provider pinning
  needed.

## 6. Theme 1.24.0 — speed, light/dark, mobile, booking-form look

**Speed**
- Preloader is now a ≤400 ms brand flash (inline ceiling 400 ms,
  chrome.js safety net 800 ms, dismissed on `pageshow` for bfcache
  restores). Content always paints underneath — the preloader never
  blocks reading.
- All header/profile/drawer/footer chrome surfaces moved onto cacheable
  CSS tokens (no behavioral change, enables theming below).

**Light/Dark mode (system-aware + manual toggle)**
- `header.php` stamps `<html data-theme="light|dark">` *before CSS
  applies* (no flash): saved visitor choice → OS `prefers-color-scheme`
  → dark default. While the visitor hasn't chosen manually, OS scheme
  changes are mirrored live.
- New sun/moon toggle button in the header (`#g2a-mode-toggle`), persists
  to `localStorage('g2a-theme')`, updates `<meta name="theme-color">`.
- Full light palette in `tokens.css` (warm bone + paper surfaces, darker
  brass/ember for AA contrast), `color-scheme` set per mode so form
  controls/scrollbars follow. Every token-driven component flips
  automatically — including the Memberistic bridge styles, the booking
  widget (`site` skin), event cards, and the countdown banner.

**Header / mobile**
- Profile control finally shows an icon: guests get a person glyph in a
  brass disc (was an empty circle + bare arrow); members keep their
  initials, now on the same styled disc. `aria-expanded` wired.
- Mobile drawer: staggered link entrance animation, sticky footer with
  "Book A Lane" + Sign In/My Account CTAs and the phone number; calendar
  day-grid gaps widened on small phones.

**Booking form look**
- New `site` skin in the booking engine inherits the Guns2Ammo tokens —
  brass/ember, theme fonts, and automatic light/dark — replacing both the
  generic blue "midnight" look and the theme's old blanket `!important`
  recolour layer. Applied via the `g2ab_form_design_tokens` filter.
- New `ladies` skin: a deliberately distinct rose-gold-over-plum take for
  the Ladies Tuesday flow (auto-applied on the Ladies Tuesday template;
  also available as `theme="ladies"` on any booking shortcode).
  Light-mode aware.
- Event countdown banner (`[g2a_event_banner]`) redesigned: token-driven
  premium look (crosshair grid + radial accents instead of camo stripes,
  tabular-numeral countdown cells, animated CTA arrow), with a
  `--type-{slug}` variant system; `ladies-day` ships rose-gold in both
  modes.

---

## Deployment notes

1. Update theme + all five plugins from the rebuilt zips (see
   `releases/`). Install order for the new ones: G2A POS Core →
   Messageistic → (existing) Memberistic update.
2. **Flush the page cache after deploying** — the guest-booking fix
   specifically de-fangs stale cached HTML, but old cached pages still
   reference the previous JS until purged.
3. In Memberistic → Integrations: confirm Verifyistic now stays ON after
   save; enable POS Bridge and SMS Notifications when those plugins are
   active.
4. POS Core requires PHP 8.1+. Its `vendor/` ships in the zip — no
   composer step needed on the server.
