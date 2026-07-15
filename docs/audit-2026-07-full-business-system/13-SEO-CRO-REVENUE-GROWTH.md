# SEO, AEO, CRO, and Revenue Growth

## What's actually there (verified this session, not assumed)

`guns2ammo/inc/seo.php` (621 LOC) is a genuinely substantial, well-built schema/SEO layer:
- **LocalBusiness JSON-LD**, combined `@type` of `['LocalBusiness', 'SportsActivityLocation', 'Store']` — an appropriately specific type choice for an indoor shooting range that is also a retailer, not a generic business type
- Real `PostalAddress` and `GeoCoordinates`, sourced from the same `g2a_biz()` single source of truth verified in `03-CLIENT-REQUIREMENTS-GAP-MATRIX.md` — meaning schema data and visible page content cannot drift apart from each other, even if they could still drift from the *correct* real-world value if the underlying option is wrong
- `OfferCatalog` with **real membership pricing** (Defender/Patriot/Guardian tiers) rather than a generic placeholder — this is the kind of detail that actually helps rich-result eligibility and AI-answer-engine citation
- `AggregateRating`, `BreadcrumbList`, `Article` schema (for blog/content pages), `FAQPage` schema
- A deliberate RankMath-conflict guard (code comment at line 235 references avoiding "LocalBusiness JSON-LDs fighting in `<head>`" if RankMath is also active) — shows awareness of a real, common WordPress SEO-plugin collision failure mode
- `inc/llms.php` (157 LOC) — dedicated `llms.txt`/AEO support, confirming the team has already invested in answer-engine optimization ahead of most WordPress sites

**This is not a weak area of the system.** The client's own report ("SEO performance has been good") is consistent with what this audit found in source.

## What was not verified this session

- Actual keyword rankings, traffic, or Search Console data — no live tools were available/authorized in this session (Ahrefs/GSC-style tools require live property access this audit did not have).
- Sitemap generation, canonical tag correctness, robots.txt content — `inc/robots.php` exists (confirmed present, found during the login/cache-cache investigation's file search) but its contents were not read this session.
- Whether the schema actually validates cleanly (no live rich-results test was run).
- Class landing pages, membership conversion pages, and category-page SEO copy quality — not read this session.
- Google Merchant Center feed status — not checked.

## The actual problem, per the client's own framing: not traffic, conversion

The client's own words are precise and should be taken at face value: strong SEO, strong range usage, weak retail sales, in a seasonally slow month (Arizona summer). This is **not a code defect** — it's a conversion/marketing-sequencing gap, correctly identified as such in the prior status document and confirmed by this audit's own review of what marketing infrastructure exists vs. what's actually built on top of it.

### What exists (infrastructure)
- Formistic: contact capture, newsletter, unsubscribe
- Messageistic: SMS, consent tracking (`Consent_Event_Repository`), campaign/automation repositories, conversation notes
- Booking Engine + Memberistic: rich behavioral data already being captured — check-ins, membership status, class attendance, renewal dates

### What does not exist (the actual gap)
- **Segmentation logic** for the specific personas the client named: active member, expired member, range visitor (has check-ins, no WooCommerce orders), class attendee, retail/firearm/ammo buyer, FFL transfer customer, newsletter subscriber with no purchase, lapsed customer, high-value customer, customer with an unsigned waiver, customer with an expiring membership. The **data to build every one of these segments already exists** across the tables this audit inventoried (`memberistic_people`/`memberistic_memberships` for member/expiry status, booking check-in records for range visits, WooCommerce orders for retail purchases, `memberistic_waivers_archive` for waiver status) — what's missing is the segmentation query layer and the campaign trigger logic on top of it, not the underlying data.
- **Campaign templates/automation** for the flows the client asked about.

## Recommended campaigns (grounded in confirmed data availability)

| Campaign | Segment definition (buildable from confirmed tables) | Trigger |
|---|---|---|
| Range visitor → retail buyer | Check-ins ≥ N in last 60 days, zero WooCommerce orders | Scheduled or check-in-triggered |
| Range visitor → member | Non-member with ≥ N check-ins (guest/walk-in pattern) | Scheduled |
| Member → class attendee | Active membership, zero class bookings on file | Scheduled |
| Class attendee → repeat customer | Class booking on file, no repeat booking in 90 days | Time-since-last-class trigger |
| Lapsed-member recovery | `memberistic_memberships.status = 'expired'`, expired within last N days | On expiry + follow-up cadence |
| Ladies Day / Summer indoor-range promotion | All active + recently-lapsed, filtered by any stored gender/preference field if one exists (not confirmed) | Seasonal/manual send |
| Suppressor/NFA services | Customers with any NFA-flagged product interest (`g2a_pos_core`'s NFA-related repositories confirmed present in the file inventory) | Manual/targeted |
| FFL transfers | `g2a_ffl_transfers` customers, cross-sell ammo/accessories | Post-transfer follow-up |
| Review requests | Post-visit or post-purchase, N days after | Time-since-visit trigger |
| Abandoned booking | Booking started, not completed (would need to confirm Booking Engine tracks incomplete booking attempts — not verified this pass) | Cart/booking-abandonment trigger |
| Abandoned membership signup | Memberistic's rate-limited checkout flow implies partial-signup state is at least reachable in principle — not confirmed whether incomplete signups are currently tracked/queryable | Signup-abandonment trigger |

## Consent and delivery hygiene (confirmed infrastructure, unconfirmed enforcement)

Messageistic has `Consent_Event_Repository` — confirmed to exist, not independently traced to confirm STOP-keyword handling, quiet-hours enforcement, or frequency capping are actually wired into a send pipeline this pass. Given the client's stated intent to run an "email and SMS social media blitz," **this should be the first thing verified live before any campaign volume increases** — TCPA/CAN-SPAM consent and quiet-hours compliance risk scales directly with send volume, and this audit cannot certify the enforcement logic from a repository-presence check alone.

## Tracking and attribution requirements

Not found this pass: campaign-to-revenue attribution (which campaign drove which WooCommerce order, membership signup, or booking). This is the natural next building block after segmentation/campaigns exist — recommend UTM-or-equivalent tagging on every campaign link, landing on a per-campaign revenue report as a Phase 3 deliverable (see `14-ADVANCED-SYSTEM-ROADMAP.md`).

## Score: SEO/AEO — not scored (insufficient live data); Conversion/marketing automation — 25/100

The low conversion-automation score reflects that the **segmentation and campaign layer does not exist**, not that the underlying data or delivery infrastructure is weak — both of the latter are confirmed present and reasonably well-built. This is one of the highest-leverage, lowest-risk investments available given how much of the hard infrastructure work is already done.
