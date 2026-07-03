# G2A Business API

WordPress plugin that serves the **Guns2Ammo Business Control Center** dashboard
(`app.guns2ammo.com`) at the REST namespace `/wp-json/g2a/v1/*`.

WordPress is the source of truth. This plugin aggregates data from the other
plugins in this repo (WooCommerce, G2A Booking Engine, Memberistic, Waiver
Manager) and exposes a single, permission-checked, versioned API for the
dashboard.

## Install / activate

Drop the directory into `wp-content/plugins/` and activate. Activation:

- Grants `g2a_dashboard` to `administrator` + `shop_manager`
- Grants `g2a_dashboard_admin` to `administrator`
- Seeds empty option rows for automations / agents / models

## Auth

`POST /wp-json/g2a/v1/auth/login` — accepts `{email, password}` where the
password is a **WordPress application password** (WP 5.6+). Returns
`{token, displayName, role}`. The token is opaque to the frontend; subsequent
requests are authenticated by the WP application-password auth header the
browser holds.

All other routes require `g2a_dashboard` (read) or `g2a_dashboard_admin`
(mutations).

## Routes

| Method | Path                                        | Cap    |
| ------ | ------------------------------------------- | ------ |
| POST   | `/auth/login`                               | public |
| GET    | `/analytics/overview?from=&to=`             | read   |
| GET    | `/analytics/bookings?from=&to=`             | read   |
| GET    | `/analytics/memberships?from=&to=`          | read   |
| GET    | `/analytics/store?from=&to=`                | read   |
| GET    | `/analytics/seo?from=&to=`                  | read   |
| GET    | `/ai/insights`                              | read   |
| GET    | `/insights/business-gaps`                   | read   |
| GET    | `/automations`                              | read   |
| POST   | `/automations/{id}/toggle`                  | admin  |
| GET    | `/agents`                                   | read   |
| POST   | `/agents/{id}/run`                          | admin  |
| GET    | `/model-connections`                        | read   |
| POST   | `/model-connections/{id}/test`              | admin  |
| GET    | `/analytics/insightistic?from=&to=`         | read   |
| GET    | `/system/health`                            | read   |
| POST   | `/bridgistic/ask`                           | read   |
| GET    | `/bridgistic/actions?status=pending`        | read   |
| POST   | `/bridgistic/actions/{id}/approve`          | admin  |
| POST   | `/bridgistic/actions/{id}/reject`           | admin  |
| GET    | `/email-drafts?status=pending`              | read   |
| POST   | `/email-drafts/{id}/send`                   | admin  |
| POST   | `/email-drafts/{id}/discard`                | admin  |
| GET    | `/cancellations?status=awaiting`            | read   |
| POST   | `/cancellations/{id}/mark-completed`        | admin  |
| POST   | `/cancellations/{id}/drop`                  | admin  |
| GET    | `/agents/{id}/history`                      | read   |
| POST   | `/agents/{id}/prompt`                       | admin  |
| GET    | `/audit-log?limit=100`                      | read   |
| GET    | `/agents/{id}/prompt`                       | admin  |
| POST   | `/public/opt-out`                           | public |

## Operational review screens

Two owner-only sub-pages under **Settings**:

- **Settings → G2A · Email Drafts** — every draft BridGistic created. Reviewer
  can add/adjust the recipient, then Send (via `wp_mail`, which any installed
  mailer plugin — Messageistic, WP Mail SMTP, Postmark, … — intercepts) or
  Discard.
- **Settings → G2A · Cancellations** — BridGistic never cancels a booking
  automatically. Every approved `cancel_booking` action lands here for a
  human to finalize in the Booking Engine, then mark completed.

Every send / discard / mark-completed / drop writes an entry to the
`g2aba_audit_log` option so the owner has a "who did what, when" record.

## Google Business Profile

`GBP_Client` reuses the shared `Google_Service_Account`. It reads listing
performance (search + maps impressions, calls, direction requests, website
clicks) and — best-effort — recent reviews. Setup:

```php
update_option( 'g2aba_gbp_location_id', 'locations/1234567890' );
```

GBP APIs require per-account allowlisting by Google, so the client is
guarded by `is_configured()`; without a location id + service-account,
`GBP_Provider` returns zeroes and the rest of the plugin keeps working.

Two new gap rules consume GBP data:

- **`gap-gbp-actions`** — ≥ 1000 listing views but < 10 calls + directions +
  website clicks combined = "seen but not acted on."
