# Release 2026-06-12 — Round 2: Light mode, animations, real data, POS width fix

| Component | Version | Headline |
|---|---|---|
| Theme | 1.25.0 | Light-mode hero fix, animations, FAQ, map, live reviews, real roster |
| Booking Engine | 1.14.0 | Live lane status, slot notices, admin detail redesign, light-mode calendar |
| POS Core | 3.0.2 | Half-width bug root-caused (WP core `.card{max-width:520px}`), Memberistic provider, shop notice fix |
| WPistic Contact Form | 1.5.2 | Branded newsletter welcome email (logo header, dedupe, settings) |

## Fixed (user-reported)
- **Light mode heroes unreadable** → photo heroes now keep their dark cinematic treatment in both modes (token re-pin inside hero containers).
- **POS blocks at half width** → WP core admin ships `.card { max-width: 520px }`; the POS Tailwind `.card` never reset it. Neutralized inside the app root; Tailwind paddings restored.
- **POS Memberships tab showed PMPro** → new `MemberisticProvider` registered first in the provider chain; detected + used automatically, feeds plan/status/expiry + upcoming bookings to the counter.
- **Shop PHP notice next to prices** → `MapPricingService` read the WC-internal `_global_unique_id` via generic `get_meta()`; now uses `get_global_unique_id()`.
- **Shop filters dead** → the page-template form posted `s=` which turns the request into a WP search; renamed to `g2a_q` + `WC()` guard.
- **"4 of 6 lanes open" fake widget** → new `GET /g2a-booking/v1/lanes-status` (live occupancy, 60s cache); book-a-lane + about widgets fetch real data and hide if unavailable.
- **Booking form** → "X of Y time slots still open on this date" note + friendly support note ("Drop us a message…") linking to /contact/, filterable via `g2ab_booking_help_note`.
- **Admin booking page (MISSION BRIEF)** → readable header (customer name + status + confirmation), plain-language section labels.
- **Events calendar dark-on-light** → all event views (`.g2a-ev*`, countdown, cards) tokenized with fallbacks + glossy calendar cells.
- **Arrow icons** → literal `->` and font-dependent `→` replaced with inline SVG arrows (footer JOIN, quick-view, banner CTA) + hover nudge.
- **robots.txt GSC errors** → removed `Crawl-delay` and invalid bare llms.txt URL lines.
- **Sitemap page** → links `/sitemap.xml` (was RankMath's `/sitemap_index.xml`); fake shop entries removed.
- **"Visa 4242" demo card public** → memberships preview gated to admins.
- **Two fake staff rosters** → single real roster (Nicholas Steigert, Alen Olson) via new `inc/instructors.php` (option/filter-based include–exclude system, Person JSON-LD), rendered on /about/ + /training/.
- **Dash corruption** → 6–9 months, 3–5 days, 4–6 weeks, 9×19mm, 7.62×39, $15–35, Saturday 1–3, class sizes 1–6/4–10/4–8/6–12/4–20 restored across 7 templates.
- **Money facts** → CCW card $120/8hr → $85/4hr + $149.99 live-fire option (per the instructor's course doc); FFL $25 → $35; NFA $95/$295; "Since 2018" → 2014; "$75 value" → $85; "open six days / Friday closed" → open 7 days.

## Added
- **Member profile photo in header** (Memberistic upload → avatar, initials fallback).
- **Animations**: scroll-reveal variants (left/right/zoom), stagger containers, count-up stats, brass underline draw, card lift, button-arrow nudge — all reduced-motion-safe.
- **Homepage FAQ section** (6 answers, FAQPage JSON-LD, animated accordion).
- **Footer Google Map** (keyless embed, lazy) with animated drop/bob brand pin linking to directions.
- **Live Google review count**: twice-daily Places API cron (key + Place ID in Customizer) transparently overrides `g2a_review_count`/`g2a_review_rating` theme mods — footer, schema, templates all update automatically. Manual Customizer numbers remain the fallback (default now 556 @ 4.7).
- **Newsletter welcome email** (WPistic CF): premium branded shell (logo header, brass rules, ember CTA, preheader) on `wpcf_newsletter_subscribed`, deduped, toggle + subject settings, reusable `WPISTIC_CF_Emails::wrap()` for future emails.
- **Redirects**: 2023 cannibal post 301, contact.html/shop.html legacy fixes, ~20-entry guessed-URL → money-page map.

## Still open (next round)
- POS: WooCommerce product backfill/scan, customer CSV export, Lipsey's API audit, AI default knowledge seeding, FFL/waiver data on customer profiles.
- Full per-page titles/metas + AZ CCW page 1,200-word rebuild + Ladies Tuesday copy rewrite (facts are fixed; the long-form copy build-out from 04OPT1.MD remains).
- Booking plugin frontend calendar glass-polish pass and banner art directions per event type.
- Theme dynamic content editing system (client-editable sections).
