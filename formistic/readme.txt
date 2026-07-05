=== Formistic — Smart Contact Forms for WordPress Leads ===
Contributors: wordpressistic
Tags: contact form, form builder, wordpress, submissions, inbox, ai, spam protection, webhooks, gdpr
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.0.5
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Professional submission operations for WordPress leads — a standalone visual form builder, unified inbox, newsletter list, replies, analytics, and a modular addon system.

== Description ==

Formistic is a professional, standalone contact-form and submission-operations plugin for WordPress. Build forms with a visual builder, collect every message in a unified inbox, keep newsletter sign-ups in a separate list, and switch features on or off from a modular Addons screen — all from one branded dashboard.

Built and published by Wordpressistic.

Core capabilities:

* Standalone visual form builder — add, remove, and drag-reorder fields with a live preview and per-form style controls (colors, radius, spacing, width, one/two-column layout).
* Contact vs Newsletter form types — contact messages go to the Inbox, newsletter sign-ups go to the dedicated Newsletter list. They are never mixed.
* Unified submission inbox with search, filters, status flow, detail view, and reply history.
* Modular Addons screen — enable only what you need: Form Captures, Spam Protection, Auto Responder, Webhooks, Reply Templates, AI Automation, and Newsletter.
* Capture from the built-in forms and popular form plugins (Contact Form 7, WPForms, Gravity Forms, Fluent Forms) when the Captures addon is on.
* Reply composer with templates, quote-original, CC/BCC, and signature.
* Spam stack: honeypot, rate limiting, reCAPTCHA v3, Turnstile, Akismet, and IP blocklist.
* Attachments with protected storage and authenticated download links.
* Bulk actions and export (CSV/JSON).
* Webhooks with optional HMAC signing and test dispatch.
* GDPR exporter/eraser integration and optional auto-purge retention.
* Analytics dashboard with volume, response KPIs, SLA overdue, and conversion metrics.
* AI and automation studio with trainable context (FAQ/KB/Sheets/text) and smart auto-reply rules.

Included AI features:

* AI smart reply draft generation.
* AI spam score (0-100) and smart tagging.
* Automated reply workflow with simple `keyword => template` rules.
* Free connection modes (no paid lock-in): Local Rules, Ollama, OpenRouter routes, HuggingFace endpoint, Custom endpoint.

== Installation ==

1. Upload the `formistic` folder to `/wp-content/plugins/` or upload the ZIP via **Plugins > Add New > Upload Plugin**.
2. Activate the plugin.
3. Open **Formistic** in wp-admin. The menu opens on the Inbox, followed by Threads, Form, Analytics, Settings, and Addons.
4. Open **Formistic > Addons** and enable the features you need (Form Captures, Spam Protection, Auto Responder, Webhooks, Reply Templates, AI Automation, Newsletter). Each addon reveals its own settings tab and, where relevant, its own submenu.
5. Open **Formistic > Form** to build a form with the visual builder, then place it with a shortcode:
   * `[wpistic_form id="N"]` — a form you built (Contact or Newsletter type)
   * `[wpistic_contact_form]` — the quick built-in contact form
   * `[wpistic_formistic_newsletter]` — a standalone newsletter sign-up field

== Frequently Asked Questions ==

= Is this plugin WP.org compatible? =
Yes. The plugin follows WordPress coding and packaging expectations, includes uninstall cleanup, sanitization, nonce/capability checks, and external service disclosures.

= Can I use AI without paid APIs? =
Yes. Use `Local Rules` mode (no external API). You can also connect to local Ollama.

= Can I train replies on my own data? =
Yes. Use the AI & Automation settings tab to add FAQ text, knowledge-base text, Google Sheets URLs, and plain text sources.

= Does it support attachments securely? =
Yes. Files are stored in protected directories and served via authenticated admin download endpoints.

= Can I export submissions? =
Yes. CSV and JSON export are available with filtered and bulk scopes.

== External services ==

This plugin can connect to third-party services when enabled by the site administrator.

= Google reCAPTCHA v3 =
* Service: Google reCAPTCHA
* Data sent: Browser/device metadata and challenge token for spam verification.
* Trigger: Form render/submit when enabled.
* Terms: https://policies.google.com/terms
* Privacy: https://policies.google.com/privacy

= Cloudflare Turnstile =
* Service: Cloudflare Turnstile
* Data sent: Browser/device metadata and verification token.
* Trigger: Form render/submit when enabled.
* Terms: https://www.cloudflare.com/website-terms/
* Privacy: https://www.cloudflare.com/privacypolicy/

= Akismet =
* Service: Akismet
* Data sent: Submission body and request metadata for anti-spam checks.
* Trigger: Submission processing when enabled.
* Terms: https://akismet.com/tos/
* Privacy: https://akismet.com/privacy/

= Webhooks (administrator-configured endpoints) =
* Service: Any endpoint URL configured by administrator.
* Data sent: Submission payload (form/sender/content/metadata/attachment count), optional HMAC signature.
* Trigger: After submission capture and replay actions.
* Terms/Privacy: Depends on destination service selected by site owner.

