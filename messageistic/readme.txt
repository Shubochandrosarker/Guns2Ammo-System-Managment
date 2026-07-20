=== Messageistic ===
Contributors: wordpressistic
Tags: sms, twilio, ottertext, communication, automation
Requires at least: 6.2
Tested up to: 6.5
Requires PHP: 8.0
Stable tag: 0.8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Premium provider-independent SMS and customer communication engine for WordPress. Supports self-hosted gateways (Jasmin, Android gateway app), OtterText, Twilio, and Testing providers.

== Description ==

Messageistic is a premium WordPress communication engine built by WordPressistic. It manages contacts, conversations, campaigns, templates, automations, logs, and reports — independent of the underlying SMS provider.

Supported providers:

* Local Gateway App — SMS Gateway for Android™ (self-hosted, local server or cloud relay mode)
* Self-hosted SMS — Jasmin SMS Gateway (SMPP middleware you host)
* OtterText
* Twilio
* Testing / Sandbox

Switch providers from settings without rebuilding the plugin.

== Installation ==

1. Upload the `messageistic` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu.
3. Visit Messageistic → Settings to configure your active provider.

== Changelog ==

= 0.8.1 =
* No functional change — version bump to align with Guns 2 Ammo System Release 2.5.0.

= 0.8.0 =
* New: `waiver.signed` lifecycle trigger — hooks Memberistic's `memberistic_waiver_signed` action to send a confirmation SMS with the waiver's validity date (skipped when the signer left no phone number).
* New: `waiver.renewal_due` lifecycle trigger — hooks Memberistic's `memberistic_waiver_renewal_due` action, carrying the tokenized re-sign link.
* New: Firearm Workflow Pack presets for both triggers above, installed inactive with the same consent/opt-out conditions as existing pack entries.

= 0.7.0 =
*Release date: 2026-07-13*

Crossmatch/unification release: the two divergent copies of Messageistic (Guns2Ammo monorepo 0.5.3 line and the dedicated repo 0.6.0 line) are merged into one tree carrying both sides' fixes.

* Merged from the 0.6.0 line: the Advanced FFL Checkout integration fix (real `wpistic_ffl_transfer_status_changed` hook, transfer lookup via `\WpisticFFL\DB`) and the Firearm Workflow Pack remap to advanced-ffl-checkout's actual transfer statuses, plus the PHPUnit test suite, PHPCS/PHPUnit configs, packaging/lint tools, and SECURITY-AUDIT.md.
* Merged from the 0.5.3 audit line: Jasmin/SMS-Gate webhook signature validation now fails closed once a secret exists; Twilio webhook signatures verified against the raw form-encoded POST fields (route/query params no longer corrupt the base string); webhook routes restricted to the active provider (widen via `messageistic_webhook_allowed_providers`); the send lock made atomic for every send path, not just pilot mode; G2A Booking bridge rewritten against the real `g2ab_*` hooks with booking-row hydration; Memberistic bridge rewritten against the real membership-ID hooks with member/plan hydration and the Memberistic "SMS Notifications" toggle; root `index.php` directory-listing guard.
* Fixed: FFL status-change bridge now skips transfers without a customer phone number instead of creating phone-less contacts.

= 0.6.0 =
* Fixed: the Advanced FFL Checkout integration never fired — it listened for a hook (`ffl_transfer_status_changed`, one array argument) that plugin has never emitted. Now listens for the real `wpistic_ffl_transfer_status_changed( $transfer_id, $old_status, $new_status )` hook and looks up the transfer itself.
* Fixed: the bundled Firearm Workflow Pack's status-triggered messages (FFL info received, transfer arrived, ready for pickup, documentation required) used status names that never matched a real advanced-ffl-checkout transfer status, so none of them could ever send. Remapped to the plugin's actual status values.

= 0.5.0 =
* Redesigned Conversations inbox: responsive two-pane chat UI (single-pane with back navigation on mobile), contact avatars, last-message previews with relative times, unread badges, status/priority indicators, and conversation search.
* Live inbox: new inbound messages and delivery-status changes appear automatically (12s polling); replies send via AJAX without a page reload, with inline error display.
* Compose upgrades: Enter to send (Shift+Enter for newline), auto-growing input, character/SMS-segment counter, failed messages highlighted in the thread.
* Workflow controls (status, priority, assignee, location) and internal notes moved into a collapsible Details panel.

= 0.4.2 =
* Fixed: replies to customer-initiated conversations were blocked with "Active consent evidence is required". Inbound messages from non-opted-out contacts now record transactional consent evidence (customer-initiated inquiry), so two-way conversations work out of the box. Marketing consent still requires explicit opt-in; opt-outs are never overridden. Disable via the messageistic_inbound_grants_transactional_consent filter.

= 0.4.1 =
* Fixed: fatal error (infinite recursion) when switching the active provider from Settings → Provider. Switch logging and health-cache invalidation now run on the option-update action instead of inside the sanitize callback.

= 0.4.0 =
* New provider: Local Gateway App — self-hosted SMS through the open-source SMS Gateway for Android™ app (local server or cloud relay mode), with one-click device webhook registration, STOP/START opt-out handling, and HMAC-verified delivery callbacks.
* Fixed: Test Connection now decrypts stored credentials before probing the provider.
* Fixed: webhook URLs shown on the settings screen now embed the real shared secret instead of the encrypted blob.
* Fixed: removed a pilot send-lock leak in the outbound send path.

= 0.3.0 =
* Phase C: multi-step journeys, staff inbox, firearm workflow pack, conversion attribution, multi-location support, and promotional approval gates.


= 0.2.0 =
* Phase B controlled pilot: one business/location/provider/sender, transactional-only sends, 100–500 daily cap, reviewed templates, and daily delivery/opt-out monitoring.


= 0.1.0 =
* Phase 1: Core skeleton, database schema, provider adapter system, settings, dashboard, and logs.
