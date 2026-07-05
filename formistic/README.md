# Formistic — Smart Contact Forms for WordPress Leads

**Formistic** turns every WordPress form into a managed conversation. Capture
submissions from your own forms and the popular form plugins, triage them in a
unified inbox, reply with templates, automate responses, and measure your team's
performance — all from one branded dashboard.

> Built and published by **Wordpressistic**.
> Website: https://www.wordpressistic.com

| | |
|---|---|
| **Stable version** | `2.0.0` |
| **Requires WordPress** | `6.2` or higher |
| **Tested up to** | `6.9` |
| **Requires PHP** | `7.4` or higher |
| **License** | GPL-2.0+ |

---

## Why Formistic

Most form plugins stop at "email me the submission." Formistic picks up where
they leave off and gives you a complete **submission operations** workflow:

- **One inbox for everything.** Captures from the built-in Formistic forms plus
  Contact Form 7, WPForms, Gravity Forms, Fluent Forms, and compatible theme
  form handlers — all in a single, searchable list with a clear status flow
  (`New → Viewed → Replied`).
- **Reply without leaving WordPress.** A built-in composer with saved templates,
  quote-original, CC/BCC, signatures, and HTML mode.
- **Keep your list clean.** Newsletter sign-ups land in a dedicated Newsletter
  tab; contact messages land in the Inbox — never mixed.
- **Automate the routine.** Auto-responders and a simple `keyword => template`
  rule engine reply for you.
- **Stay protected.** Honeypot, rate limiting, reCAPTCHA v3, Cloudflare
  Turnstile, Akismet, and an IP blocklist.
- **Prove the value.** An analytics dashboard with submission volume, response
  KPIs, SLA overdue tracking, and per-form conversion.

## Dashboard

After activation a top-level **Formistic** menu appears in wp-admin with these
screens, in order:

1. **Inbox** — every contact submission, with search, filters, detail view, and replies.
2. **Threads** — submissions grouped by sender.
3. **Form** — build unlimited forms with the visual builder.
4. **Analytics** — volume, response time, SLA, and conversion metrics.
5. **Settings** — General, Attachments, GDPR, plus a tab for each active addon.
6. **Addons** — turn optional features on or off.
7. **Newsletter** *(appears when the Newsletter addon is active)* — subscribers, with CSV export.

Additional addon submenus appear in this list as you enable them.

## Addons

Formistic is modular. Open **Formistic → Addons** to toggle features from a
card-based screen — keep only what your site needs:

| Addon | What it adds |
|---|---|
| **Form Captures** | Pull submissions from Contact Form 7, WPForms, Gravity Forms, Fluent Forms, and compatible theme forms |
| **Spam Protection** | Honeypot, rate limiting, reCAPTCHA v3, Turnstile, Akismet, IP blocklist |
| **Auto Responder** | Automatic acknowledgement email on every submission |
| **Webhooks** | Forward submissions as JSON with optional HMAC signing and replay |
| **Reply Templates** | Reusable reply snippets with placeholders |
| **AI Automation** | Smart drafts, AI spam scoring, smart tags, keyword rule engine |
| **Newsletter** | Dedicated subscribers list, kept separate from the inbox, with CSV export |

Each addon reveals its own settings tab and, where relevant, its own submenu.
Disabled addons add zero runtime overhead.

## Visual form builder

Open **Formistic → Form → Add New** to build a form:

- **Add, remove, and drag-reorder** fields (text, email, phone, URL, paragraph,
  dropdown, radio, checkboxes, date, file upload, hidden, GDPR consent).
- A **live preview** updates as you edit.
- **Style controls** — accent/button colors, corner radius, field spacing, max
  width, and one- or two-column layout.
- A **form type** switch: *Contact* forms land in the Inbox; *Newsletter* forms
  add the email to the Newsletter list and never touch the Inbox.

Place any form with `[wpistic_form id="N"]`.

## Features

