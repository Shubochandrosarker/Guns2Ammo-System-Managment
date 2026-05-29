# Memberistic Integrations toggles + membership-side Verifyistic

_Memberistic 1.37.0 · 2026-05-29_

## What's new

### 1. Functional Integrations page (toggles)
**Memberistic → Integrations** is no longer a static list — every connectable
integration now has a real, persisted on/off **switch**. Toggle state is stored
in the `memberistic_settings` option and gates the integration at runtime.

| Integration | Toggle setting key | Default | Notes |
|---|---|---|---|
| G2A Booking Engine | `integration_booking_enabled` | on | Only active when the Booking Engine plugin is present. Gates Memberistic's member-booking discount/eligibility hooks. |
| Verifyistic Age Verification | `integration_verifyistic_enabled` | off | Membership-side bridge (below). Needs the Verifyistic plugin. |
| Stripe Checkout | `stripe_enabled` | off | Same flag the Settings page uses. |
| WooCommerce | `woocommerce_enabled` | off | Needs WooCommerce. |
| Email Automation | `integration_email_enabled` | on | Core lifecycle emails. |
| Klaviyo / POS / Waiver / SMS | — | — | "Coming soon" — listed, not togglable. |

A card whose dependency plugin isn't installed shows "Plugin not detected" and a
disabled switch. Add-ons can register their own cards via the
`memberistic_integration_definitions` filter.

### 2. Membership-side Verifyistic bridge
The Booking Engine already had a Verifyistic module for **booking-time** gating;
that stays. This adds the **membership-side** integration (kept separate, as you
chose), gated by the new toggle. When enabled (and the Verifyistic plugin is
active) it:

- **Stamps the member's WP user** with their verified age / DOB / token when a
  membership is created or activated — read from the visitor's
  `verifyistic_verified` cookie → `wp_verifyistic_logs`. Stored as user meta:
  `memberistic_verified_age`, `memberistic_verified_dob`,
  `memberistic_verified_at`, `memberistic_verify_token`. Fires
  `do_action( 'memberistic_member_verified', $user_id, $record )`.
- **Shows an "✓ Age verified" line** on the member verification card.
- **Optional signup gate** — the "Require age verification before membership
  signup" checkbox flips `integration_verifyistic_require_signup`; other code can
  honor `apply_filters( 'memberistic_signup_requires_verification', false )`.
- **Auto-stamp** can be turned off with the other checkbox
  (`integration_verifyistic_autostamp`).

Helpers: `Verifyistic_Bridge::get_current_verification()` and
`Verifyistic_Bridge::get_user_verification( $user_id )`.

## Answers to the related questions

- **Sending data to Ottertext after deleting its chat widget:** add Ottertext's
  inbound webhook/API URL as a connection in **Verifyistic → Settings → Multiple
  Webhook Connections**. Verifyistic then POSTs each verification (name/DOB/age)
  to Ottertext (and any other platform). Verifyistic is *not* otherwise wired to
  Ottertext.
- **Verifyistic API:** it's an **outbound webhook** system (JSON POST + optional
  HMAC), with retry. No inbound public API.
- **Where user data lives:** `wp_verifyistic_logs` (verification records),
  `wp_verifyistic_webhook_deliveries` (delivery/retry log),
  `wp-content/uploads/verifyistic-ids/` (ID/selfie images, ID-mode only), plus
  whatever your webhook connections forward it to. On the membership side, the
  verified age/DOB/token are mirrored to **WP user meta** as above.
