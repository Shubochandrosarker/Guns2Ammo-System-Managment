=== Advanced FFL Checkout Solutions ===
Contributors: wordpressistic
Tags: FFL, firearms, WooCommerce, dealer, checkout, ATF, transfer, NICS
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.2.0
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
* 43,000 US ZIP code centroids sourced from GeoNames
* Downloaded and imported on activation (resumable, no bundled data)
* Powers instant Haversine distance queries — no geocoding API needed

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

The plugin downloads 43,000 US ZIP code centroids from GeoNames on activation. When a customer enters a ZIP code, it looks up the lat/lng from the local database and runs a Haversine SQL query against dealer ZIP centroids — no external API calls required at search time. Google Maps is optional for the visual map widget only.

= How long does the initial data import take? =

ZIP import: ~5-10 minutes of background processing (43k rows, 500/batch, 1 batch/minute).
ATF dealer sync: 2-4 hours of background processing (~80k dealers across 55 state CSV files). Both are fully resumable — a server restart or power outage will not restart from zero.

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
