=== Advanced FFL Checkout Solutions — G2A Edition ===
Contributors: wordpressistic
Tags: FFL, firearms, WooCommerce, dealer, checkout, ATF, transfer, NICS, guns2ammo
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Complete Federal Firearms License (FFL) dealer management, WooCommerce checkout integration, and transfer lifecycle tracking for licensed firearms retailers.

== Description ==

**Advanced FFL Checkout Solutions** is a production-ready WordPress plugin built specifically for federally licensed firearms retailers. It integrates deeply with WooCommerce to provide a seamless FFL dealer selection experience at checkout, backed by the full ATF dealer database (~80,000 dealers) and a complete transfer lifecycle management system.

### Core Features

**FFL Dealer Search at Checkout**
* Vanilla JS dealer selector widget — no page reloads, no Google Maps required
* ZIP code search with Haversine distance calculation (all local — zero external API calls)
* Shows distance, transfer fee, license type, and preferred dealer badges
* Auto-populates from customer billing ZIP

**ATF Dealer Database**
* Monthly sync of the complete ATF FFL licensee list (~80,000 dealers)
* Chunked, fully resumable import — safe against power interruptions
* 500-row batches on WP Cron every minute until complete
* Automatic background processing with progress tracking

**ZIP Code Engine**
* On-demand ZIP centroid lookup via the public zippopotam.us API — fetched the first time each ZIP is searched, then cached locally so repeat searches are free
* No bulk 43k-row activation import; the plugin warms its own cache as customers shop
* Powers instant Haversine distance queries against the local cache once a ZIP has been seen

**Transfer Lifecycle Tracking**
* Full 11-stage pipeline: dealer selected → payment confirmed → shipped → at dealer → NICS pending/delayed/approved/denied → transferred
* Complete audit trail with append-only event log
* ATF Form 4473 reference and NICS Transaction Number (NTN) storage
* NICS 3-day rule automatic flagging for delayed checks
* Multi-carrier shipment tracking (UPS/USPS/FedEx)

**Email Notifications**
* Transactional HTML emails at every status change
* WordPressistic-branded email templates
* Customer notifications + admin alerts for critical events (NICS delay/denial)
* Works with any SMTP plugin (WP Mail SMTP, Postmark, etc.)

**State Compliance**
* Pre-seeded compliance rules for CA, NY, NJ, MA, MD, IL, HI, CO, WA
* Checkout notices for state-specific requirements (waiting periods, permits, roster)
* Extensible via WordPress filters

**Module Permission System**
* Granular per-user module access (not just 3 fixed roles)
* Owner assigns specific dashboard modules to each manager/staff member
* JWT token injects permissions — no second API call needed in dashboard

**WordPress + WooCommerce Integration**
* WP REST API with JWT Bearer token authentication support
* WooCommerce HPOS (High Performance Order Storage) compatible
* WooCommerce Blocks / Cart Checkout Blocks compatible
* WordPress coding standards compliant — WP directory ready

### REST API Endpoints

All endpoints under namespace `wpistic-ffl/v1`:

* `GET /dealers/search?zip=XXXXX` — ZIP radius dealer search
* `GET /dealers/{id}` — Dealer detail
* `GET /transfers` — Transfer list (auth required)
* `POST /transfers` — Create transfer
* `PUT /transfers/{id}/status` — Update status + log event
* `GET /stats/dashboard` — KPI summary
* `GET /compliance/{state}` — State compliance rules

== Installation ==

1. Upload the `advanced-ffl-checkout` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Navigate to **Advanced FFL → Dashboard** in your admin menu
4. Click **Import ZIP Codes** to start the ZIP centroid import
5. The ATF dealer database will begin syncing automatically after ZIP import completes
6. Mark products as **FFL Transfer Required** in each product's general settings
7. Configure email and checkout options under **Advanced FFL → Settings**

**Requirements:**
* PHP 8.1 or higher
* PHP `ZipArchive` extension (standard on most hosts)
* WordPress 6.4+
* WooCommerce 7.0+
* MySQL 5.7+ / MariaDB 10.3+

**Recommended:**
* JWT Authentication for WP REST API plugin (for dashboard integration)
* SMTP mail plugin (for reliable email delivery)

== Frequently Asked Questions ==

= How does dealer distance search work without a Google Maps API key? =

The plugin looks up ZIP centroids on demand from the public zippopotam.us API the first time each ZIP is searched, then caches the lat/lng row locally. From then on it runs a pure-SQL Haversine query against dealer ZIP centroids — no external API call at search time. Google Maps is optional for the visual map widget only.

