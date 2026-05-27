# Guns 2 Ammo — Theme + Plugin Release Bundle

Installable archives for the Guns 2 Ammo WordPress build (Phase 1 + Phase 2 hardening included).

## Download

All zips are at the repo root and can be uploaded directly via **WP Admin → Plugins / Appearance → Themes → Upload**.

| Artifact | Filename | Current version |
|---|---|---|
| Theme | `WPistic-Theme-For-G2A-Version-1.8.9.zip` *(also in `releases/WPistic-Theme-For-G2A-Version-1.9.0.zip`)* | **1.9.0** |
| Booking Engine plugin | `g2a-booking-engine.zip` | **1.6.0** (DB schema 1.5.0) |
| Memberistic Membership Solutions plugin | `memberistic-membership-solutions.zip` | **1.11.0** |
| WPistic Contact Form plugin | `wpistic-contact-form-main.zip` | **1.1.0** |
| G2A Theme Control plugin | `g2a-theme-control.zip` | 1.0.0 (unchanged) |

> The root `WPistic-Theme-For-G2A-Version-1.8.9.zip` filename is preserved so the WP "Replace existing theme" flow recognises the upgrade. The `style.css` header inside the archive reads `Version: 1.9.0` so WP will treat it as an update, not a downgrade. A copy under `releases/WPistic-Theme-For-G2A-Version-1.9.0.zip` gives you a clean versioned archive for download history.

## Install order (fresh site)

Plugins first, theme last, so the theme activation can see the plugins.

1. **G2A Theme Control** — small structural meta-box plugin used by the theme. Upload, activate.
2. **G2A Booking Engine** — bookings + payments + reminders. Upload, activate. Confirm it ran its installer (DB schema v1.5.0). Visit `Booking Engine → Resources` and `Booking Types` to confirm seed data.
3. **Memberistic Membership Solutions** — plans, member portal, content restriction. Upload, activate. Visit `Memberistic → Settings → Pages` and create the linked pages.
4. **WPistic Contact Form** — contact form + auto-responder. Upload, activate.
5. **WPistic Theme (guns2ammo)** — upload as theme, activate.

## Upgrade in place (existing site)

WP automatically detects the version bump:

- **Plugins:** Plugins → Add New → Upload → pick the zip → click "Replace current with uploaded" when prompted.
- **Theme:** Appearance → Themes → Add New → Upload → pick the theme zip → "Replace active with uploaded".

The release ships in this order intentionally — plugin DB migrations (e.g. Booking Engine v1.5.0's payment-row unique key) run automatically on plugin activation. No manual SQL required.

## Recommended `wp-config.php` for staging / development

To prevent customer-facing emails firing from a staging copy of the prod DB:

```php
// Global kill switches — stop ALL outbound mail from these three plugins.
define( 'G2AB_EMAIL_DISABLED',          true );
define( 'MEMBERISTIC_EMAIL_DISABLED',   true );
define( 'WPCF_EMAIL_DISABLED',          true );

// OR — keep the flows visible by rerouting every outbound email to a single
// staging inbox. Original recipient is preserved in the subject prefix.
// define( 'G2AB_EMAIL_OVERRIDE_RECIPIENT',        'ops@guns2ammo.com' );
// define( 'MEMBERISTIC_EMAIL_OVERRIDE_RECIPIENT', 'ops@guns2ammo.com' );

// Stripe secret-key constants (Memberistic). Setting any of these makes the
// plugin refuse to write the matching option, AND mask the value in the
// REST GET /settings response. Drop the test keys here for staging.
// define( 'MEMBERISTIC_STRIPE_TEST_SECRET_KEY',   'sk_test_...' );
// define( 'MEMBERISTIC_STRIPE_LIVE_SECRET_KEY',   'sk_live_...' );
// define( 'MEMBERISTIC_STRIPE_WEBHOOK_SECRET',    'whsec_...'  );
```

## Post-install setup

After activating the theme, visit **Appearance → Customize → G2A Business Info** and fill in:

- `FFL License #` — required to surface the FFL block on `/transfers/` and `/transfer-request/`. Empty value hides the block entirely.
- `Phone`, `Email`, `Address` — used in schema, footer, AEO output, mobile drawer.

## What's in this build

### Theme (1.9.0)
- Hours pivot (Mon–Thu 10–6, Fri 10–7, Sat 10–7, Sun 12–6) — SEO schema, llms.txt, JS live-status pill all consistent.
- FFL placeholder removed; `g2a_ffl_license` customizer field.
- Memberistic → "Members Hub" brand sweep in member-portal contexts.
- Fake lane-booking widget removed when plugin inactive — replaced with phone CTA.
- ADA lane copy corrected to "Lanes 1 and 2".
- CCW page H1 + California-CCW FAQ rewritten for live-fire-qualification-only clarity.
- Skip-to-content link, mobile drawer ARIA + focus trap, Training tile points to `/training/`.
- Tablet/iPad portrait inputs ≥16px (no iOS focus zoom).
- `window.g2aAuth` global removed.
- **"Book Another Lane" CTA + confetti + recap card on booking confirmation.**

### Booking Engine (1.6.0, DB 1.5.0)
- Email kill-switch (`G2AB_EMAIL_DISABLED`) + recipient override.
- Reminder cron TZ math fix (was off by the site's UTC offset).
- Reminder cron now unscheduled on plugin deactivation.
- Public REST read endpoints (`/availability`, `/resources`, `/payment-methods`) gated with per-IP transient rate limit (60/min, filterable).
- Legacy confirm-payment bypass closed; clearer "contact the range" error for token-less bookings.
- Payment-row idempotency: UNIQUE KEY `(gateway, transaction_id)` + race-safe upsert helper across Stripe/PayPal/Authnet.
- Migration v1.5.0 collapses pre-existing duplicate Payments rows on upgrade.

### Memberistic (1.11.0)
- Customer-facing "Members Hub" branding.
- Blanket 100% lane discount removed; per-plan rules now drive free-lane logic.
- WP user creation deferred from checkout-start to Stripe webhook (no more email-spray vector).
- Server-side content restriction via `the_posts` redaction (CSS-blur overlay was bypass-able via view-source / RSS / REST / OG / LD-JSON).
- Stripe webhook event-id idempotency.
- Stripe secret-key constant overrides + REST masking + `_locked_secrets` indicator.

### Contact Form (1.1.0)
- Email kill-switch (`WPCF_EMAIL_DISABLED`).
- Per-target-email throttle on auto-responder (1 hour transient) — stops mail-bomb spray.

### Theme Control (1.0.0)
- Unchanged.

## Rollback

Every change lives in a separate commit on `claude/practical-hawking-LQW9g`. To roll back any single fix, `git revert <sha>` and rebuild the zip from that commit. The schema migration (Booking Engine v1.5.0 UNIQUE KEY) is additive — drop the unique key + downgrade the option `g2ab_db_version` to `1.4.0` to revert to pre-Phase-2b behavior.

## Source diffs

The repo also tracks the extracted source under `guns2ammo/`, `g2a-booking-engine/`, `memberistic-membership-solutions/`, `wpistic-contact-form-main/`, `g2a-theme-control/`. Those mirror what's inside the zips. Future commits should edit the source directories; rebuild the zips before releasing.
