# Verifyistic — Setup Guide for the Guns 2 Ammo System

_Plugin version: 1.1.0 · Last updated: 2026-05-29_

Verifyistic is the in-house replacement for the Ottertext age-gate. It shows a
modern, on-brand age-verification popup, logs every verification for compliance,
and now **fans the data out to multiple platforms** via webhooks. It also links
directly into the **G2A Booking Engine** (a bundled integration module) so an
age-verified visitor's booking is stamped with their verified DOB/age and the
waiver can be auto-accepted.

## 1. Install & activate

1. Upload `verifyistic.zip` via **Plugins → Add New → Upload** and activate.
2. On activation it creates two tables:
   - `wp_verifyistic_logs` — verification audit log.
   - `wp_verifyistic_webhook_deliveries` — webhook delivery + retry log.

## 2. Core settings (Verifyistic → Settings)

Recommended for a firearms range:

| Setting | Value |
|---|---|
| Enable | On |
| Minimum age | `21` (hard floor is 18 — it can't go lower) |
| Mode | **DOB** (collects name + date of birth) for the strongest record |
| Remember me | On, 30 days |
| Heading / message | On-brand age-21 copy |
| Exclude pages | Privacy policy / "exit" landing page if you use one |
| Redirect URL (on decline) | e.g. a "come back when you're 21" page |

Modes available: **DOB**, **Yes/No**, and **ID & Face** (document upload). DOB is
the best balance of friction vs. record quality for a range.

## 3. The hardened verification layer (new in 1.1.0)

These run automatically — no configuration needed:

- **Per-IP rate limiting** — 15 failed attempts / 15 min then a cooldown, to
  blunt automated age-guessing.
- **Honeypot field** — invisible field that only bots fill; submissions with it
  set are rejected.
- **Signed timing token** — each popup carries an HMAC-signed timestamp;
  impossibly fast (bot) or stale (replayed) posts are rejected. No DB row, no
  cookie.
- **Age sanity ceiling** — DOBs implying an age > 119 are treated as invalid.
- **Min-age floor** — admin can raise the age but never set it below 18.
- **Bot/crawler bypass** — Googlebot/Bingbot etc. see content uncovered so SEO
  is unaffected.

## 4. Multiple webhook connections (new in 1.1.0)

**Settings → Webhook / API Integration → Multiple Webhook Connections.**

Add one row per destination. Each connection has:

- **Name** — e.g. `Zapier`, `GoHighLevel`, `CRM`, `Google Sheet`.
- **Webhook URL** — the endpoint (Zapier/Make catch-hook, n8n, Apps Script, your
  CRM, etc.). A **Test** button sends a sample payload.
- **Secret** (optional) — if set, each request includes an
  `X-Verifyistic-Signature: sha256=…` HMAC header so the receiver can verify
  authenticity.
- **Status** — Enabled / Disabled.
- **Events** — leave blank for *all* events, or list any of
  `passed,failed,declined`.

Delivery behavior:

- Every verification fans out to **all enabled connections** subscribed to that
  event.
- Each attempt is recorded in `wp_verifyistic_webhook_deliveries`.
- Failures **retry automatically** with capped backoff (2 → 5 → 15 → 60 min, up
  to 5 attempts) via single WP-Cron events.
- The legacy single-webhook field still works and is auto-migrated into a
  connection named **Primary** the first time you open the settings.

### Payload

```json
{
  "event": "Age Verification",
  "id": 42,
  "timestamp": "2026-05-29T14:30:00+00:00",
  "site_url": "https://guns2ammo.com",
  "verify_type": "DOB",
  "first_name": "Jane",
  "last_name": "Doe",
  "dob": "1995-05-15",
  "age": 30,
  "status": "passed",
  "verify_token": "…32-char token…",
  "ip_address": "203.0.113.9",
  "page_url": "https://guns2ammo.com/book-a-lane/"
}
```

Headers: `X-Verifyistic-Event`, `X-Verifyistic-Site`, and (if a secret is set)
`X-Verifyistic-Signature`.

## 5. Booking Engine link (already bundled)

In **G2A Booking → Settings → Verifyistic** the booking engine can:

- **Auto-accept the waiver** for age-verified visitors (no double prompt).
- **Require verification before booking** (optional hard gate).
- **Stamp each booking** with the verify token + verified DOB/age for the
  compliance audit trail.
- **Pre-fill the customer name** from the verification record.

This works as long as both plugins are active — the `verifyistic_verified`
cookie/token is matched to the most recent passing verification log.

## 6. Compliance notes

- Logs capture name, DOB, age, IP, user agent, page URL, timestamp.
- COPPA-safe: failed/under-age checks are logged for audit but the visitor is
  not let through.
- Export the full log to CSV from **Verifyistic → Verification Logs**.