= How long does the initial data import take? =

ZIP cache: warms itself as customers search (no bulk activation import). Each unseen ZIP costs one cached API call (~200ms) the first time.
ATF dealer sync: 2-4 hours of background processing (~80k dealers across 55 state CSV files). Fully resumable — a server restart or power outage will not restart from zero.

= How do I mark a product as requiring FFL transfer? =

In the product edit screen, check the **FFL Transfer Required** checkbox in the General product data tab. Also set the item type (handgun/rifle/shotgun) for accurate state compliance checking.

= Does this work with WooCommerce High Performance Order Storage (HPOS)? =

Yes. The plugin explicitly declares HPOS compatibility.

= Can I add custom state compliance rules? =

Yes. Use the `state_rules` database table or add rows directly. The REST endpoint `GET /wpistic-ffl/v1/compliance/{state}` will return them.

= What happens to my data if I deactivate the plugin? =

Deactivation preserves all data. Data is only removed on plugin deletion IF you enable "Delete Data on Uninstall" in Settings first.

= Can the FFL module be used standalone, without the G2A dashboard? =

Yes. The plugin is fully self-contained. The dashboard integration is optional.

== Changelog ==

= 1.7.4 — G2A Edition (Checkout dealer-picker contrast fix) =
* Fix: "Select Your FFL Dealer" intro paragraph (`.wpistic-ffl-widget__lede`) was rendering at low contrast / invisible on some themes — brightened the muted token and hardened it against host-theme colour bleed.
* Synced the repository source tree up to the live 1.7.x feature set (4473 forms, carrier tracking, compliance checks, dealer onboarding, GDPR, scheduler, scorecard, state laws, outbound webhooks) which had regressed to 1.4.0 in the repo.

= 1.7.3 — G2A Edition (Security + correctness audit pass) =
SECURITY
* CORS headers are now scoped to the plugin's REST namespace (`/wpistic-ffl/v1/`) via `rest_pre_serve_request` instead of being emitted globally on every WP request. Stops the dashboard's allow-list from leaking onto unrelated theme/plugin endpoints.
* JWT bridge no longer defaults unknown users to the `staff` role; resolves to `none` (with `[]` permissions) unless `manage_woocommerce` is held or `wpistic_ffl_g2a_role` user meta is explicitly set. Fixes a privilege-escalation surface where any user who could mint a JWT got dashboard / CRM / FFL module access.
* CSV transfer export now requires a `wpistic_ffl_export` nonce. The Export CSV button in `admin/views/transfers.php` is wrapped in `wp_nonce_url()`. Stops CSRF-induced data dumps.
* Theme-bridge subject match (`G2A_Bridge`) tightened — was matching any subject containing the substring "transfer" (sweeping in "Transfer my membership", "Transfer a vehicle", etc.). Now requires the strict subject `ffl transfer request` or an explicit `form_type=ffl_transfer` field.
* `Mailer::dealer_email_for()` now returns `WP_Error` instead of silently falling back to the site admin's address. Confidential dealer-portal links can no longer be misdelivered when a dealer has no `email` column set; callers (`send_dealer_confirmation_email`, `send_dealer_reminder_email`, OTP email) guard via `is_wp_error()`.

CORRECTNESS
* Compliance checkout notices now block checkout by default (`wc_add_notice( ..., 'error' )`). Strict mode can be opted out via `update_option( 'wpistic_ffl_compliance_strict', '0' )` for the legacy warning behavior.
* New `Compliance::validate_dealer_for_buyer()` advisory log fires from `Checkout::create_transfer_on_payment()` when the selected dealer's state and the buyer's billing state aren't on an admin-defined allow-list. Logs only; never blocks.
* `wpdb->prepare()` in `admin/class-wpistic-ffl-admin.php` line 500 now spreads `$values` instead of passing the array — the legacy form silently yields `null` on PHP 8.x and the bulk upsert wasn't running. (Matches the same fix already in `includes/class-wpistic-ffl-sync.php`.)
* `payment_confirmed` added to the `$valid_statuses` whitelist in `API::update_transfer_status()`; that's the status `Checkout::create_transfer_on_payment` writes and it was being rejected with HTTP 400 when the dashboard tried to set it explicitly.
* `Sync::start_full_sync()` no longer mass-inactivates every dealer up front. An aborted import would leave the whole dealer list disabled. We capture `started_at` and only sweep rows whose `last_synced` is older than this run via `Sync::mark_unseen_dealers_inactive()` at the end of `finish_sync()`, and only when at least one row was actually imported.
* Analytics WooCommerce endpoint is now HPOS-aware. When HPOS is active and `wc_order_stats` is unavailable, revenue/orders fall back to `wc_get_orders()` instead of an empty `wp_posts` query. Status breakdown reads from `wc_orders` directly under HPOS.

