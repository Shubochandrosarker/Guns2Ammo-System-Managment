# PMPro → Memberistic migration reconciliation (May 28, 2026)

Source files reviewed (PMPro exports):
- members export — **335 member rows**
- orders export — **945 order rows**

> **Scope note.** This compares the *PMPro source export* against what the
> Memberistic importer (`includes/admin/class-import-page.php`) is designed
> to produce. It is a logic/fidelity check, not a live database diff — that
> requires an export of the *current* Memberistic members to compare 1:1
> (see "How to get a true 1:1 diff" at the end).

---

## 1. What the importer preserves faithfully ✅

| PMPro field | Memberistic target | Status |
|---|---|---|
| firstname/lastname/email/username | person name, email, login | ✅ |
| `membership` (level name) | plan (via level→plan map) | ✅ all 15 levels mapped |
| `subscription_transaction_id` (`sub_…`) | `stripe_subscription_id` | ✅ all 140 carried |
| `next_payment_date` | `renewal_date` | ✅ |
| `expires` | `end_date` (+ status) | ✅ |
| `startdate`/`joined` | `start_date` | ✅ |
| "… Additional Member" levels | linked person (not own plan) | ✅ detected by name |

**Level → plan mapping (all covered):**
Bronze / Bronze Yearly / Trigger (Annually) → **defender**;
Silver / Silver Yearly / Silver-Additional / Trigger Pro → **patriot**;
Gold / Gold-Yearly / Gold-Additional / Trigger Pro Plus / Master / Happy
Family → **guardian**.

## 2. Expected Memberistic state after a clean import

| Metric | Expected |
|---|---|
| Total source rows | 335 |
| → Primary memberships | **293** |
| → Linked people (Additional Member rows) | **42** |
| Plan: defender | 227 |
| Plan: patriot | 47 |
| Plan: guardian | 19 |
| Status: active (at import) | 286 |
| Status: expired (at import) | **7** |
| Rows with live Stripe subscription | **140** |
| Rows with NO subscription (no auto-charge) | **195** |

Cross-checks: every one of the 140 active subscription ids also appears in
the orders file (no orphan subs); 0 duplicate emails; 0 rows missing email.

## 3. Caveats — data that does NOT transfer 1:1 ⚠️

1. **Per-member custom/grandfathered prices are not preserved.** The account
   page shows the *plan's* standard price, not each member's historical
   `billing_amount` (e.g. 19.99 legacy rate). Stripe still charges the real
   subscription amount — this is display-only, but worth knowing.
2. **`cycle_period` column is ignored.** Billing cycle is inferred from the
   level name ("Yearly/Annual" → annual, else monthly). This is correct for
   every level in this file, but it's a derivation, not a copy.
3. **Linked members attach by a plan-capacity heuristic, not an exact
   parent reference** (the PMPro export has no parent-id column). The 42
   Additional-Member rows are linked to *a* primary on the same plan, which
   may not be their real family head. Spot-check family groupings.
4. **`stripe_customer_id` (`cus_…`) is in neither export.** Memberistic
   needs it for the new self-serve billing portal. It must be backfilled
   from Stripe (derivable from each `sub_…`) for the portal to open for
   imported members.

## 4. Findings that explain the live problems 🔴

1. **195 of 335 members have no Stripe subscription at all.** Nothing
   auto-charges them; on their renewal day the daily job expires them. These
   are cash/in-store/free/legacy members — they must be put on a Stripe
   subscription to auto-renew (self-serve via `/account/`, or staff link).
2. **67 of the 140 recurring members are already past their
   `next_payment_date` — 50 of them by 90+ days.** A recurring member 90+
   days overdue almost certainly has a **cancelled or failed Stripe
   subscription** that PMPro never updated. Importing them as "active
   recurring" overstates reality; verify these in the Stripe dashboard.
3. **Dashboard showed 56 expired vs 7 expected-at-import.** The extra ~49
   were expired *after* import by the daily job because Stripe renewals
   aren't being recorded in Memberistic — i.e. the **Stripe webhook is not
   delivering** `invoice.payment_succeeded` (so `renewal_date` never
   advances). Fixing the webhook (Settings step in the incident doc) stops
   the bleed; reactivating/migrating the subscriptions fixes the rest.
4. Orders include 17 `error` and 12 `refunded` rows that still carry a
   subscription id — confirm those members aren't being treated as active.

## 5. How to get a true 1:1 diff
Export the **current Memberistic** members to CSV (Members console →
export) and send it. I'll diff it row-by-row against this PMPro source and
list every member that differs (missing, wrong plan, wrong status, missing
subscription id, etc.).
