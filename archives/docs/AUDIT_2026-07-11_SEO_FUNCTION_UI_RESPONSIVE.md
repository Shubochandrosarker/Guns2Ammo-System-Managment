# Guns 2 Ammo — Full-System Audit: SEO/AEO, Functional, UI, Responsive

_Date: 2026-07-11_ · _Scope: entire repo (theme, 10 plugins, dashboard-app SPA, Cloudflare worker)_ ·
_Method: static source-code review across every plugin/theme/app in the repo. `https://guns2ammo.com`
returned HTTP 403 to automated fetches from this environment, so nothing here is a live crawl — every
finding is grounded in file:line references you can open and verify directly. Items that need a live
check (Rich Results Test, PageSpeed Insights, GSC, an actual iPhone) are called out explicitly._

This report combines four independent deep-dive audits into one document, then closes with a single
ranked improvement list for the business. Severity throughout: **Critical** (money/compliance/security
exposure or a broken core flow) → **High** (real user- or revenue-impacting defect) → **Medium**
(meaningful but contained) → **Low** (polish/hygiene).

---

## Executive summary

The system is larger and more mature than a typical single-site WordPress build — 10 custom plugins plus
a React staff dashboard, with real engineering discipline in several places (booking-capacity locking,
webhook signature verification, WooCommerce responsive fixes, the mobile drawer's accessibility). But the
audit surfaced **five issues that belong at the top of the list regardless of anything else in this
document**, because they touch money, compliance, or security directly:

1. **✅ FIXED (2026-07-11).** Age verification is not enforced anywhere a firearm is actually purchased or booked, by default. (Functional §1)
2. **✅ FIXED (2026-07-11).** Waiver expiry (1-year policy) is not checked at the booking gate — an expired waiver still lets someone book/check in. (Functional §2)
3. **✅ FIXED (2026-07-11).** NICS 3-business-day math ignores federal holidays, so the "3-day rule reached" flag can fire early. (Functional §3)
4. **✅ FIXED (2026-07-11).** `/llms.txt` and `/llms-full.txt` — the files built specifically to ground AI answer engines — are silently shadowed by dead legacy code and serve stale, inaccurate business info to every AI crawler that requests them. (SEO §5)
5. **✅ FIXED (2026-07-11).** An unauthenticated endpoint leaks internal FFL dealer notes and can be scraped to exfiltrate the entire dealer database. (Functional §16)

Everything else in this report is real and worth fixing, but these five were addressed first. See the
**"Fixes shipped 2026-07-11"** section at the end of this document for exactly what changed, in which
plugin, and at which new version number.

---

## Part 1 — SEO / AEO Audit

### 1. Structured data (JSON-LD)

| Severity | Finding | File:line |
|---|---|---|
| **High** | Product schema is fully disabled site-wide. The RankMath-overlap guard blanket-disables `rank_math/json_ld` and `rank_math/schema/output` with no product exception, while the theme's own Product JSON-LD self-disables whenever an SEO plugin is active. Net effect: **every product page emits zero Product schema.** | `guns2ammo/inc/woocommerce.php:34`, `inc/seo.php:236-238` |
| Medium | Two disconnected top-level entities (`Organization` from the dead `aeo.php` and `LocalBusiness/Store` from `seo.php`) with no `parentOrganization`/`sameAs` link between them — Google may resolve two competing "Guns 2 Ammo" nodes. | `inc/aeo.php:18-49`, `inc/seo.php:362-418` |
| Medium | LocalBusiness/Article fallback image URLs (`g2a-storefront.jpg`, `g2a-logo.png`) don't match any real asset filename in the theme — likely 404, which can invalidate rich-result eligibility. Needs a Rich Results Test check. | `inc/seo.php:367,478` |
| Low | Product `Offer` is missing `priceValidUntil`, `shippingDetails`, `hasMerchantReturnPolicy` — increasingly required for full Product rich-result eligibility. | `inc/woocommerce.php:45-62` |
| **Done well** | `aggregateRating` only emits when a real rating+count exist (never fabricated); `openingHoursSpecification` correctly omits closed days; FAQPage schema is deliberately scoped to exactly two places with a guard against a third duplicate. | `inc/seo.php:428-436,345-360`; `inc/faqs.php:213-253` |

### 2. Meta tags

- Canonical URL generation is genuinely host-based (not the spoofable `Host:` header) — confirmed. It does, however, drop the entire query string, which quietly canonicalizes `/?s=ammo` search results to `/` (harmless since WP core already noindexes search, but cosmetically wrong). `inc/seo.php:292-299`
- OG/Twitter tags lack `og:image:width/height`/`twitter:image:alt` — low-cost fix for reliable social-card rendering. `inc/seo.php:279-289`
- **Done well**: `wp_robots` correctly noindexes account/cart/checkout by both slug and function check; meta-description fallback logic has no generic boilerplate fallback.

### 3. Sitemaps

- `lastmod` on the master sitemap index is stamped `gmdate('now')` on every request regardless of whether content actually changed — a weak freshness signal to Google. `inc/sitemap.php:140,148`
- Product sitemap pulls the entire catalog in one file with no pagination — fine today, will hit the 50,000-URL sitemap-protocol cap as the catalog grows. `inc/sitemap.php:212-234`
- **Landmine, not a live bug**: `inc/aeo.php:279-306` contains a second, fully-formed, hand-curated `/sitemap.xml` handler that is dead code today only because `inc/sitemap.php` intercepts earlier in the request lifecycle. Any future refactor of that intercept order silently re-exposes a stale, drift-prone sitemap.

### 4. robots.txt

- **Done well**: the live `inc/robots.php` deliberately wins the race against RankMath via a `parse_request` priority-0 intercept plus a `PHP_INT_MAX` fallback filter. No crawl-delay, no CSS/JS blocking.
- `inc/aeo.php` contains a second, dead robots handler with a *richer* AI-bot allowlist (Amazonbot, cohere-ai, Bytespider, Meta-ExternalAgent, Perplexity-User, DuckDuckBot, YandexBot) than the live file — low-cost fix: merge those bot names into the live `robots.php`.

### 5. llms.txt / llms-full.txt — **critical, the standout SEO finding**

WordPress fires `init` before `parse_request`. `inc/aeo.php` intercepts `/llms.txt` and `/llms-full.txt`
on `init` and `exit()`s; `inc/llms.php` — the newer, documented, `g2a_biz()`-driven, content-DB-backed
module described in `docs/SEO_AEO_PLAYBOOK.md` — intercepts on `parse_request` and is **never reached**.

The result actually served to every AI crawler today is `inc/aeo.php`'s static, hand-written version:

- Hardcodes **"449+ Google reviews"** — the exact number the May-2026 audit fixed everywhere else (current single source of truth defaults to **556**). The one artifact built to feed AI answer engines is the one place still showing the stale number.
- Hardcodes phone/address/hours directly rather than reading `g2a_biz()` — a fifth undocumented place hours must be kept in sync, on top of the four the playbook already tracks.
- Static prose only — doesn't pull live page content, FAQ data, or recent posts, so the intended "one source of truth, AI-grounded" architecture isn't actually running.

**Fix**: delete `inc/aeo.php`'s llms handler (keep only its Organization/WebSite JSON-LD block, after fixing #1 in Part 1), or move `inc/llms.php`'s intercept earlier than `init`.

### 6. Redirects — the most mature area of the SEO layer

All redirects are genuine 301s, www→bare-host canonicalization is host-based (not `Host:`-header-based, avoiding cache-poisoning risk), and the ~40-entry legacy-URL map has no redirect chains. No action needed here beyond routine maintenance.

### 7. NAP consistency — **high, a known follow-up that regressed**

The May-2026 audit's own follow-up item ("sweep ~12 remaining files with hardcoded NAP") was never
completed — the count is now **18 files**, including `template-contact.php` (which also hardcodes the
entire hours table) and, most consequentially, `inc/aeo.php` itself. All current values happen to match
`g2a_biz()`'s defaults, so there's no visible drift *today* — but the moment a client edits the phone
number or hours in the Customizer, these 18 files silently keep showing the old value. Membership
pricing (`$29.99/$39.99/$59.99`) is likewise still hardcoded in 11 files instead of reading from one
plan-pricing source (follow-up item #3, also still open).

### 8. Content / on-page SEO

- **High**: WooCommerce archive/category pages render **two `<h1>` elements** — a static "Shop" heading plus WooCommerce's own dynamic page-title `<h1>` a few lines later — diluting heading-hierarchy signal on every shop/category page. `woocommerce/archive-product.php:53,94`
- **Done well**: every other template has exactly one H1; alt text is present on essentially every image sitewide; URL slugs are clean and keyword-descriptive.

### 9. Performance-adjacent SEO factors

- **Done well**: fonts are genuinely self-hosted and preloaded; JS is deferred and enqueued in-footer; the preloader has a hard 400ms ceiling; `IntersectionObserver` scroll-reveal respects `prefers-reduced-motion` — all match the documented claims.
- **Medium, needs live verification**: the homepage hero and several section backgrounds are CSS `background-image`s with no `<link rel="preload" as="image">` hint. If the hero is the LCP element (likely, given this design), this is a plausible, unverified Core Web Vitals cost — confirm with a DevTools LCP trace.

### 10. Product/WooCommerce SEO

Out-of-stock products correctly stay indexable (schema flips to `OutOfStock` rather than hiding the page — the right call, avoiding a common overcorrection). The standout issue is the disabled Product schema in §1.

### 11. AEO (answer-engine optimization)

FAQ coverage is genuinely good (~26 Q&As across 6 topic groups, schema-backed, non-duplicated). The
llms.txt bug in §5 is the dominant AEO gap on the whole site — worth fixing before any further AEO
content work, since right now that work wouldn't reach the AI crawlers it's aimed at.

### Ranked SEO/AEO fixes

1. Fix the llms.txt/llms-full.txt hook-order bug (§5) — highest leverage single fix on the whole SEO surface.
2. Restore Product JSON-LD when RankMath is active — scope the conflict guard to exclude the `product` post type.
3. Fix the duplicate `<h1>` on shop/category archives.
4. Verify/fix the LocalBusiness and Article fallback image paths via Rich Results Test.
5. Delete `inc/aeo.php`'s dead sitemap/robots handlers (keep only its live entity JSON-LD) and merge its richer AI-bot list into the live `robots.php`.
6. Finish the NAP sweep — now 18 files, not 12 — prioritizing `inc/aeo.php` and `template-contact.php`.
7. Add `hasMerchantReturnPolicy`/`shippingDetails`/`priceValidUntil` to the Product `Offer`.
8. Convert the homepage hero background to a real preloaded `<img>` and confirm the LCP win.
9. Cross-link the Organization and LocalBusiness JSON-LD nodes into one consolidated entity.
10. Populate WooCommerce category meta descriptions and make sitemap `lastmod` reflect real change dates.

**Needs manual/live verification** (blocked by the 403 from this environment): Rich Results Test on
home/blog/FAQ/product pages; PageSpeed Insights LCP trace; GSC URL Inspection on noindexed pages; a
direct diff of production `/llms.txt` against `inc/llms.php`'s expected output to confirm the hook-order
finding empirically.

---

## Part 2 — Functional / Business-Logic Audit

_10 sub-audits covering every plugin's REST security, data integrity, payment correctness, cross-plugin
contracts, and compliance-critical flows (age verification, waivers, NICS, TCPA opt-out, timezone math)._

### Critical

**1. Age verification is not enforced server-side anywhere a firearm is actually purchased or booked, by default.**
WooCommerce checkout (`advanced-ffl-checkout`) has zero age-verification hook — only state-law compliance
and FFL dealer selection are validated at `woocommerce_checkout_process`. The one real server-side gate
that exists (`g2a-booking-engine/includes/modules/verifyistic/class-verifyistic-integration.php`) ships
**disabled by default at two separate levels** (module `default_active => false`, and its own
`OPT_REQUIRE_VERIFICATION` option defaults to `0`), and even fully enabled its route-match covers
`/…/bookings` but not `/events/book` (firearms classes). The age-gate popup is cosmetic UX only in the
default configuration — confirmed by two independent sub-audits.
_Fix: wire a real checkout-time age check for firearm-flagged products; flip `OPT_REQUIRE_VERIFICATION`
on by default; extend the booking-engine route match to cover `/events/book`._

### High

**2. Waiver expiry isn't checked at the booking gate.** The booking-creation waiver gate is satisfied via
`Waivers_Archive::has_on_file()`, which has no expiration concept — it just checks "a signature exists."
The display-layer system (`Waiver_Service::is_current()`) correctly tracks the 365-day validity window,
but nothing wired to booking creation or range self-check-in consults it. A waiver signed in 2023 still
satisfies the gate in 2026. _Fix: route the `g2ab_waiver_satisfied` filter through `is_current()`, not
the raw archive lookup._

**3. NICS 3-business-day math ignores federal holidays.** `three_business_days_from_now()` correctly
skips Sat/Sun (verified by tracing a Friday-set delay to the correct Wednesday) but has zero holiday
calendar anywhere in the plugin. A window spanning Thanksgiving/Christmas/New Year's computes a deadline
that lands before the true §922(t)(1)(B)(ii) window — the dealer UI flags "3-day rule reached" a day or
more early. _Fix: add a federal-holiday table to the day-walk loop._
`advanced-ffl-checkout/includes/class-wpistic-ffl-g2a-nics.php`

**4. Admin/manual bookings bypass blackout and business-hours rules entirely.** The admin manual-booking
endpoint re-implements the capacity/overlap check but never queries the blackout/business-hours rule
table — confirmed zero references. Staff can create a booking on a day the range is closed (holiday,
private event, maintenance) with no system guard; the endpoint's own doc-comment only mentions skipping
rate-limit/waiver, not this. `g2a-booking-engine/includes/rest/class-admin-bookings-controller.php`

**5. No idempotency key on booking creation — duplicate bookings/charges on retry.** `POST /bookings`
and `POST /events/book` mint a fresh UUID per call with no natural-key constraint; a network retry or
double form-submit on a resource with spare capacity (a class, not a single lane) creates two confirmed
bookings and can trigger two separate payment-intent creations.

**6. Public, unauthenticated leak of internal FFL dealer notes + full dealer-database scraping.**
`GET /dealers/{id}` in `advanced-ffl-checkout` is `permission_callback => '__return_true'` and selects
the `notes` column (free-text internal staff commentary), right next to a code comment claiming "never
SELECT * (leaks dealer email/import metadata)." The sibling `/dealers/search` route has an explicit
30/min/IP throttle built specifically to stop bulk scraping; **`/dealers/{id}` has no rate limit at all**,
so iterating sequential IDs exfiltrates the entire ~80k-row ATF dealer database, notes included, at
unlimited speed. `advanced-ffl-checkout/includes/class-wpistic-ffl-api.php`

**7. FFL transfer-status SMS bridge is dead code.** Messageistic listens for action
`ffl_transfer_status_changed` (array arg); `advanced-ffl-checkout` actually fires
`wpistic_ffl_transfer_status_changed` (3 scalar args, from 6+ call sites) — names never match. FFL
transfer SMS works today only via a separate path straight to Verifyistic's webhook dispatcher,
**bypassing Messageistic's TCPA consent/quiet-hours/frequency-cap engine entirely** for this one message
category.

**8. POS-sold memberships never notify Memberistic.** No hook fires anywhere in the repo when POS
creates a membership; the existing bridge only runs the opposite direction. A membership sold at the
counter is permanently invisible to the member portal, waiver tracking, QR verification, corporate
groups, and billing — a real split-brain between the two systems (already flagged in a prior audit,
still unfixed).

**9. CRITICAL bug in dashboard-app's own login flow (staff-facing, not customer-facing).** `api.ts`
builds the real Basic-auth credential, writes it to session, then merges in the `/auth/login` server
response with `{ token: basic, ...server }` — since the backend really does return its own `token` field
and the spread comes after the explicit key, **the working credential gets silently overwritten with a
useless one.** Every subsequent request sends `Authorization: Basic <garbage>`; `/auth/me` on the next
mount 401s and bounces the user back to `/login`. In any real (non-mock) build, login appears to succeed
for an instant and then immediately fails. _Fix: drop `server.token` before spreading, or stop returning
a `token` field from `/auth/login`._

**10. Booking-engine webhook endpoint always returns HTTP 200, even when processing fails, across all
4 payment gateways.** When `process_webhook_event()` returns `handled:false` (booking not found, no UUID,
amount mismatch), the controller still returns 200 and logs it at `severity:'info'` — so the gateway
marks the webhook delivered and **never retries**. Concrete scenario: Stripe completes a charge for a
booking the expiry cron just deleted (a real race) — the customer is charged, the booking stays
unpaid/expired, and nothing alerts anyone. Messageistic's own webhook controller does this correctly
(propagates `WP_Error` as a non-2xx) and is the pattern to copy.

**11. The 24h/2h booking reminder cron is only timezone-correct by accident.** It mixes a Phoenix-local
`current_time()` string with `strtotime()`/`gmdate()` (UTC) in the same computation — a mismatch that
happens to cancel out today only because WordPress core forces PHP's default timezone to UTC at
bootstrap and Phoenix has no DST. Any future change (a plugin calling `date_default_timezone_set()`,
a WP-CLI context, a host override, or setting the server tz to `America/Phoenix` as the ROADMAP itself
suggests) silently reintroduces a ~7-hour error in customer-facing reminder timing, with no test guarding it.

**12. Over-broad PII access via one coarse Memberistic capability.** `view_memberistic_dashboard` —
granted to cashier/POS-staff/instructor roles meant for narrow counter/instructor duties — also gates
full member profiles, staff notes, payment history, and a bulk `/emails/directory` export (every
member's name/email/phone/waiver status). Combined with finding below (no rate limiting on these
routes), a compromised or malicious low-privilege staff account can script fast bulk exfiltration of the
entire member PII database.

**13. Broken auth-check on Memberistic's member profile-photo upload.** The intended gate ("admin OR
active membership OR own account") calls a function, `memberistic_user_has_membership()`, that **does
not exist anywhere in the plugin** — that branch is permanently dead code, and the fallback
(`current_user_can('edit_user', own_id)`) is granted unconditionally by WP core. Net effect: any
authenticated WordPress user, not just members, can upload/delete a "member" profile photo.

**14a. Duplicate FFL transfer records can be created for one real firearm purchase.** `create_transfer_on_payment()` is registered on both `woocommerce_payment_complete` and `woocommerce_order_status_processing`, which many gateways fire close together; its dedup guard is a check-then-act read of order meta, not atomic, and `transfers.order_id` has only a plain (non-unique) index. Two overlapping invocations can both pass the check and both insert a transfer row — two separate dealer notifications, two audit trails, and two portal confirmation links for one purchase, in a workflow that's supposed to be one-per-order for regulatory tracking. `advanced-ffl-checkout/includes/class-wpistic-ffl-checkout.php:326,333`

**14b. FFL transfer status can double-advance and send duplicate customer/dealer notifications.** Both the carrier-webhook handler and the WooCommerce-order status bridge read a transfer's current status, decide whether to auto-advance it, and `UPDATE` with no `WHERE status = <expected>` precondition — so a webhook retry (a normal, expected carrier behavior) racing the scheduled poll cron, or an admin action racing a callback, can each pass the check and both fire the advance, duplicating status-change emails and outbound webhooks. `class-wpistic-ffl-g2a-carrier-providers.php:579-635`, `class-wpistic-ffl-g2a-status-bridge.php:93-126`

**14c. Corporate membership groups can be oversold past their purchased seat count.** The self-service group-owner portal's "invite member" action checks the group's active member count against `seats_total`, then inserts the new member — two near-simultaneous invites (plausible for a company onboarding several employees at once) can both pass the capacity check before either insert lands, pushing the group over its paid-for seat count with nothing to reconcile it. `memberistic-membership-solutions/includes/corporate/class-corporate-module.php` (`Corporate_Member_Service::add_member()`)

**14d. The membership CSV importer has no duplicate protection on re-run.** `commit_payments()` has no dedup check at all before inserting — unlike the Stripe/WooCommerce webhook paths, which check for an existing transaction first — so re-uploading the same payments CSV (or a double-click on "Confirm & Import") inserts a full second copy of every payment row, double-counting revenue in the admin reports. `commit_members()` only dedups by email, and only when a checkbox (on by default, but toggleable) is checked; rows with no email — an explicitly supported import case — are always re-inserted as new memberships on every re-run. `memberistic-membership-solutions/includes/admin/class-import-page.php:1036,842`

### Medium

14. **Webhook "mark consumed" fires before processing completes**, in booking-engine and separately in
    Memberistic's Stripe handler — a mid-processing failure permanently swallows the retry of a real
    payment event.
15. **`g2ab_payments` has no unique constraint on `(gateway, transaction_id)`** — concurrent webhook
    redeliveries can double-count a single charge in revenue reporting.
16. **PayPal refunds don't update the payment ledger** and, keyed off an unreliable field, can be
    silently lost with no retry (Stripe/Authnet both handle this correctly).
17. **Front-desk cash-collection has a lost-update race** — two near-simultaneous payment collections on
    one booking (two terminals) can have one silently overwrite the other's contribution.
18. **Memberistic's "reconciliation" is a fixed 3-day grace period, not real reconciliation** — nothing
    ever polls Stripe to confirm a renewal actually happened; a dropped webhook silently lapses a paying member.
19. **Oversell race in the POS fallback stock-decrement path** (used only when a WooCommerce order fails
    to create) — the primary inventory-ledger path is correctly locked; this one exception isn't.
20. **The same non-atomic rate-limiter pattern (`get_transient` → compare → `set_transient`) is
    duplicated across five plugins** (business-api, Verifyistic, wpistic-contact-form, Memberistic,
    messageistic), so a scripted concurrent burst can exceed every one of their configured caps. Most
    consequential instance: **messageistic's daily/per-contact SMS-frequency caps**, which are
    TCPA-relevant for a firearms-industry messaging system.
21. **`/auth/login` on the staff dashboard is public and completely unrate-limited** — a
    credential-stuffing vector against real WP accounts that bypasses whatever protects `/wp-login.php`,
    despite the plugin's own rate limiter being used correctly elsewhere in the same codebase.
22. **Migration "upgrade" markers get stamped even when the migration may have failed**, and run
    unlocked on every request (not just activation) — a live plugin-file swap without a deactivate cycle
    can race multiple concurrent visitor requests into `install()` simultaneously.
23. **7 dashboard-app analytics pages render real API errors as "No data"** instead of an error/retry
    state, so a genuine backend outage looks to staff like an empty report rather than a broken one.
24. **Verifyistic's own anti-abuse rate limiter only counts failed verification attempts** and fails open
    on a missing anti-bot token — an unauthenticated script can loop the verify endpoint unthrottled,
    flooding every configured outbound webhook.
25. Memberistic's admin "expiring soon" dashboard counts are skewed ~7 hours from mixing local and UTC
    time math (reporting-only; the actual renewal emails are correctly timezone-safe).
26. A separate, optional Memberistic-integration module in the booking engine derives membership status
    from a user-meta field the core controller already had to stop trusting — dormant unless enabled,
    but would reintroduce a fixed bug if it ever is.
27. Dashboard-app stores a long-lived WP application-password credential in `localStorage` with no
    server-side invalidation on logout — an XSS-exfiltrated token keeps working indefinitely.

### Low / hygiene

- Two Verifyistic tables use `CREATE TABLE IF NOT EXISTS` fed into `dbDelta()`, which breaks dbDelta's
  own diffing — future column changes may silently fail to apply on already-installed sites.
- Several POS-core "verify chain" endpoints build a `LIMIT {$limit}` clause via string interpolation
  rather than `prepare()` — not exploitable today (server-side int-cast first) but worth closing.
- `wpistic-contact-form` uninstall leaves four tables behind after a full plugin delete.
- The kiosk waiver "thank-you" page marks a waiver signed for *any* logged-in user who lands there
  (already tracked in a prior audit doc).
- Dashboard-app's Automation Center "+ New automation" and System Health "Re-run checks" buttons are
  still unwired stubs; a failed automation toggle fails silently with no visible error to the operator.
- A fully working RAG service already exists (`cloudflare-rag-worker`) but is wired to POS-core's
  BrainService, not the dashboard's AI Models page, which shows an (honestly-labeled) "not configured" state.

### What's well-built — don't lose this in the findings volume

- **Booking-capacity locking is genuinely correct**: lane and event bookings, admin creation, and
  reschedule all wrap the check+insert in real `START TRANSACTION` / `SELECT ... FOR UPDATE` — true
  double-booking prevention, not naive check-then-insert. It's specifically the money-mutation paths
  that didn't get the same treatment — a fixable inconsistency, not a systemic gap.
- **No SQL injection found anywhere** across 250+ audited `$wpdb` call sites in booking-engine and
  equivalent coverage across every other plugin — consistent, correct `prepare()` usage throughout.
- **Payment webhook signature verification is done right** across all four gateways (Stripe HMAC-SHA256
  with replay tolerance, PayPal's real verify-signature API call, Fortis HMAC-SHA256, Authnet
  HMAC-SHA512) — every public webhook route is backed by real cryptographic verification.
- **SMS opt-out enforcement is fail-closed and identical for both bulk campaigns and transactional
  messages** — all 7 send call-sites route through one compliance gate, no bypass flag exists.
- **The FFL dealer portal and customer "My FFL Transfers" tab are IDOR-safe by construction** — dealers
  authenticate via HMAC tokens bound to `(transfer_id, dealer_id)` that can't be substituted; the
  customer tab is server-scoped to the logged-in user, never a client-supplied ID.
- **REST capability models in business-api, POS-core, memberistic, and booking-engine are disciplined**:
  every sensitive write requires the stronger admin capability; capability strings are constants, not
  hand-typed; `manage_options` is a safe OR-fallback everywhere.
- Schema migrations across the ecosystem are almost universally `dbDelta()`-based and safe to rerun.

---

## Part 3 — UI / Design-System Audit

### High-severity brand-consistency findings

1. **The Verifyistic age-gate popup — the first screen every visitor sees — doesn't use the site's
   brand tokens at all.** It's built on a teal/slate palette (`#14b8a6`, `#0f172a`, `#6366f1`) with zero
   `var(--color-*)` references, contradicting the "unify the brass/dark token bridge across plugin UIs"
   goal a prior audit explicitly called out as still-needed work — confirmed it still hasn't happened.
   `verifyistic/assets/css/frontend.css:8-18`

2. **The booking widget — one of the highest-intent conversion surfaces on the site — reads as an
   unrelated generic SaaS product.** `--g2ab-primary:#5B7BFF` (cool blue) and `"Inter"` font, nothing
   shared with the brass/ember/Bebas-Barlow system. A concrete, reproducible bug compounds this: the
   inline PHP-generated style block falls back to `var(--g2ab-primary,#D2691E)` — chocolate-orange, a
   *third* unrelated color — so if the enqueued CSS ever fails to load (cache purge race, asset
   optimizer), the widget silently renders in yet another hue. `g2a-booking-engine/assets/css/frontend.css:15`,
   `includes/class-frontend.php:543-547`

3. **Memberistic's member account dashboard — the most-used post-purchase surface for loyal customers —
   hardcodes 155 unique generic colors** (navy, teal, indigo, amber) with only one incidental brass match.
   `memberistic-membership-solutions/assets/frontend.css`

4. **No CSS token-bridge actually exists.** "Brass/dark token bridge" is doc language, not implemented
   infrastructure — grep found only PHP data-sync classes named `*-bridge.php`, never a shared stylesheet.
   Advanced FFL Checkout is the one plugin that gets close (hand-copies the theme's exact hex values with
   a comment noting the source), but that's a manually-synced snapshot that will silently drift the
   moment the theme's brass hue is ever adjusted.

### Accessibility

- **High (dashboard-app)**: none of the four modal implementations in the staff SPA (AI Models, AI
  Agents, Leads, Reports) have `role="dialog"`, `aria-modal`, a focus trap, Escape handling, or focus
  return — a stark contrast with the WordPress theme's genuinely careful modal work sitting in the same
  repo. Staff using keyboard/screen-reader workflows get a materially worse experience than customers on
  the public site.
- **Medium**: Verifyistic's success-state messages ("Age Verified!") have no `aria-live`/`role="status"`,
  so screen-reader users may not hear the confirmation (error states correctly use `role="alert"`).
- **Low-Medium**: the `brass-dim` token computes to 2.0–3.4:1 contrast against several backgrounds —
  currently used almost exclusively as a border color (a lower-stakes 3:1 requirement, not a live bug),
  but the token name invites future misuse as text color, especially in the comparatively under-audited
  light mode.
- **Done well**: skip-link, mobile drawer, and quick-view modal are all genuinely best-practice —
  focus trap, `inert`-based background hiding, Escape close, focus restoration, all independently
  re-implemented correctly in two places. FAQ accordion uses real buttons with synced `aria-expanded`.
  `prefers-reduced-motion` is respected consistently.

### Visual polish

- **Medium bug, 5 files**: decorative bullet markers are invisible — `li::before` rules set an empty
  `content:""` with no background/size, so list items render as plain unbulleted paragraphs on pages
  selling CCW classes, lane bookings, and machine-gun rental tiers (`ccw.css:19`, `book-a-lane.css:74`,
  `machine-gun.css:43`, plus two page templates). The correct technique (an inline SVG checkmark or a
  colored dot) already exists and works elsewhere in the same theme.
- The flagship Machine Gun Experience page's inventory cards have **no hover state at all**, while every
  comparable card elsewhere in the theme gets a lift/border treatment — on the page built to sell the
  site's most novel, high-margin experience, the cards feel the least alive.
- **Documentation contradicts the code**: `FEATURES.md` describes booking-confirmation confetti and a
  "Book Another Lane" recap card as already shipped; the actual confirmation screen is a plain checkmark
  + message + code, and a repo-wide search for "confetti" returns zero hits. A prior audit doc correctly
  lists this as still-to-build — the code agrees with that doc, not with `FEATURES.md`.
- **Confirmed still fixed** from the prior audit: checkout checkbox visibility, select2 dark-mode
  styling, quick-view flash-of-placeholder, and coupon/Update-Cart alignment are all genuinely intact in
  current code.
- **Confirmed real, not aspirational**: the 404 page's custom "bullet hole" treatment, the live
  open/closed hours pill (real timezone math, not hardcoded), and the digital membership card are all
  present and well-built.

### Dashboard-app specific

- **Confirmed bug, repeats across 7 pages**: API errors are rendered as "No data" rather than a real
  error state (already listed under Functional §23 — flagged here too since it's as much a UI defect as
  a correctness one). `SystemHealth.tsx` already has the correct pattern in the same codebase; it just
  wasn't applied everywhere.
- Mock data is safely gated behind an explicit env flag with no silent-fallback path — well engineered.
- Only 5 shared UI primitives exist for ~17 pages (no shared Button/Modal/Table component) — buttons
  stay visually consistent in practice only because every page reaches for the same Tailwind utility
  classes, which is fragile but currently works.

### Ranked UI improvements

1. Re-skin the Verifyistic age-gate popup onto the theme's brass/void tokens — it's the first thing every visitor sees.
2. Re-skin the booking widget off its own blue palette, and fix the `#D2691E` fallback-color mismatch.
3. Re-skin Memberistic's account dashboard off its 155 hardcoded generic colors.
4. Add real dialog semantics (role, focus trap, Escape) to the four dashboard-app modals.
5. Fix the 7 dashboard-app pages rendering API errors as "No data."
6. Build an actual CSS token-bridge artifact plugins genuinely consume, replacing four independently hand-copied palettes.
7. Fix the five empty `li::before` bullet rules.
8. Add a hover/lift treatment to the machine-gun inventory cards.
9. Reconcile the confetti/"Book Another Lane" claim between `FEATURES.md` and the actual code — build it, since it's cheap and already scoped.
10. Adopt the existing `--space-*` scale in real CSS (currently 0-of-928 raw px values reference it).

---

## Part 4 — Responsive / Mobile UI Audit

### What's genuinely strong

The theme runs on an unusually disciplined, consistent breakpoint vocabulary reused file-to-file
(1100/980/900/780/720/620/600/440/420) — rare in a hand-rolled multi-plugin WordPress build. The mobile
drawer (focus trap, `inert`, Escape, resize safety-net) and WooCommerce's documented responsive fixes
(cart stacking, checkout/single-product column collapse, related-product title clamping) all verify
exactly as claimed in prior docs. Memberistic's booking-modal fix for the calendar's "last column cut
off" issue is a real, targeted, verified fix. No un-scoped `100vw` usage or horizontal-overflow risks
were found anywhere.

### High-severity findings

1. **No responsive image loading anywhere on hero/section backgrounds.** Every hero across ~10 page
   templates is a single fixed-URL CSS `background-image` with no `srcset` equivalent — a 375px iPhone
   downloads the exact same desktop-resolution JPG as a 2560px monitor. This is the single largest
   real-world mobile-bandwidth and likely-LCP cost found in the whole audit.
2. **WooCommerce checkout and cart-coupon text inputs render at 14–15px**, below the 16px threshold that
   prevents iOS Safari's auto-zoom-on-focus — on the site's actual purchase funnel. A sitewide 16px
   protection rule exists and covers most other forms, but these specific rules use `!important` and
   override it. Tapping "First Name" at checkout on an iPhone zooms the whole page in.
3. **The mobile drawer's scroll-lock (`overflow:hidden` on `<body>`) doesn't reliably stop iOS Safari's
   touch-driven rubber-band scroll** — the page behind an open drawer can still visibly drag/bounce on
   iPhones even though desktop and Android respect it.

### Medium-severity findings

4. Dashboard-app's mobile nav drawer has no focus trap, Escape handler, or scroll lock — a real
   regression relative to the theme's own drawer sitting one directory over in the same repo, and staff
   using iPads at the counter will hit this directly.
5. The FFL dealer-search widget's iOS-zoom fix is scoped to `≤480px` only — misses landscape phones and
   larger-screen Android devices, reopening the zoom bug the developer clearly tried to close.
6. The digital membership card's auto-`window.print()` fires from a delayed `setTimeout` rather than
   directly inside the click handler; some mobile browsers are stricter about honoring print calls
   outside a direct user gesture, and there's no visible fallback instruction if it's silently ignored.
7. Memberistic's "Change/Remove photo" buttons are ~22–24px tall — under both the 44px Apple guideline
   and the edge of WCAG's 24px minimum.
8. The Shop nav's dropdown submenus are hover/`:focus-within`-only and reachable on touch-capable
   tablets wider than the 1100px mobile cutoff (iPad Air/Pro landscape) — exactly the device class
   likely to be used at the front counter.
9. Booking calendar day cells run ~40–42px on a 375px-wide phone (iPhone SE) with no column reduction —
   usable but tighter than ideal.
10. Dashboard-app KPI grids jump straight from 2 columns to 4 at the `lg` breakpoint with nothing at
    `md` — tablet-width viewports (iPad portrait, most Windows tablets) render only 2 columns, under-using
    the available width on exactly the "staff iPad at the counter" scenario this system is built around.

### Low-severity

- The sticky WooCommerce product bar doesn't account for `env(safe-area-inset-bottom)` on notched iPhones.
- One pricing table relies on a sitewide catch-all `overflow-x:auto` safety net rather than its own
  explicit wrapper (not broken, just fragile).
- Select2 dropdowns have no native-picker fallback on mobile (functional, just not the smoothest pattern).

### Ranked responsive fixes

1. Bump the `!important`-forced 14–15px checkout/cart-coupon inputs to 16px — fixes iOS zoom on the actual purchase funnel.
2. Add `srcset`/responsive image handling to hero/section backgrounds — the biggest real mobile-bandwidth cost found.
3. Fix the mobile drawer's iOS scroll-lock with the standard `position:fixed` technique.
4. Add focus trap/Escape/scroll-lock to the dashboard-app's mobile nav drawer.
5. Widen the FFL widget's iOS-zoom fix beyond `≤480px`.
6. Add a visible fallback instruction to the digital-card print flow in case the auto-print is silently blocked.
7. Enlarge Memberistic's photo-upload buttons to clear 44px.
8. Add an explicit tap handler (or lower the mobile cutover) for the Shop nav's dropdown on landscape tablets.
9. Reduce the booking calendar's day-cell density below ~400px, or move to a 2-row week view.
10. Add a `md:` step to the dashboard's KPI grids so tablet widths get 3 columns, not 2.

---

## Part 5 — Consolidated improvement list for the business

Ranked across all four audits, grouped by what kind of attention each needs.

### Fix immediately — compliance, security, and money — ✅ ALL FIXED (Round 1 + Round 3, see "Fixes shipped" below)

1. ✅ **Enforce age verification at the point of sale** — wire a real checkout-time gate for firearm
   products and flip the existing (but disabled) booking-engine gate on by default. *(Functional #1)*
2. ✅ **Make waiver-expiry actually gate bookings**, not just display a banner. *(Functional #2)*
3. ✅ **Add a federal-holiday calendar to the NICS 3-day computation.** *(Functional #3)*
4. ✅ **Add blackout/business-hours checks to admin/manual bookings.** *(Functional #4)*
5. ✅ **Lock down the FFL dealer endpoint** — require auth (or at minimum strip `notes` from public
   responses) and rate-limit `/dealers/{id}` the same as `/dealers/search`. *(Functional #6)*
6. ✅ **Fix the llms.txt hook-order bug** so AI answer engines stop getting stale business info. *(SEO #5)*
7. ✅ **Restore Product schema** for the entire catalog. *(SEO #1)*
8. ✅ **Fix the FFL SMS bridge's mismatched hook name** so transfer texts go through the TCPA-compliant
   messaging engine like every other message type. *(Functional #7)*
9. ✅ **Fix the dashboard-app login token-overwrite bug** before staff rely on the live (non-mock) build. *(Functional #9)*
10. ✅ **Make webhook handlers reflect real failure states** (non-2xx on failure, not an always-200 that
    silently drops retries) across booking-engine's four payment gateways. *(Functional #10)*
11. ✅ **Add a unique constraint (or an atomic claim step) on FFL `transfers.order_id`** and give the
    carrier-webhook/status-bridge advance handlers a `WHERE status = <expected>` precondition — closes
    both the duplicate-transfer-per-order and duplicate-status-notification races. *(Functional #14a/14b)*
12. ✅ **Add dedup protection to the membership CSV importer's payment-commit step** before it's used again
    for any future migration/reconciliation pass. *(Functional #14d)*

### High-value, schedule soon — ✅ ALL FIXED 2026-07-11 (see "Fixes shipped" Round 2 below)

13. ✅ Wire POS membership sales to notify Memberistic (close the split-brain between the two systems). *(Functional #8)*
14. ✅ Add idempotency protection to booking creation to stop duplicate bookings/charges on retry. *(Functional #5)*
15. ✅ Tighten Memberistic's PII-access capability so counter/instructor roles can't bulk-export the member directory; add rate limiting to those routes. *(Functional #12)*
16. ✅ Fix the Memberistic profile-photo upload auth bypass. *(Functional #13)*
17. ✅ Standardize the repeated non-atomic rate-limiter pattern — fixed for messageistic's TCPA-relevant send caps (the priority instance); business-api/Verifyistic/wpistic-contact-form/Memberistic's checkout limiter still open. *(Functional #20)*
18. ✅ Bump checkout/cart-coupon input font-size to 16px to stop iOS zoom on the purchase funnel. *(Responsive #2)*
19. ✅ Add responsive image loading to hero/section backgrounds (Machine Gun + About pages; static bundled images left as CSS backgrounds by design). *(Responsive #1)*
20. ✅ Re-skin the Verifyistic popup and booking widget onto the theme's own brand tokens — the two most visible off-brand surfaces. *(UI #1, #2)*
21. ✅ Add proper dialog semantics to the four dashboard-app modals and fix the 7 pages that render API errors as "No data." *(UI #4/#5, Functional #23)*
22. ✅ Fix the mobile drawer's iOS scroll-lock and bring the dashboard-app's own mobile nav up to the same standard. *(Responsive #3, #4)*
23. ✅ Add an atomic precondition to the FFL transfer-status advance handlers and the corporate-group seat-capacity check. *(Functional #14b/14c)*

### Medium-term, real but contained — ✅ ALL FIXED 2026-07-11 (see "Fixes shipped" Round 3 below)

24. ✅ Add `UNIQUE(gateway, transaction_id)` to the payments table; fix PayPal's refund-ledger gap. *(Functional #15, #16)*
25. ✅ Replace the booking-engine reminder cron's accidentally-correct timezone math with an explicit, tested implementation. *(Functional #11)*
26. ✅ Build a real CSS token-bridge and re-skin Memberistic's account dashboard onto it. *(UI #3, #6)*
27. ✅ Fix the five invisible-bullet CSS rules and add a hover state to the machine-gun cards. *(UI #7, #8)*
28. ✅ Add a `md:` breakpoint step to the dashboard's KPI grids and widen the FFL widget's iOS-zoom fix. *(Responsive #5, #10)*
29. ✅ Finish the NAP/pricing sweep (now 27 + 11 files) before the next time hours or prices change. *(SEO #6)*
30. ✅ Replace Memberistic's fixed grace-period "reconciliation" with a real Stripe-state poll. *(Functional #18)*

### Low priority / hygiene, batch together — ✅ ALL FIXED 2026-07-12 (see "Fixes shipped" Round 4 below)

31. ✅ Clean up the dead `inc/aeo.php` sitemap/robots handlers and merge its richer AI-bot allowlist into the live file. *(SEO)*
32. ✅ Add missing `hasMerchantReturnPolicy`/OG image dimensions/table freshness signals. *(SEO)*
33. ✅ Fix the two Verifyistic tables using `CREATE TABLE IF NOT EXISTS` with `dbDelta()`, and close the string-interpolated `LIMIT` clauses in POS-core with `prepare()`. *(Functional)*
34. ✅ Wire up the dashboard-app's two remaining stub buttons (New Automation, Re-run Checks) and connect the already-working `cloudflare-rag-worker` to the AI Models page instead of leaving it "not configured." *(Functional)*
35. ✅ Reconcile the confetti/recap-card claim in `FEATURES.md` against actual code — build it or correct the doc. *(UI)*

---

## Fixes shipped 2026-07-11

### Round 1 — the five "fix immediately" items

Implemented, PHP-linted, packaged into updated plugin/theme zips (`releases/`), and this report was
updated to reflect the fixed state rather than leaving it to drift out of date the way `inc/aeo.php`
itself drifted (see finding #4 above).

| # | Fix | Files |
|---|---|---|
| 1 | Verifyistic booking gate now defaults **on** (module `default_active`, `OPT_REQUIRE_VERIFICATION`); gate now also covers `/events/book`, not just `/bookings`. Added a real checkout-time age-verification check (`woocommerce_checkout_process`) for any cart containing an FFL-required product — fails closed if Verifyistic isn't installed. | `g2a-booking-engine/includes/modules/verifyistic/module.php`, `.../class-verifyistic-integration.php`; `advanced-ffl-checkout/includes/class-wpistic-ffl-compliance.php` |
| 2 | `Waivers_Archive::find_on_file()`/`has_on_file()` now excludes waivers older than `Waiver_Service::validity_days()` (default 365) instead of matching any "current" (i.e. non-superseded) row regardless of age. Fixes the booking-gate bridge, the admin lookup tool, and the cross-plugin `memberistic_waiver_on_file()` helper in one place. | `memberistic-membership-solutions/includes/waivers/class-waivers-archive.php` |
| 3 | `three_business_days_from_now()` now skips the 11 federal holidays (with standard Saturday/Sunday observance shifting) in addition to weekends. | `advanced-ffl-checkout/includes/class-wpistic-ffl-g2a-nics.php` |
| 4 | Removed `inc/aeo.php`'s dead `init`-hooked `/llms.txt`/`/llms-full.txt` handler (and its stale `g2a_llms_txt()` content) so `inc/llms.php`'s `parse_request`-hooked, `g2a_biz()`-driven handler — the one actually documented as canonical — is no longer silently shadowed. | `guns2ammo/inc/aeo.php` |
| 5 | Dropped the `notes` column from both `/dealers/search`'s and `/dealers/{id}`'s public SELECT lists (internal staff commentary, previously leaked to anyone). Added the same 30/min/IP rate limit `/dealers/search` already had to `/dealers/{id}` so sequential-ID scraping of the ~80k-row dealer table is no longer unthrottled. | `advanced-ffl-checkout/includes/class-wpistic-ffl-api.php` |

### Round 2 — the "High-value, schedule soon" tier (Part 5, items 13–22)

| # | Fix | Files |
|---|---|---|
| 13 | POS-sold memberships now fire `g2a_pos_membership_sold`; Memberistic mirrors the sale into a real membership + primary person record (plan mapped by slug/name, flagged for manual mapping if no match), stamping `pos_customer_id` — closing the split-brain between the two systems. | `g2a-pos-core/includes/API/MembershipBillingController.php`; `memberistic-membership-solutions/includes/integrations/class-pos-bridge.php` |
| 14 | Added a DB-backed `idempotency_key` (unique, 10-minute request-derived bucket) to `POST /bookings` and `POST /events/book`; a retry/double-submit now replays the original booking's response instead of creating a duplicate booking or a second payment-gateway intent. | `g2a-booking-engine/includes/class-installer.php`, `.../rest/class-bookings-controller.php` |
| 15 | Added a `view_memberistic_pii` capability (granted only to manager/staff roles) and a `pii_permissions_check()` gate; `/memberships/(id)`, `/people`, `/payments`, `/activity`, `/bookings`, and `/emails/directory` (bulk PII export) now require it instead of the coarser `view_memberistic_dashboard` cashier/POS-staff/instructor roles already held. | `memberistic-membership-solutions/includes/class-capabilities.php`, `.../rest/class-rest-controller.php`, `.../rest/class-memberships-controller.php` |
| 16 | Defined the `memberistic_user_has_membership()` helper that was referenced but never implemented, and removed the `current_user_can('edit_user', $user_id)` fallback that made the profile-photo-upload gate pass for any authenticated user regardless of membership. | `memberistic-membership-solutions/includes/utilities/global-functions.php`, `.../rest/class-memberships-controller.php` |
| 17 | Messageistic's send lock (`Pilot_Send_Lock`, a real MySQL `GET_LOCK`) now wraps **every** SMS send, not just pilot-mode ones, so the daily/per-contact frequency-cap checks in `Policy_Engine::evaluate()` and the message row that satisfies them are atomic — closing the TCPA-relevant race the audit flagged as the priority instance of the non-atomic-rate-limiter pattern. (The same pattern in business-api/Verifyistic/wpistic-contact-form/Memberistic's checkout limiter is not yet fixed — tracked as a follow-up.) | `messageistic/includes/Compliance/Pilot_Send_Lock.php`, `.../Messaging/SMS_Service.php` |
| 18 | FFL carrier-webhook and WooCommerce-status-bridge "advance transfer" handlers now re-check status inside the `UPDATE`'s `WHERE` clause (not just an earlier PHP read) and only log/fire the status-changed hook when the update actually matched a row — a webhook retry or racing admin edit can no longer double-advance a transfer or send duplicate notifications. Corporate group invites (`add_member()`/`attach_membership()`) now hold a per-group MySQL advisory lock across the seat-capacity check through the insert, closing the same TOCTOU for concurrent invites to one group. | `advanced-ffl-checkout/includes/class-wpistic-ffl-g2a-carrier-providers.php`, `.../class-wpistic-ffl-g2a-status-bridge.php`; `memberistic-membership-solutions/includes/corporate/class-corporate-module.php` |
| 19 | Checkout and cart-coupon `input.input-text` font-size bumped from 15px to 16px (both `!important`-forced, so they were overriding the sitewide iOS-zoom protection). | `guns2ammo/assets/css/wc-fixes.css` |
| 20 | Machine Gun and About page hero/team/tour photos now render as real `wp_get_attachment_image()` markup (srcset/sizes, `fetchpriority="high"` on the LCP hero) when backed by a genuine Media Library upload, falling back to the original CSS background unchanged when it isn't. Static bundled theme images (no attachment ID possible) were deliberately left as CSS backgrounds — documented in-file. | `guns2ammo/inc/site-content.php`, `page-templates/template-machine-gun.php`, `page-templates/template-about.php`, `assets/css/machine-gun.css` |
| 21 | Re-skinned the Verifyistic popup and booking-widget CSS off their own hardcoded teal/blue palettes onto the theme's brass/void/gunmetal tokens and font stack; fixed the `#D2691E` inline-style fallback in `class-frontend.php` so a CSS-load failure can no longer render a third, unrelated color. (A further ~29 files carrying the old blue/orange as WP option *defaults* were identified but left untouched — flagged as a follow-up.) | `verifyistic/assets/css/frontend.css`, `g2a-booking-engine/assets/css/frontend.css`, `g2a-booking-engine/includes/class-frontend.php` |
| 22 | Added a shared `useDialogA11y` hook (focus trap, Escape-to-close, focus restore) to the four dashboard-app modals (AI Models, AI Agents, Leads, Reports) and to the mobile nav drawer (which also gained a body-scroll lock); added a new `ErrorState` component and wired it into the 7 analytics pages that previously rendered a real API error as "No data". The theme's own mobile drawer scroll-lock was fixed with the standard `position:fixed` + scroll-restore technique. | `dashboard-app/src/lib/hooks.ts`, `.../components/ui/ErrorState.tsx`, `.../components/layout/AppLayout.tsx`, `.../pages/{AIModels,AIAgents,Leads,Reports,BookingRevenue,WooStoreAnalytics,MembershipRevenue,BusinessAnalysis,InsightisticAnalytics,SEOGrowth,ShooterInsights}.tsx`; `guns2ammo/assets/css/app.css`, `assets/js/chrome.js` |

Versions after Rounds 1-2: g2a-booking-engine → **1.9.9.6**, advanced-ffl-checkout → **1.9.2**,
memberistic-membership-solutions → **1.10.3**, guns2ammo theme → **1.27.10**, g2a-pos-core → **3.1.7**,
messageistic → **0.5.2**, verifyistic → **1.4.2**.

### Round 3 — the remaining "Fix immediately" items (4, 7-12) + the entire "Medium-term" tier

Auditing our own prior work turned up a gap: only 5 of the 12 "Fix immediately" items (1, 2, 3, 5, 6)
were actually implemented in Round 1, even though the report already marked the tier as addressed. The
7 higher-severity items below were fixed first, ahead of the medium-term work that was originally
requested for this round, since they outrank it.

| # | Fix | Files |
|---|---|---|
| 4 | Admin/manual bookings now run the same blackout + business-hours check as public bookings, gated behind an explicit, audit-logged `override_availability` param for the rare legitimate override (e.g. a manager honoring a phone booking outside posted hours). | `g2a-booking-engine/includes/rest/class-admin-bookings-controller.php` |
| 7 | Removed the theme's own `g2a_seo_plugin_active()` early-return around its Product JSON-LD block — `inc/seo.php`'s RankMath overlap guard already silences RankMath's own `rank_math/json_ld`/`rank_math/schema/output` filters, so the theme was the sole possible schema source and that guard was producing zero Product schema site-wide whenever RankMath was active. | `guns2ammo/inc/woocommerce.php` |
| 8 | Messageistic's FFL bridge was listening for a hook advanced-ffl-checkout never fires (`ffl_transfer_status_changed` with a single array arg); rewired to the real `wpistic_ffl_transfer_status_changed( $transfer_id, $old_status, $new_status )` signature and to query the transfers table directly, so FFL status-change texts now go through the TCPA-compliant messaging engine instead of bypassing it via a separate webhook-only path. | `messageistic/includes/Integrations/FFL_Checkout/FFL_Integration.php` |
| 9 | Fixed an object-spread ordering bug in the dashboard-app's login handler — `{ token: basic, ...server }` let the backend's own throwaway `token` field silently overwrite the real Basic-auth credential every subsequent request needs; reordered to `{ ...server, token: basic }`. | `dashboard-app/src/lib/api.ts` |
| 10 | `g2a-booking-engine`'s webhook controller now returns HTTP 422 (not always-200) when a gateway event isn't actually handled, and logs it at `warning` severity instead of `info` — gateways can now tell a dropped/misrouted event from a real success and retry it. | `g2a-booking-engine/includes/rest/class-webhooks-controller.php` |
| 11 | Added `UNIQUE KEY uniq_order (order_id)` to the FFL `transfers` table (the carrier-webhook/status-bridge `WHERE status = <expected>` half of this item was already fixed as Round 2 item 18); `create_transfer_on_payment()` now recovers from the duplicate-key case by re-querying the existing row and only fires the created-transfer side effects (email, dealer notify, `wpistic_ffl_transfer_created`) for the call that actually inserted it. | `advanced-ffl-checkout/includes/class-wpistic-ffl-db.php`, `.../class-wpistic-ffl-checkout.php` |
| 12 | The membership CSV importer's `commit_payments()` now checks `Payments_Repository::get_by_gateway_transaction_id()` (the same dedup check the Stripe webhook path already used) before inserting, and reports a "Skipped (already imported)" count — safe to re-run a migration/reconciliation import. | `memberistic-membership-solutions/includes/admin/class-import-page.php` |

With the compliance/security backlog closed, the originally-requested medium-term tier (items 24-30)
was completed in the same round:

| # | Fix | Files |
|---|---|---|
| 24 | Added `UNIQUE KEY uniq_gateway_txn (gateway, transaction_id(191))` to `g2ab_payments`; introduced a shared `g2ab_insert_or_update_payment()` helper (insert, recover from the duplicate-key failure by re-querying, update instead) and wired Stripe/PayPal/Authorize.Net onto it. PayPal's `mark_booking_refunded()` now also updates the payment ledger row (previously only the booking), pulling `capture_id` from `custom_id` or the PayPal `links` "up" relation as a fallback. | `g2a-booking-engine/includes/helpers/functions.php`, `.../class-installer.php`, `.../payments/class-stripe.php`, `.../class-paypal.php`, `.../class-authnet.php` |
| 25 | Replaced the reminder cron's `strtotime()`+`gmdate()` window math — which only produced the correct wall-clock string because parsing "as UTC" and formatting "as UTC" happened to cancel out — with an explicit `wp_timezone()`-aware `DateTimeImmutable`, so the 24h/2h reminder windows stay correct through DST transitions instead of by accident. | `g2a-booking-engine/includes/modules/email-automation/class-email-cron.php` |
| 26 | Built a `--memberistic-*` CSS custom-property token bridge mapped to the theme's own `--color-*`/`--font-*` tokens and re-skinned `frontend.css` (~155 hardcoded hex colors) onto it. | `memberistic-membership-solutions/assets/token-bridge.css`, `.../assets/frontend.css`, `.../includes/class-plugin.php`, `.../templates/account.php` |
| 27 | Fixed five invisible-bullet CSS rules and added a hover state to the machine-gun cards; widened the FFL widget's iOS-zoom input fix. | `guns2ammo/assets/css/{ccw,book-a-lane,machine-gun}.css`, `advanced-ffl-checkout/assets/css/ffl-checkout.css`, `guns2ammo/page-templates/{template-ccw-syllabus,template-range-safety}.php` |
| 28 | Added a `md:` breakpoint step to the dashboard-app's KPI grids so 2-column tablet layouts no longer jump straight from 1 to 4 columns. | 10 files under `dashboard-app/src/pages/` |
| 29 | Extended the NAP/pricing sweep from the originally-scoped 18+11 files to all 27+11 files actually carrying hardcoded phone/address/hours/price strings (broad grep turned up 9 more than the audit's original file list); built a `g2a_biz()`-backed helper set plus a new `inc/pricing.php` (reads Memberistic's live `Plans_Repository` when active, transient-cached, invalidated on plan create/update) so a hours/price/NAP change in one place now shows up everywhere instead of drifting out of 20+ hardcoded copies. `template-about.php`'s two-bucket weekday/weekend hours card doesn't cleanly fit the real per-day hours table (Friday differs) — left as-is, flagged for a product decision on redesigning that card. | `guns2ammo/inc/pricing.php` (new), `guns2ammo/functions.php`, and 31 other theme files (see commit diff) |
| 30 | Replaced the fixed 3-day grace-period guess with a real Stripe subscription-status check (`Stripe_Service::get_subscription()`) before hard-expiring a recurring membership: `active`/`trialing` re-syncs `renewal_date` from Stripe's `current_period_end` and skips expiry, `past_due`/`unpaid`/`incomplete` marks `past_due`, `canceled`/`incomplete_expired` proceeds to a genuine expiry. Falls back to the old fixed-grace-period heuristic only if Stripe is unreachable/misconfigured. | `memberistic-membership-solutions/includes/class-scheduler.php` |

Versions after Round 3: g2a-booking-engine → **1.9.9.7** (DB schema 1.5.2), advanced-ffl-checkout →
**1.9.3** (DB schema 1.4.1), memberistic-membership-solutions → **1.10.4**, guns2ammo theme →
**1.27.11**, messageistic → **0.5.3**. g2a-pos-core and verifyistic are unchanged this round. Packaged
archives are in `releases/` (previous version of each retained alongside, per this repo's convention);
`INSTALL.md`'s version table was updated to match. `dashboard-app` has no plugin-zip release artifact
(it's deployed separately per `DEPLOYMENT.md`) — its changes are committed and ready for the next deploy.

**Not yet fixed after Round 3** — only the "Low priority / hygiene" tier (Part 5, items 31-35) remained
open, along with the two follow-ups called out in Round 2 (the non-atomic rate limiter in four other
plugins; ~29 files still carrying the pre-re-skin blue/orange as option defaults in
g2a-booking-engine — both still open after Round 4 below, as neither was in the hygiene tier's scope).

### Round 4 — the "Low priority / hygiene" tier (Part 5, items 31–35)

| # | Fix | Files |
|---|---|---|
| 31 | Removed `inc/aeo.php`'s dead `init`-hooked `/sitemap.xml` handler and dead `robots_txt` filter (both fully superseded by `inc/sitemap.php`/`inc/robots.php`'s `parse_request`-priority-0 intercepts, so neither ever actually ran) — the file now holds only its Organization/WebSite JSON-LD block. Merged its richer AI-bot allowlist (Amazonbot, cohere-ai, Bytespider, Meta-ExternalAgent, Perplexity-User, DuckDuckBot, YandexBot) into the live `inc/robots.php`. | `guns2ammo/inc/aeo.php`, `inc/robots.php` |
| 32 | Product `Offer` now carries a real `hasMerchantReturnPolicy` (sourced from the actual `/refund-and-returns-policy/` page: firearms are `MerchantReturnNotPermitted` via the `_wpistic_ffl_required` product-meta flag, everything else gets the real 14-day window — also fixed a stale "30 days" in the FAQ answer to match), a `priceValidUntil`, and `shippingDetails` built from the store's actually-configured WooCommerce shipping zones (never a fabricated rate; omitted entirely for firearms, which don't ship direct-to-consumer). OG/Twitter image tags now include real `og:image:width/height` (resolved from the actual Media Library attachment) and `twitter:image:alt`. The sitemap index's `lastmod` per sub-sitemap now reflects the real most-recently-modified page/post/product instead of stamping every slice with the current request time regardless of whether it changed. | `guns2ammo/inc/woocommerce.php`, `inc/seo.php`, `inc/faqs.php`, `inc/sitemap.php` |
| 33 | Both Verifyistic tables' `CREATE TABLE IF NOT EXISTS` (which breaks `dbDelta()`'s own column-diffing) changed to plain `CREATE TABLE`, restoring dbDelta's ability to apply future schema changes to already-installed sites. POS-core's two audit-chain `verify_chain()` endpoints now build their `LIMIT` clause with `$wpdb->prepare( ..., %d )` instead of string interpolation (not exploitable today — `$limit` was already PHP `int`-typed — but no longer relies on that alone). | `verifyistic/includes/class-verifyistic-db.php`, `.../class-verifyistic-webhooks.php`; `g2a-pos-core/includes/Database/AuditLogRepository.php`, `.../BoundBookRepository.php` |
| 34 | Automation Center's "+ New automation" now opens a real dialog that files a genuine staff task through the existing Tasks system (`POST /tasks`) — automations here are a fixed, developer-wired catalog with no no-code builder to open, so this is an honest "request it" flow rather than a fake builder or a silent no-op. System Health's "Re-run checks" now actually re-fetches all four health queries (checks, integrations, namespaces, site health). The AI Models page's "RAG stores" card, which hardcoded "not configured" regardless of real state, now reads the same already-working `GET /brain/stats` endpoint the AI Agents page uses — showing the real backend (`cloudflare` when the `cloudflare-rag-worker` is configured, `local` otherwise) and real document/chunk counts. | `dashboard-app/src/pages/AutomationCenter.tsx`, `SystemHealth.tsx`, `AIModels.tsx` |
| 35 | Built the "Book Another Lane" CTA + confetti + recap card on the lane-booking confirmation screen that `FEATURES.md` already claimed existed. The recap (resource, date/time, party size) is assembled entirely from what the visitor already picked client-side — no backend change needed. Confetti is a small dependency-free DOM-based burst that respects `prefers-reduced-motion` (skipped entirely, not just visually muted). "Book Another Lane" resets the widget back to the calendar stage without a full page reload. | `g2a-booking-engine/includes/class-frontend.php`, `assets/css/frontend.css` |

Versions after Round 4: g2a-booking-engine → **1.9.9.8**, g2a-pos-core → **3.1.8**, verifyistic →
**1.4.3**, guns2ammo theme → **1.27.12**. advanced-ffl-checkout, memberistic-membership-solutions, and
messageistic are unchanged this round. Packaged archives are in `releases/` (previous version of each
retained alongside); `INSTALL.md`'s version table was updated to match.

**Not yet fixed after Round 4** — every item in this report's ranked improvement list (Part 5, items
1–35) had been implemented across four rounds, but the two follow-ups called out in Round 2 (neither
ranked in the consolidated list) remained open: the non-atomic rate limiter in
business-api/Verifyistic/wpistic-contact-form/Memberistic's checkout limiter, and ~29 files in
`g2a-booking-engine` still carrying the pre-re-skin blue/orange as WP option *defaults*.

### Round 5 — the two Round-2 follow-ups

| # | Fix | Files |
|---|---|---|
| Rate limiter | Same fix pattern as messageistic's Round-2 `Pilot_Send_Lock` (a real MySQL `GET_LOCK`/`RELEASE_LOCK` wrapping the read-check-increment-write), applied to the four remaining instances: g2a-business-api's public `/opt-out` endpoint, Verifyistic's failed-attempt counter and per-IP token-mint cap, wpistic-contact-form's submission throttle, and Memberistic's public checkout endpoint. Each fails closed (refuses rather than proceeds) if the lock can't be acquired within a few seconds. Added lock-behavior unit tests to g2a-business-api's `Rate_Limiter` suite (497 tests pass). | `g2a-business-api/includes/ops/class-rate-limiter.php` (+tests), `verifyistic/includes/class-verifyistic-security.php`, `.../class-verifyistic-ajax.php`, `wpistic-contact-form/includes/class-wpcf-spam.php`, `memberistic-membership-solutions/includes/payments/class-stripe-service.php` |
| Booking-engine re-skin | Investigating the "~29 files" turned up a more consequential bug than the audit's own framing suggested: the booking widget's actual color *defaults* (`class-frontend.php`'s `get_design_tokens()`) still hardcoded the old orange/gold palette, silently **overriding** `assets/css/frontend.css`'s Round-2 `var(--color-brass)` fallback on every install that hadn't touched the Form Customizer — meaning the widget likely never visually re-skinned in practice. Fixed by defaulting the design tokens to `var(--color-brass)`/`var(--color-ember)`/`var(--color-void)`/etc. (extending `sanitize_color()` to accept a narrow, injection-safe `var(--name)` pattern) so the widget now tracks the theme's live tokens, including its light/dark toggle, the same way the CSS file's own fallback does. Also fixed the two genuinely customer-facing surfaces still on the old palette (the Authorize.Net hosted-payment redirect page, and the SEO event-landing-page generator — the latter also had a 3-way category-accent scheme that was collapsed onto the theme's single ember accent, since those pages are never shown side-by-side and the distinction had no real UX value), the admin settings screen's color-picker previews, and a vestigial unused brand-color option pair. A background sweep then closed out the remaining ~29 admin-chrome/settings/module files still on the old palette — collapsed onto the same brass/ember/void/gunmetal/silver token set, admin-only files treated as lower-priority polish rather than brand risk. | `g2a-booking-engine/includes/class-frontend.php`, `class-seo.php`, `payments/class-authnet.php`, `admin/class-settings-pro.php`, `class-admin.php`, `class-activator.php`, `assets/js/frontend.js`, and 25 other admin/module files |

Versions after Round 5: g2a-booking-engine → **1.9.9.9**, verifyistic → **1.4.4**,
wpistic-contact-form → **1.5.7**, memberistic-membership-solutions → **1.10.5**, g2a-business-api →
**0.1.1** (internal version only — not part of this repo's release-zip distribution, no `INSTALL.md`
row). guns2ammo theme, advanced-ffl-checkout, g2a-pos-core, and messageistic are unchanged this round.
Packaged archives are in `releases/` (previous version of each retained alongside); `INSTALL.md`'s
version table was updated to match.

**Not yet fixed** — no known open items remain from this report or its Round-2 follow-ups.

### Round 6 — fresh from-scratch audit (email/NAP focus, dark + light UI, functional, SEO, responsive)

Requested as a brand-new audit rather than a re-verification pass, specifically flagging a suspected
wrong business address in automated emails. Five parallel research agents covered email/notification
address accuracy, functional/business-logic/compliance, SEO/AEO, dark-UI contrast, and light-UI
contrast + responsive/mobile, each blind to the others' findings. Root cause of the reported bug:
`g2a-booking-engine` maintained its own `g2ab_business_address` option, seeded at activation with a
placeholder (`"Mesa, Arizona"` — no street/suite/zip), fully disconnected from the theme's
`g2a_biz()` single source of truth. The same "plugin keeps its own stale copy of NAP" pattern turned up
independently in three more plugins.

| # | Fix | Files |
|---|---|---|
| 1 | Added `g2ab_business_name()`/`_phone()`/`_address()` fallback helpers: prefer an explicit plugin-level option override, else fall through to the theme's live `g2a_biz()` data — treating the known `"Mesa, Arizona"` placeholder as invalid so already-activated sites are fixed retroactively (an `add_option()`-seeded value can't be corrected just by changing the code's default parameter). Rolled out across every consumer: email automation (tags, from-name, footer), PDF invoices, front-desk receipts, AI autoreply system prompt, SEO/JSON-LD, Authorize.Net/PayPal redirect pages, and all admin settings screens. | `g2a-booking-engine/includes/helpers/functions.php` (new helpers), `class-activator.php`, `modules/email-automation/class-email-engine.php`, `modules/ai-autoreply/class-autoreply-engine.php`, `modules/pdf-invoices/class-invoice-engine.php` + `class-invoice-settings.php`, `rest/class-frontdesk-controller.php`, `admin/class-settings-pro.php`, `class-admin.php`, `admin/class-shooters.php`, `frontend/class-self-checkin.php`, `class-seo.php`, `payments/class-authnet.php` + `class-paypal.php`, `modules/email-automation/class-email-settings.php` |
| 2 | Modernized the booking-engine's transactional email UI: `wrap_html()`'s shell now uses a rounded 16px card, soft shadow, void header with a brand-color underline, warm neutral background tones, and an Inter font stack; the default footer fallback now shows full NAP (name, then address · phone, then copyright) instead of copyright alone. All 8 templates (`booking_created` → `booking_completed`) redesigned with sentence-case rounded CTA buttons, a shared label/value detail-row component, and a modernized monospace confirmation-code box. | `g2a-booking-engine/includes/modules/email-automation/class-email-engine.php` |
| 3 | De-hardcoded the same NAP literal from three more plugins that had it correct today but brittle against the next Customizer address change: `wpistic-contact-form`'s branded-email footer line, Formistic's AI knowledge-base seed text + keyword auto-reply rules (FFL/hours/booking templates), and `g2a-pos-core`'s `DefaultKnowledgePack` (the site's own AI/RAG answer engine) — the latter had been running a fully static identity document nightly alongside a separately-correct dynamic ingestion path, a real dual-source contradiction risk. | `wpistic-contact-form/includes/class-wpcf-emails.php`, `formistic/includes/class-formistic-g2a-defaults.php`, `g2a-pos-core/includes/Ai/DefaultKnowledgePack.php` |
| 4 | Fixed `advanced-ffl-checkout`'s dealer-portal theming defaults reading theme_mod keys that don't exist (`g2a_business_email`/`g2a_business_phone` instead of the real `g2a_email`/`g2a_phone`) — the FFL transfer-status email support-contact fields had never actually read the live Customizer values, always falling back to `admin_email` and an empty phone. | `advanced-ffl-checkout/includes/class-wpistic-ffl-theming.php` |
| 5 | Fixed 3 real dark-mode/light-surface contrast failures surfaced by the parallel color audits: Memberistic's "FRONT DESK" staff-header eyebrow label (bright green on solid ember, ~1.6:1) now uses the header's own light text; the systemic white-text-on-solid-ember CTA pattern across ~9 Memberistic buttons/pills (~2.6:1, including the plan-purchase button) now pairs with dark ink text (~6.8:1) instead, matching the "brass/bright fills pair with dark text" rule already documented elsewhere in the same token bridge; the account dashboard's stale copy-pasted `--ma-silver` hex was brought back in sync with the live token. | `memberistic-membership-solutions/assets/frontend.css`, `templates/account.php` |
| 6 | Fixed dashboard-app's dark-mode `--text-subtle`/`--sidebar-fg-muted` token (was `#5b6272`, computed to 2.99–3.30:1 against the app's own dark surfaces — real informational text in Login/WebsiteContent/Reports/Leads/Sidebar) and two raw Tailwind `rose-600` usages (dashboard error banner, AI Models delete button) that bypassed the app's own tuned `--danger` token — both now compute above 4.5:1. | `dashboard-app/src/styles/index.css`, `src/pages/DashboardHome.tsx`, `src/pages/AIModels.tsx` |
| 7 | Closed a real compliance gap in Memberistic's own Stripe-Checkout membership-signup flow: age verification was gated on a setting (`integration_verifyistic_require_signup`) that defaulted to `no` in three separate places (the filter, the authoritative `signup_blocked()` gate, and the settings-save allowlist fallback) — meaning a fresh install, or one where an admin never opened Integrations → Verifyistic, let anyone buy a paid range membership online with zero age check. All three now default to fail-safe (on). | `memberistic-membership-solutions/includes/integrations/class-verifyistic-bridge.php`, `includes/admin/class-settings-page.php`, `includes/admin/class-admin-menu.php` |
| 8 | Added the missing server-side re-validation to the corporate/staff QR check-in write path: `Corporate_Checkin::maybe_record()` only checked capability + nonce, never re-checking membership eligibility — the "Check In Now" button's disabled state was a client-side cue only, so a direct POST could record range access for an expired/inactive membership. Now hard-blocks on ineligible status server-side (mirroring the panel's own `$eligible` logic); a missing/expired waiver stays staff discretion (as designed — the UI already treats it as a warning, not a hard stop) but is now stamped into the check-in's notes so the audit trail reflects reality. | `memberistic-membership-solutions/includes/corporate/class-corporate-module.php` |
| 9 | Fixed the Fortis Pay refund webhook: `mark_booking_refunded()` only flipped the booking's own status, never touching the `g2ab_payments` ledger row (Stripe's and PayPal's equivalent handlers already did) — meaning a Fortis-paid booking showed "refunded" everywhere in the UI while its underlying payment row permanently still read `succeeded`, silently overstating revenue in any report built off that table. Also added the idempotency guard the paid-path already had, so a webhook retry can't double-process the same refund. | `g2a-booking-engine/includes/payments/class-fortis.php` |

**Not fixed this round — logged for a future pass** (lower severity, or requires a product decision):
Memberistic's Stripe webhook idempotency check is a non-atomic check-then-act (a real `GET_LOCK`
already exists two dozen lines away in the same class for the checkout rate limiter but isn't applied
here), so a redelivered webhook can double-fire duplicate customer emails and downstream hook
listeners; Messageistic's kiosk-integration bridge listens for two hooks (`g2a_kiosk_checkin_completed`,
`g2a_waiver_incomplete`) that nothing in the repo ever fires, so those automation triggers are
permanently inert; `g2a-booking-engine`'s admin CRUD screens and its light-theme shortcode/SEO badges
carry the dark-mode `--color-silver`/gold hex literally onto white admin/light surfaces (real contrast
failures, but a WP-admin/opt-in-light-theme surface rather than the site's own dark mode); a handful of
previously-reported-fixed items the functional agent re-verified were never actually fixed (duplicate
`<h1>` on WooCommerce archives, disconnected Organization/LocalBusiness/Article JSON-LD, "Founded 2014"
hardcoded in 3 places instead of reading `founded_year`, the dashboard-app mobile nav drawer's
`overflow:hidden` scroll-lock still broken on iOS, a few theme 3-column grids missing a tablet
breakpoint step).

Versions after Round 6: g2a-booking-engine → **1.9.9.10**, memberistic-membership-solutions →
**1.10.6**, wpistic-contact-form → **1.5.8**, advanced-ffl-checkout → **1.9.4**, g2a-pos-core →
**3.1.9**, Formistic → **2.0.7** (its first appearance in this repo's release-zip pipeline —
`scripts/build-release-zips.sh` and `INSTALL.md` now include it). guns2ammo theme, verifyistic, and
messageistic are unchanged this round. `dashboard-app` has no plugin-zip release artifact (deployed
separately per `DEPLOYMENT.md`). Packaged archives are in `releases/` (previous version of each
retained alongside); `INSTALL.md`'s version table was updated to match.

**Between Round 6 and Round 7:** `wpistic-contact-form` was retired entirely and its functionality
merged into Formistic (branded HTML emails, one-click newsletter unsubscribe, an atomic rate-limiter
lock) — see `docs/FORMISTIC_G2A_SETUP.md` for the full writeup; not repeated here since it's a plugin
consolidation, not an audit-item fix.

### Round 7 — Messageistic dead hooks, Stripe webhook race, and previously-claimed-fixed items

Closes the remaining items this report had logged as "not fixed this round" after Round 6, plus the
"previously-reported-fixed items the functional agent re-verified were never actually fixed."

| # | Fix | Files |
|---|---|---|
| 1 | Messageistic's kiosk-integration bridge listened for `g2a_kiosk_checkin_completed`/`g2a_waiver_incomplete` — two hooks nothing in the repo ever fired, so kiosk SMS automation was permanently inert. Wired both into `g2a-booking-engine`'s self-check-in REST endpoint (`POST /range/self-checkin`) — the actual QR self-serve kiosk flow — firing the checkin event on every successful self-check-in and the waiver-incomplete event when the booking's waiver isn't on file, with a guest-waiver link built from Memberistic's `Waiver_Public::guest_url()` (soft dependency, falls back to a plain `add_query_arg()` call if Memberistic isn't active). | `g2a-booking-engine/includes/rest/class-range-controller.php` |
| 2 | Memberistic's Stripe webhook idempotency check (`is_event_processed()` → `mark_event_processed()`) was a non-atomic check-then-act, despite a real MySQL `GET_LOCK`/`RELEASE_LOCK` pair already existing two dozen lines away in the same class for the checkout rate limiter. Wrapped the check-and-mark in the same lock so two near-simultaneous deliveries of the same Stripe event id (a documented Stripe behavior) can no longer both slip past the dedup check and double-fire duplicate emails/Activity rows/`membership_activated` listeners. | `memberistic-membership-solutions/includes/payments/class-stripe-service.php` |
| 3 | Duplicate `<h1>` on WooCommerce archive pages: a static `<h1>Shop</h1>` hero heading rendered unconditionally alongside WooCommerce's own dynamic `<h1 class="woocommerce-products-header__title">` (which correctly shows the category name on a category archive) — both always rendered together, a genuine duplicate-heading bug, not a false alarm. The hero now renders the one true `<h1>` with the real dynamic title; the second, now-redundant heading was removed (its wrapper + `woocommerce_archive_description` action stayed). | `guns2ammo/woocommerce/archive-product.php` |
| 4 | Disconnected Organization/LocalBusiness/Article JSON-LD: `inc/aeo.php`'s `Organization` node (`#organization`) and `inc/seo.php`'s `LocalBusiness`/`SportsActivityLocation`/`Store` node were two separate, unlinked entities describing the same real business (different `@id`s, no cross-reference) — plus every `Article`'s `author`/`publisher` and every instructor's `Person.worksFor` inlined a *third*, minimal, anonymous duplicate `Organization` object. The LocalBusiness node now shares the same `@id` as the Organization node (the richer view of the same entity, not a second one), and every other reference now points at that one `@id` instead of inlining a fresh duplicate. | `guns2ammo/inc/seo.php`, `inc/instructors.php` |
| 5 | "Founded 2014" hardcoded in prose across 3 files (a 4th, `inc/business-info.php`, is the legitimate dynamic-default source and was untouched) instead of reading the theme's `founded_year` Customizer field — `template-about.php` alone had 5 separate hardcoded mentions, including a "12+ YEARS" stat that now computes from the real founding year instead of being a stale literal. | `guns2ammo/page-templates/template-about.php`, `template-post.php`, `inc/llms.php`, `inc/seo.php` |
| 6 | dashboard-app's mobile nav drawer used `document.body.classList.add('overflow-hidden')` to lock background scroll — confirmed broken on iOS Safari, which doesn't reliably respect `overflow:hidden` on `<body>` during touch scrolling (the same bug class the theme's own drawer was correctly fixed for in Round 2, but this component was never migrated). Added a shared `useBodyScrollLock` hook using the standard `position:fixed` + scroll-position-restore technique, available for future modals too. | `dashboard-app/src/lib/hooks.ts`, `src/components/layout/AppLayout.tsx` |
| 7 | A dozen theme 3-column grids (instructor photos, related-article cards, curriculum/module cards, homepage review cards, the machine-gun weapons/tiers grids, etc.) jumped straight from 3 columns to 1 at their tablet breakpoint with no 2-column step in between — verified each one's actual `@media` rules rather than assuming, and added the missing intermediate step. Pricing/membership plan cards were deliberately left alone: their 3→1 collapse is tied to a "popular plan" visual-treatment reset, a real design choice rather than a missed breakpoint. | `guns2ammo/assets/css/front-page.css`, `machine-gun.css`, `ccw.css`, and 8 `page-templates/*.php` files |

Versions after Round 7: g2a-booking-engine → **1.9.9.11**, memberistic-membership-solutions →
**1.10.7**, guns2ammo theme → **1.27.13**. `formistic`, `advanced-ffl-checkout`, `g2a-pos-core`,
`verifyistic`, and `messageistic` are unchanged this round. `dashboard-app` has no plugin-zip release
artifact (deployed separately). Packaged archives are in `releases/` (previous version of each
retained alongside); `INSTALL.md`'s version table was updated to match.

**Not yet fixed** — no further known open items remain from this report.

### Round 8 — repo-wide version-consistency sweep + root README

Requested as a final check across every plugin/theme version, rather than a fresh audit pass. Compared
each plugin's main-file header `Version:` against its `define('..._VERSION', ...)` constant (all
already matched — no drift there) and then against its `readme.txt`'s `Stable tag` and any version
field in its `README.md`. The latter comparison surfaced real, widespread drift: every plugin's
`readme.txt` had fallen behind its actual shipped version, in one case (`g2a-booking-engine`'s
`README.md` badge, still reading `1.4.0` against an actual `1.9.9.10`) by over 5 major version jumps.
Also found `memberistic-membership-solutions/README.md` citing a "Plugin version" of `1.44.0` —
not a typo of the real version, just stale/wrong. Fixed every one to match the plugin's actual
current version; `releases/` and `INSTALL.md` were already in sync (verified, not just assumed).

| Plugin | `readme.txt` Stable tag (before → after) |
|---|---|
| `g2a-booking-engine` | 1.9.9.4 → 1.9.9.11 (also fixed `README.md`'s version badge, 1.4.0 → 1.9.9.11) |
| `memberistic-membership-solutions` | 1.10.1 → 1.10.7 (also fixed `README.md`'s "Plugin version" line, 1.44.0 → 1.10.7) |
| `formistic` | 2.0.6 → 2.1.0 (also fixed `README.md`'s "Stable version" line, 2.0.0 → 2.1.0) |
| `advanced-ffl-checkout` | 1.9.0 → 1.9.4 |
| `messageistic` | 0.5.1 → 0.5.3 |
| `verifyistic` | 1.4.1 → 1.4.4 |

Not touched: `memberistic-membership-solutions/CHANGELOG.md` tops out at its own `1.10.1` entry (six
versions behind current `1.10.7`) — this repo tracks detailed per-round changes in this audit series
instead, so backfilling six versions of changelog prose there was judged out of scope for a version-
consistency pass; flagged here rather than silently left unmentioned. `guns2ammo-waiver-manager` is a
legacy plugin (integrates ApproveMe + PMPro) superseded by Memberistic's own waiver module for day-to-
day use and was already absent from `INSTALL.md`'s install path before this round — left as-is, noted
in the new root `README.md`'s component table as legacy rather than silently presented as current.

Also added a root-level `README.md` (none existed before) — a repository-wide orientation doc covering
the system architecture (theme + plugins + dashboard-app + Cloudflare Worker, with a diagram), a
one-line description of every component and its status, pointers to `INSTALL.md`/`DEPLOYMENT.md`, a
curated documentation index distinguishing this doc series' living references from its dated
historical records, the single-source-of-truth NAP pattern this whole audit history keeps enforcing,
and the release-process steps (version bump → `build-release-zips.sh` → `releases/` prune →
`INSTALL.md` update → changelog entry) so future contributors don't have to reconstruct the convention
from git history.

No version bumps this round — this was a documentation/consistency pass, not a code change.

## Appendix — audit scope note

This is a source-code audit, not a live-site crawl or a running-instance security test — the environment
this ran in received a 403 from `guns2ammo.com`, so nothing here reflects production configuration
drift (Customizer values, cached CDN behavior, actual DNS/SSL state, live plugin versions). Everywhere a
finding depends on live behavior (Rich Results Test, PageSpeed Insights, GSC indexing status, actual
device testing on iOS Safari), that's called out explicitly in the relevant section rather than assumed.
