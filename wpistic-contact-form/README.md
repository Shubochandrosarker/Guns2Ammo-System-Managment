# WPistic Contact Form

WPistic Contact Form is a professional submission operations plugin for WordPress. It centralizes form capture, inbox management, replies, analytics, automation, and AI-assisted workflows in one admin experience.

Plugin URL: https://www.wordpressistic.com/marketplace/plugins/wpistic-contact-form/
Website: https://www.wordpressistic.com

## Version
- Current release: `1.0.0`
- WordPress tested up to: `6.9`
- PHP requirement: `7.4+`

## Core Features
- Unified submission inbox with status lifecycle (`new`, `read`, `replied`)
- Multi-source capture pipeline
- Reply composer with templates, quote-original, CC/BCC, and signature
- Spam prevention stack: honeypot, reCAPTCHA v3, Turnstile, Akismet, IP blocklist, rate limit
- Protected attachments and authenticated download flow
- Bulk actions and CSV/JSON export
- GDPR consent/export/erase/retention workflow
- Webhooks with optional HMAC signing and replay controls
- Analytics with SLA and conversion metrics
- AI & Automation module with trainable context and smart rule engine

## AI & Automation Highlights
- Smart reply draft generation
- AI spam scoring (0-100)
- Smart tagging for triage
- Auto-reply rule engine: `keyword => template`
- Training inputs:
  - FAQ text
  - Knowledge base text
  - Google Sheets published URLs
  - Plain text URL/file sources
- Free connection modes:
  - Local rules (no API)
  - Ollama
  - OpenRouter routes
  - HuggingFace endpoint
  - Custom endpoint

## Plugin Structure
- `wpistic-contact-form.php` - bootstrap and constants
- `includes/` - core modules
- `assets/` - admin/frontend assets
- `languages/` - translation files
- `readme.txt` - WordPress.org readme
- `USER-GUIDE.md` - practical usage manual

## Working Process
1. Form submission is captured.
2. Spam/security validation is applied.
3. Submission is stored in inbox tables.
4. Optional notifications/webhooks are dispatched.
5. AI layer enriches with score/tags/draft.
6. Team reviews, replies, and tracks SLA.
7. Export/reporting and retention policies run as configured.

Detailed implementation process is documented in `docs/PLUGIN-WORKFLOW.md`.

## Installation
1. Upload plugin folder to `/wp-content/plugins/` or upload ZIP.
2. Activate plugin in wp-admin.
3. Open `WPistic Contact` and configure settings tabs.
4. Add shortcode `[wpistic_contact_form]` or `[wpistic_form id="N"]`.

## Publishing Notes
- WP.org-focused metadata and disclosures are maintained in `readme.txt`.
- This repository includes production-ready docs and release packaging.

## License
GPL-2.0+
