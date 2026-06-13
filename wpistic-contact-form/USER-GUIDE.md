# WPistic Contact Form - User Guide (v1.0.0)

## 1. Overview
WPistic Contact Form helps you collect, manage, reply, analyze, and automate website submissions from one WordPress dashboard.

Primary URL:
https://www.wordpressistic.com/marketplace/plugins/wpistic-contact-form/

## 2. Quick Start
1. Install and activate plugin.
2. Go to **WPistic Contact** in wp-admin.
3. Open **Settings** and configure General + Spam first.
4. Add shortcode `[wpistic_contact_form]` to a page.
5. Submit a test entry and verify it appears in **Inbox**.

## 3. Forms
### 3.1 Default Form
Use:
`[wpistic_contact_form]`

Optional attachments:
`[wpistic_contact_form upload="1"]`

### 3.2 Custom Builder Form
1. Go to **WPistic Contact > Forms**.
2. Create fields and publish.
3. Use shortcode:
`[wpistic_form id="123"]`

## 4. Inbox Workflow
### 4.1 Status flow
* `new` -> `read` -> `replied`

### 4.2 Daily team process
1. Filter by `new`.
2. Open details and verify fields/files.
3. Reply from modal (template or custom).
4. Add internal notes/tags if needed.

## 5. Reply System
Features:
* To/CC/BCC
* Template insert
* Quote original message
* HTML mode
* Signature append
* Reply history log

## 6. Spam Protection
Enable any/all:
* Honeypot
* IP rate-limit
* reCAPTCHA v3
* Turnstile
* Akismet
* IP blocklist

Recommended baseline:
* Rate limit ON
* Honeypot ON (default)
* One captcha system ON

## 7. Attachments
* Configure allowed extensions and max MB.
* Files are stored in protected directories.
* Downloads are served through authenticated endpoint.

## 8. GDPR
Supports:
* Consent checkbox
* WordPress personal data export
* WordPress personal data erasure
* Auto-purge policy (retention days)

## 9. Webhooks
1. Enable Webhooks in settings.
2. Add endpoint URLs (one per line).
3. Add signing secret (optional).
4. Use test dispatch button.

Replay options are available per submission in details modal.

## 10. Analytics
Dashboard includes:
* Last 30 days volume
* Today count
* Replied rate
* Average and P50 reply time
* SLA overdue (24h)
* Conversion by form

## 11. AI & Automation
### 11.1 Provider options
* Local Rules (free)
* Ollama (local)
* OpenRouter
* HuggingFace
* Custom endpoint

### 11.2 Training data
You can train contextual behavior with:
* FAQ text
* Knowledge base text
* Google Sheets published URLs
* Plain text file/URL sources

### 11.3 Automated replies
Rule syntax:
`keyword => reply template`

Template placeholders:
* `{name}`
* `{site_name}`
* `{site_url}`

## 12. Recommended Production Configuration
1. Set From Name/Email correctly.
2. Add signature.
3. Enable one anti-spam captcha provider.
4. Configure webhook + signing.
5. Set GDPR retention.
6. Add at least 5 automation rules for high-frequency intents.

## 13. Troubleshooting
### Submission not appearing
* Check capture toggles in Settings > Captures.
* Confirm nonce/captcha validity.

### Emails not sending
* Verify WordPress mail transport (SMTP plugin recommended).
* Test From Email and Reply settings.

### AI draft empty
* Check provider config endpoint/model.
* Test with Local Rules fallback mode.

## 14. Support
Website:
https://www.wordpressistic.com

Plugin page:
https://www.wordpressistic.com/marketplace/plugins/wpistic-contact-form/
