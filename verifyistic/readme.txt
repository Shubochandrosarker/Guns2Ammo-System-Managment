=== Verifyistic - Advanced Age Verification ===
Contributors: wordpressistic
Tags: age verification, age gate, age check, firearms, alcohol, adult, cannabis, popup, COPPA
Requires at least: 5.8
Tested up to: 6.7
Stable tag: 1.4.5
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Advanced age verification system for firearms dealers, shooting ranges, alcohol, cannabis, and adult websites. Modern popup with multiple verification modes.

== Description ==

**Verifyistic** is a production-ready age verification plugin for WordPress. Built for US firearms dealers, shooting ranges, alcohol stores, cannabis dispensaries, and any website requiring age-gating.

= Key Features =

* **Three Verification Modes:**
  * Date of Birth Input (with name fields)
  * Yes / No Confirmation
  * ID & Face Document Upload

* **Modern Popup Design:**
  * Full-screen blur overlay (0–100% opacity control)
  * Mobile responsive & ADA/WCAG compliant
  * SEO-friendly — content stays crawlable by bots
  * Bot/crawler auto-bypass (Googlebot, Bingbot, etc.)
  * Smooth CSS animations, scroll lock

* **Fully Customizable:**
  * Upload client logo
  * Custom heading, message, and button text
  * Full color control (popup background, overlay, buttons, fonts)
  * Button shape selector (Rounded, Pill, Sharp)
  * Custom CSS injection

* **Cookie & Session Management:**
  * 30-day persistent cookie for verified users
  * Session-only option (no remember me)
  * "Remember Me" checkbox toggle

* **Verification Logging:**
  * Logs: name, DOB, age, IP, user agent, page URL, timestamp
  * COPPA-safe: no data stored for failed checks
  * Admin search, filter, and paginated log table
  * One-click CSV export

* **Webhook / API Integration:**
  * POST JSON payload on every verification
  * Compatible with Zapier, Make, n8n, Google Sheets (Apps Script)
  * HMAC-SHA256 signature header for security
  * Built-in webhook test button

* **Compliance:**
  * Minimum age floor hardcoded at 18 (admin can raise, never lower)
  * Full audit log: IP + timestamp + user agent
  * COPPA-safe data handling

= Built by WordPressistic =

Verifyistic is designed, developed, and maintained by [WordPressistic](https://wordpressistic.com).

== Installation ==

1. Upload the `verifyistic` folder to `/wp-content/plugins/`
2. Activate through the **Plugins** screen in WordPress admin
3. Navigate to **Verifyistic → Settings** to configure
4. Set your minimum age, choose verification mode, upload logo
5. Done! The popup activates on all frontend pages

== Frequently Asked Questions ==

= Does this comply with US firearms age requirements? =
Yes. The minimum age is hardcoded at a floor of 18. For firearms, set it to 21.

= Is the site content crawlable by search engines? =
Yes. The popup is CSS/JS overlay only. All page content remains in the HTML and is fully crawlable. Known bots are auto-bypassed.

= Can I exclude certain pages? =
Yes. In Settings → General, add comma-separated page IDs to exclude.

= Does it work with WooCommerce? =
Yes. The popup works on all WordPress pages including WooCommerce shop, product, and checkout pages.

= Where are ID uploads stored? =
ID and selfie uploads are stored in `/wp-content/uploads/private/verifyistic-ids/` with year/month subfolders. The directory is not publicly listed.

= Does this require any third-party API? =
No. All verification is handled locally. The webhook integration is optional and connects to your own endpoints.

== Screenshots ==

1. Dashboard — verification stats and recent logs
2. Settings — General configuration panel
3. Settings — Design & appearance customization
4. Settings — Advanced & webhook integration
5. Verification Logs — full audit table with export
6. Frontend popup — Date of Birth mode
7. Frontend popup — Yes/No mode
8. Frontend popup — ID & Face upload mode

== Changelog ==

= 1.4.5 =
* Uninstall is now non-destructive by default: the age-verification log
  and webhook delivery history are kept when the plugin is deleted, unless
  an admin explicitly opts in via a new "Delete verification records on
  uninstall" toggle on the Settings page. Previously every uninstall
  silently dropped both tables with no way to recover the audit trail.

= 1.4.1 =
* FIX: Visitors on page-cached sites were walled off with "Network error.
  Please try again." and could not enter at all. The security nonce baked
  into cached HTML expires after the WordPress nonce lifetime; once stale,
  every verify request was rejected with a 403 — and the token-refresh
  endpoint required that same stale nonce, so there was no way to recover.
  The refresh endpoint no longer requires a nonce (it only mints anti-abuse
  material and is now per-IP rate limited) and returns a fresh nonce along
  with the fresh token. The popup JS refreshes both on show, and a submit
  that still hits a 403 silently fetches fresh credentials and retries once
  instead of dead-ending on the error message.

= 1.3.2 =
* FIX: Customers on page-cached sites (WP Rocket, LiteSpeed, Cloudflare, etc.)
  could be permanently blocked at the age gate with "Please take a moment to
  complete the form and try again", even with a valid date of birth. The
  anti-bot timing token baked into cached HTML was arriving stale, expired, or
  already burned by an earlier visitor and was treated as a hard failure. The
  token is now a soft anti-bot signal: a stale/expired/burned/missing token
  fails open (the nonce, honeypot, per-IP rate limit and DOB age check remain
  the hard gates), while a correctly-signed token submitted impossibly fast for
  a human is still hard-blocked as a bot.

= 1.2.0 =
* NEW: Mobile-friendly Date of Birth picker — replaced the native date input
  with fast Month / Day / Year dropdowns (no more endless year-scrolling on
  phones). Validates real calendar dates client- and server-side.
* Theme-skinnable popup (the host theme can restyle the .vfy-* elements).

= 1.1.0 =
* NEW: Multiple webhook connections — fan verification data out to unlimited
  platforms (Zapier, Make, n8n, CRM, Google Sheets, custom endpoints), each with
  its own URL, HMAC secret, enabled flag and per-event subscription.
* NEW: Webhook delivery log + automatic retry with capped exponential backoff.
* NEW: Hardened verification layer — per-IP rate limiting, honeypot field,
  HMAC-signed anti-bot/anti-replay timing token, and a DOB sanity ceiling.
* Legacy single webhook is auto-migrated into a "Primary" connection.
* Fix: removed a duplicated code block in the old single-webhook sender.

= 1.0.0 =
* Initial release
* Three verification modes: DOB, Yes/No, ID & Face
* Full admin dashboard with stats
* Settings panel with tabbed UI
* Verification log with CSV export
* Webhook integration with HMAC signature
* Cookie and session management
* ADA-compliant, SEO-friendly popup

== Upgrade Notice ==

= 1.3.2 =
* Hardened anti-bot timing token; added per-IP rate limit; webhook fanout to multiple connections.

= 1.2.0 =
* Multiple webhook connections; admin diagnostics.

= 1.1.0 =
* Strict mode; SSRF guard on webhook URL.

= 1.0.0 =
Initial release.