- **`gap-gbp-reviews`** — ≥ 20 direction requests but < 3 total reviews =
  install a post-visit review-ask flow.

## Configuration

Owners store credentials in **Settings → G2A Business API** (WP-admin). No
WP-CLI required. The plugin never rendrs stored plaintext keys — only a
"configured" / "not set" indicator.

The screen holds:

- **Google service-account JSON** — shared by GSC + GA4. Paste the whole JSON
  key that Google's Cloud Console gives you.
- **GSC site URL** — the exact identifier from your GSC property
  (`sc-domain:guns2ammo.com` or `https://guns2ammo.com/`).
- **GA4 property id** — numeric id from GA4 Admin → Property Settings.
- **Anthropic API key** — for the insight generator.

Grant Google service-account access separately:

- **GSC**: the service-account email → Restricted user of the property.
- **GA4**: the service-account email → viewer on the property.

Access tokens are cached per scope for `expires_in − 60s` so we don't
mint a fresh JWT on every request.

## AI insight generator

An hourly WP-Cron event (`g2aba_generate_insights`) collects a 30-day
analytics snapshot, sends it to Anthropic with a strict JSON-only prompt,
validates + normalizes the result, and stores it in `g2aba_insights_cache`.
`/ai/insights` only reads that option — the LLM is never on the request
hot path.

The connection id defaults to `anthropic-primary`. Store the API key with:

```php
WordPressistic\G2ABA\Secrets::put( 'model:anthropic-primary', 'sk-ant-…' );
```

## Automations (real WP-Cron)

**`Automation_Store`** owns the persistent list of scheduled actions
(booking reminder, waiver reminder, membership renewal 30/7/1-day,
weekly report, low-stock alert, SEO click-drop alert, …). Each record
carries a stable slug that doubles as the WP-Cron hook name and the
interval the scheduler uses.

**`Cron_Scheduler::apply(record)`** is the only code that touches
`wp_schedule_event` / `wp_unschedule_event`. Toggling an automation via
`POST /automations/{slug}/toggle` mutates the store then re-applies the
scheduler — flipping enabled actually changes the WP-Cron state.

`Automation_Store::seed_defaults()` is idempotent: running it preserves
each record's `status`, `lastRun`, and `runsLast7d` counters, so
reactivating the plugin doesn't re-enable something a human paused.

### Handlers (safe by default)

Concrete handlers subscribe to their per-slug hook and are wired via
`Handler_Base::register()`. Every handler in this plugin writes to
`Email_Draft_Store` — it does **not** auto-send. Sending still goes
through the existing owner-approved `Email_Sender` path (audit-logged).

- **`Weekly_Report_Handler`** — Monday weekly cadence. Builds a
  compact text summary (revenue, bookings, memberships, store, SEO)
  and drafts one email.
- **`Low_Stock_Handler`** — twice-daily. Walks WooCommerce products at
  or under their per-product low-stock threshold (site-wide fallback
  when no per-product override is set) and drafts one email listing
  up to 20 SKUs. Skips gracefully when Woo is absent.
- **`SEO_Drop_Handler`** — daily. Compares GSC top-page clicks vs the
  previous 7 days and drafts an alert for any page down ≥ 25%.
- **`Membership_Renewal_Handler`** — daily. Finds Memberistic
  memberships expiring in exactly 30 / 7 / 1 day and drafts one
  personalised renewal email per member.
- **`Booking_Reminder_Handler`** — hourly. For any booking whose
  `start_at` is inside the next 24h window, drafts a friendly
  reminder addressed to the booking's email (or the linked user's).
- **`Waiver_Reminder_Handler`** — hourly. Same window as
  Booking_Reminder but scoped to bookings whose `waiver_signed_at`
  is null. Drafts a "please sign before you visit" nudge.
- **`Abandoned_Inquiry_Handler`** — hourly. Reads the WPistic
  Contact Form submissions table for entries older than 48h with no
  `replied_at`. Drafts one internal-staff summary email to the
  `admin_email`.
- **`Ladies_Upsell_Handler`** — hourly. For Ladies Tuesday bookings
  created in the last hour, drafts two follow-ups per attendee:
  "bring a friend" and "graduate to CCW class".
- **`Churn_Risk_Handler`** — daily. Reads Memberistic for active
  memberships expiring in the next 14 days and drafts a personalised
  "we miss you" email per match.

## Opt-out compliance

- **`Ops\Opt_Out_Store`** — persistent list keyed by lowercased
  email. Case-insensitive lookup + insert. Bounded ring of 5,000
  entries.
