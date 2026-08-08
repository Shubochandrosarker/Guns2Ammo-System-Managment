# Memberistic Checkout Incident Note - 2026-07-28

## Root Cause

The public checkout form in `templates/checkout.php` posts to `Stripe_Service::checkout_action_url()`, which currently resolves to `/?memberistic_checkout_handler=1`. `maybe_handle_public_checkout_request()` routes valid POST submissions to `handle_checkout_request()`.

Before Stripe Checkout Session creation, `handle_checkout_request()` builds a transient key:

`memberistic_checkout_rl_` + `md5( client_ip + email_hash )`

That key is 56 characters. The old `acquire_lock()` prepended `memberistic_rl_lock_` and passed the resulting 76-character name to `GET_LOCK()`. MySQL advisory lock names are limited to 64 characters, so `GET_LOCK()` could return NULL/failure before Stripe was contacted. Memberistic then treated the database failure as normal lock contention and surfaced "Checkout is busy. Please try again in a moment." with HTTP 503. `RELEASE_LOCK()` used the same oversized name.

## Fix Summary

- Advisory lock names now use `memberistic_rl_` plus a SHA-256 hash truncated to fit the 64-character MySQL limit.
- `GET_LOCK()` results are categorized: `1` means acquired, `0` means contention, and NULL/false means database/compatibility failure.
- Checkout rate limiting falls back to a durable `memberistic_rate_limits` table when advisory locks are unavailable.
- Public checkout sends no-cache headers, rejects GET processing with 405, requires the existing action and nonce, and checks same-origin referers when available.
- Stripe redirect hosts are restricted to `checkout.stripe.com` for checkout redirects.
- The checkout-start email is no longer sent before Stripe returns a valid Checkout Session. Activation email remains webhook/API-authoritative after payment verification.
- Failed Stripe session creation leaves the pending membership recoverable and records a manual-review entry without activating the membership.

## Cache and Cloudflare Exclusions

Exclude these paths and query patterns from WordPress page cache, server cache, CDN cache, and Cloudflare cache rules:

- `*memberistic_checkout_handler=1*`
- Memberistic checkout page
- Stripe webhook endpoint: `/wp-json/memberistic/v1/webhooks/stripe`
- Thank-you/payment confirmation page
- Failed-payment/cancel page
- Member account and billing portal pages

Do not cache pages containing checkout nonces or customer-specific account data.

## Staging QA Checklist

1. Back up the staging database and current plugin directory.
2. Deploy the patched plugin to staging.
3. Purge WordPress, server, and Cloudflare cache.
4. Confirm the `memberistic_rate_limits` table exists after activation/upgrade.
5. Use Stripe test mode.
6. Test anonymous monthly checkout.
7. Test anonymous annual checkout.
8. Test logged-in member checkout.
9. Double-click the submit button.
10. Refresh the handler POST safely.
11. Open checkout in two tabs.
12. Cancel at Stripe and retry.
13. Complete payment successfully.
14. Confirm exactly one local membership.
15. Confirm exactly one Stripe customer/subscription.
16. Confirm one payment record.
17. Confirm webhook processing activates the membership.
18. Confirm account provisioning.
19. Confirm confirmation email after payment verification.
20. Confirm no PHP warnings/notices.
21. Confirm no duplicate pending membership.
22. Confirm checkout works behind Cloudflare.
23. Confirm rate limiting returns HTTP 429 only after the configured threshold.

Use unique test emails and remove or reconcile test data afterward.

## Production Deployment and Rollback

1. Put the production site in a controlled deployment window.
2. Back up the database and existing `memberistic-membership-solutions` plugin directory.
3. Upload the clean 1.18.5 plugin ZIP and replace the existing plugin.
4. Visit wp-admin once or run the normal upgrade path so `MEMBERISTIC_DB_VERSION` advances to 1.11.0.
5. Purge WordPress, server, and Cloudflare caches.
6. Run one Stripe test-mode checkout on staging immediately before production, then one low-risk live checkout or staff-assisted live verification if business policy allows.
7. Monitor PHP logs, Stripe Dashboard events, Memberistic activity, and webhook delivery for at least one billing cycle event.

Rollback: restore the previous plugin directory and database backup from the deployment window. If any Stripe Checkout Session was created during the deployment window, reconcile in Stripe before deleting or changing local pending membership rows.