VISIBILITY
* SMS class now surfaces an admin notice when SMS is enabled but `Verifyistic_Webhooks` is missing, instead of silently no-op'ing for the lifetime of the install.

DOCS
* readme.txt ZIP description corrected — the plugin uses on-demand zippopotam.us lookups with a local cache, not a bulk 43k-row activation import.

= 1.7.2 — G2A Edition (Selected-dealer contrast + theme-bleed guard + responsive pass) =
* FIX: **"Selected Dealer" card on checkout was white-on-white.** The card still carried the light-gradient background from the original design (`linear-gradient(180deg, #F0FDF4 0%, #FFFFFF 100%)`) but the text used the new `var(--wf-text)` (white). Replaced with a tinted-on-dark gradient matching the brass / success palette; the "Directions", phone, and "Change Dealer" links now pin to brass instead of inheriting the host theme's cyan link color.
* NEW: **Theme bleed-through guard.** The Guns2Ammo theme paints anchors cyan and headings brass via `.entry-content` descendant rules — those were leaking into the widget. Added a scoped one-level guard inside `.wpistic-ffl-widget` for anchors, h1–h6, strong/b, label, p, and button elements. Wins the cascade against the theme without `!important` on every line.
* NEW: **Inline JS color hard-codes removed** for the selected-dealer renderer (Directions + phone links). CSS now owns those colors so the brand is applied consistently and the JS payload shrinks.
* NEW: **Expanded responsive breakpoints.** Old stylesheet had a single `max-width: 600px` rule; now layered at 980px (tablet — saved-dealer grid → 2-col), 720px (phone — card actions stack, results-bar collapses), and 480px (narrow phone — tabs go vertical, inputs upgrade to 44 px iOS-finger-target + 16 px font-size to defeat iOS focus-zoom).
* FIX: Loading-message error variant repainted with semantic warning tokens — `rgba(232,128,47,0.12)` background + `var(--wf-warning)` text — instead of the old light-mode `#FEE2E2 / #991B1B` pair.
* FIX: View-toggle "is-active" pill now uses `var(--wf-text)` foreground (was dark-on-dark `var(--wf-ink)` ⇒ unreadable).
* FIX: `#wpistic-ffl-change-btn` specifically styled as a ghost button on dark surface; defeats theme button-default styling.

= 1.7.1 — G2A Edition (Compliance audit accuracy patch) =
* FIX: **Compliance audit no longer flags `wpistic_ffl_process_zip_import` as WARN when the ZIP import has legitimately completed.** The cron correctly self-cancels once `wpistic_ffl_zip_import_status` is `complete` — that's expected behavior, not a problem. Audit now reports PASS with "Work complete — cron correctly unscheduled".
* FIX: Cron-check block also looks for **Action Scheduler-pending actions** (`as_next_scheduled_action`), not just WP-Cron. Async paths migrated to AS in v1.6 are now visible to the audit.

= 1.7.0 — G2A Edition (Customer + Ops + Compliance roundup) =
BRAND / UX
* Critical contrast fix on the checkout widget — the dealer-list result count, card titles, and "Contact Dealer for Fee" were rendering with the brass-button contrast color on a dark surface, making them unreadable. Swapped every misuse of `--wf-ink` for `--wf-text`; recommended + selected dealer cards rebuilt with brass-tinted-on-dark backgrounds instead of light gradients.
* Active tab now uses brass-on-dark for stronger brand alignment.

CUSTOMER EXPERIENCE
* "Request a different dealer" form on the public tracking page — visible only while the parcel is still pre-arrival; HMAC-signature-protected, honeypot, single click confirms.
* .ics calendar invite auto-attached to the "your firearm is ready for pickup" email — next-business-day 12:00 local, 30 min window, 1-hour reminder, dealer name + address in LOCATION, pickup-checklist in DESCRIPTION.
* Spanish (es_US) localization for the customer tracking page — `?lang=es` toggle, header link to flip.

