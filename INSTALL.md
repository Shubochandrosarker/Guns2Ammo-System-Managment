# Guns 2 Ammo — Theme + Plugin Release Bundle

Installable archives for the Guns 2 Ammo WordPress build (Phase 1 + Phase 2 hardening included).

## Download

All zips are at the repo root and can be uploaded directly via **WP Admin → Plugins / Appearance → Themes → Upload**.

| Artifact | Filename | Current version |
|---|---|---|
| Theme | `WPistic-Theme-For-G2A-Version-1.8.9.zip` *(also in `releases/`, current archive `WPistic-Theme-For-G2A-Version-1.27.13.zip`)* | **1.27.13** ✅ |
| G2A Booking Engine plugin | `g2a-booking-engine.zip` (current archive `g2a-booking-engine-1.9.9.15.zip`) | **1.9.9.15** ✅ (Stripe webhook signature/idempotency fix, see `docs/INCIDENT-AUDIT-2026-07-19-STRIPE-SIGNUP.md`) |
| Memberistic Membership Solutions plugin | `memberistic-membership-solutions.zip` (current archive `memberistic-membership-solutions-1.18.3.zip`) | **1.18.3** ✅ (Stripe webhook + activation reliability fix) |
| G2A Theme Control plugin | `g2a-theme-control.zip` | **1.0.0** ✅ |
| Verifyistic (age verification) plugin | `verifyistic.zip` (current archive `verifyistic-1.4.6.zip`) | **1.4.6** ✅ (Cloudflare-aware rate limiting) |
| Advanced FFL Checkout (G2A Edition) plugin | `advanced-ffl-checkout.zip` (current archive `advanced-ffl-checkout-1.21.0.zip`) | **1.21.0** ✅ (NICS automation, dealer portal, 5-distributor drop-ship, GunBroker sync, Credova financing) |
| Messageistic (SMS / local gateway) plugin | `messageistic.zip` (current archive `messageistic-0.8.0.zip`) | **0.8.0** ✅ (provider failover, multi-location) |
| G2A POS Core plugin | `g2a-pos-core.zip` (current archive `g2a-pos-core-3.3.0.zip`) | **3.3.0** ✅ (dual-account hardening, integrity checks, PHP 8.1+) |
| Formistic (contact forms, inbox, newsletter, AI auto-reply) plugin | `formistic.zip` (current archive `formistic-2.1.0.zip`) | **2.1.0** ✅ (unified inbox, smart tagging, GDPR) |

> **Formistic is the site's sole contact-form/inbox/newsletter solution.** The
> older "WPistic Contact Form" plugin has been retired and removed from this
> repo — its full feature set (branded auto-responder emails, one-click
> newsletter unsubscribe, spam/rate-limiting hardening) now lives in
> Formistic. See `docs/FORMISTIC_G2A_SETUP.md` for the migration runbook if
> a live site still has the old plugin active.

> The root `WPistic-Theme-For-G2A-Version-1.8.9.zip` filename is preserved so the WP "Replace existing theme" flow recognises the upgrade. The `style.css` header inside reads `Version: 1.27.0` so WP treats it as an update, not a downgrade.
>
> `releases/` keeps only the two most-recent versions of each artifact (current + previous) for easy rollback; older archives are pruned.

## Install order (fresh site) — Release 2.1.0

Plugins first, theme last, so the theme activation can see the plugins.

