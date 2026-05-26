# Installation guide

This guide walks through getting Memberistic Membership Solutions installed and configured for a production launch.

> Memberistic is co-developed by [WordPressistic](https://www.wordpressistic.com) and launch partner [Guns 2 Ammo](https://guns2ammo.com). The defaults described below — Defender / Patriot / Guardian plans, waiver-gated check-ins, lane-booking integration — mirror the production [Guns2Ammo](https://guns2ammo.com) operation.

## 1. Upload and activate

1. Copy `memberistic-membership-solutions/` into `wp-content/plugins/`.
2. Activate the plugin from the WordPress admin (Plugins → Installed Plugins).
3. On activation Memberistic will:
   - Create all 10 custom tables.
   - Seed Defender, Patriot, and Guardian plans (only on first install).
   - Add the six custom roles and their capabilities.
   - Schedule three daily cron jobs (renewal reminders, auto-expire, waiver follow-up).

## 2. Set the brand

Open **Memberistic → Settings → General** and fill in:

- Brand label (shown in admin menu, emails, status badges).
- Business name, phone, and address.
- Primary brand colour and (optional) logo URL.
- Default currency.
- Timezone (defaults to your site's timezone).

## 3. Map the frontend pages

Open **Memberistic → Tools → Page Mapping** and click **Create Pages**. Memberistic will create or remap eight branded pages with the right shortcodes:

| Setting key | Page slug | Shortcode |
|-------------|-----------|-----------|
| `plans_page_id` | `memberistic-memberships` | `[memberistic_plans]` |
| `checkout_page_id` | `memberistic-checkout` | `[memberistic_checkout]` |
| `account_page_id` | `memberistic-account` | `[memberistic_account]` |
| `renewal_page_id` | `memberistic-renewal` | `[memberistic_renewal]` |
| `login_page_id` | `memberistic-login` | `[memberistic_login]` |
| `thank_you_page_id` | `memberistic-thank-you` | `[memberistic_thank_you]` |
| `failed_payment_page_id` | `memberistic-payment-failed` | `[memberistic_payment_failed]` |
| `staff_dashboard_page_id` | `memberistic-staff-dashboard` | `[memberistic_staff_dashboard]` |

You can edit page titles and bodies freely afterwards — Memberistic only cares about the page IDs stored in settings.

## 4. Enable Stripe

1. Sign in to your Stripe dashboard and copy your publishable + secret keys (test or live).
2. In **Memberistic → Settings → Payments**:
   - Enable Stripe.
   - Pick **test** or **live** mode.
   - Paste the matching publishable and secret keys.
3. In the Stripe dashboard, **Developers → Webhooks**, add an endpoint:
   `https://your-site.com/wp-json/memberistic/v1/webhooks/stripe`
4. Select the events:
   - `checkout.session.completed`
   - `invoice.payment_succeeded`
   - `invoice.payment_failed`
   - `customer.subscription.deleted`
5. Copy the webhook signing secret into **Memberistic → Settings → Payments → Webhook secret**.

A signing secret is **required** — the webhook route returns `503` if it is not configured.

## 5. (Optional) Enable WooCommerce

1. In **Memberistic → Settings → Integrations** enable WooCommerce.
2. (Optional) set a shared WooCommerce webhook secret for signed webhook delivery.
3. From WP-CLI or a one-off admin action, fire `memberistic_ensure_woocommerce_products` to create the six hidden virtual products (Defender / Patriot / Guardian × Monthly / Annual). If you sell memberships only through Stripe Checkout, you can skip this step.

## 6. Email setup

1. **Memberistic → Settings → Emails**:
   - From-name (defaults to your brand label).
   - From-email (defaults to the WP admin email).
2. Confirm that WordPress can send mail (use a deliverability plugin like FluentSMTP or Postmark if needed).
3. The three daily cron jobs run automatically once a day at ~06:00. To verify, use a plugin like WP Crontrol and look for `memberistic_daily_renewal_reminders`, `memberistic_daily_expire_memberships`, `memberistic_daily_waiver_followup`.

## 7. Staff roles

Assign your team to the right roles in **Users → Edit user**:

| Role | Capabilities |
|------|--------------|
| `memberistic_manager` | Full Memberistic management |
| `memberistic_staff` | Search, view, create members, check-in, notes |
| `memberistic_cashier` | Create / renew members, manage payments |
| `memberistic_instructor` | View members and waiver eligibility |
| `memberistic_kiosk_operator` | Check-in only (reserved for the KIOSK module) |
| `memberistic_pos_staff` | Members + payments (reserved for the POS module) |

## 8. Smoke test

1. Open the public Memberistic Plans page — Defender, Patriot, Guardian should appear with the monthly/annual toggle.
2. Click **Choose Defender → Monthly → Continue to Secure Payment**. Stripe Checkout should open in test mode.
3. Pay with `4242 4242 4242 4242` (any future expiry, any CVC).
4. Confirm:
   - You land on the Thank You page.
   - **Memberistic → Members** lists the new membership with status **Active**.
   - **Memberistic → Activity** shows `membership_created` and `membership_activated`.
   - **Memberistic → Payments** lists the Stripe payment.
   - The member receives the `membership_activated` email.

## 9. Uninstall behaviour

`uninstall.php` only drops data if **Memberistic → Settings → Advanced → Delete data on uninstall** is set to **Yes**. The default is **No** so re-activating the plugin restores state cleanly.
