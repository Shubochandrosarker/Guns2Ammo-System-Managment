# Incident triage — memberships, payments, emails, hours (May 28, 2026)

Client report (Guns 2 Ammo) covered several issues at once. They split
into three buckets: **(A) fixed in code here** (ship the updated plugin/
theme zips), **(B) live-site / dashboard configuration** that no code
change can do for you, and **(C) decisions / roadmap**. Read all three —
the payment problems are mostly bucket B.

---

## A. Fixed in code (this branch — deploy the new zips)

### 1. Dead "Update Payment" link → real Stripe Billing Portal
**Root cause:** every payment-management button on `/account/`
(`Update Payment`, `Update Payment Method`, `Switch to Monthly/Annual`,
the past-due/expired banners) linked to `$renewal_url`, which had been
re-pointed to the `account` page itself. So the link loaded the same
page — the "dead link" the customer hit.

**Fix:**
- New `Stripe_Service::create_billing_portal_session()` +
  `maybe_handle_billing_portal_request()` handler (registered on `init`).
- New nonced `Stripe_Service::billing_portal_action_url()`.
- `templates/account.php` now sends members with a Stripe customer to the
  **Stripe-hosted billing portal** (update card, view invoices, cancel),
  and members with **no** Stripe customer (legacy / cash / POS imports)
  to the plans page to start a real recurring subscription.
- Fail-safe: if the portal isn't enabled in Stripe yet, the member is
  bounced back to `/account/` with a friendly notice instead of a white
  error screen.

> **Requires one dashboard step — see B2.** The portal link only works
> after you enable the Customer Portal in Stripe.

### 2. Saturday/Friday hours wrong on two pages
Canonical hours (`guns2ammo/inc/business-info.php`) are correct
(Mon–Thu 10–6, Fri & Sat 10–7, Sun 12–6) and the home page + JSON-LD
schema already render from it. Two **hardcoded** stragglers still showed
the old values and are now corrected to **Sat 10–7 / Fri 10–7**:
- `guns2ammo/page-templates/template-contact.php` (showed Fri "Closed",
  Sat "9am–8pm").
- `memberistic-membership-solutions/templates/account.php` dashboard
  "Range Hours" tile (showed "Sat 9–8").

### 3. Email Reply-To + header filter (deliverability nudge)
`Email_Service::headers()` now adds a `Reply-To` (option
`memberistic_email_reply_to_address`, falls back to the From address) and
a `memberistic_email_headers` filter so an SMTP/deliverability add-on can
append `List-Unsubscribe` etc. without clobbering the branded From.
This helps inbox placement but is **not** a substitute for SPF/DKIM/DMARC
(see B3).

### 4. (already on this branch, before today)
Legacy PMPro URL redirects (`/membership-account/`, `/membership-billing/`,
…) → `/account/`; PMPro stale expiration emails suppressed; recurring
memberships get a 3-day grace + "past due" instead of a misleading
"expired" notice when a Stripe webhook is late.

---

## B. Live-site / dashboard actions (no code can do these for you)

### B1. Why members "didn't get charged" and show as EXPIRED — the big one
The 56 EXPIRED + the renewals "missing this month" are almost certainly
**legacy/imported members who have no Stripe subscription**. The system
only auto-charges members whose membership row has a
`stripe_subscription_id` (created when they check out through the new
Stripe flow). Imported members were never put on a Stripe subscription,
so:
- nothing charges them on their renewal day, and
- the daily job flips them to EXPIRED and emails them (e.g. Christopher).

**There is no way to "turn on" recurring billing for these members purely
in code** — each one needs a Stripe subscription created against their
saved card. Options:
1. **Self-serve (now wired):** point them at `/account/` → "Update
   Payment / Renew" → plans → checkout. New checkout = real Stripe
   subscription that auto-renews monthly on the same day going forward.
2. **Staff-assisted:** send each a Stripe payment link / subscription
   from the Stripe dashboard (or the Memberistic payment-link tooling).
3. **Migration:** if cards already live in Stripe (e.g. from a prior
   PMPro+Stripe setup), create subscriptions for those customers in the
   Stripe dashboard and store the `cus_…`/`sub_…` ids on the membership
   rows. This is a data task, not a code change.

