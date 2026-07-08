# G2A Live Incident Audit — 2026-07-08

Read-only code audit of the repository against the six reported symptoms. No live
systems were touched; no secret values were read or printed. Four low-risk fixes
are included in this branch as independent commits; everything else is a proposed
action below. **Nothing here is deployed** — plugin deploys for this site are
manual zip uploads, so every fix stays inert until someone builds and uploads a
release with sign-off.

---

## Executive summary (the no-BS version)

**Most of this is one event, not six bugs.** On **2026-07-02** the repo adopted the
"live-production line" of three components in one sweep — booking engine
(`b945e59`), memberistic (`3209e3b`), and theme login hardening (`55fd207`) —
followed July 3–6 by a large new surface (g2a-business-api, AI/dashboard layer,
Formistic rebrand, FFL checkout 1.9.0). The complaint window matches this rollout.

| Issue | Status | Root cause |
|---|---|---|
| A. Confirmation emails (bookings + classes) | **CONFIRMED in code** | `b945e59` deleted the booking-id resolver from email automation — customer sends silently skipped. **Fixed in this PR.** |
| B. Stripe payments not reflecting | Confirmed design flaw + live config unknown | System is webhook-only; the redirect fallback 403s by design flaw. **Fallback fixed in this PR**; webhook config needs a 10-minute Stripe Dashboard check (checklist below). |
| C. POS ↔ memberships | **CONFIRMED in code** (known, being worked) | POS membership sales write to a POS-only table; memberistic never hears about them. Smallest fix mapped below. |
| D. E-commerce / Lipsey's dual API | Partially exonerated | The sync **cannot** zero stock or break checkout — storefront runs on last-known-good data. Real bugs found: account-crossing risk when the two Lipsey's rows lack distinct account numbers, and failures being masked as "synced" (**masking fixed in this PR**). "E-commerce is down" needs one live datapoint to localize (see below). |
| E. Customers can't log in, admin fine | Strong hypothesis (cache) + 3 alternates | `/login/` and `/account/` are edge-cacheable because the plugin's cache exclusion never fires for template-rendered pages. **Hardened in this PR**; Cloudflare rule + one incognito test confirms. |
| F. Slow/non-loading pages | Downstream of D + E | Not an independent issue until D and E are fixed. Contributors identified (worker-pinning sync timeouts, cache flushes, per-page membership queries). |

