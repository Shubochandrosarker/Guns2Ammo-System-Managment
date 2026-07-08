# coreSTORE ↔ Memberistic ↔ WooCommerce Integration Plan

**Date:** 2026-07-08 · **Status:** PROPOSED — awaiting approval before any build work starts
**Author:** WPistic engineering (Claude-assisted audit)

---

## 1. The situation

The client rings up in-store sales on **coreSTORE** (Coreware's firearms POS,
corestore.com) and will keep doing so until our own `g2a-pos-core` is complete.
Meanwhile memberships live in **Memberistic** on guns2ammo.com, and online
product sales run on **WooCommerce**.

Three things need to work *now*, without waiting for g2a-pos-core:

1. **At the counter:** when staff ring up a sale in coreSTORE, they must see
   whether the customer is an active member (plan, expiry) without opening a
   second system.
2. **Member discounts in-store:** an active member buying products in coreSTORE
   gets their member discount automatically — no cashier judgment calls.
3. **Member discounts online:** a member logged into guns2ammo.com who buys
   WooCommerce products gets the same discount automatically at cart/checkout.

## 2. What coreSTORE gives us to work with

coreSTORE is built on the PHP Point of Sale platform (Coreware acquired it),
which ships a documented **REST API**:

- **Auth:** per-store API key sent as an `x-api-key` header. Generated in
  coreSTORE under Store Config → API Keys ("Copy Key"). *(Confirm with
  Coreware whether the API is included in the client's plan or a paid add-on.)*
- **Base URL:** `https://<client-store-domain>/index.php/api/v1/`
- **Customers endpoint** — the one that matters most. Each customer record
  supports, among others:
  - `tier_id` — assigns the customer to a **price tier**. Tiers are how
    coreSTORE does automatic customer-class pricing: define a "G2A Member"
    tier with percentage discounts (per category or storewide) and every sale
    rung up for a tier member gets the discount applied by the POS itself.
  - `custom_fields` — arbitrary labeled fields shown on the customer
    dashboard. We write `Membership Plan`, `Status`, `Expires` here so the
    cashier sees membership state on the screen they already use.
  - `points` / `disable_loyalty` — native loyalty points, usable later.
  - Standard identity fields (email, phone, account_number) for matching.
- **Sales / items / employees endpoints** — read access to transactions, which
  later lets us pull in-store purchase history into the member's G2A account
  (nice-to-have, phase 3).

So we do **not** need Coreware to build anything: membership state is pushed
*into* coreSTORE's own customer + tier model, and coreSTORE's native discount
engine does the work at the register.

## 3. Architecture: a `CoreStore_Bridge` inside Memberistic

A new integration class in the plugin we already own
(`memberistic-membership-solutions/includes/integrations/class-corestore-bridge.php`),
following the exact pattern of the existing `POS_Bridge` / `WooCommerce_Bridge`
(toggle on Memberistic → Integrations, plus API URL + key fields).

**Event-driven push (real-time):**

| Memberistic event (hooks already exist) | Action in coreSTORE |
|---|---|
| `memberistic_membership_activated` | Find customer by email/phone (create if missing) → set `tier_id` = Member tier, write plan/expiry custom fields |
| membership cancelled / expired (status change) | Clear `tier_id` back to default, update custom fields to "Lapsed" |
| renewal recorded | Refresh the `Expires` custom field |

The coreSTORE customer id is stored on the membership row — the
`pos_customer_id` column already exists for exactly this purpose.

**Nightly reconciliation (safety net):** a WP-cron sweep comparing all active
memberships against coreSTORE tier assignments, fixing drift both ways
(member lapsed while API was down, member created directly at the register,
etc.), plus a manual **"Sync now"** button and a sync log on the Integrations
screen.

**Counter workflow after this ships:** cashier attaches the customer to the
sale exactly as they do today → membership plan/expiry is visible on the
customer dashboard → the member discount is already applied by the tier. Zero
new steps, zero new screens. For walk-ins claiming membership, the existing
Memberistic staff dashboard / digital-card QR remains the lookup of record.

## 4. WooCommerce member discounts (online)

New capability in the existing `WooCommerce_Bridge` (today it only *sells*
memberships as Woo products; it does not discount the rest of the catalog):

- **Per-plan discount settings** on each Memberistic plan: discount %,
  storewide or per product-category include/exclude list, and an "exclude
  sale items" toggle. (Firearms retail reality: MAP/dealer agreements often
  forbid discounting certain brands or new firearms — category exclusions are
  a first-class requirement, not polish.)
- **Application:** for a logged-in user with an **active** membership
  (resolved through the same lookup the POS bridge uses), prices are adjusted
  at cart-calculation time so the discount composes correctly with taxes,
  coupons and shipping. Guests and lapsed members see normal prices.
- **Visibility:** "Member price" shown on product pages next to the regular
  price, and a cart line making the saving explicit — the discount doubles as
  a membership-sales pitch to non-members ("Members would save $12.40 on this
  order — join from $X/mo").
- **Consistency rule:** one source of truth for the discount matrix (plan →
  %, categories) stored in Memberistic and used by BOTH the Woo bridge and the
  coreSTORE tier setup, so in-store and online never disagree.

## 5. Build phases & estimates

| Phase | Scope | Estimate |
|---|---|---|
| **1. Woo member discounts** | Plan discount settings + cart/product-page application + member-savings messaging. Pure in-repo work, no third-party dependency, testable immediately. | 1–2 days |
| **2. coreSTORE bridge** | API client, activate/lapse push, nightly reconcile, Integrations UI (URL, key, tier mapping, sync log), initial bulk sync of existing members. | 2–3 days + a joint session with the client to create the "G2A Member" tier and its discount rules inside coreSTORE |
| **3. Nice-to-haves** | In-store purchase history on the member's G2A account (via sales endpoint); coreSTORE loyalty points earned from Woo orders; booking check-in surfaced to the counter. | scoped later |

Phase 1 and 2 are independent — Phase 1 can start immediately on approval.

## 6. What we need before starting Phase 2

1. **coreSTORE API key** + the store's coreSTORE URL (client generates the key
   in Store Config; 2 minutes).
2. Confirmation from Coreware that **API access is enabled** on the client's
   subscription (some plans gate it).
3. The agreed **discount matrix**: which plans get what %, on which
   categories, and the exclusion list (MAP-protected brands, new firearms,
   consignment, gift cards…).
4. Decision on **matching rule** for existing POS customers: email first,
   fall back to phone — and what to do on ambiguous matches (recommend: leave
   unmatched, list them in the sync log for staff review, never guess).

## 7. Risks & honest caveats

- **API surface verification:** the PHP-POS-derived API documents customers
  (incl. `tier_id`, `custom_fields`), items, sales. First implementation day
  includes a spike against the client's real store to confirm coreSTORE hasn't
  restricted any of it. If `tier_id` writes were ever locked down, fallback is
  the customer-level discount field — same automation, slightly less granular.
- **Discount governance:** an automatic tier discount applies to whatever the
  tier says, register-wide. The category/brand exclusion list must be
  configured inside coreSTORE's tier rules too, not just online — that's part
  of the Phase-2 joint session.
- **This is a bridge, not the destination:** everything here rides on hooks
  (`memberistic_membership_activated`, `pos_customer_id`, the discount
  matrix) that `g2a-pos-core` already consumes via `POS_Bridge`. When the
  in-house POS is ready, we switch the toggle: no member data migration, no
  re-work.