1. **G2A Theme Control** (v1.0.0) — meta-box plugin used by the theme. Upload, activate.
2. **G2A Booking Engine** (v1.9.9.15) — bookings + payments + reminders. Upload, activate. Confirm DB schema v1.5.2. Visit `Booking Engine → Resources` and `Booking Types` for seed data. In the Stripe dashboard, add webhook endpoint `https://YOUR-SITE/wp-json/g2a-booking/v1/webhooks/stripe` listening to exactly `checkout.session.completed` + `charge.refunded`, and paste its `whsec_…` into **Settings → Payments → Stripe → Webhook secret**.
3. **Memberistic Membership Solutions** (v1.18.3) — plans, member portal, family linking, content restriction. Upload, activate. Visit `Memberistic → Settings → Pages` to wire the linked pages. See `docs/MEMBERS_AND_USERS_EXPLAINED.md` for member/user relationships. **Membership activation is a SEPARATE Stripe webhook from the Booking Engine's** — in the Stripe dashboard, also add `https://YOUR-SITE/wp-json/memberistic/v1/webhooks/stripe` listening to `checkout.session.completed`, `invoice.payment_succeeded`, `invoice.payment_failed`, `customer.subscription.deleted`, and paste its own (different) `whsec_…` into **Memberistic → Settings → Webhook secret**. Skipping this step means paid members are charged but never activated — see `docs/INCIDENT-AUDIT-2026-07-19-STRIPE-SIGNUP.md`.
4. **Formistic** (v2.1.0) — contact forms, form builder, inbox, newsletter, spam protection, and AI auto-reply. Upload, activate. Visit `Formistic → Addons` to enable Newsletter/Auto-Responder/AI Automation, then `Formistic → Settings → AI & Automation → Seed Guns 2 Ammo defaults` (see `docs/FORMISTIC_G2A_SETUP.md`).
5. **Verifyistic** (v1.4.6) — age verification popup + multi-webhook delivery, COPPA compliance. Upload, activate. Then `Verifyistic → Settings` (see `docs/VERIFYISTIC_SETUP_G2A.md`). Replaces Ottertext — see `docs/OTTERTEXT_REMOVAL.md`. If the origin is reachable directly (not exclusively through Cloudflare), define `VERIFYISTIC_TRUSTED_PROXIES` in `wp-config.php` to match your actual edge network so rate limits key on real visitor IPs.
6. **Advanced FFL Checkout (G2A Edition)** (v1.21.0) — FFL dealer search at checkout, transfer lifecycle, dealer confirmation portal, customer "My FFL Transfers" tab, NICS 3-business-day automation (holiday-aware), WC↔transfer status bridge, SMS via Messageistic, 5 toggleable distributor drop-ship integrations (Lipsey's, Sports South, RSR Group, Bill Hicks & Co., Chattanooga), GunBroker.com marketplace listing sync, and a Credova financing payment gateway. Upload `advanced-ffl-checkout.zip`, activate. Watch `Advanced FFL → Dashboard` for the auto-started ZIP centroid + ATF dealer sync, and `Advanced FFL → Add-ons` to enable individual distributors. Mark firearm products **FFL Transfer Required** in their general product data. Optional: define `WPISTIC_FFL_TOKEN_SECRET` in `wp-config.php` for the strongest portal token security. Optional: define `wpistic_ffl_trusted_proxies` filter for accurate IPs behind Cloudflare/LB.
7. **Messageistic** (v0.8.0) — SMS engine with provider failover (Twilio / local Android gateway / Jasmin). Upload, activate. Pick the provider under `Messageistic → Settings`, then enable the **SMS Notifications (Messageistic)** module in `Memberistic → Integrations` to turn on membership + booking texts.
8. **G2A POS Core** (v3.3.0) — full FFL POS with dual-account support and integrity checking (requires PHP 8.1+; composer vendors ship inside the zip). Upload, activate, then enable the **POS Bridge** module in `Memberistic → Integrations` so the counter sees live membership status.
9. **WPistic Theme (guns2ammo)** (v1.27.13) — upload as theme, activate. The theme ships system-aware light/dark mode (header toggle) and skins the booking widget automatically.

## Upgrade in place (existing site)

- **Plugins:** Plugins → Add New → Upload → pick the zip → "Replace current with uploaded".
- **Theme:** Appearance → Themes → Add New → Upload → pick the theme zip → "Replace active with uploaded".

Plugin DB migrations run automatically on activation.

---

## CLIENT-EDITABLE CONTENT — admin guide

Everything customer-facing is editable from the WP Admin without touching code. Here's where each piece lives.

### 1. Site-wide brand, hours, FFL #, contact details

**Appearance → Customize → G2A Business Info**

- `FFL License #` — required to surface the FFL block on `/transfers/` and `/transfer-request/`. Empty = block hidden site-wide.
- `Phone`, `Email`, `Address`, `Hours` — used in schema, footer, AEO output, mobile drawer.
- `Brand Label` (Memberistic) — defaults to the WP site name; override at **Memberistic → Settings**.

### 2. Pages — text, images, hero copy, CTAs

Every page (`/training/`, `/transfers/`, `/memberships/`, `/pricing/`, `/machine-gun/`, etc.) is built from a page template. The page editor exposes the editable fields in side panels via **G2A Theme Control**.

For each page:
- **Page editor → main content** — body copy. Theme renders it inside the template.
- **G2A Theme Control side panels** (right-hand metabox) — image fields use the WP Media Library picker; text/textarea/url fields are free-form.

### 3. Machine Gun Inventory (20+ guns, fully editable)

**Pages → Edit "Machine Gun" page → "Machine Gun Inventory" panel** (right side).

Add as many rows as you need. Each row exposes:
- **Weapon Name** — e.g. `MP5`, `M16A4`, `AK-47`.
- **Image (Media Library)** — pick from media library or upload a new image. Renders on hero row and inventory grid.
- **Caliber** — e.g. `9mm`, `5.56 NATO`, `7.62×39mm`.
- **Rate of Fire (RPM)** — number or text, e.g. `800 RPM`.
- **Magazine Capacity** — e.g. `30 rounds`.
- **Category** — `SMG`, `Rifle`, `Pistol` (free-form, drives badge color).
- **Starting Price** — e.g. `$49 / 50 rounds`.
- **Short Description** — 1–2 sentence blurb shown on card hover/expand.
- **Show in Hero Row (1 = yes)** — set to `1` to feature this weapon in the top "Arsenal" row; leave blank to put it in the "Complete Inventory" grid below.
- **Detail Page URL (optional)** — links the card to a dedicated page if you have one.

Save the page → the front-end updates immediately. No code changes needed.

> If you delete every row, the front-end shows an admin-only empty-state hint reminding the editor to add weapons. Logged-out visitors see a clean "Inventory coming soon" message.

### 4. Membership Digital Card + Member profile photo

The member dashboard (`/account/` or wherever the `[memberistic_account]` shortcode is rendered) now supports:

**For members (self-service):**
1. Log in → visit account page.
2. Click **"Change photo"** in the avatar slot.
3. Pick an image (≤5 MB, JPG/PNG/WebP). It uploads to the WP Media Library and links to the member profile.
4. Photo appears on the avatar AND inside the downloadable digital card.
5. **"Remove"** clears the photo and falls back to initials.

**Dynamic verification QR (replaces the old static PII QR):**
- Every member is auto-assigned a 32-char verification token, stored privately in `wp_usermeta`.
- The QR encodes a short verify URL: `https://YOUR-SITE/?memberistic_verify=TOKEN`.
- A range staffer scans the QR with any phone camera → loads a branded verification card with the member's **live** plan, status, renewal date, and uploaded photo (so the gate clerk can compare faces).
- The QR itself contains **zero personal info** — if a member screenshots their card and the screenshot leaks, the most an outsider sees is "this person is/was a member of XYZ Range." No email, no address, no plan price.
- Status updates instantly: if a membership is cancelled in WP Admin, the next QR scan shows the cancellation, no card-reissue needed.

**Admin override:** go to **Users → edit member → Memberistic section** (planned).

### 5. Pricing plans — Defender / Patriot / Guardian

**Pages → Edit "Memberships" / "Pricing"** — copy and per-plan feature lists are in the page editor / G2A Theme Control panels.

Plan prices are seeded by Memberistic at activation:
- **Memberistic → Plans** → edit each plan to change monthly/annual price, features, capacity.
- Changes flow to the front-end automatically.

### 6. Per-tier guest pricing (Defender $15 / Patriot $10 / Guardian $10 per extra shooter)

**Status: NOW LIVE on the pricing + memberships pages.**

A "How lane pricing works" matrix sits below each plan card showing:
| Plan | Primary Member | Per Extra Shooter | Example: 3 people, 1 hr |
|---|---|---|---|
| Walk-in | $20/hr | $20/hr each | $60 |
| Defender | 1 hr FREE | $15/hr each | $30 |
| Patriot | 1 hr FREE | $10/hr each | $20 |
| Guardian | 1 hr FREE | $10/hr each | $20 |

Each plan card lists "Bring friends: $X / extra shooter / hour" inline so the value prop is obvious before the customer clicks Select. After the first free hour, additional hours bill at standard rates. Linked profiles on Patriot/Guardian count as primary members, not guests.

> The booking-flow auto-application (so a logged-in member sees their per-tier rate at checkout) is the next phase. For now, staff applies the discount at check-in based on the member's plan.

### 7. Training Room / Members Lounge / Instructor Membership

**Status: NOT YET BUILT — intentionally invisible.** These three resources need final pricing, capacity, and benefit specs from the client. Once confirmed, they'll be added as bookable resources (Training Room, Lounge) and as a Memberistic plan ($49.99/mo Instructor tier with $15/lane/hr perk).

### 8. Newsletter signup + subscriber list (NEW in v1.5.0)

The footer "GET RANGE UPDATES" form and the blog page's "Range Brief" form post to **Formistic** and write to a dedicated subscribers table.

**Where to manage subscribers:**
- WP Admin → **Formistic → Newsletter**
- See full list with email, source (`footer` / `blog` / `contact-form:<form-name>`), source URL, IP, subscribed date.
- Filter by **Active** / **Unsubscribed** and search by email or source.
- **"Export CSV"** button — downloads the filtered list as `newsletter-subscribers-active-YYYY-MM-DD.csv`.
- Click **Unsubscribe** on any row to manually opt the email out.
- **Confirmation email** — a branded welcome email with a one-click unsubscribe link is sent on every subscribe/resubscribe; toggle and edit the copy right on the Newsletter admin page.

**How visitors subscribe:**
1. Footer form on every page → POST `admin-ajax.php?action=wpistic_formistic_newsletter_subscribe`.
2. Blog page "Range Brief" form → same endpoint, `source=blog`.
3. Contact form has a "Subscribe to monthly range update newsletter" checkbox — if checked, the contact submission **also** subscribes the sender's email automatically (`source=contact-form:<form-name>`).
4. REST mirror: `POST /wp-json/formistic/v1/newsletter` with `{ email, source }`.
5. Shortcode: `[wpistic_formistic_newsletter source="custom-page"]` for in-content placement.

**Built-in protections:**
- 60-second per-IP throttle prevents spam floods.
- Resubscribe handling — if a previously unsubscribed email subscribes again, the row is re-activated (no duplicate).
- WP nonce on every form.

### 9. Footer, social links

**Appearance → Customize → Footer / Social** for social URLs.

Footer columns (Quick Links, Hours, Contact) are theme-driven; edit copy via **Appearance → Customize → Footer Content**.

---

## Recommended `wp-config.php` for staging / development

To prevent customer-facing emails firing from a staging copy of the prod DB:

```php
define( 'G2AB_EMAIL_DISABLED',              true );
define( 'MEMBERISTIC_EMAIL_DISABLED',       true );
define( 'WPISTIC_FORMISTIC_EMAIL_DISABLED', true );

// OR — keep flows visible by rerouting every outbound email to a single
// staging inbox. Original recipient is preserved in the subject prefix.
// define( 'G2AB_EMAIL_OVERRIDE_RECIPIENT',        'ops@guns2ammo.com' );
// define( 'MEMBERISTIC_EMAIL_OVERRIDE_RECIPIENT', 'ops@guns2ammo.com' );

// Stripe secret-key constants (Memberistic). Setting any of these makes the
// plugin refuse to write the matching option AND mask the value in the
// REST GET /settings response.
// define( 'MEMBERISTIC_STRIPE_TEST_SECRET_KEY',   'sk_test_...' );
// define( 'MEMBERISTIC_STRIPE_LIVE_SECRET_KEY',   'sk_live_...' );
// define( 'MEMBERISTIC_STRIPE_WEBHOOK_SECRET',    'whsec_...'  );
```

## Post-install setup

1. **Appearance → Customize → G2A Business Info** — fill FFL #, phone, email, address.
2. **Memberistic → Settings → Pages** — point at your /account/, /checkout/, /thank-you/ pages.
3. **Booking Engine → Resources** — confirm lane / range resources exist with the right capacity. DB schema should read **v1.6.1** under Booking Engine → Build Status.
4. **Pages → Machine Gun** — add your 20+ weapons via the inventory repeater.
5. **Formistic → Settings → Auto-Responder** — point the auto-responder at the right inbox.

---

## What's in this build

### Theme (1.14.0)
- **Newsletter signup forms (footer + blog) now work** — POST to WPistic Contact Form's `wpcf_newsletter_subscribe` endpoint with inline success/error status.
- **Pricing + Memberships pages updated** with per-tier guest pricing baked into every plan card + a full "How lane pricing works" matrix.
- Previous 1.13.0 work below.

### Theme (1.13.0)
- Hours pivot consistent across SEO schema, llms.txt, JS live-status pill.
- `g2a_ffl_license` customizer field (block hides when empty).
- Skip-to-content link, mobile drawer ARIA + focus trap.
- Tablet/iPad portrait inputs ≥16px (no iOS focus zoom).
- "Book Another Lane" CTA + confetti + recap card on booking confirmation.
- **Machine-gun inventory: hardcoded weapons removed; admin-editable repeater drives both hero row and inventory grid.**

### Booking Engine (1.9.0, DB 1.5.0)
- Email kill-switch + recipient override.
- Reminder cron TZ math fix; cron unscheduled on deactivation.
- Public REST read endpoints rate-limited per IP (60/min, filterable).
- Legacy confirm-payment bypass closed.
- Payment-row idempotency: UNIQUE KEY `(gateway, transaction_id)` + race-safe upsert.

### Memberistic (1.15.0)
- Customer-facing "Members Hub" branding.
- Per-plan rules drive free-lane logic (no more blanket 100% off).
- WP user creation deferred from checkout-start to Stripe webhook.
- Server-side content restriction via `the_posts` redaction.
- Stripe webhook event-id idempotency + secret-key constant overrides.
- **Member profile photo upload (REST `/profile/image`, 5MB MIME-checked).**
- **Dynamic verify QR: card QR encodes `/?memberistic_verify=TOKEN` resolving to a live, no-PII verification card with live plan/status/photo.**
- **`memberistic_get_brand_label()` defaults to the site name (no hardcoded "GUNS 2 AMMO").**

### Contact Form (1.5.0, DB 1.2.0)
- **NEW Newsletter system**: subscribers table, public AJAX + REST + shortcode capture, admin "Newsletter" tab under WPistic Contact with search/filter/unsubscribe + CSV export. Contact-form opt-in checkbox auto-subscribes on submit. 60-second per-IP throttle.
- Email kill-switch (`WPCF_EMAIL_DISABLED`).
- Per-target-email throttle on auto-responder (1 hour transient).

### Theme Control (1.0.0)
- **Machine-gun inventory repeater with image (Media Library), text, textarea, url subfield types.**
- Per-subfield sanitization in the save handler.
- 200-row cap (filterable via `g2a_tc_repeater_max_rows`).

## Rollback

Every change lives in a separate commit on `claude/practical-hawking-LQW9g`. To roll back any single fix, `git revert <sha>` and rebuild the zip from that commit. The Booking Engine v1.5.0 UNIQUE KEY migration is additive — drop the key + downgrade `g2ab_db_version` option to `1.4.0` to revert.

## Source diffs

The repo tracks extracted source under `guns2ammo/`, `g2a-booking-engine/`, `memberistic-membership-solutions/`, `formistic/`, `g2a-theme-control/`. Those mirror what's inside the zips. Future edits should land in the source dirs; rebuild the zips before releasing.