**Can you keep the site up? Yes.** Nothing found suggests data loss or a security
breach in progress. Bookings ARE being saved (the email failure is post-commit and
swallowed), Stripe IS charging customers (the money is in Stripe; WordPress just
doesn't hear about it), and the storefront serves last-known-good catalog data.
The damage is trust/comms, not data. The two customer-facing bleeds — no
confirmation emails and "unpaid" paid bookings — are addressed by the fixes in
this PR plus one Stripe Dashboard session.

**One structural warning:** production is deployed by manual zip upload and the
repo has already diverged from live once (that's literally what the July 2 "sync"
commits were). Before deploying anything, verify what production is actually
running — the booking engine ships a check for exactly this:
`https://guns2ammo.com/?g2ab_version_check=1` while logged in as admin
(`g2a-booking-engine/g2a-booking-engine.php:208`). It reports executing vs on-disk
version and OPcache staleness.

---

## Step 0 — Timeline

- **Jul 2** — `b945e59` booking engine replaced with 1.9.9.x production line
  (webhooks controller, payment gateways, event booking — and the email
  regression). `3209e3b` memberistic replaced with 1.9.9.4 line (repo was 1.46.0 —
  a *downgrade by number*; new `class-auth.php` login surface). `55fd207` theme
  login hardening (custom login URLs, per-IP throttle). `226ccec` booking 1.9.9.2
  (secret fields become "keep-on-empty" on save).
- **Jul 3–5** — g2a-business-api Phases 1–14 (REST API, automations, AI agents,
  email sender for AI drafts, hourly/daily crons), dashboard SPA, POS/Lipsey's
  credential handling changes (`f3a757a`, `f6edf35`), OpenRouter/RAG layer.
- **Jul 6–7** — Formistic 2.0.6 wiring, FFL checkout 1.8.0/1.9.0, CI workflow.

Also noted: `releases/` zips predate the July 2 line (e.g. booking 1.14.5/1.14.6
zips vs current 1.9.9.2 source) — the release archive does not reflect current
source. Treat the live site's actual versions as unknown until checked.

## Step 1 — System map

| Concern | Owner |
|---|---|
| Lane bookings, class/course registrations, booking emails, Stripe | `g2a-booking-engine` (both flows share one lifecycle: `g2ab_booking_created` etc.) |
| Membership provisioning, member login/account pages | `memberistic-membership-solutions` (+ theme templates `guns2ammo/page-templates/template-login.php`, `template-account.php`) |
| POS, Lipsey's dual-API, wholesaler catalog | `g2a-pos-core` |
| E-commerce storefront | WooCommerce + `guns2ammo` theme + `advanced-ffl-checkout` |
| SMS (not email) | `messageistic` |
| AI/dashboard/automation (new Jul 3+) | `g2a-business-api` + `dashboard-app` |

---

## Issue A — Booking AND class confirmation emails stopped

**Root cause: CONFIRMED.** Commit `b945e59` (Jul 2) deleted the
`resolve_booking()` normalizer from
`g2a-booking-engine/includes/modules/email-automation/class-email-automation.php`.
Lifecycle emitters pass an **integer booking id**
(`class-bookings-controller.php:908`, `:1355`); without the resolver the merge-tag
build collapses the int to `[0 => 123]`
(`class-email-engine.php:139`), `customer_email` renders empty, and the customer
send is skipped by the guard at `class-email-engine.php:58`. The **admin copy
still sends** (it reads its own option, line 63–66) — which is why staff inboxes
looked normal while customers got nothing. Bookings and class registrations fire
the **same hook**, so both broke simultaneously. The booking itself still saves —
the hook is wrapped in log-and-swallow (`class-bookings-controller.php:911-917`).

- **Fix:** restore the resolver — **included in this PR** (commit
  `fix(booking): restore resolve_booking()…`).
- **Risk of deploying:** minimal — re-adds a previously shipped, self-contained
  private method; no schema, no settings.
- **Manual action:** none required. To verify after deploy: submit one test
  booking and one class registration; both customer emails should arrive.
  (SPF/DKIM/transport is effectively exonerated by admin copies arriving.)

## Issue B — Stripe payments not reflecting as paid

**What the code says (confirmed):**
- Integration is Stripe **Checkout redirect** (`includes/payments/class-stripe.php:47`).
- The **only** working path to "paid" was the webhook:
  `POST /wp-json/g2a-booking/v1/webhooks/stripe`
  (`includes/rest/class-webhooks-controller.php:20`), which requires the event
  **`checkout.session.completed`** with `payment_status=paid`
  (`class-stripe.php:213-234`) and a valid signing secret
  (`g2ab_stripe_webhook_secret` or `G2AB_STRIPE_WEBHOOK_SECRET`; empty secret →
  every delivery 400s, `class-stripe.php:171`).
- The customer-return fallback was **dead code**: the endpoint demands the
  `confirm_token` issued at creation (`class-bookings-controller.php:1022-1037`)
  but the return page never had it (`assets/js/frontend.js`) → 403 on every
  attempt. No reconciliation cron exists. So any webhook problem = permanently
  "unpaid" bookings with the money sitting in Stripe.
- A further trap: webhook event ids are marked consumed **before** processing
  (`includes/functions.php:139-152`), so a failed first delivery poisons all of
  Stripe's retries as "duplicate".

**Fix included in this PR:** the confirm-token is now threaded through the Stripe
success URL and the return page passes it to `confirm-payment`, which verifies
the session against Stripe upstream. This restores a second, independent path to
"paid". Risk: low — additive; the token was already returned to the customer in
the create-booking response, so the URL adds no new exposure.

**Manual checklist — needs a human in the Stripe Dashboard (~10 min):**
1. Webhook endpoint exists, live-mode, URL exactly
   `https://guns2ammo.com/wp-json/g2a-booking/v1/webhooks/stripe`.
2. Subscribed events include **`checkout.session.completed`** (and
   `charge.refunded`). `payment_intent.*` alone is NOT enough — the handler
   deliberately ignores it.
3. Open the endpoint's delivery log: 400 = secret missing/mismatch; 403/404 =
   WAF/security plugin or wrong URL; timeouts = origin issue.
4. Re-enter the signing secret in WP (Bookings → Settings) if in doubt — note
   `226ccec` made secret fields keep-on-empty; a re-save around Jul 2 could have
   left it blank. Check presence only, never print it.
5. Confirm the account has no restriction/review flag (firearms merchants get
   extra scrutiny) and that charges are in fact succeeding.
6. Server clock within ~5 min of true time (signature tolerance,
   `class-stripe.php:194`).

**Staff visibility (flagged per ground rules — do NOT widen Stripe access):**
a key-free paid/unpaid screen **already exists**: WP-admin → Bookings → Payments
(`includes/admin/class-payments-list.php`, capability `manage_g2ab_payments`).
Grant that capability to floor staff. Two follow-ups worth building later: remove
the stale "PHASE 1 — pay-in-store" banner (`class-payments-list.php:84-87`) and
add a per-booking "re-check with Stripe" button + a reconciliation cron.

## Issue C — POS doesn't talk to memberships (known; documented, not rabbit-holed)

**Root cause: CONFIRMED.** Two independent gaps:
1. In-store membership sales go through
   `g2a-pos-core/includes/API/MembershipBillingController.php:16` →
   `MembershipRepository::create()` → a **POS-only custom table**
   (`{prefix}g2a_memberships`). No WooCommerce order, no action fired,
   memberistic never notified.
2. Even a membership rung up as a normal product wouldn't provision: memberistic's
   WooCommerce bridge (`class-woocommerce-bridge.php:114`) bails unless the order
   carries `_memberistic_membership_id` meta (`:125`), which only memberistic's
   own web checkout sets. (General POS sales DO fire `payment_complete` correctly
   via `includes/Domain/WooBridge.php:73` — the plumbing is fine; the membership
   flow just never uses it.)

**Smallest viable fix (proposed, not implemented — coordinate with whoever is
already working this):** use the existing designed seam,
`memberistic/includes/integrations/class-pos-bridge.php` (currently read-only).
POS fires `do_action( 'g2a_pos_membership_sold', $data, $id )` after the repo
write in `MembershipBillingController`; POS_Bridge listens, maps tier→plan,
creates the memberistic row, and fires `memberistic_membership_activated` —
reusing the entire existing provisioning chain (role sync, account provisioning,
welcome email, renewal reminders). Mirrors what the WooCommerce bridge already
does for web orders. Risk: medium (touches membership state) — needs a test plan
for sell/renew/cancel before sign-off.

## Issue D — "E-commerce is down" / Lipsey's dual API

**The feared failure mode does not exist in this code.** Verified:
- A failed/timed-out/empty Lipsey's response **short-circuits before any write**
  (`LipseysProvider.php:219-262`); the sync only writes to the staging table
  `g2a_wholesaler_products` (`WholesalerProductRepository.php:63-82`) and **never
  touches WooCommerce stock/status**. There is no "zero items missing from the
  feed" logic anywhere.
- Nothing at add-to-cart/checkout makes a live Lipsey's call — the storefront
  already runs on last-known-good WooCommerce data. The requested fallback
  behaviour effectively **already exists**.
- Retry loops are bounded (≤3 attempts, one re-auth; `LipseysApiClient.php:225-279`).
- Token caches are per-account (`g2a_pos_lipseys_token_%d`) — the two accounts'
  tokens cannot cross.

**Real defects found:**
1. **Account-crossing risk (strongest integration-level suspect for "the dual-API
   setup is broken"):** `WholesalerRepository::findByCode()` (`:22-33`) returns
   the **lowest-id** Lipsey's row — so anything resolving by provider code always
   gets the *firearms* account and the accessories account is unreachable via
   that path (`WholesalerImportBridge.php:83-88`). Worse, `upsert()`'s fallback
   match (`:52-59`) keys on `provider_code + account_number`; if both rows carry a
   blank account number they're indistinguishable and a save can overwrite one
   account's (retained) password with the other's. **Manual check first:** confirm
   in the POS admin that both Lipsey's rows have distinct, non-empty account
   numbers. Code fix (proposed): make `findByCode()` callers account-aware and
   refuse fallback-matching on blank account numbers. Risk: low-medium.
2. **Failures masked as success:** the hourly cron stamped `last_sync_at` even
   when the sync returned `ok=false` (`Core/Plugin.php:213-215`) — a dead account
   looks healthy in the admin. **Fixed in this PR.** Risk: minimal.
3. **Performance side-effects (feeds Issue F):** catalog fetches allow 120 s × 3
   retries per call (`LipseysApiClient.php:182`) pinning PHP workers when Lipsey's
   hangs, and the catalog import calls `wp_cache_flush()` every 200 items
   (`LipseysProvider.php:197`) — degrading the whole site during a big import.
   Proposed: cap timeouts (~30 s), replace full cache flush with targeted deletes.

**"E-commerce is down" is not yet explained by this code.** The one live datapoint
needed: what does a customer actually see (blank page? empty shop? checkout
error?) and what's the matching line in the PHP error log. If products are
missing/zeroed, the cause is outside the Lipsey's sync (manual action in admin,
a WooCommerce-level change, or the FFL checkout 1.9.0 rollout) — check
WooCommerce → Status → Logs and the fatal-error log for Jul 2–8.

## Issue E — Customers can't log in; admin fine

Login flow: header SIGN IN → `/login/` (`guns2ammo/template-parts/nav.php:168`) →
theme template renders `[memberistic_login]` via `g2a_plugin_section()`
(`page-templates/template-login.php:17`, `inc/plugins.php:30`) → memberistic
`Auth::process_login()` → `wp_signon()` → redirect to `/account/`. No
`authenticate` filter anywhere in the repo — a role-blocking auth hook is ruled
out. No caching plugin in the codebase — caching is at the Cloudflare edge.

**H1 (strongest) — edge-cached login/account pages.** Memberistic's cache
exclusion (`class-auth.php:104-143`) only fires when the page's `post_content`
contains the shortcode or the page IDs are set in `memberistic_settings`. The
theme renders the shortcodes from page templates onto **empty-content pages**
(`inc/login.php:57`), so unless the settings are filled in, `/login/` and
`/account/` go out cacheable. Result: a member signs in (POST bypasses cache), is
redirected to `/account/`, and the edge serves the **cached logged-out variant —
which renders the login form again** (`class-shortcodes.php:103-105`). To the
customer, login "doesn't work." Admins are immune: their logged-in cookie
bypasses cache — exactly matching "on my PC I can move around without issues."
Explains part of Issue F too (cache misses hit full PHP for those users).
**Hardened in this PR** (theme now sends no-cache headers on both templates —
safe regardless of which hypothesis is right). **Manual actions:** in
Memberistic → Settings set the Login/Account page IDs; at Cloudflare verify no
cache-everything rule covers `/login/`, `/account/`, `/my-account/` and that
`wordpress_logged_in_*` bypasses cache.
**Confirming test (2 min):** incognito, `curl -sI https://guns2ammo.com/account/`
→ `cf-cache-status: HIT` is the smoking gun.

**H2 — shared-IP login throttle (new in `55fd207`, Jul 2).**
`guns2ammo/inc/login.php` 429s wp-login.php POSTs after 20 failures per 15 min
per IP, resolved via `CF-Connecting-IP` **falling back to `REMOTE_ADDR`**. If any
customer path reaches origin without the CF header, all customers share one
proxy-IP counter → everyone gets "Too many failed sign-in attempts". Affects
wp-login.php-routed logins (e.g. WooCommerce `/my-account/`). Check the access
log for 429s; fix is correct client-IP resolution at the proxy layer.

**H3 — stale cached WooCommerce nonce** on `/my-account/` (same cache root cause;
that form carries `woocommerce-login-nonce`, the memberistic form has no nonce).

**H4 — memberistic version swap (`3209e3b`) schema mismatch.** Members (never
admins — `class-content-restrictions.php:294` short-circuits on
`manage_options`) trigger membership lookups on every page; if the swapped-in
code's queries don't match the live DB schema, members get errors/white screens
admins never see. Check `debug.log` for SQL errors on `*_memberships`/`*_people`
tables.

**Triage order:** run the single incognito login test while watching the network
tab — `cf-cache-status: HIT` → H1; 429 → H2; nonce error → H3; 500 → H4.

## Issue F — Slow / non-loading pages

Treat as downstream until D and E land. Identified contributors, all secondary:
uncached full-PHP requests for exactly the users hit by Issue E; Lipsey's
120 s×3 worker pinning + `wp_cache_flush()` during imports (Issue D); per-page
membership lookups for logged-in members (~4 queries/page,
`class-content-restrictions.php:32` → `class-booking-engine.php:165-192`);
new hourly g2a-business-api AI crons firing on visitor requests if WP-Cron is
request-driven (`class-insight-generator.php:37`; recommend `DISABLE_WP_CRON` +
a real system cron). Re-evaluate only if slowness persists after D + E.

---

## Fixes in this branch (each independently revertable)

1. `fix(booking): restore resolve_booking()` — **Issue A, confirmed root cause.**
2. `fix(booking): Stripe return-page fallback` — **Issue B recovery path.**
3. `fix(pos): stop stamping last_sync_at on failed sync` — **Issue D masking.**
4. `fix(theme): no-cache headers on login/account templates` — **Issue E H1.**

## Data/access still needed to close this out

- PHP error log + WooCommerce → Status → Logs for Jul 2–8 (Issues D, E-H4, F).
- One screenshot/description of what "e-commerce is down" looks like to a customer.
- Stripe Dashboard session per the Issue B checklist (someone with existing access).
- Cloudflare: cache rules + WAF events for `/wp-json/g2a-booking/v1/webhooks/stripe`.
- Output of `?g2ab_version_check=1` (admin-only) to confirm what production runs.
- Confirmation that both Lipsey's wholesaler rows show distinct account numbers.
