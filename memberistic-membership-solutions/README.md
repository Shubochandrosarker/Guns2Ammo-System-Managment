# Memberistic Membership Solutions

A modern membership operations engine for service businesses — co-developed by **[WordPressistic](https://www.wordpressistic.com)** and launch partner **[Guns 2 Ammo](https://guns2ammo.com)**, the US-based indoor shooting range and firearms retail business.

Memberistic runs the full lifecycle of a membership — plans, signup, payment, renewals, family / linked members, check-ins, waivers, staff dashboards, REST API, and Stripe + WooCommerce integration — from a single WordPress plugin. It's the membership-management backbone behind the **Guns 2 Ammo Membership Engine** and the foundation of the upcoming Memberistic commercial product.

- **Plugin version:** 1.10.7
- **WordPress:** 6.0+
- **PHP:** 8.0+
- **License:** GPLv2 or later

---

## 🤝 Built with our launch partner

Memberistic is the product of a **joint venture between [WordPressistic](https://www.wordpressistic.com) and [Guns 2 Ammo](https://guns2ammo.com)**. It is not a generic, untested membership plugin — every feature in this engine has been designed against the real day-to-day operations of an active US shooting range and retail counter.

**[Guns 2 Ammo](https://guns2ammo.com)** runs the kind of business this software was built for:

- An indoor shooting range with lane bookings and waiver-gated check-ins.
- A retail counter selling firearms, ammunition, and accessories.
- A family-friendly membership model with primary and linked members.
- A staff team that needs to onboard, check-in, and renew members in seconds at the front desk.

Every workflow in this plugin — the **Defender / Patriot / Guardian** plan tiers, the linked-member system, the staff dashboard, the booking-engine integration, the kiosk-ready waiver model, the POS-ready schema — is **battle-tested in production at [Guns2Ammo](https://guns2ammo.com)**.

The two companies are jointly preparing the next stage — a unified mobile + web membership and POS dashboard — for a US launch.

> _If you operate a shooting range, fitness studio, climbing gym, dive shop, or any service business that runs on memberships, [visit Guns 2 Ammo](https://guns2ammo.com) to see Memberistic in real-world operation._

---

## What's in the box

### Core engine

- Custom database with 10 dedicated tables (plans, memberships, people, payments, check-ins, notes, activity, email logs, integrations, system logs).
- Three default membership tiers seeded on first install: **Defender**, **Patriot**, **Guardian** — the canonical [Guns 2 Ammo](https://guns2ammo.com) plan structure.
- Ten membership statuses including `pending`, `active`, `past_due`, `expired`, `cancelled`, `paused`, `comped`, `trial`, `suspended`, `needs_review`.
- Linked / family member CRUD with per-person waiver, phone, DOB, relationship, and check-in history.
- 21 tracked activity event types feeding a per-membership timeline.

### Admin experience

- A modern React-driven admin under `Memberistic` in the WP admin menu: Dashboard, Members, Plans, Payments, Check-Ins, Activity, Settings, Emails, Integrations, Shortcodes, Tools.
- Live dashboard with active-members, MRR, expiring-soon, past-due, check-ins-today, waiver-missing, and 12-month revenue chart.
- Saved-views system for staff who want named filters (e.g. "Past due last 7 days").
- 6 custom roles + admin: Manager, Staff, Cashier, Instructor, KIOSK Operator, POS Staff.

### Frontend

- 14 shortcodes: `[memberistic_plans]`, `[memberistic_checkout]`, `[memberistic_account]`, `[memberistic_people]`, `[memberistic_payment_history]`, `[memberistic_booking_history]`, `[memberistic_renewal]`, `[memberistic_login]`, `[memberistic_thank_you]`, `[memberistic_payment_failed]`, `[memberistic_profile_summary]`, `[memberistic_status]`, `[memberistic_expiring_notice]`, `[memberistic_staff_dashboard]`.
- Auto-mapped branded pages on activation.
- Brand-colour-driven plan cards with monthly/annual toggle and annual-savings badge.
- Content-restriction overlay for posts / pages locked to specific plans.

### Payments

- Stripe Checkout (hosted) for monthly + annual subscriptions.
- Full webhook handling: `checkout.session.completed`, `invoice.payment_succeeded`, `invoice.payment_failed`, `customer.subscription.deleted`, plus a `memberistic_stripe_webhook_event` action for custom event types.
- WooCommerce bridge with auto-created hidden virtual products (Defender / Patriot / Guardian × Monthly / Annual), order-completed sync, refund / cancel sync, and a signed `/webhooks/woocommerce` route.
- Manual + cash payment recording from the admin.

### Email automation

- 13 transactional templates: membership created / activated / renewed / cancelled / expired / 30-day / 7-day / 1-day reminders, payment failed, linked-member added, waiver missing, generic renewal reminder, and staff manual message.
- 20 documented merge tags (`{member_name}`, `{plan_name}`, `{renewal_date}`, `{account_url}`, …).
- Three daily cron jobs: renewal reminders, auto-expire, waiver follow-up.
- Every send logged to `wp_memberistic_email_logs`.

### REST API

Namespace: `wp-json/memberistic/v1/`

```
plans                              GET, POST
plans/{id}                         GET, PUT, DELETE
memberships                       GET (9 filters), POST
memberships/{id}                  GET, PUT, DELETE
memberships/{id}/people           GET, POST
people/{id}                       PUT, DELETE
memberships/{id}/payments         GET, POST
memberships/{id}/activity         GET
memberships/{id}/bookings         GET   → returns { bookings, checkins }
memberships/{id}/checkins         POST
memberships/{id}/notes            POST
memberships/{id}/renew            POST
memberships/{id}/cancel           POST
memberships/{id}/upgrade          POST
memberships/{id}/emails           POST
email-templates                   GET
dashboard/stats                   GET
dashboard/expiring-soon           GET
dashboard/recent-activity         GET
dashboard/revenue-history         GET
payments                          GET
checkins                          GET
activity                          GET
activity/types                    GET
saved-views                       GET, POST, DELETE
settings                          GET, PUT
settings/pages                    POST
settings/pages-options            GET
webhooks/stripe                   POST
webhooks/woocommerce              POST
```

Every authenticated route enforces a capability-based `permission_callback`. Webhook routes verify HMAC signatures and a 5-minute replay window.

---

## Installation

1. Upload `memberistic-membership-solutions/` to `wp-content/plugins/`.
2. Activate **Memberistic Membership Solutions** in WordPress.
3. Visit `Memberistic → Plans` and confirm the three default tiers are present.
4. Visit `Memberistic → Settings` and:
   - Save business name / phone / address.
   - Pick a primary brand colour.
   - Set the from-name and from-email for transactional mail.
   - Enable Stripe and paste your publishable / secret / webhook keys.
   - Optionally enable WooCommerce and (if you want signed webhooks) set the shared secret.
5. Use `Tools → Page Mapping → Create Pages` to generate the branded frontend pages and shortcodes.

The Stripe webhook URL to register in your Stripe dashboard is:

```
https://your-site.com/wp-json/memberistic/v1/webhooks/stripe
```

The WooCommerce webhook URL is:

```
https://your-site.com/wp-json/memberistic/v1/webhooks/woocommerce
```

See [`docs/INSTALL.md`](docs/INSTALL.md) for the full production setup walkthrough.

---

## Documentation

- **[Audit report](docs/AUDIT_REPORT.md)** — feature-by-feature compliance against the canonical Memberistic spec.
- **[Installation guide](docs/INSTALL.md)** — production setup walkthrough.
- **[Hook reference](docs/HOOKS.md)** — every action and filter Memberistic exposes.
- **[Partners](docs/PARTNERS.md)** — about WordPressistic and Guns 2 Ammo.
- **[Changelog](CHANGELOG.md)** — version history.

---

## Branding and customisation

Most strings, default templates, and behavioural toggles are filterable. The most useful hooks:

| Filter | Purpose |
|--------|---------|
| `memberistic_default_plans` | Replace or extend the three seeded plans on first install. |
| `memberistic_email_templates` | Register additional transactional email templates. |
| `memberistic_email_template_subject` / `_body` | Override the subject or body of any template. |
| `memberistic_email_merge_tags` | Add custom merge tags for branded campaigns. |
| `memberistic_brand_label` | Replace the visible brand name in admin + emails. |
| `memberistic_roles` | Add or rename custom roles. |
| `memberistic_capabilities` | Extend the capability set. |
| `memberistic_required_pages` | Customise the auto-created frontend pages. |
| `memberistic_should_send_email` | Short-circuit individual email sends per membership. |
| `memberistic_stripe_webhook_event` | Hook custom Stripe event handling. |

Full hook reference: [`docs/HOOKS.md`](docs/HOOKS.md).

---

## Repository layout

```
memberistic-membership-solutions.php   Plugin bootstrap
includes/
  class-plugin.php                     Coordinator + hook wiring + asset enqueue
  class-installer.php                  Install / upgrade entry point
  class-activator.php                  Activation hook
  class-deactivator.php                Deactivation hook
  class-roles.php                      Custom roles
  class-capabilities.php               Capability map
  class-content-restrictions.php       Plan-gated post / page overlay
  class-scheduler.php                  Daily cron — reminders, expiry, waiver follow-up
  class-router.php                     Admin slug constants
  admin/                               Admin React mount points + legacy handlers
  database/                            Schema, migrations, and one repository per table
  emails/                              Email service + merge-tag rendering
  frontend/                            Shortcodes + frontend staff dashboard
  integrations/                        Booking Engine + WooCommerce bridge
  payments/                            Stripe Checkout + webhook handling
  rest/                                REST controllers, one per resource group
  utilities/                           Sanitization, formatting, helpers
assets/                                Admin React apps + frontend JS + CSS
templates/                             Public-facing PHP templates
docs/                                  Audit report, install guide, hook reference, partners
uninstall.php                          Safe data removal (gated by setting)
```

---

## Credits & partners

**[WordPressistic](https://www.wordpressistic.com)** — Plugin architecture, engineering, and ongoing product development. WordPressistic builds custom WordPress engines for service businesses and is the company behind Memberistic.

**[Guns 2 Ammo](https://guns2ammo.com)** — Launch partner, joint-venture co-developer, and the flagship deployment of this engine. [Guns2Ammo](https://guns2ammo.com) is a US-based indoor shooting range and firearms retail business; its real-world membership and front-desk operations have shaped every workflow in Memberistic.

For commercial inquiries or to see Memberistic in action, visit **[guns2ammo.com](https://guns2ammo.com)** or **[wordpressistic.com](https://www.wordpressistic.com)**.

---

## License

GPLv2 or later. See [`LICENSE`](LICENSE).