OPERATIONS / ANALYTICS
* Generic outbound webhook dispatcher (G2A_Webhooks_Out) — POST every FFL event to Zapier / Make / n8n / custom CRM / Slack-relay endpoints. HMAC-signed via `X-Wpistic-Ffl-Signature`; failed deliveries retry with exponential backoff (1m / 5m / 30m / 2h / 12h). Per-endpoint event filter. Admin page at FFL → 🔌 Webhooks Out.
* Operations Toolset (G2A_Ops_Tools) admin page at FFL → 🛠️ Ops Tools:
  - **Bulk-set dealer transfer fee by state** (with "only if currently 0.00" safety toggle).
  - **Customer LTV lookup** by email — total transfers, lifetime value (via WC), avg days from order → pickup, last dealer used, full recent-transfer list with one-click order links.
  - **Dealer health alerts** — nightly cron flags dealers whose recent issue-reported rate exceeds 25% (min 5 transfers). Admin email fires when a new dealer joins the flagged list.

SECURITY / COMPLIANCE
* Admin TOTP 2FA (G2A_Admin_2FA) — opt-in per user from their Profile page. RFC 6238, Google Authenticator / Authy / 1Password compatible. Backup codes (8, single-use, SHA-256 hashed). Secret AES-256-CBC encrypted at rest with NONCE_SALT-derived key. Challenge interstitial blocks the admin UI until a valid code is entered. Validated against the RFC 4226 test vectors at smoke-test time.
* WordPress personal-data exporter + eraser (G2A_Gdpr) — Tools → Export/Erase Personal Data now includes FFL transfers + saved dealers. Erasure anonymizes customer name/email/phone but retains the row for ATF compliance.
* Form 4473 worksheet generator (G2A_Form_4473) at `/ffl-4473-draft/{transfer_id}/` — admin-only, browser-printable HTML page that pre-fills Section A/B/C/D field labels from the transfer record. Banner-stamped "DRAFT — NOT FOR ATF SUBMISSION" on every page.
* State law engine expanded to all 50 states + DC (G2A_State_Laws) — top-up routine that adds a baseline rule for every previously-unseeded state. Hand-tuned states are never overwritten.

REACH
* Public dealer onboarding shortcode `[g2a_ffl_dealer_onboard]` — branded form FFLs can fill in to apply for inclusion. Honeypot + per-IP rate limit (3/hour). Submissions land in the events table + admin email.

ADMIN ARTIFACTS
* Per-dealer Scorecard PDF (G2A_Scorecard) at `/ffl-scorecard/{dealer_id}/` — admin-only, browser-printable 90-day report: total transfers, in-flight, completed, issues, avg ship→arrival days, portal-confirmation rate. Quarterly ATF-review-ready.

= 1.6.0 — G2A Edition (OTP 2FA + Scheduler + Saved Dealers) =
* G2A: **Email-OTP 2FA for the dealer portal** — when `two_factor_method = email_otp`, the portal auto-issues a 6-digit code to the dealer's email on first page load and renders an OTP input. Transient-backed (no schema migration), 10-minute expiry, hash-equals verification, 5-miss brute-force cap, throttled "Resend" link (1/min). Recipient address is shown masked (`j***@example.com`).
* G2A: **New brand-aligned OTP email template** — short, clear, 32-pt monospace code, brass-on-graphite frame, 10-min expiry warning.
* G2A: **G2A_Scheduler abstraction** — single class wrapping Action Scheduler (bundled with WC) with a graceful WP-Cron fallback. Gives us idempotent enqueue, retry policy, per-action logging at Tools → Scheduled Actions. Every existing async + recurring job migrated: dealer-token async, carrier daily poll. Async dealer email no longer stacks duplicate cron events under load.
* G2A: **Deactivation cleanup hardened** — cancels every plugin hook from both engines (AS + WP-Cron) to prevent leaked schedules after plugin removal.
* G2A: **Saved dealers / "quick pick" for repeat customers** — auto-populated after every successful FFL order (cap 5, most-recent first). Surfaced as a one-tap strip above the dealer search at checkout. Customer can pin a default or remove entries from the My Account → My FFL Transfers tab. REST endpoints `GET|POST|DELETE /me/saved-dealers` for the dashboard app.
* G2A: **wpistic_ffl_checkout_localize filter** — feature classes can extend the localized JS payload without touching the Checkout class.
* G2A: **Mailer::resolve_dealer_email_public** — public wrapper around the dealer-email resolver so the Portal can render a masked recipient on the OTP form without duplicating the fallback chain.