= AI Endpoint Connections (optional) =
* Service: Ollama/OpenRouter/HuggingFace/custom endpoint as configured.
* Data sent: Prompt context containing submission content and optional administrator-provided training text.
* Trigger: AI smart reply/tagging/spam scoring when enabled.
* Terms/Privacy: Depends on selected provider.

== Changelog ==

= 2.0.5 =
* Fix: the Inbox action buttons (View, Reply, Delete, notes, bulk actions) did nothing on sites where a JavaScript optimizer / deferral plugin broke the external admin script. The admin script is now printed inline (after its localized data), so the dashboard controls always work.

= 2.0.4 =
* Fix: admin dashboard styles are now printed inline, so the branded design always renders even when a CSS optimizer, concatenation/minification plugin, CDN, or aggressive cache would otherwise strip or stale the external stylesheet.
* New: duplicate-submission protection — an identical submission captured within a short window (double-click, refresh, or the same payload arriving via two hooks) resolves to the original entry instead of creating a duplicate. Window is filterable via `wpistic_formistic_dedupe_seconds`.
* Newsletter sign-ups remain de-duplicated by unique email, and auto-responder emails remain per-recipient throttled.

= 2.0.3 =
* New: connect ANY existing form (custom contact forms, custom newsletter sign-ups, theme forms, or other plugins) to the Formistic dashboard.
* New: no-code catch-all — the renamed "Any form that sends email (catch-all)" capture option records submissions from any form that emails you.
* New: developer API — `formistic_capture_contact()` and `formistic_add_subscriber()` helper functions, plus `formistic_capture` / `formistic_subscribe` action hooks.
* New: REST endpoint `POST /wp-json/formistic/v1/capture` for JavaScript / headless contact forms (newsletter REST endpoint already existed).
* The Captures settings screen now explains how to connect your own forms.

= 2.0.2 =
* Fix: the form builder post type was 22 characters, over WordPress's 20-character limit, so it never registered ("Invalid post type"). Renamed it to `formistic_form` — the Form screen and builder now work.
* Fix: contact submissions and newsletter sign-ups were not saved on sites that updated from an older release, because the v2.0.0 table rename meant the new tables were never created. The schema is now ensured on every request (admin, front end, AJAX, admin-post), so submissions and subscribers save reliably.
* Note: data stored under the old table names from pre-2.0 builds is not migrated; new submissions and subscribers are recorded correctly going forward.

= 2.0.1 =
* Fix: the "Form" submenu (visual form builder) is now always available under the Formistic menu. The form CPT is attached explicitly so it no longer depends on menu registration timing.
* Fix: admin styles now load reliably on the Settings and Addons screens by enqueueing on the exact page hooks (with a version bump to bust cached CSS).
* The form add/edit screens keep the Formistic → Form menu highlighted.

= 2.0.0 =
* New: modular Addons screen with card-based on/off toggles for Form Captures, Spam Protection, Auto Responder, Webhooks, Reply Templates, AI Automation, and Newsletter. Features (and their settings tabs / submenus) load only when enabled.
* New: standalone visual form builder — drag-reorder fields, a live preview pane, and per-form style controls (accent/button colors, corner radius, field spacing, max width, one/two-column layout).
* New: Contact vs Newsletter form type. Newsletter forms store sign-ups in the Newsletter list only and never appear in the Inbox.
* Fix: newsletter sign-ups are kept strictly separate from the inbox; contact submissions stay in the inbox.
* Change: admin menu order is now Inbox, Threads, Form, Analytics, Settings, Addons, then addon submenus (Newsletter) serially.
* Change: fully rebranded internal code structure to the Wpistic_Formistic namespace (classes, constants, options, tables, hooks, and file names).
* Bumped version to 2.0.0.

= 1.5.2 =
* Completed the Formistic product rebrand across plugin metadata, admin screens, documentation, translations, and release packaging.
* Reordered the admin menu so the Formistic dashboard opens on the Inbox, followed by Forms, Newsletter, Threads, Analytics, and Settings.
* Restored the Contact Form 7 capture integration so CF7 submissions are recorded in the inbox.
* Renamed the "Guns 2 Ammo theme" capture toggle to "G2A Theme".
* Preserved all existing shortcodes, stored settings, database tables, hooks, AJAX actions, and integration behavior.

= 1.0.0 =
* Initial WP.org publish build.
* Full `wpistic_formistic` code signature integration for plugin internals and new AI modules.
* Unified inbox, reply, templates, forms, spam, attachments, export, GDPR, webhooks, analytics.
* AI & Automation tab with trainable context and easy auto-reply rules.

== Upgrade Notice ==

= 2.0.0 =
Major release: modular Addons system, standalone visual form builder, Contact/Newsletter form types, and a fully rebranded code structure. A fresh install is recommended.

= 1.5.2 =
Formistic branding release. Existing forms, submissions, settings, and integrations remain compatible.
