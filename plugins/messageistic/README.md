# Messageistic

Provider-independent SMS and customer communication engine for WordPress. Built by [WordPressistic](https://www.wordpressistic.com) — initial client: Guns 2 Ammo.

## Architecture

```
Messageistic Core
  ↓
Provider Manager
  ↓
Active Provider
  ↓
Local Gateway App (Android)  |  Jasmin (self-hosted)  |  OtterText  |  Twilio  |  Testing
```

The core (contacts, templates, campaigns, automations, conversations, logs, reports, dashboard) is provider-independent. The Local Gateway App, Jasmin, OtterText and Twilio are delivery adapters behind a common interface:

```php
interface Messageistic\Providers\SMS_Provider_Interface {
    public function get_key(): string;
    public function get_label(): string;
    public function get_capabilities(): array;
    public function validate_settings(array $settings);
    public function test_connection(array $settings);
    public function send_sms(array $payload);
    public function handle_inbound(array $payload);
    public function handle_status_callback(array $payload);
}
```

Switch providers from **Messageistic → Settings → Provider** without losing contacts, templates, campaigns, automations, conversations, or logs.

## Building an installable ZIP

WordPress identifies a plugin by its **folder name**, so a GitHub
"Download ZIP" archive (which unpacks as `Messageistic-<branch>/`)
installs as a *second* plugin next to an existing `messageistic/`
install instead of upgrading it — you'll see both on the Plugins screen.
Never activate both at once (duplicate classes will fatal). Build the
upload package instead:

```bash
bash tools/build-zip.sh        # -> dist/messageistic.zip
```

The ZIP unpacks as `messageistic/`, so uploading it through
**Plugins → Add New → Upload Plugin** replaces the installed copy in
place ("Replace current with uploaded" on WP 5.5+). If you already have
a duplicate from a GitHub ZIP: deactivate and delete the duplicate
entry (data lives in the database, not the plugin folder, so contacts,
messages, and settings survive), then upload `dist/messageistic.zip`.

## Build phases

| Phase | Scope | Status |
|-------|-------|--------|
| 1 | Core skeleton, DB schema, provider adapter system, Testing provider, settings, dashboard, logs, Phase-1 REST | ✅ |
| 2 | OtterText: settings, test connection, contact push/sync, send, signed webhooks | ✅ |
| 3 | Twilio: send SMS, inbound + status webhooks, HMAC-SHA1 signature, STOP/START | ✅ |
| 4 | Contacts module + manual messaging + consent/opt-out | ✅ |
| 5 | Conversations inbox | ✅ |
| 6 | Templates + Campaigns (internal queue + provider push) | ✅ |
| 7 | Automations (trigger → condition → delay → template → send) | ✅ |
| 8 | REST API for external G2A dashboard | ✅ |
| 9 | G2A integrations as adapters (commercial-readiness refactor) | ✅ |
| 10 | Provider sync engine — scan provider universe by API key + business/campaign ID | ✅ |
| 11 | CSV bulk import (contacts / templates / campaigns) + light SaaS UI | ✅ |

## Plugin layout

```
messageistic/
├── messageistic.php           Main plugin bootstrap
├── uninstall.php              Opt-in data removal
├── readme.txt
├── includes/
│   ├── Core/                  Autoloader, Plugin, Activator/Deactivator, Installer, Permissions
│   ├── Admin/                 Menu, Assets, page controllers
│   ├── Database/              Tables (dbDelta schema)
│   ├── Providers/             Interface, Abstract, Manager, Testing/Twilio/OtterText/Jasmin
│   ├── Helpers/               Logger, Sanitizer, Phone_Normalizer
│   └── REST/                  REST_Controller (Phase 1 endpoints + webhook placeholders)
├── assets/
│   ├── css/admin.css
│   └── js/admin.js
└── templates/admin/           dashboard.php, settings.php, logs.php
```

## Custom tables

`wp_messageistic_contacts`, `wp_messageistic_conversations`, `wp_messageistic_messages`, `wp_messageistic_campaigns`, `wp_messageistic_campaign_recipients`, `wp_messageistic_templates`, `wp_messageistic_automations`, `wp_messageistic_logs`, `wp_messageistic_optouts`, `wp_messageistic_provider_meta`.

Provider IDs live on the relevant table **and** in `provider_meta`, so the same internal record can carry mappings to multiple providers over time.

## Capabilities

`manage_messageistic`, `view_messageistic`, `send_messageistic_sms`, `manage_messageistic_campaigns`, `manage_messageistic_settings`, `manage_messageistic_providers`.

Granted to `administrator` on activation.

## REST endpoints

All under `messageistic/v1`. Authenticate with cookie+nonce, application
passwords, or any WP-compatible JWT plugin. Permissions enforced via
Messageistic capabilities.

### Provider control

```
GET    /provider                       Active provider + cached health
POST   /provider                       Switch active provider  { key }
GET    /provider/capabilities          Capability matrix for every provider
POST   /provider/test                  Test connection         { key? }
```

### Sync (provider → us)

```
GET    /sync                           All current/last jobs (or filter by ?provider=)
POST   /sync                           Start a sync
                                       { provider, kind?, list_id?, campaign_id? }
DELETE /sync                           Cancel a sync           { provider }
```

### Bulk import (CSV → us)

```
GET    /import                         All current/last import jobs (or filter by ?kind=)
DELETE /import                         Cancel an import { kind }
```

CSV upload itself happens via the WP-Admin **Messageistic → Import** page
(needs `manage_messageistic` capability) — that route handles `multipart/form-data`
uploads, mapping preview, and start. Once started, progress is pollable via the
REST endpoint above.

Supported `kind` values: `contacts`, `templates`, `campaigns`. Each kind has a
**Download sample CSV** link on the upload screen so you know exactly which
column headers to use.

The mapping screen auto-detects common header aliases (`firstname`, `fname`,
`phone_number`, `mobile`, …) so even messy exports usually map themselves;
override anything on the screen before kicking off the run.

### Webhooks (provider-side)

```
POST   /webhooks/twilio/inbound        Twilio inbound SMS (HMAC-SHA1 signed)
POST   /webhooks/twilio/status         Twilio delivery status
POST   /webhooks/ottertext/inbound     OtterText inbound (HMAC-SHA256 signed)
POST   /webhooks/ottertext/status      OtterText status callback
POST   /webhooks/jasmin/inbound        Jasmin MO (token-authenticated)
POST   /webhooks/jasmin/status         Jasmin DLR (token-authenticated)
POST   /webhooks/smsgate/inbound       Gateway app sms:received (token + optional HMAC)
POST   /webhooks/smsgate/status        Gateway app sms:sent/delivered/failed (token + optional HMAC)
POST   /provider/smsgate/webhooks      Push the four webhook subscriptions onto the device
```

### Dashboard data

```
GET    /settings                       General + active provider
GET    /contacts (paginated, search)   POST upserts; GET/PATCH /:id
GET    /messages?contact_id=           POST sends through SMS_Service
GET    /conversations                  GET /:id returns conversation+messages
GET    /templates  GET /campaigns      GET /automations
GET    /reports                        Provider × status counts
GET    /dashboard/stats                Aggregate counters
```

## Scanning a provider's contact universe

> "With API key + business ID + campaign ID, scan the whole contact list."

This is the **Provider Health & Sync** page (Messageistic → Provider Health).

### OtterText

1. Settings → OtterText: enter API Base URL, API Key, Account/Business ID, optional Location ID, optional Default List ID, optional Default Campaign ID, Webhook Secret. Tick "Enable Contact Sync".
2. Click **Test Connection**.
3. Provider Health → OtterText → optionally override **List ID** / **Campaign ID** for this run → **Start sync**.
4. The page polls progress; jobs survive page refresh because state lives in `messageistic_sync_jobs`.

What happens under the hood:

- `Sync_Service::start('ottertext', ['list_id' => …, 'campaign_id' => …])` creates a job and enqueues `messageistic_sync_tick`.
- Each tick pulls a 100-contact batch from `OtterText_Client::list_contacts()` (which paginates by `?page=` or `?cursor=`), upserts each one through `Contact_Repository::upsert_by_phone()`, and records the OtterText contact ID in `wp_messageistic_provider_meta`.
- Phones go through `Phone_Normalizer::normalize()` so the same human is one row regardless of how the provider formatted the number.
- Reschedules itself until `next_page` and `next_cursor` are both null.
- Cancellable at any time; cancel flips the job status, the running tick exits cleanly.

### Twilio

Twilio doesn't host contact lists. The Twilio sync scans inbound `Messages` and imports each unique sender as a contact:

```bash
curl -u USER:APP_PASSWORD \
  -H "Content-Type: application/json" \
  -X POST https://example.com/wp-json/messageistic/v1/sync \
  -d '{"provider":"twilio","kind":"inbound_contacts"}'
```

The job pages through `/Accounts/{sid}/Messages.json?Page…` filtered by `To = your From Number`, and stores any new inbound message in `wp_messageistic_messages` (deduplicated by `provider_message_id`).

### Polling progress

```bash
curl -u USER:APP_PASSWORD \
  https://example.com/wp-json/messageistic/v1/sync?provider=ottertext
```

```jsonc
{
  "id": "…uuid…",
  "provider_key": "ottertext",
  "kind": "contacts",
  "status": "running",
  "total": 12480,
  "fetched": 4200,
  "imported": 3812,
  "updated": 388,
  "skipped": 0,
  "failed": 0,
  "page": 43,
  "cursor": null,
  "list_id": "list_abc",
  "campaign_id": null,
  "started_at": "2026-05-10 14:20:11",
  "updated_at": "2026-05-10 14:23:46"
}
```

### Cancel

```bash
curl -u USER:APP_PASSWORD \
  -H "Content-Type: application/json" \
  -X DELETE https://example.com/wp-json/messageistic/v1/sync \
  -d '{"provider":"ottertext"}'
```

## Self-hosted SMS with a local gateway app (Android phone)

The fastest fully self-hosted route needs no server-side SMPP setup at
all: install the open-source [SMS Gateway for Android™](https://sms-gate.app)
app on an Android phone with an active SIM, and the phone becomes the SMS
gateway. Messages send and receive through the phone's own SIM plan —
no aggregator account, no per-message API fees, and (in Local Server
mode) no third-party service in the path.

Two connection modes, identical API:

| Mode | Base URL | When to use |
|---|---|---|
| **Local Server** | `http://<phone-ip>:8080` | WordPress and the phone share a LAN, VPN (WireGuard/Tailscale), or the phone is port-forwarded. Fully self-hosted — nothing leaves your network. |
| **Cloud Server** | `https://api.sms-gate.app/3rdparty/v1` | WordPress is on external hosting and cannot reach the phone. The phone pulls jobs from the vendor relay; message content transits the relay. |

### Full setup process

1. **Install the app** from [sms-gate.app](https://sms-gate.app) (F-Droid /
   APK / Play Store) on an Android phone with a SIM that has an SMS plan.
   Disable battery optimization for the app so Android doesn't kill it.
2. **Enable a server mode** in the app — *Local Server* (shows the phone's
   IP, port, username, and password) or *Cloud Server* (shows cloud
   credentials). Note the credentials.
3. **Configure the plugin** at **Messageistic → Settings → Local Gateway
   App**: enter the Base URL for your mode, the username and password from
   the app, choose a SIM slot if the phone is dual-SIM, leave **Delivery
   Reports** on, set a **Webhook Secret** (any long random string), and —
   if you set a webhook *signing key* inside the app — the matching
   **Signing Key**. Tick **Webhook Validation**. Save.
4. **Test Connection** — the plugin authenticates against the gateway's
   `/webhooks` endpoint, so a green check proves both the URL and the
   credentials.
5. **Register Webhooks on Device** — one click pushes all four event
   subscriptions (`sms:received`, `sms:sent`, `sms:delivered`,
   `sms:failed`) onto the device, each bound to this site's webhook URLs
   with the `?token=…` secret attached. The phone must be able to reach
   your site URL (for Local Server mode on a LAN, that means the site
   must resolve/route from the phone; for Cloud mode the site must be
   publicly reachable over HTTPS).
6. **Switch the active provider** on the **Provider** tab to *Local
   Gateway App (Android phone)* and send a test message to your own
   number from a contact's conversation view.

After that the loop is closed: outbound messages submit to the phone,
the phone reports `sent` → `delivered` (or `failed`) back through the
status webhook, replies and STOP/START keywords arrive on the inbound
webhook, and opt-outs are recorded exactly as with every other provider.

### Status mapping

| Gateway state / event | Messageistic status |
|---|---|
| `Pending`, `Processed` | `queued` |
| `Sent` / `sms:sent` | `sent` |
| `Delivered` / `sms:delivered` | `delivered` |
| `Failed` / `sms:failed` | `failed` |

### Operational notes

- **Throughput**: a phone is not an SMPP shortcode. Android rate-limits
  background SMS; keep campaign volume modest (the app spaces sends) and
  respect the plugin's daily limits.
- **Carrier terms**: bulk or marketing traffic over a consumer SIM can
  violate the carrier's AUP and get the SIM blocked (and in the US,
  P2P/A2P rules still apply). Use this for low-volume transactional
  messaging — confirmations, reminders, two-way support — and clear
  anything heavier with the carrier.
- **Security**: keep **Webhook Validation** on; prefer the HMAC signing
  key on top of the URL token. In Local Server mode, putting the phone
  and WordPress on a WireGuard/Tailscale network avoids exposing port
  8080 anywhere.

## Self-hosted SMS (Jasmin)

Messageistic ships an adapter for [Jasmin SMS Gateway](https://jasminsms.com)
— open-source SMPP middleware the operator self-hosts. The gateway holds
the SMPP route to your carrier or aggregator (Twilio Programmable SMS over
SMPP, Vonage, Sinch, BulkSMS, Plivo, a local MVNO …); Messageistic only
submits messages to its HTTP API. No SMS traffic crosses a third-party
SaaS, and the customer database (contacts, templates, campaigns,
automations, conversations, opt-outs) stays inside WordPress.

Intended for general business SMS — transactional confirmations, booking
reminders, member notifications — not promotional carrier-restricted
content (regulated verticals must clear their use case with the carrier
the gateway is bound to).

### Wiring

1. Stand up Jasmin (Docker, VPS, or any Linux box) and bind an SMPP route
   to your carrier — see Jasmin's own docs.
2. Create an `httpapi` user inside Jasmin (`jcli` → `user -a`). That user
   and its password are what Messageistic uses on `/send`.
3. **Settings → Self-hosted SMS** in WP Admin: enter the gateway base URL,
   `httpapi` username + password, default sender, Unicode toggle, and a
   webhook secret. Tick **Webhook Signature Validation**.
4. Click **Test Connection** — the plugin calls `/ping` (expects
   `Jasmin/PONG`) and then verifies the httpapi credentials via
   `/balance`.

   Troubleshooting: `HTTP 401 for /ping` means the URL is not Jasmin's
   httpapi — Jasmin's `/ping` never asks for credentials. Point the Base
   URL at the httpapi port (default **1401**, not the REST API on 8080),
   check any reverse-proxy HTTP auth in front of the gateway, and if the
   device answering is the Android gateway app, configure it on the
   **Local Gateway App** tab instead.
5. In Jasmin, configure the MO (inbound) http connector to POST to the
   **Inbound (MO) Webhook URL** shown on the settings page. The same
   `?token=…` is reused; rotate the secret in WP and update the connector
   to invalidate old DLR/MO traffic.
6. DLR (delivery receipts) need no Jasmin-side config — Messageistic sends
   `dlr-url`, `dlr-method`, and `dlr-level` on every `/send`, so each
   message's status callback returns to the **Delivery Status (DLR)
   Webhook URL**.

### Status mapping

SMPP DLR codes are normalized into Messageistic's vocabulary so dashboard
reports look identical regardless of provider:

| SMPP `message_status`            | Messageistic status |
|----------------------------------|---------------------|
| `DELIVRD`                        | `delivered` |
| `ACCEPTD`, `ENROUTE`             | `sent` |
| `EXPIRED`, `UNDELIV`, `REJECTD`, `DELETED` | `failed` |
| anything else                    | `unknown` |

STOP / START keywords in MO bodies route through `Inbound_Handler` the
same way Twilio's do — opt-out is recorded in
`wp_messageistic_optouts` and the contact's `consent_state` updated.

## Webhook signatures

| Provider | Scheme |
|---|---|
| Twilio | HMAC-SHA1 of URL + sorted POST params, base64. Validated against `X-Twilio-Signature` when **Validate Twilio Signature** is on. |
| OtterText | HMAC-SHA256 of the **raw** request body. Sent as `X-OtterText-Signature` and validated against the configured Webhook Secret. The plugin captures the raw body before WordPress consumes it (`Raw_Body_Cache`), so signature checks work even though REST controllers run after parsing. |
| Jasmin | Shared-secret `?token=…` query string bound to the DLR / MO callback URL. Jasmin's `httpapi` has no native callback signature; the secret travels on the URL the operator wires into Jasmin and is compared with `hash_equals` on every callback. |
| Local Gateway App | Shared-secret `?token=…` on the webhook URL **plus** (optional, recommended) the app's native HMAC-SHA256 signing: `X-Signature` = hex HMAC of `raw_body + X-Timestamp` with the configured signing key, verified with `hash_equals` and a 5-minute replay window. |

Failed validations are logged at `warn` level and the request is rejected with a `WP_Error`.

## Capabilities

`manage_messageistic`, `view_messageistic`, `send_messageistic_sms`, `manage_messageistic_campaigns`, `manage_messageistic_settings`, `manage_messageistic_providers`, `manage_messageistic_inbox` — all granted to `administrator` on activation.

## License

GPL-2.0-or-later.

## Production cron and compliance setup

Messageistic now fails closed through a central outbound policy engine. Live sends require active consent evidence; firearm-industry mode additionally requires verified age and an explicitly approved provider. Marketing uses a separate consent class.

For production, disable request-driven cron and run WordPress cron every minute from the host scheduler:

```php
define( 'DISABLE_WP_CRON', true );
```

```cron
* * * * * cd /path/to/wordpress && wp cron event run --due-now --quiet
```

The admin displays a warning when the Messageistic heartbeat is more than five minutes old. Campaign workers atomically claim recipients, release stale locks, retry temporary failures with exponential backoff, and use idempotency keys to prevent duplicate sends.

Before enabling **Firearms** industry mode, record written provider approval, configure the approved provider key, verify each recipient's age and consent evidence, and leave firearm marketing disabled until separately approved.


### Development checks

The required CI matrix intentionally uses dependency-free checks on every supported PHP version:

```bash
composer validate --no-check-publish
php tools/php-lint.php
php tests/run.php
```

Optional development dependencies provide the fuller PHPUnit and PHPCS commands after `composer install`:

```bash
composer test:unit
composer quality
```

The optional PHPCS command is not a required merge check until the existing repository has a reviewed coding-standard baseline. This prevents historical formatting debt from hiding syntax, compatibility, or compliance-test failures.

## Phase B controlled pilot

Enable **Messageistic → Settings → General → Controlled Pilot** only after the provider has approved the business, U.S. location, transactional use case, and sender. Pilot mode enforces:

- one configured business and one U.S. location;
- one approved live provider and one exact sender;
- transactional classification only;
- a site-local daily cap between 100 and 500 messages;
- a currently approved template for every outbound message;
- automatic template approval invalidation whenever its body or classification changes;
- daily delivery, failure, queue, and opt-out reporting by email and in **Messageistic → Reports**;
- an administrator acknowledgement for each generated daily report.

Use the following rollout sequence:

1. Configure the business, U.S. location, provider, sender, approval reference, and a 100-message initial cap.
2. Set the same provider as the active provider and complete its connection test.
3. Create transactional templates and approve each one in a separate review action.
4. Send only to contacts with valid transactional consent and, in firearms mode, verified minimum age.
5. Review and acknowledge delivery failures and opt-outs every day before increasing volume.
6. Increase the cap gradually, never above 500 during the controlled pilot.

## Phase C advanced customer experience

Phase C adds controlled scale-up features while preserving the Phase A/B fail-closed controls:

- **Multi-step journeys:** up to six ordered send, wait, tag, condition, and staff-assignment steps in the admin builder. Every run stores a snapshot of its automation version and steps so later edits do not change an in-flight journey.
- **Staff inbox:** status, priority, assignee, location filters, private notes, and an immutable workflow activity trail. Grant `manage_messageistic_inbox` to staff who may change workflow state or add notes.
- **Firearm workflow pack:** installs six conservative transactional templates in pending-review state and inactive automations. FFL status journeys are scoped to the matching status and must be reviewed and activated manually.
- **Conversion reporting:** WooCommerce completed orders emit conversion events attributed to the most recent outbound message within the attribution window, with aggregate value and location reporting.
- **Multi-location operations:** locations define timezone, provider, and sender; contacts, conversations, templates, campaigns, automations, messages, runs, and conversions carry location scope. Sending fails closed if a location is assigned to a provider other than the active provider.
- **Promotional approval gate:** marketing remains disabled until Settings records the exact approved provider, provider approval reference, legal review reference, and approval date. Pilot mode always blocks promotions, and campaigns require a currently approved marketing template plus active marketing consent evidence.

The workflow pack is operational scaffolding, not legal advice. Operators remain responsible for carrier rules, federal/state/local requirements, template review, consent language, and provider approval.