Until a member is on a Stripe subscription, "each month same day cut the
payment" cannot happen for them.

### B2. Enable the Stripe Customer Billing Portal (required for A1)
Stripe Dashboard → **Settings → Billing → Customer portal** → activate,
choose what members can do (update card, cancel, switch plan), save.
Without this, `create_billing_portal_session` returns an error and the
new buttons fall back to the notice banner.

Also confirm in Memberistic → Settings:
- Stripe **enabled**, mode **live**, live secret key set.
- **Webhook** endpoint added in Stripe pointing at the site, signing
  secret saved, subscribed to: `checkout.session.completed`,
  `invoice.payment_succeeded`, `invoice.payment_failed`,
  `customer.subscription.deleted`. Missing webhooks = renewals not
  recorded and members drifting to past-due/expired.

### B3. Emails landing in spam (or "no emails at all")
Plain `wp_mail` from a shooting-range domain is high-risk for spam
folders. Code can't fix domain auth — DNS can:
- **SPF, DKIM, DMARC** records for the sending domain/relay (you're using
  "Site Mailer" per the screenshots — enable its DKIM and add the SPF
  include; publish a DMARC record).
- Send from a real mailbox on the domain (From `range@guns2ammo.com` or
  similar), not a generic/no-reply.
- Verify the domain inside the Site Mailer / SMTP provider.
- Use Memberistic → (email logs) to confirm sends are logged as `sent`.
  If logged `sent` but not received → deliverability (DNS). If not logged
  at all → the send path wasn't hit (see B4).

### B4. The 10-person group "got no setup emails"
Group members **do** each get a `group_member_welcome` email in code
(verified — each group member gets their own membership and the welcome
routes to their address). Most likely causes, in order:
1. Deliverability / spam (B3) — "received nothing" usually = spam or DKIM.
2. The email kill-switch is on: check constant `MEMBERISTIC_EMAIL_DISABLED`
   and option `memberistic_emails_disabled`, and that no
   `memberistic_email_override_recipient` is rerouting mail to a staff
   inbox (a staging leftover).
3. The members were added through a path with "send welcome" unticked.
Check the email logs for those 10 addresses to tell #1 from #2/#3.

### B5. `/account/` page must exist and host the shortcode
Confirm a published page at `/account/` containing
`[memberistic_account]`, and that Memberistic → Settings → "Account page"
points to it. The redirects assume `/account/` resolves.

---

## C. Decisions / roadmap (your call)

### C1. "Should I mark everyone who signed in store as waiver-signed?"
Reasonable, but two cautions:
- It's currently **per-member** in the admin (no bulk button yet) — see C2.
- Don't blanket-mark *every* member; only those you actually have a signed
  paper/electronic waiver for, and set a realistic expiry (the system
  treats waivers as valid 1 year). Mass-marking without proof defeats the
  point of the waiver record.

### C2. Waiver system ETA / "dump Otter by end of June"
A built-in waiver flow exists for group members (signable link, 1-year
validity, QR). What's **not** built: a self-serve waiver for *all*
members and a **bulk "mark signed"** admin action. If you want to retire
Otter, scope these two as the next milestone:
1. Bulk waiver-status action on the members console (React + REST).
2. Public waiver-sign page for any member (not just corporate), with
   logged signature + expiry, surfaced on the member card / check-in.
Happy to implement on confirmation — it's a feature build, not a hotfix,
so it's intentionally **not** in this PR.

### C3. Christopher specifically (MBR-6PEZTKHPA9710VV4PDWPDVRA)
Renewal 5/26, Monthly, not charged → he's on the legacy/no-subscription
path (B1). Quickest fix for him today: have him use the now-working
"Renew / Update payment" button on `/account/`, or send him a Stripe
subscription link from staff. That puts him on auto-renew so 5/26 charges
every month.

---

## Files changed in this PR
- `memberistic-membership-solutions/includes/payments/class-stripe-service.php`
- `memberistic-membership-solutions/includes/class-plugin.php`
- `memberistic-membership-solutions/includes/emails/class-email-service.php`
- `memberistic-membership-solutions/templates/account.php`
- `guns2ammo/page-templates/template-contact.php`