- Unified submission inbox with status lifecycle and unified sender view
- Multi-source capture pipeline (Formistic forms + major form plugins)
- Reply composer with templates, quote-original, CC/BCC, and signature
- Spam prevention stack: honeypot, rate limit, reCAPTCHA v3, Turnstile, Akismet, IP blocklist
- Protected attachment storage with authenticated download links
- Bulk actions and CSV / JSON export (filtered or selected scope)
- Dedicated newsletter capture, list management, and CSV export
- GDPR consent, export, erase, and auto-retention purge
- Webhooks with optional HMAC signing, multiple endpoints, and replay
- AI & Automation studio with trainable context and a smart rule engine

## AI & Automation

Formistic ships an optional AI layer with **no paid lock-in**:

- Smart reply draft generation
- AI spam scoring (0–100) and smart tagging for triage
- Auto-reply rule engine using simple `keyword => template` rules
- Trainable context: FAQ text, knowledge-base text, Google Sheets URLs, and plain-text sources
- Connection modes: **Local Rules** (no API), Ollama, OpenRouter, HuggingFace, or any custom endpoint

## Installation

1. Download the latest release and upload the `formistic` folder to
   `/wp-content/plugins/`, **or** install the ZIP via
   **Plugins → Add New → Upload Plugin**.
2. Activate **Formistic**.
3. Open the **Formistic** menu in wp-admin and configure the Settings tabs.
4. Add a form to any page or post with a shortcode:
   - `[wpistic_contact_form]` — the quick built-in contact form
   - `[wpistic_form id="N"]` — a form you built on the Forms screen

## Shortcodes

| Shortcode | Purpose |
|---|---|
| `[wpistic_contact_form]` | Render the default built-in contact form |
| `[wpistic_form id="N"]` | Render a custom form built on the Forms screen |
| `[wpistic_formistic_newsletter]` | Render a newsletter sign-up field anywhere |

## Connect your existing forms

Already have a custom contact form or newsletter sign-up (hand-coded, a theme
form, or another plugin)? You don't have to rebuild it — point it at Formistic
with whichever method fits your setup.

### 1. No code — catch any form that emails you

If your form sends you an email when someone submits it (most do), enable the
catch-all:

**Formistic → Addons → Form Captures (on) → Settings → Captures → "Any form that
sends email (catch-all)".**

Every email WordPress sends is then copied into the Inbox. Works with any form,
no editing required. (It also catches non-form emails like password resets, so
review the Inbox accordingly.)

Built-in integrations for **Contact Form 7, WPForms, Gravity Forms, and Fluent
Forms** are also on the same Captures screen — no code needed for those.

### 2. One line of PHP — send submissions from your own handler

In your form's submit handler, hand Formistic the fields. Sender name, email,
phone, and message are detected automatically from the labels:

```php
// Contact form → Inbox
formistic_capture_contact( array(
    'Name'    => $_POST['name'],
    'Email'   => $_POST['email'],
    'Message' => $_POST['message'],
), array( 'form_name' => 'My Contact Form' ) );

// Newsletter sign-up → Newsletter list
formistic_add_subscriber( $_POST['email'], 'footer-form' );
```

Prefer hooks? These do the same thing:

```php
do_action( 'formistic_capture', array( 'Email' => $email, 'Message' => $msg ) );
do_action( 'formistic_subscribe', $email );
```

### 3. REST API — for JavaScript / headless / external systems

```
POST /wp-json/formistic/v1/capture      { "form_name": "...", "fields": { "Email": "...", "Message": "..." } }
POST /wp-json/formistic/v1/newsletter    { "email": "...", "source": "..." }
```

Send the WordPress REST nonce as the `X-WP-Nonce` header (from
`wp_create_nonce('wp_rest')`) for same-site requests.

> Contact submissions land in the **Inbox**; newsletter sign-ups land in the
> **Newsletter** list (enable the Newsletter addon to view them). The spam
> stack still applies when the Spam Protection addon is on.

## External services

Formistic only contacts third-party services when you explicitly enable them
(reCAPTCHA, Turnstile, Akismet, administrator-configured webhooks, and optional
AI endpoints). Each service, the data it receives, and its terms/privacy links
are documented in `readme.txt` under **External services**.

## Support

Formistic is developed and maintained by Wordpressistic.
For help and updates, visit https://www.wordpressistic.com.

## License

Formistic is free software released under the **GPL-2.0+** license. See
[`LICENSE`](LICENSE) for the full text.