- **`Ops\Opt_Out_Signer`** — HMAC-SHA256 over `email + '|' + expires`
  keyed by a derivation of `AUTH_KEY`. Tokens are base64url-encoded,
  90-day TTL by default, verified with `hash_equals` (constant-time).
- **`Email_Sender`** — gate: any recipient in the opt-out store is
  refused before wp_mail is called. The draft transitions to
  `failed` with `Recipient has opted out`, and an
  `email.suppressed_opt_out` audit entry lands.
- Every outgoing message adds the `List-Unsubscribe` header +
  `List-Unsubscribe-Post: List-Unsubscribe=One-Click` (RFC 8058) and
  a plain-text unsubscribe footer with the signed link.
- **`POST /public/opt-out`** (no auth) validates `{email, expires, token}`
  against the signer, records the opt-out, and writes an audit entry.

## Agent prompt history

Every `set_prompt` that actually changes the template pushes the
previous template into a bounded per-agent version log
(`g2aba_agent_prompt_history_<id>`, capped at 10). `GET
/agents/{id}/prompt` (admin cap) returns the current template + the
history so operators can review and restore.

## AI Agents (real runtime)

**`Agent_Store`** persists agent metadata + a per-agent `promptTemplate`
that gets `{{snapshot}}` substituted at run time. Seed is idempotent —
operator prompt edits survive re-seeds.

**`Agent_Runner`** subscribes to the `g2aba_run_agent` cron hook.
`POST /agents/{id}/run` schedules a single event so the REST call
doesn't block on the model roundtrip. The runner:

1. Short-circuits with a helpful message if Anthropic isn't configured
   (before spending queries on a snapshot).
2. Builds a snapshot from the analytics providers.
3. Substitutes `{{snapshot}}` into the agent's prompt.
4. Calls the connected model.
5. Records the output as `lastOutput` on the agent + appends to
   **`Agent_History`** (bounded ring per agent, 50 entries).

`GET /agents/{id}/history` returns the ring for one agent.

## BridGistic action bridge

`POST /bridgistic/ask` classifies a natural-language command into:

- **read** — no state change, returned immediately.
- **draft** — a preview to review before sending; nothing goes out.
- **action** — enqueued for owner approval.

The classifier is **deterministic** (a rule-based list of verbs) — it does
not call an LLM. That way a prompt-injection attempt cannot talk the
classifier into treating a destructive command as a read.

Approving an action fires `do_action('g2aba_bridgistic_action_approved', $entry)`.
The plugin's built-in **`Executor`** listens on that hook, routes the query
through an **`Intent_Router`** (deterministic verb/noun rules), and delegates
to a specific handler:

- `send_email`   → drafts the message into `g2aba_bridgistic_email_drafts`
                   with `status = pending_send`. Nothing goes out over SMTP
                   without a second human step.
- `create_task`  → appends to `g2aba_bridgistic_tasks`.
- `cancel_booking` → extracts a booking id from the query, records a
                   cancellation *request* in `g2aba_bridgistic_cancel_queue`.
                   The plugin will not auto-cancel — cancellations touch
                   money and are always finalized manually.
- `unknown`      → records "no handler" against the action's result.

Adding a real destructive handler is a follow-on PR with the specific safety
rules for that handler.

Money on the wire is always **USD cents** (integer). Date-range params are
ISO `YYYY-MM-DD`; both optional; default is the trailing 30 days in site
timezone.

## Secrets

`Secrets` (`includes/class-secrets.php`) stores AI provider API keys with
AES-256-GCM using a key derived from `AUTH_KEY`. `Models_Controller` never
serializes plaintext keys — it replaces them with a static mask.

Rotating `AUTH_KEY` invalidates every stored secret. This is intentional:
Rotating a WP encryption key should force re-entry of provider keys, not
silently keep decrypting them.

## Development

```bash
composer install
composer test
```

Tests are pure PHP — they stub the tiny surface of WP the classes touch, so
you don't need a full WP install. Integration tests belong in a separate WP
develop-lib suite (out of scope here).

## Not in this plugin (by design)

- No POS state. That lives in `pos.guns2ammo.com` and never talks to this API.
- No live GA4 / GSC OAuth wiring — the `SEO_Provider` returns an empty
  skeleton until a service-account refresh token is added in Phase 3.
- No LLM calls in the hot path. `/ai/insights` reads a cache; generation is
  scheduled and rebuilt out-of-band.
