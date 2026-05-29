# Guns 2 Ammo — Full System Audit & Work Report

_Date: 2026-05-29_

## Scope

Audited the whole bundle: `guns2ammo` theme, `g2a-booking-engine`,
`memberistic-membership-solutions`, `wpistic-contact-form`, `g2a-theme-control`,
plus the uploaded **Verifyistic** plugin. Delivered: the Ladies Tuesday booking
fix, an upgraded Verifyistic, an Ottertext decommission path, and this report.

## 1. Health check — what's in the system

| Component | Version | Status |
|---|---|---|
| guns2ammo theme | 1.21.0 | Healthy. Clean template/inc separation; SEO/AEO, schema, llms.txt, robots, sitemap all wired. |
| g2a-booking-engine | 1.10.0 | Healthy + extended (see §2). Modular: payments, email automation, AI autoreply, PDF invoices, migration adapters, Memberistic/PMPro/Woo bridges, **Verifyistic module**. |
| memberistic | 1.15.0 | Present, wired to theme + booking engine. |
| wpistic-contact-form | 1.5.0 | Present; theme forms bridge into its unified inbox. |
| g2a-theme-control | 1.0.0 | Meta-box/field config plugin for the theme. |
| Verifyistic | 1.1.0 | **Added to repo + upgraded** (see §3). |
| Insightistic | — | Absent, as intended (to be added later). |
| Ottertext | — | **No code footprint** — external embed only (see §4). |

PHP lint passes on every changed/added file. No fatal issues found in the audited
plugins. The booking engine already had solid safeguards (idempotency keys,
buffer/capacity overlap math, blackout rules, REST nonce + rate-limit gates).

## 2. Fix: Ladies Tuesday booking is now event-driven

**Problem (confirmed):** the Ladies Tuesday form reused the standard lane
calendar — every date for 90 days was bookable with generic business-hours
slots. Nothing tied it to the Ladies Tuesday events.

**Fix shipped (booking engine 1.10.0):**

- New REST endpoint `GET /g2ab/v1/event-availability` returns only the dates +
  time windows that have a published Event of a given type.
- The Ladies Tuesday calendar now unlocks **only event dates** (highlighted with
  a brass ring/dot) and shows **only that event's time slots**.
- `create_booking` is now **event-aware**: event-gated types validate the slot
  against the event window instead of generic business hours (blackouts still
  apply), so legitimate event bookings can't be falsely rejected.
- Generic + reusable: any booking type can be event-gated via shortcode
  (`source="events" event_type="…"`) or booking-type settings.

Full details + admin steps: `LADIES_TUESDAY_BOOKING.md`.

## 3. Verifyistic — audited & upgraded to 1.1.0

Audited the uploaded plugin, fixed a bug, and made it stronger + multi-platform.

**Fixed:** the old `fire_webhook()` had a duplicated, copy-pasted block and only
ever supported one webhook URL.

**New — stronger verification layer:**

- Per-IP rate limiting (anti age-guessing).
- Invisible honeypot field.
- HMAC-signed timing token (rejects instant-bot and replayed posts; no DB/cookie).
- Age sanity ceiling (> 119 rejected) + enforced 18 min-age floor.

**New — multiple webhook connections:**

- Unlimited destinations, each with its own URL, HMAC secret, enabled flag, and
  per-event subscription (`passed/failed/declined`).
- Delivery log table + automatic retry with capped backoff (up to 5 attempts).
- Legacy single-webhook auto-migrated to a "Primary" connection.
- Admin repeater UI with per-row Test button.

**Already-present integration:** the booking engine's Verifyistic module links
the verification token to bookings, can auto-accept the waiver, optionally
require verification before booking, and pre-fills the customer name.

Setup for Guns 2 Ammo: `VERIFYISTIC_SETUP_G2A.md`.

## 4. Ottertext — decommissioned

Ottertext has **zero code in this repo** — it's an external chatbot/age-popup
embed (header-scripts tool, GTM tag, theme-options box, or a companion plugin).
Removal is a config task. Shipped a safety-net scrubber
(`guns2ammo/inc/ottertext-cleanup.php`) that dequeues/strips any leftover
Ottertext asset while you remove it at the source and cut the data link.

Step-by-step: `OTTERTEXT_REMOVAL.md`.

---

## Top 10 Improvements to make Guns 2 Ammo a true premium range

1. **Unify the age gate → booking → waiver flow end-to-end.** Turn on
   Verifyistic "require verification before booking" + waiver auto-accept so a
   member never re-enters DOB/waiver twice. One identity, one record, zero
   double prompts.

2. **Promote Insightistic into a real analytics cockpit.** When you add it,
   pipe booking conversions, no-show rate, lane utilization, Ladies-Tuesday
   fill rate, and verification pass/decline ratios into one dashboard with
   week-over-week deltas.

3. **Smart lane availability + waitlist.** Add an auto-waitlist when a slot is
   full that texts/emails the next person the moment a lane frees (the "we'll
   text when a lane opens" promise on the Ladies Tuesday page should be a real
   feature, not just copy).

4. **Membership-aware dynamic pricing everywhere.** Surface member vs.
   walk-in price live in every booking widget (the engine already computes
   member discounts) and upsell membership at the moment of booking with a
   "you'd save $X as a member" nudge.

5. **First-class reminders + reduce no-shows.** SMS/email reminders at 24h and
   2h, one-tap reschedule/cancel links (the engine already has customer
   reschedule/cancel REST routes — wire them into reminder messages), and a
   deposit-on-booking option for high-demand slots.

6. **Verification → CRM automation via the new multi-webhook system.** Route
   `passed` to your CRM/email list (tagged by interest), `declined` to a
   re-engagement flow, and `failed` to a fraud/abuse review — each to its own
   platform now that multiple connections are supported.

7. **Self-hosted, premium UI polish on every plugin form.** The theme already
   has a brass/dark token bridge; extend it so Memberistic checkout, the booking
   confirmation, and the Verifyistic popup all share the exact same premium
   styling, micro-animations, and confetti success states.

8. **Trust + compliance layer front-and-center.** Public "safety first" badges,
   an FFL/transfer status tracker for customers, downloadable waiver/▾
   verification receipts, and an admin compliance export (verification log +
   booking + waiver in one CSV per date range).

9. **Performance + Core Web Vitals.** Self-host all remaining third-party
   scripts (Ottertext removal already helps), defer non-critical JS, preload
   the brand fonts, and add full-page caching rules that respect the
   booking/age-gate cookies. A premium range should load instantly on mobile.

10. **SEO/AEO content engine for local + intent capture.** Build out
    location/intent landing pages (CCW classes, first-time shooter, ladies day,
    corporate/group events, gun rentals) with FAQ schema and the events feed,
    so the range ranks for every high-intent local query and AI answer engines
    cite Guns 2 Ammo directly.

---

## Files changed in this pass

- `g2a-booking-engine/` — event-driven calendar (events helper, REST
  `event-availability`, event-aware `create_booking`, frontend calendar JS +
  CSS, shortcode wiring). Version → 1.10.0.
- `verifyistic/` — added to repo + upgraded to 1.1.0 (multi-webhook manager,
  security helper, hardened AJAX, admin repeater UI, popup/JS/CSS guard fields).
- `guns2ammo/inc/ottertext-cleanup.php` — new scrubber; theme → 1.21.0.
- `docs/` — this report + `LADIES_TUESDAY_BOOKING.md`,
  `VERIFYISTIC_SETUP_G2A.md`, `OTTERTEXT_REMOVAL.md`.