= 1.5.0 — G2A Edition (Carrier API integration) =
* G2A: **Live carrier status sync** — new G2A_Carrier_Providers class adds three ingestion paths so delivered parcels auto-advance the transfer to `received_by_dealer` without a manual dealer-portal click.
* G2A: **Pull path** — daily WP-Cron (`wpistic_ffl_carrier_poll`) checks every in-flight transfer (status `shipped_to_dealer`, has tracking number) against EasyPost. One key covers UPS / USPS / FedEx / DHL / OnTrac.
* G2A: **Push path** — REST endpoint `/wpistic-ffl/v1/carrier/webhook` accepts HMAC-signed events from EasyPost, Shippo, AfterShip, ShipStation (auto-detected by payload shape). Generic `X-Wpistic-Ffl-Signature` header also supported for custom integrations. Verification gate: `hash_equals( hash_hmac('sha256', body, secret), header )`.
* G2A: **Manual "Check now"** AJAX action (`wpistic_ffl_carrier_check_now`) so any admin can on-demand poll the provider per transfer.
* G2A: **📦 Carriers admin page** at Advanced FFL → 📦 Carriers — provider dropdown, EasyPost API key field, webhook URL display, HMAC secret with one-click rotate, toggle for auto-advance.
* G2A: **Carrier check added to Compliance audit** — surfaces whether the integration is configured (pass/warn/info).
* G2A: **wpistic_ffl_carrier_providers filter** — third-party providers can register themselves; `wpistic_ffl_carrier_webhook_verify` filter lets custom signature schemes override the default HMAC check.
* G2A: **Audit-logged** — every carrier status reception (pull, push, manual) creates an `events` row + `analytics_events` row so the activity log shows the carrier event even when no status advance happens.

= 1.4.0 — G2A Edition (Improvements pack) =
* G2A: **Public customer tracking page** at `/track-transfer/{ref}/{sig}/` — HMAC-protected. Every customer email now includes a "View Transfer Status" CTA pointing here. Visual 5-step timeline (Order Placed → Shipped → Arrived → Background Check → Complete) plus dealer card with click-to-call and Maps directions.
* G2A: **Activity Log admin page** at Advanced FFL → 📋 Activity Log. Surfaces a unified feed of the `events` and `analytics_events` tables — status changes, dealer portal actions, NICS 3-day flags, email sends, customer track-page views. One-click links to each transfer.
* G2A: **Compliance & Security audit page** at Advanced FFL → 🛡️ Compliance. 15 real-time checks (WC active, HPOS declared, token secret in wp-config, trusted-proxy filter, JWT Auth, Verifyistic, cron health, ZIP/ATF data sizes, store FFL license, state rules, NICS attention queue, dealer-email coverage, active tokens, Memberistic).
* G2A: **Regenerate HMAC token secret** admin action — rotates `wpistic_ffl_token_secret`, revokes every active dealer-portal token in one shot, audit-logged.
* G2A: **Admin nag notice** if `WPISTIC_FFL_TOKEN_SECRET` is not defined in wp-config.php (dismissible, scoped to FFL pages).
* G2A: **Carrier auto-advance** (G2A_Carrier) — when admin enters a tracking number on a pre-shipment transfer, status auto-flips to `shipped_to_dealer` and `shipped_date` defaults to today. Public carrier-track URLs generated for UPS, USPS, FedEx, DHL.
* G2A: **Honeypot on the checkout dealer-selector widget** — silent reject for bots; real users never see the trap field.
* G2A: **Google Maps "Directions" link** on the selected-dealer card so mobile shoppers can tap to navigate to the pickup point. Click-to-call phone link too.
* G2A: **`wpistic_ffl_transfer_updated` action** fired from the central API status-update path. Plugins (and G2A_Carrier) can react to arbitrary column changes, not just status flips.
* G2A: **`{tracking_url}` merge tag** added to `Theming::replace_tags()` and auto-derived from `{transfer_ref}` so email templates can drop the customer-tracking link inline.
* G2A: **REST endpoints for the dashboard app**:
  - `GET /wpistic-ffl/v1/activity` — paginated cross-table feed
  - `GET /wpistic-ffl/v1/activity/summary` — daily counts, status breakdown, funnel
  - `GET /wpistic-ffl/v1/transfers/{id}/details` — transfer + dealer + activity timeline + customer tracking URL
  - `GET /wpistic-ffl/v1/compliance/audit` — full audit JSON
