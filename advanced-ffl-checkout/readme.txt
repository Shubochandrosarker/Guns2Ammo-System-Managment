=== Advanced FFL Checkout Solutions — G2A Edition ===
Contributors: wordpressistic
Tags: FFL, firearms, WooCommerce, dealer, checkout, ATF, transfer, NICS, guns2ammo
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.21.0
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

= 1.21.0 — 2026-07-16 — Distributors as toggleable add-ons =
* NEW: "🧩 Add-ons" admin page (Advanced FFL → 🧩 Add-ons) — each of the five distributor drop-ship clients (Lipsey's, Sports South, RSR, Bill Hicks & Co., Chattanooga) is now independently toggleable, so a store only carries the hooks/admin surface for the ones it actually uses instead of all five unconditionally.
* A disabled distributor's class is never instantiated by the main bootstrap — no `wp_ajax_*` actions register, no settings/catalog AJAX handlers exist, and its tab disappears from the "📦 Distributors" page. Disabling never deletes that distributor's settings, credentials, or catalog/order history — turning it back on picks up exactly where it left off.
* Enabled by default for every distributor, so upgrading is a no-op with zero functional change until a store explicitly turns one off.
* Disabling RSR (the only distributor that self-schedules a recurring cron for polling asynchronous order confirmations) also clears that cron — closing a gap this round's own code-review pass caught, where the event would otherwise stay scheduled forever with nothing left hooked to it.
* Test coverage: 104 → 111 tests.

= 1.20.0 — 2026-07-16 — Credova firearms financing at checkout =
* NEW: `G2A_Gateway_Credova` (WooCommerce → Settings → Payments → Credova) — a real payment gateway against Credova's lending API (lending-api.credova.com/v2), confirmed against Credova's own open-source Ruby SDK (github.com/ammoready/credova), not marketing copy. Closes the financing gap from the same competitor sweep that led to v1.18.0/v1.19.0's GunBroker/Chattanooga work. Sezzle was deliberately NOT built this round — its own marketing guidelines list firearms as a prohibited co-branding category while several live merchant pages show real firearms retailers using it; that contradiction needs direct confirmation with Sezzle's approvals team first, same "don't guess" posture as everything else in this plugin.
* Redirect-based, not inline like the NMI card gateway: creates a real financing application (confirmed request fields: `storeCode`, `firstName`/`lastName`, `mobilePhone`, `email`, `referenceNumber`, `redirectUrl`, `products` — there is no `amount`/`cartTotal` field, the financed amount is derived from line items), sends the customer to Credova's own hosted application flow, and holds the order on-hold until a real outcome is confirmed. Never marks an order paid at checkout time, since the exact approval signal (response field names, status enum, webhook payload shape) was never independently confirmed — the gem itself is create-only and never documents its own response/webhook shape.
* Resolution happens two ways: a webhook (requires a configured shared secret — left blank, the endpoint refuses all requests with HTTP 503 rather than trusting an unauthenticated claim) and a "Check Credova financing status" order action (plus a daily automated sweep) that polls Credova's confirmed status endpoint directly.
* An unrecognized application status is never guessed either way — the order stays on-hold with a note for staff review, and the approved/declined status-word lists are filterable so a store can correct them once a real account's response reveals the true values.
* Honesty note: whatever field the create-application response carries the redirect URL/application ID under was never confirmed — checkout fails closed with a clear error rather than redirecting to a guessed URL if none of the tried spellings match.
* Test coverage: 97 → 104 tests, covering the confirmed request-payload construction and the defensive field-extraction helper.

= 1.19.0 — 2026-07-16 — Chattanooga Shooting Supplies, a fifth distributor =
* NEW: `G2A_Chattanooga` (Advanced FFL → 📦 Distributors → Chattanooga Shooting Supplies) — a real client against Chattanooga's documented REST API (api.chattanoogashooting.com/rest/v5), cross-verified against four independent real production codebases found on GitHub (a commercial coreFORCE POS module, a real FFL-hub PHP client, a Go client, a JS sync script), including the exact CSV header row of the product-feed confirmed against a real 81,195-row sample export. Closes the next-clearest gap from the same competitor sweep that led to v1.18.0's GunBroker work.
* Catalog sync downloads the product-feed CSV (one file, same "whole catalog in one shot, capped, log the rest as skipped" convention as every other distributor here) and upserts it into the shared catalog cache.
* Order submission (`POST orders`) is always an explicit admin click, and is hard-blocked up front if Chattanooga doesn't have the transfer's dealer FFL on file yet (`GET federal-firearms-licenses/{fflNumber}`) — a real, confirmed precondition check unique to this distributor among the five, so a doomed order never gets sent. A failure of that *check* itself (a transient hiccup on that one endpoint) doesn't block — only a definitive "not on file" answer does; the orders endpoint remains the real arbiter either way.
* Honesty note: there is no FFL-required/firearm-classification column anywhere in the real sample product feed — unlike Bill Hicks/GunBroker, there's no vendor-supplied signal to even guess from. `is_firearm` defaults to false and must come from a store-supplied filter (e.g. matching the feed's own `Category` column).
* Fixed during this round's own review pass (same rigor as v1.17.1/v1.18.0): a CSV-parsing bug that silently dropped any row with a quoted, legally embedded newline; a price/quantity cast that truncated at the first non-numeric character (so `"$1,250.00"` read as `1.0`); a missing `wp_unslash()` on the API token field; and an FFL-not-on-file precondition failure that previously left no audit trail.
* Test coverage: 92 → 97 tests.

