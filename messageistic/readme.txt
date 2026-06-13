=== Messageistic ===
Contributors: wordpressistic
Tags: sms, twilio, ottertext, communication, automation
Requires at least: 6.2
Tested up to: 6.5
Requires PHP: 8.0
Stable tag: 0.5.1
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
