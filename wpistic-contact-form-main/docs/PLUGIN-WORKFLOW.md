# Plugin Workflow and Feature Operation

## 1. Capture Layer
The plugin captures submission data from shortcode forms and integrated sources, normalizes key fields, then stores a structured submission record.

## 2. Security and Anti-Spam Gate
Before storage, requests pass spam checks:
- Honeypot
- reCAPTCHA/Turnstile (if enabled)
- Akismet (if enabled)
- IP blocklist
- Rate limit thresholds

## 3. Inbox Persistence
Core records include:
- submission metadata
- full field payload JSON
- reply logs
- attachment references
- operational status

## 4. Operator Workflow
From `WPistic Contact` admin screen:
1. Filter by status/form/search
2. Open detail modal
3. Review sender, fields, files, timeline
4. Send reply with template tools
5. Add internal notes/tags

## 5. Reply Engine
Reply sending flow:
- validates email targets
- supports CC/BCC and HTML mode
- appends configured signature
- stores outbound reply in history
- updates submission status to `replied`

## 6. Automation Layer
Automation supports:
- global auto-responder
- replay actions (webhook/autoresponder)
- rule-based reply body generation (`keyword => template`)

## 7. AI Layer
AI processing can enrich each submission with:
- spam score
- smart tags
- generated draft reply
- provider metadata

Training context can include FAQ/KB/Sheets/Text sources.

## 8. Webhook Delivery
Webhook mode sends JSON payloads to one or many endpoints with optional HMAC signature headers for verification.

## 9. Analytics and SLA
Dashboard tracks:
- submission volume
- response rates
- average and P50 response time
- overdue SLA items
- per-form conversion (impressions vs submissions)

## 10. Compliance and Data Lifecycle
GDPR tools include:
- consent flow
- personal data export/erase integration
- auto-retention purge

## 11. Recommended Production Rollout
1. Configure anti-spam stack
2. Configure sender/reply identity
3. Enable webhooks + test send
4. Activate templates and automation rules
5. Train AI context sources
6. Validate SLA dashboard