= 1.18.0 — 2026-07-16 — GunBroker.com marketplace sync =
* NEW: `G2A_Gunbroker` (Advanced FFL → 🎯 GunBroker) — a real client against GunBroker.com's documented REST API (api.gunbroker.com), confirmed against two independent real production PHP integrations found on GitHub (github.com/NArun412/coreware, github.com/jonfirearmland/gunbroker-bridge), not marketing copy — the same rigor bar this plugin already applies to every distributor client. Closes the clearest gap found in a competitor sweep against AmmoReady, Orchid, and the WooCommerce rival "FFL Cockpit," all of which sync inventory/orders to GunBroker and this plugin didn't.
* NEW: product-listing sync — flag a WooCommerce product "List on GunBroker," set a Category ID, and push it (create/update) via the confirmed `POST/PUT Items` contract; "End Listing" removes it via `DELETE Items/{itemID}`. A "Browse Categories" admin action surfaces GunBroker's real category tree for reference — no full taxonomy is hardcoded or guessed. Listing sync is free to run any time.
* NEW: order import — an hourly cron (and a manual "Check for New Orders" action) pulls GunBroker sales via `GET OrdersSold`, matches the buyer's FFL license against this plugin's own ATF-synced `dealers` table, and creates a real WooCommerce order that flows through the exact same `Checkout::create_transfer_on_payment()` pipeline a normal storefront sale uses — so the FFL transfer, A&D ledger, and Form 4473 worksheet all work unchanged. A GunBroker sale is already paid for on GunBroker's side; importing it never spends money or places an order. Orders with no matching dealer land in a "needs dealer" queue for one-click manual staff assignment.
* FIX: bumped `DB::SCHEMA_VERSION` (stale at 1.11.0 since v1.11.0) so the upgrade path actually re-runs `dbDelta()` — this had silently been skipping every table added since (regulatory_watch, fraud_scores, distributor_products/orders, and now the two GunBroker tables) on any site upgraded in place rather than freshly reinstalled. dbDelta is idempotent, so this safely catches every site up in one pass.
* Honesty notes (same posture as every other integration): GunBroker's DevKey approval terms, rate limits, and full category taxonomy are not published anywhere — a store must request API access directly from GunBroker. The exact JSON field names an order's buyer/FFL data comes back under were never independently confirmed; the importer tries several plausible key spellings defensively rather than assuming one is correct. Ships un-smoke-tested against a live account, same as every distributor client — verify against a real DevKey before trusting it with a real listing/order.
* Test coverage: 76 → 86 tests, covering listing-payload construction, defensive order-field extraction, license normalization, and dealer matching.

= 1.17.1 — 2026-07-16 — Distributor client hardening pass =
* FIX: Bill Hicks `normalize_catalog_row()` treated a blank `marp` (MAP price) column as *present*, silently pricing MAP-less items at $0.00 instead of falling back to `product_price`.
* FIX: Bill Hicks quantity join now also tries the row's UPC against the inventory-file quantity map (falling back to an inline `qty_avail` column, then 0) instead of only ever trying the catalog file's own `product` key, which may not match the inventory file's identifier scheme.
* FIX: Bill Hicks `submit_order()` reused the same remote filename (`{transfer_ref}-order.txt`) on every submission attempt for a transfer, silently overwriting a prior upload before Bill Hicks could pick it up. `build_order_file()` now takes an attempt number and suffixes both the filename and the PO number with it.
* FIX: Bill Hicks and RSR `check_responses()` both used `?: []`/similar on `ftp_nlist()`, which conflates "directory read failed" with "directory is legitimately empty" (`ftp_nlist()` returns `false`, not an empty array, on error) — both now return a `WP_Error` when the FTP listing call itself fails.
* FIX: RSR `check_responses()` re-downloaded every matching response file once per pending order (O(N×M) FTP round-trips) — now downloads each matching file once into a cache, then matches all pending orders against it in a single pass. Matching also now checks the stored `po_number` in addition to `distributor_order_ref`, since the real RSR acknowledgement-file content format was never independently confirmed to key off one specific field.
* NEW: RSR now schedules an actual daily cron (`G2A_Scheduler::recurring()`) to run `check_responses()`, closing a gap where the code's own comment already promised a daily check but nothing ever registered it.
* FIX: the admin panel showed the same green "confirmed" color for RSR/Bill Hicks orders that had only been *uploaded* via FTP, not yet acknowledged by the distributor — an async/eventually-consistent flow, unlike Lipsey's/Sports South's synchronous REST/SOAP responses. Both panels now show amber for "submitted, awaiting confirmation" and reserve green for an actual confirmed status.
* CHANGE: removed Sports South `submit_order()`'s dead `$note` parameter (never read, never persisted).
* CHANGE: removed Lipsey's dead, unreachable, already-`@deprecated` private `encrypt_secret()` shim in favor of its one real call site using `G2A_Distributor_Support::encrypt_secret()` directly.
* CHANGE: the old `?page=wpistic-ffl-lipseys` admin URL (from before the shared "📦 Distributors" tabbed page existed) now redirects to the new page's Lipsey's tab instead of 404ing/access-denying for anyone with it bookmarked.
* Test coverage: 73 → 76 tests, covering the marp/UPC-quantity fixes and the per-attempt filename uniqueness fix.
* No real distributor accounts exist yet to smoke-test against live credentials — this round substitutes a rigorous line-by-line, cross-file, and behavior-preserving code review of all four distributor clients in place of that, per STATUS.md §7's existing standing policy against building/shipping against anything unconfirmed.

