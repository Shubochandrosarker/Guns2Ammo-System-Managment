# Corporate / Group Membership — Audit & Implementation Plan

## A. System audit (what already exists)

The client asked for a separate `g2a-corporate-groups` plugin with
8 new tables (groups, group_members, group_payments, payment_links,
qr_tokens, checkins, email_logs, activity_log). **Most of that
backbone already exists inside Memberistic.** Building a separate
plugin would duplicate and fragment the data. The engineering call
(which the spec invites — "integrate with the current system, do
not duplicate") is to add a **Corporate Groups module inside
Memberistic** that reuses its infrastructure and adds only the
group-specific tables.

### What Memberistic already provides

| Spec wants | Already in Memberistic | Reuse strategy |
|---|---|---|
| Individual member accounts | `memberistic_memberships` + WP users + `memberistic_people` | Each group member = a normal Memberistic membership, tagged with a `group_id`. |
| Waiver status per member | `memberistic_people.waiver_status / waiver_signed_at / waiver_expires_at` | Reuse as-is; group view aggregates these. |
| QR / check-in identity | `Utilities\Verification` (per-user 32-char token + `/?memberistic_verify=TOKEN` live card) + `memberistic_checkins` table | Reuse the existing token + verify page. Non-member buyers get a token the same way. |
| Check-in log | `memberistic_checkins` (checkin_type, etc.) | Reuse; add optional `group_id`. |
| Email logs | `memberistic_email_logs` | Reuse; log group emails here with new email_type values. |
| Activity log | `memberistic_activity` | Reuse; log group events here. |
| Payments | `memberistic_payments` | Reuse for per-member; add `memberistic_group_payments` for the group-level (one payer, many seats). |
| Confirmation / branded emails | `Emails\Email_Service` (merge tags, branded templates, `/account/` `/login/` `/book-a-lane/` URLs) | Reuse; add group + waiver + payment-link templates. |
| Branded member card + QR | `templates/account.php` digital card (logo, photo, QR) | Reuse; non-member buyers get a lighter card. |
| Stripe payments | `Payments\Stripe_Service` (checkout, webhook idempotency) | Reuse for the custom-amount payment link. |
| WooCommerce bridge | `Integrations\WooCommerce_Bridge` | Reuse for POS/online-order reference linking. |

### Gaps the new module must fill

1. **Group container** — one record linking a primary payer + N
   individual memberships, with seats_total / seats_used /
   max_future_seats / custom_price / payment_status / visibility.
2. **Group-level payment record** — one $600 POS/cash payment that
   covers the whole group (independent of per-member payments).
3. **Custom-amount payment links** — generate a tokenized link,
   email it, mark paid, connect to the group + a hidden WC order.
4. **Group admin UI** — list + single-group tabs (Overview /
   Members / Payments / Waivers / QR / Emails / Activity).
5. **Group front-end** — owner sees group summary + members;
   members see their own card/waiver/QR.
6. **Public handling** — group plans hidden by default; optional
   "Corporate / Group Plans — Call for Details" CTA.
7. **Non-member online-buyer flow** — purchase → guest profile →
   QR + waiver email → staff check-in (a lightweight "Guest Pass"
   plan in Memberistic, reusing the existing QR + waiver infra).

## B. Implementation plan (phased)

### Phase 1 — DB + admin group management  ← THIS RELEASE
- New tables: `memberistic_corporate_groups`,
  `memberistic_group_members`, `memberistic_group_payments`,
  `memberistic_payment_links` (dbDelta, versioned, additive).
- Capabilities: `manage_memberistic_groups`,
  `view_memberistic_groups`.
- Admin → Memberistic → **Corporate Groups**: list table +
  Create Group form (name, primary payer, plan, seats_total,
  max_future_seats, custom_price, payment_method, payment_status,
  visibility, notes). Nonce + sanitization + capability checks.
- Repository layer (`Corporate_Groups_Repository`).
- Activity logged to the existing `memberistic_activity` table.

### Phase 2 — member linking + confirmations
- Add/create/bulk-import members under a group (each = a real
  Memberistic membership tagged `group_id`).
- Seats-used accounting + seat-limit guard.
- Branded individual confirmation email (account setup link +
  group name + waiver link + QR) via the existing Email_Service.
- Group summary email to the primary payer.

### Phase 3 — payment links + offline payment tracking
- Custom-amount payment link generator (tokenized URL,
  expiry, paid/unpaid, branded invoice).
- Stripe checkout via the existing Stripe_Service; on success,
  write a `group_payments` row + hidden WC order metadata
  (group_id, payer_id, link_id, purpose, creator).
- Manual/offline payment recording (cash/POS reference, partial
  payment, balance-due, deposit).

### Phase 4 — waiver automation
- Auto-send waiver email on member add / guest purchase.
- Status tracking (not sent / sent / completed / expired) on the
  existing `memberistic_people` waiver fields.
- Staff quick actions: resend, open signing link, manual
  exception with note.

### Phase 5 — QR / check-in (mostly reuse)
- Reuse `Verification` tokens for every member + guest buyer.
- Staff check-in screen (capability-gated) showing name, order,
  waiver status, group status, eligibility, notes.
- Write check-ins to `memberistic_checkins` with `group_id`.
- Non-member "Guest Pass" plan → QR + waiver email on purchase.

### Phase 6 — front-end account UX
- Group owner: group summary, members list, seats used/available,
  payment status, invite + request-more-seats actions.
- Individual member: their card, waiver, QR, group name.

### Phase 7 — reports + polish
- Active groups, members per group, payment status, waiver-pending
  list, recent check-ins, expiring groups, groups with open seats,
  groups with unpaid balance.

## Recommendation to the client on the real 10-person scenario

Set the group up as **one Corporate Group record (primary payer =
the person who paid the $600 POS sale) with 10 individual member
accounts linked to it.** Each member keeps their own login, waiver,
QR, and confirmation email. The $600 is recorded once as a
group-level payment (method: POS/Cash, reference: POS receipt #).
When they grow to 60, you raise `seats_total` and add members — no
rebuild. The plan is hidden from public pricing; the public site
shows a "Corporate / Group Plans — Call for Details" CTA instead.

This is exactly what Phase 1 builds the foundation for.