* BRAND: full admin + checkout asset rebrand — admin.css purple variables remapped to brass tokens, menu icon switched to the FFL shield in brass, settings header SVG re-skinned, checkout widget CSS variables remapped to graphite + brass (mirrors `guns2ammo/assets/css/tokens.css`), Google Maps marker fill recolored.
* SECURITY: Honeypot on the checkout widget closes the second-to-last attack surface (portal already had one).
* DOCS: `Compliance & Security` page is self-documenting — points admins at exactly the line of wp-config / functions.php they need to touch.

= 1.3.0 — G2A Edition =
* G2A: **HPOS-safe order meta** — Dealer ID, name and ZIP now route through `$order->update_meta_data()` / `get_meta()` instead of `update_post_meta()`. Fixes silent failures on stores running HPOS-only mode.
* G2A: **Dealer ID validation on save** — the dealer ID submitted from checkout is verified against the dealers table before being written to order meta. Stops forged-ID attacks.
* G2A: **Payment-gated dealer notification** — the dealer portal email only fires when `$order->is_paid()` is true. COD / failed-payment orders no longer leak portal links to dealers. Filterable via `wpistic_ffl_can_notify_dealer_on_order`.
* G2A: **Async dealer email** — token issuance moved to `wp_schedule_single_event` so checkout never blocks on SMTP latency. Same hook reused for the auto-send-on-ship path.
* G2A: **Guns2Ammo brand palette is the default** — Theming engine now defaults to brass `#DCB45F` on graphite `#1A191E` (matching `guns2ammo/assets/css/tokens.css`). Customer emails + dealer portal + dealer block all share the same tokens.
* G2A: **Theming-aware customer email frame** — `Mailer::wrap()` rebuilt to pull surface, text, border and accent colors from `Theming::settings()`. Per-status hex literals replaced with semantic keys (`primary`, `success`, `warning`, `danger`).
* G2A: **"My FFL Transfers" My Account tab** — customers can view every transfer linked to their account at `/my-account/ffl-transfers/`. Shows status badge, dealer card, tracking number, NICS 3-day countdown.
* G2A: **WC order ↔ transfer status bridge** — `processing` → payment_confirmed, `completed` → transferred, `refunded` / `cancelled` → cancelled. Never moves a transfer backwards or overwrites a terminal status.
* G2A: **NICS 3-day rule automation** — `nics_delay_expires` is set automatically when a transfer enters the `delayed` bucket (Mon-Fri counted). A nightly admin alert email fires the day the window elapses (one-shot, idempotent).
* G2A: **SMS notifications via Verifyistic** — fires `ffl_transfer_status` webhook events on `shipped_to_dealer`, `received_by_dealer`, `delayed`, `approved`, `transferred` when the customer phone is present. Filterable copy via `wpistic_ffl_sms_message`.
* G2A: **Theme transfer-request bridge** — submissions to `admin_post_g2a_request` (the Guns2Ammo theme's `/transfer-request/` form) now create a placeholder transfer in the FFL DB so the staff dashboard has one inbox.
* G2A: **Theme Customizer wiring** — `g2a_business_name`, `g2a_business_email`, `g2a_business_phone` and `g2a_ffl_license` theme mods are read as defaults for Theming settings. New `{store_ffl_license}` merge tag.
* SECURITY: `Token::client_ip()` only honors X-Forwarded-For / CF-Connecting-IP when REMOTE_ADDR is in the `wpistic_ffl_trusted_proxies` filter list (defaults to none). Stops audit-log + rate-limit spoofing.
* SECURITY: `/dealers/search` REST endpoint is now rate-limited (30 req/min/IP, filterable). Stops bulk scraping of the ATF dataset.
* SECURITY: Portal preview path uses `wp_validate_redirect()` on the return URL — closes the open-redirect-shaped login bounce.
* SECURITY: `License::show_branding()` is now respected by the customer email footer (was previously hard-coded "Powered by Wordpressistic").

= 1.2.0 =
* NEW: **Email dealer immediately on order placed** — when a customer completes checkout for an FFL product, the receiving dealer now gets the secure portal link right away. They no longer have to wait until the admin marks the transfer "shipped to dealer." Toggle: Settings → Portal → "Email dealer immediately when an order is placed."
* NEW: **Dedicated `email` column on the dealers table** — replaces the fragile "paste email into the notes field" workaround. Inline editor in the Dealers admin list lets you set or clear a dealer's portal email in one click. The `wpistic_ffl_dealer_portal_email` filter and the legacy notes-regex fallback still work for backwards compatibility.
* FIX: `Mailer::dealer_email_for()` now resolves email in this order: (1) dealer email column → (2) legacy notes regex → (3) `wpistic_ffl_dealer_portal_email` filter → (4) admin email. The mail log records when the admin fallback is used so silent fallbacks are visible.
* FIX: REST `PUT /dealers/{id}` now accepts an `email` field.
* SCHEMA: bumped to 1.2.0 — adds `dealers.email VARCHAR(190)`. Upgrade is automatic via dbDelta on plugin load.

= 1.1.3 =
* CRITICAL FIX: **Dealer portal email never sent** — root cause was that WP plugin ZIP upgrades don't re-run the activation hook. So `wpistic_ffl_portal_settings` was missing → the auto-send-on-ship hook saw `enabled=empty` and exited silently. Now defaults are guaranteed on every plugin load.
* CRITICAL FIX: **Customer order confirmation email missing** — the Mailer's switch statement used the old status name `payment_confirmed` but newer code paths and the React dashboard send `dealer_selected`, `shipped_to_dealer`, `received_by_dealer`, `delayed`, etc. Added a status-alias map so every variant routes to the correct email template.
* FIX: Email-toggle logic inverted to "explicit opt-OUT" — missing key now means "send" (was "send by accident only"). Fixes sites where settings got saved with empty checkbox values.
* FIX: `Mailer::send()` now returns bool + captures `wp_mail_failed` errors so SMTP issues are surfaced.
* NEW: **🩺 Diagnostics admin page** (Advanced FFL → Diagnostics) — one-page health check showing plugin version, DB schema status, portal config, mail config, SMTP plugin detection, last 10 transfers, last 10 dealer tokens, last 100 mail-log entries.
* NEW: **Built-in mail log** — every `Mailer::send()` attempt logged with timestamp + result + failure reason (capped at 100 entries, stored in WP option).
* NEW: **Reset Settings to Defaults** button in Diagnostics — recovers from misconfigured saved settings.
* NEW: **Clear Mail Log** button.

= 1.1.2 =
* FIX: **Recommended toggle now works** — admin inline JS was reading the localized nonce before WP had a chance to output it. Wrapped in DOMContentLoaded with explicit fallback to window.ajaxurl.
* FIX: **Save buttons work across every Settings tab** — same root cause as the toggle, also wrapped in DOMContentLoaded.
* FIX: **ZIP+4 format dealers now appear in search** — `LEFT(premise_zip, 5)` comparison handles `12345-6789` storage format.
* FIX: ZIP centroid lookup hardened — 3-second timeout (was 8s) and 1-hour failure cache to prevent retry storms when zippopotam.us is unreachable.
* NEW: **Portal preview mode** — `/ffl-confirm/PREVIEW/` (admin-only, requires manage_options) renders the dealer portal with mock transfer + dealer data so admins can preview theme changes without sending a real email.
* NEW: **External integrations layer** — server-side proxy clients for ChatBotistic (WhatsApp lead widgets) and Otter Text (bulk SMS). API keys stored in WP options or wp-config.php constants — never exposed to the browser.
* NEW: **Settings → Integrations tab** — paste API keys, test connections.
* NEW: **Send Test Email button** in Settings → Email Templates — verifies wp_mail() routing through Elementor Site Mailer / WP Mail SMTP / Fluent SMTP / etc.
* NEW: **Business analytics REST endpoints** — `/analytics/woocommerce` (revenue, orders, AOV, top products, low stock, status breakdown, daily trend, new customers), `/analytics/amelia` (bookings, revenue, by-service, day-of-week), `/analytics/chatbotistic` (lead breakdown by widget), `/analytics/otter` (SMS campaign stats), `/analytics/leads` (aggregated multi-source lead breakdown).
* Conflict audit clean — every option, AJAX action, cron hook, REST namespace, and global JS variable is prefixed with `wpistic_ffl` / `wpistic-ffl`.

= 1.1.1 =
* NEW: **Advanced multi-filter dealer search at checkout** — search by ZIP / Name / License # / Phone / State, with tab-switcher UI in G2A brand colors (red/black).
* NEW: **Google Maps view with animated drop-pin markers** — recommended dealers show a gold star marker, regular dealers a red pin. Click to see info window. Map bounds auto-fit to results.
* NEW: **Recommended dealer toggle** — admin can mark any dealer as Recommended directly from the Dealers list. Shows a gold ⭐ badge on checkout + sorts them to the top of results. Uses the existing `is_preferred` column (no schema change).
* NEW: **Dealers without lat/lng coordinates now appear in search** when the user's ZIP/state matches — fixes "can't find my own business by ZIP" issue for FFLs not yet in ATF geo data.
* NEW: Admin CSV export button on the Transfers screen now actually exports (previously linked to a dead endpoint).
* NEW: Admin "Re-geocode dealer" AJAX endpoint for backfilling lat/lng from ZIP centroid.
* Fix: Radius search no longer silently excludes non-geocoded dealers when the user also matches on exact ZIP or state/name.
* UX: Modern checkout card design with tactical red accent, gradient recommended badge, drop-pin animation, info-window select button.
* UX: Mobile-first responsive checkout widget — filters stack cleanly on narrow screens.

= 1.1.0 =
* NEW: **Dealer Confirmation Portal** — HMAC-signed magic-link system. Receiving dealers click one button to confirm receipt, no login required.
* NEW: **Three action states** — Item Received, Report Issue, Not My Shipment.
* NEW: **Configurable 2FA** — choose None / Last-4-of-FFL / Email OTP (coming v1.2.0) in plugin settings.
* NEW: **Token expiry control** — 7 / 14 / 30 / 60 / 90 / 180 day presets, admin-configurable per-site.
* NEW: **Self-contained analytics engine** — tracks token issued → email sent → portal viewed → confirmed, per transfer and per dealer. Zero external plugin dependency.
* NEW: **Portal admin dashboard** — KPI cards, slowest-confirming dealer list, confirmation-rate tracking.
* NEW: **Auto-reminder email system** — configurable schedule (default 3/7/14 days) with escalating urgency copy.
* NEW: **Full theming engine** — every color, font, logo, button label, and footer line configurable from Settings → Theme.
* NEW: **Fresh branded email templates** — confirmation + reminder + admin notification, built mobile-first, accessible to assistive tech.
* NEW: **License feature-flag architecture** — ready for v1.2.0 remote activation against wordpressistic.com with Paid Memberships Pro integration.
* NEW: REST endpoints — `/portal/issue-token`, `/portal/resend`, `/portal/revoke`, `/analytics/portal`, `/analytics/dealers`, `/analytics/timeseries`.
* NEW: Rewrite rule `/ffl-confirm/{token}/` — admin-configurable slug, no theme dependency.
* Security: Single-use tokens, HMAC-SHA256 signing, SHA-256 DB storage (raw token never persisted), constant-time verification, rate-limit per IP, honeypot field, optional HTTPS-only mode, full IP + UA audit log for ATF compliance.
* Schema bump: 1.0.0 → 1.1.0 — adds `wp_wpistic_ffl_dealer_tokens` + `wp_wpistic_ffl_analytics_events`.
* Fix: Main version constant was stuck at 1.0.1 — now matches plugin header.

= 1.0.1 =
* Fix: ZIP import never auto-started — process_chunk now calls start() when status is 'pending'
* Fix: DB tables used ON UPDATE CURRENT_TIMESTAMP which dbDelta cannot handle reliably
* Fix: DATE NOT NULL column had no default value (MySQL strict mode rejection)
* Fix: $wpdb->prepare() used spread+positional args pattern — rewritten to use array approach
* Fix: WooCommerce 8+ order meta not saving — added woocommerce_checkout_order_created hook
* Fix: Variation field callback had mismatched type hint (string vs int for $loop)
* Fix: display_dealer_in_admin/email type hints replaced with safe duck-typed checks
* Fix: Removed pointless Mailer class instantiation (static-only class)
* Improvement: JS script now depends on jQuery for WC checkout refresh compatibility
* Improvement: search_btn i18n key added to JS localization

= 1.0.0 =
* Initial release
* Complete ATF dealer database sync (chunked, fully resumable)
* ZIP centroid engine (GeoNames, 43k ZIPs, no geocoding)
* Vanilla JS dealer selector widget
* Full transfer lifecycle (11 stages)
* NICS tracking + 3-day rule flagging
* Email notifications (customer + admin)
* State compliance rules (12 states seeded)
* JWT bridge + module-level permissions
* WooCommerce HPOS + Blocks compatibility
* WordPressistic admin panel (purple branded)
* REST API (wpistic-ffl/v1)
* WP directory compliant

== Upgrade Notice ==

= 1.0.0 =
Initial release.

== Screenshots ==

1. Admin dashboard with KPI cards and import progress
2. FFL dealer selector widget at WooCommerce checkout
3. Transfer management list with status badges
4. Dealer database browser with filtering
5. Settings panel with email configuration