= 1.17.0 — 2026-07-16 — Three more distributor drop-ship integrations =
* NEW: **Sports South** (`G2A_Sports_South`) — a real client against Sports South's SSAPI (legacy SOAP-ish ASMX web service at webservices.theshootingwarehouse.com), confirmed against two independent open-source clients. Catalog sync via `DailyItemUpdate`; order submission via the real `AddHeader → AddDetail → Submit` flow with `DeleteOpenOrder` rollback on failure.
* NEW: **RSR Group** (`G2A_Rsr`) — RSR has no REST/SOAP API; both catalog and order exchange are real, confirmed FTPS file transfers (verified against the actual source of the open-source `rsr_group` gem). Catalog sync downloads the dropship-eligible inventory file; order submission uploads a fixed-format order file (file header, ship-to, FFL, detail, trailer lines) and a "Check Responses" action polls for RSR's asynchronous ECONF/EERR confirmation files.
* NEW: **Bill Hicks & Co.** (`G2A_Bill_Hicks`) — also FTP-based, confirmed by triangulating three independent open-source implementations (though, unlike RSR, never officially published by Bill Hicks itself — see the class's own honesty note). Order acknowledgements are listed for staff to read rather than auto-classified, since that exact convention wasn't independently confirmed.
* NEW: `G2A_Distributor_Registry` — a filterable registry (`wpistic_ffl_distributor_providers`) listing all four distributor clients, mirroring the existing carrier-provider pattern.
* CHANGE: the single "📦 Distributor (Lipsey's)" admin page is now "📦 Distributors" — one page, tab-switched per distributor, instead of a growing list of separate admin-menu entries.
* CHANGE: extracted `G2A_Distributor_Support` — shared credential encryption, catalog-cache upsert, and order bookkeeping used by all four distributor clients. Lipsey's own encryption now delegates to this shared helper under the same crypto context string, so already-encrypted Lipsey's credentials keep decrypting correctly.
* Evaluated and deliberately did NOT build a **Davidson's** integration this round: no public catalog field layout and no confirmed order-submission API exist for it — a credible integrator source states plainly it has no automated order path at all. Documented in STATUS.md, same honesty posture as ATF's eZ Check.
* Test coverage: 51 → 73 tests, covering the registry, the shared crypto/upsert/bookkeeping helpers, and the pure catalog-parsing/order-file-building logic for RSR, Bill Hicks, and Sports South.
* No schema change — `distributor_products`/`distributor_orders` already supported multiple distributors via their existing `distributor` column.

= 1.16.0 — 2026-07-16 — Regulatory Watch: filterable + broadened search terms =
* NEW: `G2A_Regulatory_Watch::TERMS` is no longer a hardcoded, unfilterable list — the effective terms are now resolved through the new `wpistic_ffl_regulatory_watch_terms` filter (`G2A_Regulatory_Watch::active_terms()`), the same "every tunable list is filterable" pattern already used for fraud-score weights and the state-rules seed. A store can narrow the list (less noise), widen it (less risk of a differently-worded rule going unnoticed), or replace it outright — no code change required.
* CHANGE: broadened the curated default from 3 terms to 7, grounded in the withdrawn "Licensee eZ Check Verification for Transfers" rule's own language plus plausible phrasings a re-proposed or related rule would use.
* CHANGE: the 📅 Regulatory Watch admin page now lists the currently active search terms, closing a real gap — there was previously no way for a store to see what was actually being watched without reading the plugin source.
* No schema change.

= 1.15.2 — 2026-07-15 — Expanded unit test coverage =
* Added a FakeWpdb test double (`tests/Unit/Support/FakeWpdb.php`) so business logic gated behind `$wpdb` reads/writes can be exercised without a live database. Used it to add the first test coverage for `G2A_Fraud_Score::score_transfer()` (signal detection, weight/threshold scoring, the store-supplied weights filter, and the admin-notify path) and for the v1.15.1 Lipsey's credential encryption (legacy-plaintext passthrough + transparent re-encryption, round-trip decryption, and tamper-rejection). 39 tests → 47 tests. No functional/runtime changes.

= 1.15.1 — 2026-07-15 — Lipsey's credential encryption =
* Security: the Lipsey's dealer email/password stored in wp_options is now encrypted at rest (AES-256-GCM keyed from AUTH_KEY). Legacy plaintext values are re-encrypted transparently on first read; the settings screen and API client behave exactly as before. Rotate the Lipsey's password if this plugin previously ran in production with the plaintext credential.

= 1.15.0 — 2026-07-13 — Cross-repo unification (crossmatch) =
* CHANGE: **The two copies of this plugin are now byte-identical.** A full file-by-file crossmatch between the guns2ammo monorepo copy (still at 1.9.4) and this repo's 1.14.0 confirmed that the v1.14.0 backport had already captured every real fix made independently in the monorepo (checkout age-verification gate, dealer-endpoint rate limit + `notes` leak fix, federal-holiday-aware NICS math, theme Customizer email/phone key fix, iOS zoom CSS fix, atomic transfer-creation/status-advance race guards) — 1.14.0 was a strict functional superset. The unified 1.15.0 tree has been written to both repos so they can no longer drift.
* FIX: Restored the standard printer emoji (U+1F5A8) on the dealer scorecard's Print button — the parity sync had substituted U+1F5B6, a glyph with poor emoji-font coverage that renders as a blank box on many systems. (Only genuine monorepo-side difference worth porting; the monorepo copy inherits all 1.10.0-1.14.0 features, tests, composer/phpunit tooling, and the vendored chart/FPDF libraries with this release.)
* No schema change — DB schema stays at 1.11.0.

= 1.14.0 — Backport real fixes independently made in the guns2ammo repo =
* FIX: **Server-side age-verification enforcement at checkout.** `Compliance::validate_age_verification()` blocks checkout for any cart containing an FFL-required item unless the visitor has passed Verifyistic age verification (cookie + `wp_verifyistic_logs` lookup). This closes a real gap — the Verifyistic popup shown elsewhere on the site was only a UI prompt, not a server-side gate, so a firearm purchase could previously complete with no age check enforced at the actual point of sale. Defaults on, filterable off via `wpistic_ffl_require_age_verification` for stores using a different in-house mechanism.
* FIX: **Public dealer-detail REST endpoint (`GET /dealers/{id}`) was unrated-limited and leaked internal staff notes.** Added the same 30/min per-IP rate limit `/dealers/search` already has (`wpistic_ffl_search_rate_limit` filter) — without it, the endpoint's sequential auto-increment ID made it possible to scrape the entire ~80k-row dealer database bypassing the search endpoint's throttle. Also removed the dealer's internal `notes` column from both this endpoint's and `/dealers/search`'s public response — that field is manager-only staff commentary (written via the manager-gated `PUT /dealers/{id}`), never meant to be public.
* FIX: **NICS 3-business-day math now excludes federal holidays**, not just weekends — a dealer is normally closed on a federal holiday same as a weekend, so a holiday inside the window was making the computed expiry land before the true statutory window under 18 U.S.C. § 922(t)(1)(B)(ii). New `wpistic_ffl_federal_holidays` filter (11 OPM-list holidays, weekend-observance-shifted). The Form 3310.4 multi-sale watcher's own 5-business-day window now reuses the same holiday table for consistency (it was already documented as mirroring this exact math).
* FIX: **Theme business email/phone were always reading empty.** `Theming::default_theme_settings()` was reading nonexistent `g2a_business_email`/`g2a_business_phone` theme_mod keys — the Guns2Ammo theme's actual Customizer keys are `g2a_email`/`g2a_phone` (via `g2a_biz_email()`/`g2a_biz_phone()`). Emails/portal pages using these as a fallback support contact were silently falling back to `admin_email`/blank this whole time.
* FIX: **iOS Safari zoomed on focusing a checkout dealer-search field** at non-mobile viewport widths — the 16px anti-zoom font-size was previously scoped to a mobile-only media query; moved to the base rule so it applies everywhere the fix is needed.
* FIX: **Real atomicity for the v1.12.0 multi-item transfer creation.** `Checkout::create_transfer_on_payment()` is hooked on two WC events (`woocommerce_payment_complete`, `woocommerce_order_status_processing`) that many gateways fire close together — the previous per-unit idempotency check was check-then-act (COUNT, then insert the shortfall), so two near-simultaneous calls could both pass the count check and double-insert a transfer for the same physical firearm. New `transfers.order_item_unit` column + `UNIQUE KEY uidx_order_item_unit (order_id, order_item_id, order_item_unit)` make this atomic: the DB itself now rejects a duplicate insert instead of relying on a PHP-side pre-check. Applied the same compare-and-swap pattern (WHERE clause re-checks the expected old status, checks affected-row count) to `G2A_Status_Bridge::advance_transfer()` and the EasyPost carrier auto-advance-on-delivered path in `G2A_Carrier_Providers`, closing the same class of race there.
* SCHEMA: bumped to 1.11.0 — adds `order_item_unit` to `transfers`, replaces the non-unique `idx_order_item` index with `UNIQUE KEY uidx_order_item_unit (order_id, order_item_id, order_item_unit)`.

= 1.13.0 — Remaining market-audit gaps: #11-15 =
* NEW: **Automated test coverage** (gap #14) — this plugin had zero, unlike sibling plugins in the monorepo. Adds a PHPUnit suite (Brain Monkey mocks WordPress functions; there's no live WP/WooCommerce/MySQL environment available here) covering the v1.12.0 multi-item Checkout logic, the WC-status-to-transfer-status map, CSV formula-injection guards, the verification-reminder threshold picker, and NICS 3-business-day math, plus a GitHub Actions workflow across PHP 8.1-8.3.
* NEW: **Real charting/BI layer** (gap #15) — every admin "dashboard" was HTML KPI tiles and tables. Adds a small, dependency-free vendored canvas chart renderer (no CDN, matches the FPDF vendoring pattern) on the main FFL Dashboard (transfers-by-status, 30-day trend) and the Dealer Confirmation Portal page (tokens-issued vs. confirmed trend). Also fixes the hardcoded `coming_soon: true` "Phone Calls" stub in the leads-analytics REST response — replaced with `wpistic_ffl_phone_call_count` / `wpistic_ffl_phone_call_source_label` filters so the row only appears once a site connects real call-tracking, instead of a fake placeholder that never resolved.
* NEW: **Persistent dealer portal login + self-service** (gap #12, Advanced FFL → 🔑 Dealer Logins) — an opt-in alternative to the single-use magic-link confirmation flow, which is unchanged and stays the default for any dealer who isn't invited. A real WP user account (new `ffl_dealer` role, no wp-admin access) linked to a dealer via new `dealers.wp_user_id` lets a dealer log in any time via the new `[ffl_dealer_portal]` shortcode to see and act on their own active transfers and update their own contact info.
* NEW: **Buyer-side fraud / straw-purchase risk scoring** (gap #13, Advanced FFL → 🚩 Fraud Review) — a transparent, filterable, rules-based scorer (no fraud vendor — same honesty rationale as the NICS/ID-verification provider registries) covering order/IP velocity, first-time-buyer + high-value orders, dealer/buyer state mismatch (an ATF-documented straw-purchase indicator), disposable email domains, correlation with the Form 3310.4 multi-sale watcher, and rapid dealer-switching. Recommendation only — never blocks, holds, or cancels a transfer automatically.
* NEW: **Lipsey's distributor drop-ship integration** (gap #11, Advanced FFL → 📦 Distributor) — a real client against Lipsey's documented dealer API (api.lipseys.com), confirmed against the public reference client rather than guessed. Catalog sync is free to run any time; drop-ship order submission is always an explicit admin click, scoped to a specific transfer's own dealer FFL, never automatic. Ships un-smoke-tested against a live Lipsey's account — no dealer credentials were available in-session; verify before trusting it with a real order (same honesty note as the NMI gateway).
* SCHEMA: bumped to 1.10.0 — adds `wp_user_id` to `dealers` and `customer_ip` to `transfers`, and three new tables: `fraud_scores`, `distributor_products`, `distributor_orders`. Upgrade is automatic via dbDelta on plugin load.

= 1.12.0 — Multi-firearm orders + Verification Hub Phase B =
* FIX: **Checkout now creates one transfer per FFL unit, not just one per order.** `Checkout::create_transfer_on_payment()` previously found only the *first* FFL-flagged line item in an order and stopped — a cart with 2+ firearms (either as separate line items or a quantity of 2+ on one line) silently undercounted the Acquisition & Disposition bound-book ledger, the Form 3310.4 multiple-sale watcher, and EasyPost rate-shopping/label-buying, all of which are documented, known gaps as of v1.10.0/v1.11.0. `transfers` gets a new `order_item_id` column so a re-fired hook tops up any still-missing units instead of creating duplicates or bailing out once the first unit exists. `G2A_Status_Bridge` (WC order status → transfer status) and the customer-LTV lookup in Ops Tools were both updated to handle an order owning several transfers — the LTV calculation was silently double-counting an order's total once it had more than one transfer row.
* NEW: **Verification Hub Phase B** (from the v1.9.0 phase plan). Three pieces, all extending the existing 🛡️ Verification Hub:
  * **Certified-copy expiration reminders** at 60/30/7/0 days out, emailed to the admin, reusing the shared daily cron. Each threshold fires exactly once per document.
  * **🛡️ FFL Verification Status dashboard widget** on the WP admin dashboard — missing certified copies, copies expiring within 30 days, and dealers needing review, without opening the Hub page.
  * **CSV + PDF audit export** of the dealer verification directory (Advanced FFL → 🛡️ Verification Hub → Export CSV / Export PDF).
* NEW: **📅 Regulatory Watch** (Advanced FFL → 📅 Regulatory Watch) — a nightly sweep of the real, public, key-free Federal Register API for ATF documents matching FFL-licensee-verification terms (in case a rule like the withdrawn "Licensee eZ Check Verification for Transfers" is revived or a similar one is proposed). Alert-only: a new match emails the admin and is logged for review; nothing here ever changes the Hub's policy-mode setting automatically.
* SCHEMA: bumped to 1.7.0 — adds `order_item_id` to `transfers`; adds `reminder_60_sent_at` / `reminder_30_sent_at` / `reminder_7_sent_at` / `reminder_0_sent_at` to `ffl_verification_documents`; adds `wp_wpistic_ffl_regulatory_watch`. Upgrade is automatic via dbDelta on plugin load.

= 1.11.1 — Exhibit 09 becomes a proper g2a-booking-engine addon =
* CHANGE: **`G2A_Booking_Bridge` now delegates to a real g2a-booking-engine module.** g2a-booking-engine ships a first-party `ffl-checkout` addon module (`G2AB_Module_Ffl_Checkout`) as of its own v1.9.9.4 — this plugin no longer writes that plugin's `g2ab_resources` / `g2ab_booking_types` / `g2ab_availability_rules` tables directly; it calls the module's public `sync_dealer_resource()` / `ensure_pickup_booking_type()` API instead. Requires g2a-booking-engine 1.9.9.4+ for scheduling to be available (older versions fall back to the `.ics` invite, same as when the booking engine isn't installed at all).
* FIX: **Security hardening — a booked pickup time is now validated as a real open slot.** The booking-engine's admin-bookings path (used to create the appointment in-process, for the reasons documented in `G2A_Booking_Bridge`'s class docblock) applies no business-hours/lead-time/blackout validation of its own by design — previously the only protection against a forged or stale `start_at` was the race-safety capacity check, which prevents double-booking but doesn't verify the time is a real, open, in-hours slot at all. `ajax_confirm()` now calls the new module's `is_real_open_slot()` to re-derive real availability server-side before booking, rejecting with HTTP 409 otherwise.
* NOTE: the default-hours filter for a newly-created dealer resource moved with the seeding logic — it's now g2a-booking-engine's `g2ab_ffl_checkout_default_hours` (fired from the module) instead of this plugin's `wpistic_ffl_booking_default_hours`. Exhibit 09 shipped in v1.11.0 (this same audit round), so no site has had the old hook in production long enough to depend on it.

= 1.11.0 — G2A Edition (FFL Checkout Solutions audit — Exhibits 06-10) =
* NEW: **Real license activation** — Exhibit 06. `License::activate()` now makes a real signed HTTP request to `wordpressistic.com/wp-json/wpistic-licenses/v1/{activate,validate,deactivate}`, with weekly revalidation and a 7-day grace window if the server is briefly unreachable (exactly the plan the code's own long-standing comment described). Investigated Memberistic (this account's other membership plugin) as a possible backend — it's Guns2Ammo's shooting-range membership system (Defender/Patriot/Guardian tiers), an unrelated product, so it was not repurposed. The client is real and correct; standing up the actual server at that URL is a separate deployment this PR doesn't include (see STATUS.md).
* NEW: **NMI payment gateway adapter** (WooCommerce → Settings → Payments → NMI) — Exhibit 07. Most real firearms-tolerant high-risk processors (PaymentCloud, Durango Merchant Services, Easy Pay Direct) are white-labeled on top of NMI, so this targets NMI's documented Direct Post / Collect.js contract directly. Card data is tokenized client-side (Collect.js) — this site never sees or stores a raw card number, keeping PCI scope at SAQ A-EP. New compliance-audit check flags when a known firearms-averse gateway (Stripe/PayPal/Square) is the active one.
* NEW: **Carrier rate-shop + label purchase** — Exhibit 08. `G2A_Carrier_Providers` now quotes EasyPost rates automatically when a transfer is created (using the linked WooCommerce product's real weight/dimensions when set, or a sensible handgun/long-gun default), and adds a "Buy Label" admin action next to the existing "Check Now" button. Defaults to requiring an adult signature at delivery (standard carrier policy for firearms shipments, admin-configurable). Rate-shopping is automatic and free; buying a label is always an explicit admin click — real money spent never happens silently.
* NEW: **Real pickup scheduling via g2a-booking-engine** (Advanced FFL "arrived at dealer" email) — Exhibit 09. Replaces the old fixed-guess `.ics` invite (next business day, 12:00, 30 minutes — a time nobody confirmed) with a link to a page that queries the dealer's ACTUAL availability from g2a-booking-engine (a real, already-running, race-safe appointment system in the same business system) and lets the customer book a real open slot. No changes to g2a-booking-engine were needed — its public `/availability` REST route and its transactional (`SELECT ... FOR UPDATE`) booking-creation logic already covered everything; this plugin calls that logic in-process and idempotently syncs each dealer to a booking-engine "resource" the first time it's needed. Falls back to the old `.ics` invite on sites without the booking engine installed.
* NEW: **ID / age verification gated by ship-to state** (Advanced FFL → 🪪 ID Verification) — Exhibit 10. New `_wpistic_ffl_age_restricted` product flag (for ammunition/accessories that ship without an FFL transfer) alongside the existing FFL-required flag. In a configured state, a checkout notice (informational or blocking) appears, and once the order is placed the customer gets a secure link to submit a photo ID + DOB for staff review — approving is always a manual decision, never automatic. Same honest, filterable provider pattern as Exhibit 01's background-check registry: manual review ships as the real default, with an HMAC-verified webhook route for a future connected hosted provider (Persona/Jumio/ID.me-class) to push results — no proprietary vendor API was hardcoded since this plugin has no way to test against one.
* NEW: **Product admin fields for excise-tax opt-in and age-restriction** — small additions to the product data panel added in v1.10.0.
* SCHEMA: bumped to 1.6.0 — adds `wp_wpistic_ffl_id_verifications`; adds `easypost_shipment_id`, `shipping_rates_json`, `shipping_label_url`, `booking_appointment_id`, `booking_slot_start` to `transfers`. Upgrade is automatic via dbDelta on plugin load. Uninstall now also drops the new table.

= 1.10.0 — G2A Edition (FFL Checkout Solutions audit — Exhibits 01-05) =
* NEW: **Background-check provider registry** (Advanced FFL → 🔫 Background Check) — Exhibit 01. Mirrors the existing carrier-provider pattern: a filterable registry (`wpistic_ffl_background_check_providers`) plus an HMAC-verified push webhook (`/nics/webhook`) so a dealer's own NICS E-Check integration or a licensed vendor can report a result automatically. There is no public NICS API to call, so this is push-only, same constraint class as ATF's FFL eZ Check. Manual entry remains the permanent fallback and writes into the same fields. A "delayed" result auto-starts the federal 3-day clock (a timer, not a compliance decision); "proceed"/"denied" are recorded and emailed to staff for review — nothing here ever auto-approves or auto-denies a transfer.
* NEW: **Acquisition & Disposition (bound book) ledger** (Advanced FFL → 📕 Bound Book) — Exhibit 02. Serial-level `ad_ledger` table, auto-populated on receipt (acquisition) and completed transfer (disposition), with a "Needs Serial Number" review queue and an ATF-format CSV export. Models the receiving FFL's bound-book obligation; excluded from GDPR erasure (20-year ATF retention requirement), same rationale already used for `transfers` and `signatures`.
* NEW: **Real PDF generation for the 4473 draft worksheet** — Exhibit 03. Vendors FPDF (`includes/lib/fpdf/`) and adds a "⬇ Download PDF" option alongside the existing browser print-to-PDF, embedding captured signatures as real images. Also fixes a gap the audit surfaced: the draft worksheet page had no link anywhere in the admin UI — added "4473 Draft" / "4473 PDF" buttons to the transfer-detail screen. The "DRAFT — NOT FOR ATF SUBMISSION" labeling is unchanged and still correct.
* NEW: **Multiple-sale (Form 3310.4) watcher** (Advanced FFL → ⚠️ Multi-Sale Watch) — Exhibit 04. Detects 2+ handgun transfers to the same buyer within a rolling 5-business-day federal reporting window, raises an immediate admin email alert (same-day filing deadline, so no nightly batching here), and tracks a review queue with a "Mark Filed" action. Never files anything with ATF automatically. Known limitation: a single WooCommerce order containing 2+ handguns currently produces only one `transfers` row (a pre-existing limitation in `Checkout::create_transfer_on_payment()`), so this watcher reliably catches repeat purchases across separate orders but not multiple handguns in one cart — documented rather than silently worked around.
* NEW: **Filterable, versioned state-rules data feed + admin editor** (Advanced FFL → 🗺️ State Rules) — Exhibit 05. State compliance notices now have a real CRUD admin page instead of requiring a code deploy to change; `wpistic_ffl_state_rules_seed` / `wpistic_ffl_state_rules_seed_topup` filters let a future maintained legal-data feed extend or override the built-in seed without touching plugin source. Still explicitly not legal advice.
* NEW: **Pittman-Robertson federal excise tax line item** — Exhibit 05. Opt-in per product (new "Federal Excise Tax" checkbox + 10%/11% rate on the product data panel) for merchants who are themselves the manufacturer/importer of record; adds a `woocommerce_cart_calculate_fees` line item via `wpistic_ffl_excise_tax_amount` (filterable). No store-wide toggle — this only ever fires for a product an admin has explicitly flagged. Not tax advice.
* NEW: **Product admin fields for item type / manufacturer / model / caliber** — these were read everywhere (state rules, 4473 worksheet, A&D ledger, multi-sale detection) but had no admin UI to set them, so every product silently defaulted to `handgun`. Added to the product data panel alongside the existing "FFL Transfer Required" checkbox.
* SCHEMA: bumped to 1.5.0 — adds `wp_wpistic_ffl_ad_ledger` and `wp_wpistic_ffl_multi_sale_flags`; adds `nics_response`/`nics_source` to `transfers` and `source`/`rule_version` to `state_rules`. Upgrade is automatic via dbDelta on plugin load. Uninstall (with "Delete Data on Uninstall" enabled) now also drops the two new tables.

= 1.9.0 — G2A Edition (FFL Compliance Verification Hub — Phase A) =
* NEW: **FFL Compliance Verification Hub** (Advanced FFL → 🛡️ Verification Hub) — built in response to ATF withdrawing the "Licensee eZ Check Verification for Transfers" direct final rule on 2026-07-06. Certified-copy collection stays the required default; this hub organizes that workflow instead of assuming eZ Check can replace it.
* NEW: **Verification Policy Mode** setting — Certified Copy Required (default), Certified Copy + eZ Check Log (recommended enhanced mode), or Custom. There is deliberately no "eZ Check Alternative Allowed" mode while the ATF rule remains withdrawn.
* NEW: **Certified-copy upload + expiration tracking**, scoped per dealer. Files are stored under a private, non-media-library path (`wp-content/uploads/wpistic-ffl-private/`) with a best-effort `.htaccess` deny rule, and are only ever served through a capability-gated view endpoint that logs every view to the Activity Log — never a public URL.
* NEW: **Manual FFL eZ Check log** — staff record what `fflezcheck.atf.gov` returned (name, trade name, address, expiration, outcome) as supporting evidence. There is intentionally no code path that queries ATF's eZ Check tool automatically: ATF publishes no API for it, and automating its public web form would mean scraping a federal compliance tool outside any documented terms.
* NEW: **ATF-sync validity check** — a genuinely automatable check that runs against data this plugin already owns (the monthly-synced `dealers` row: active flag, license expiration, and how stale the last sync is), with zero new external calls.
* NEW: **Manager review queue** surfaces any dealer with an expired/inactive ATF record, a missing or expired certified copy, or a flagged eZ Check mismatch. Nothing here auto-approves a transfer — every check produces a recommendation for a logged staff decision, matching the plugin's existing compliance philosophy (`Compliance::validate_dealer_for_buyer()` already follows the same never-auto-block-or-approve rule).
* SCOPE: the hub only tracks dealers this store has actually shipped an FFL transfer to, not the full ~80,000-row ATF dealer universe already held in the `dealers` table.
* SCHEMA: bumped to 1.4.0 — adds `wp_wpistic_ffl_ffl_verification_documents` and `wp_wpistic_ffl_ffl_verifications`. Upgrade is automatic via dbDelta on plugin load. Uninstall (with "Delete Data on Uninstall" enabled) now also removes the private upload directory, not just the database rows.

= 1.8.0 — G2A Edition (Form 4473 signature capture) =
* NEW: **On-screen signature capture for the Form 4473 worksheet** — the buyer and dealer can now sign directly on the draft worksheet with a mouse/touch/pen signature pad instead of leaving a blank line to fill in by hand. Signed image is embedded into the same printable page, so "Print / Save as PDF" already includes it — no PDF library was added.
* NEW: Signature captures are stored append-only in a new `signatures` table (same audit philosophy as the `events` log) — re-signing adds a new row rather than overwriting the prior capture, so the signing history is never lost. Every capture also writes a `signature_captured` row to the existing Activity Log.
* SECURITY: Signature save endpoint is nonce + `manage_woocommerce`-gated, validates the upload is structurally a real PNG (`getimagesizefromstring`), and caps payload size before it ever reaches the database.
* SCHEMA: bumped to 1.3.0 — adds `wp_wpistic_ffl_signatures`. Upgrade is automatic via dbDelta on plugin load.

= 1.7.5 — G2A Edition (Theme transfer-form bridge fix + hardening) =
* Fix: the theme "Firearms Transfer Request" form posts g2a_subject="Firearms Transfer Request", but the bridge only matched the exact string "ffl transfer request", so the FFL transfer record was never created from the website form. Now matches any subject containing "transfer request" (or an explicit form_type).
* Hardening: all DB work in the admin-post bridge is wrapped in try/catch so a missing table or schema drift can never blank the shared admin-post.php request — the theme handler still runs and redirects to the thank-you page.

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
