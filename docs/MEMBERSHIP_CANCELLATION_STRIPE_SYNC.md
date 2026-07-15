# Membership Cancellation ↔ Stripe Sync — Verification & Fix (2026-07-15)

Client complaint: *"Cancelling a membership on the website doesn't cancel the
Stripe subscription — billing keeps running."*

## What the audit found (Memberistic 1.12.0, before this fix)

Propagation code existed (added 1.10.1–1.12.0) and covered every cancel path,
but the **operation order was unsafe for a financial action**:

1. Every cancel path (members-app REST action, REST status update, legacy
   wp-admin links, WooCommerce refund bridge) routes through
   `Memberships_Repository::change_status()`, which fires
   `memberistic_membership_status_changed` **after** the local row is already
   marked `cancelled`.
2. The listener `Stripe_Service::maybe_cancel_remote_subscription()` then
   called Stripe **once**. On failure it only wrote an activity-feed entry —
   no retry, no admin notice. Result: site says *cancelled*, Stripe keeps
   billing until a human happens to read the feed.
3. Verified safe paths (unchanged): customer self-service cancels go through
   the **Stripe billing portal** (Stripe is the source of truth) and inbound
   webhooks (`customer.subscription.deleted`) sync Stripe-side cancellations
   into the site, with a re-entrancy guard so the sync never calls Stripe
   back. A raw `Memberships_Repository::update(['status'=>…])` would bypass
   the hook, but no cancel path uses it (audited all callers).

## The fix (Memberistic 1.13.0)

**Stripe first, local status second.**

- New `Stripe_Service::cancel_remote_first()` runs **before** the local flip
  in the members-app REST cancel and the legacy wp-admin cancel action. The
  membership is only marked `cancelled` after Stripe confirms the
  subscription is stopped (idempotent: "already cancelled"/"no such
  subscription" counts as confirmed; no subscription on file or Stripe
  disabled ⇒ nothing to stop).
- **On Stripe failure the membership keeps its current status.** The operator
  gets an explicit error (REST 502 with the Stripe message; a red notice on
  the legacy screen). No more silent divergence.
- **Automatic retries with backoff** (5 min, 30 min, 2 h, 6 h, 24 h, 48 h)
  via WP-Cron (`memberistic_stripe_cancel_retry`). A successful retry also
  completes the local cancellation automatically, so the operator's intent
  is always fulfilled — just never faked.
- **Persistent wp-admin notice** lists every membership whose Stripe cancel
  is failed/retrying ("these members may still be billed"), clearing only
  when Stripe confirms. Retries exhausted ⇒ the notice stays with an
  instruction to cancel manually in the Stripe Dashboard.
- **Explicit override:** `POST /memberistic/v1/memberships/{id}/cancel` with
  `force=true` cancels locally even while Stripe is failing (retries keep
  running in the background).
- The post-flip hook listener remains as a safety net for any other
  `change_status('cancelled')` caller (e.g. the WooCommerce refund bridge,
  where the refund has already happened so the local cancel must proceed) —
  it now queues the same retry machinery on failure instead of only logging.

## What still needs live verification on production

1. **Stripe webhook configuration** — the inbound sync only works if the
   webhook endpoint is registered in the Stripe Dashboard and the signing
   secret matches the plugin setting (~10-minute check; needs Stripe
   Dashboard access).
2. **A real end-to-end test** on staging or with a test-mode subscription:
   cancel on the site → confirm the subscription shows `canceled` in Stripe;
   then simulate a Stripe outage (wrong key) → confirm the membership stays
   active, the notice appears, and a retry completes it.
3. Production must actually be running Memberistic ≥ 1.13.0 (deployment is
   manual zip upload; `releases/memberistic-membership-solutions-1.13.0.zip`).
