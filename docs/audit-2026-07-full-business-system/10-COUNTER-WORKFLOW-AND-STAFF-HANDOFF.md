# Counter Workflow and Staff Handoff

## One-page counter workflow

The client already has this: `docs/RANGE_COUNTER_CHECKLIST.md` (delivered per the prior status document, present in this repo as `docs/RANGE_~1.MD` / referenced as `RANGE_COUNTER_CHECKLIST.md`). This audit's job was to verify the underlying mechanics that checklist assumes actually work — not to rewrite it. Findings below are about **what's true and what still needs a live check**, mapped to that checklist's own steps.

| Checklist step | Underlying mechanism | This audit's verification |
|---|---|---|
| Search customer by email/phone/name/QR | Booking Engine + Memberistic lookups | Not independently re-traced this pass beyond the waiver/check-in paths already covered |
| Waiver status (Green/Red/Gray) | `Waivers_Archive::has_on_file()` via `g2ab_waiver_satisfied` filter | ✅ **Verified robust** — re-derives from the archive directly, not the potentially-stale `People_Repository.waiver_status` (see `09-WAIVER-CONTACT-MEMBERSHIP-MIGRATION.md`) |
| Confirm booking / start walk-in | `class-range-controller.php` walk-in creation, `class-bookings-controller.php` | Verified the walk-in insert path sets `waiver_signed` from the same on-file check (line 308: `'waiver_signed' => $waiver ? 1 : 0`) |
| Payment status must show paid | Booking/POS payment records | Not independently re-traced this pass; the checklist's own "Never manually mark a booking paid" rule is a strong, correct operational control regardless |
| Complete check-in | `G2AB_Checkin_Service` (referenced in `class-range-controller.php:365`) | Not read in full this pass |
| QR self-check-in | `class-range-controller.php` — "this endpoint IS the actual self-serve QR kiosk" per its own code comment | ✅ Confirmed this is the live QR path, distinct from staff-initiated check-in |

**The checklist's own header is correct and should not be softened:** it explicitly says it reflects how the system is *designed* to work and has not been walked through live on production. This audit adds source-level confidence to several of its assumptions (especially the waiver gate) but cannot substitute for that live walkthrough — nothing in a static audit can confirm what actually renders on a physical counter screen.

## Exception handling — cross-referenced to defects found

- **"Payment shows unpaid/failed but the customer insists they already paid"** → escalate to manager, per the checklist. This audit did not find a reconciliation view that would let a manager quickly answer this (see `12-PERFORMANCE-RELIABILITY-OBSERVABILITY.md` observability gaps) — today this likely means checking the Stripe/POS dashboard directly, a context-switch the checklist itself doesn't (and given a manager's job is exception handling, understandably can't) eliminate.
- **"A waiver won't pull up, or you get a system error"** → escalate. If the underlying cause is ever the `G2A-HIGH-001` stamping gap, the **check-in gate itself is not affected** (verified above) — a "waiver won't pull up" error is more likely a genuine system error than this specific known gap, which manifests as an account-page display issue, not a check-in failure.
- **Membership mismatch / duplicate customer** → directly connects to `G2A-CRIT-004` (the no-unique-email defect). Until that's fixed and a reconciliation pass has run, staff should expect to occasionally encounter this and the checklist's manager-escalation rule is the correct interim control.

## Where staff must context-switch today

Based on the components inventoried in `01-SYSTEM-INVENTORY.md`, a counter transaction can plausibly require: the booking front desk screen (check-in), Memberistic (membership/waiver status), POS (payment collection, product lookup), and wp-admin directly (for anything not surfaced in the above) — potentially 4 different screens for one customer interaction. `dashboard-app` (the React SPA) is architecturally positioned to become the single counter workspace the audit brief asks for, but this pass did not verify how much of the counter workflow it currently actually covers vs. how much still requires wp-admin. **Recommend this be the first thing checked in a live walkthrough**: have one staff member attempt a full walk-in check-in using ONLY dashboard-app, and note every point they had to leave it.

## Required staff roles (as currently coded)

- `g2ab_staff` — `read`, `manage_g2ab_bookings`, `view_g2ab_reports` (Booking Engine, `class-activator.php:120-122`)
- `g2ab_instructor` — `read`, `manage_g2ab_bookings` (`:124-127`)
- `g2a_cashier` (POS Core, referenced in prior verification) — baseline POS access
- WordPress `administrator` — gets every custom capability via the same activation-only provisioning affected by `G2A-CRIT-005`

**Gap:** no role appears purpose-built for "front counter staff who need booking check-in + waiver visibility + payment collection but NOT settings/reports/resources management" — the closest, `g2ab_staff`, already includes `view_g2ab_reports`, which may be more access than a counter role needs (not a security problem given reports are read-only, but worth a deliberate least-privilege review as part of the training-mode/role redesign below).

## Manager escalation

The checklist's escalation triggers (payment dispute, waiver system error, safety/intoxication/age concern, general uncertainty) are sound and match this audit's own findings about where the system's actual failure modes live (payment/membership state drift, waiver display staleness). No changes recommended to the escalation *list* — recommend adding one more trigger once `G2A-CRIT-001` is fixed: **"a membership shows `cancel_failed`/`requires_review`"** should be an explicit manager-escalation trigger once that status exists.

## Training-mode recommendation

No sandboxed training environment exists today (`05-MISSING-AND-INCOMPLETE-FEATURES.md`). Recommended shape:
- A `training_mode` flag (site-wide toggle, admin-only) that, when active, routes all payment gateway calls to a no-op stub, all email/SMS sends to a log-only sink, and seeds a small set of clearly-labeled fake customers/bookings/waiver statuses (e.g., name-prefixed `[TRAINING]`) covering every scenario in the checklist's "Two ways people arrive" and "Never do" sections.
- Employee completion tracking: a simple per-staff-account "completed training scenarios" checklist, visible to managers, satisfying the client's "I don't know enough to teach the employees" concern with an auditable trail rather than a verbal confirmation.
- This is meaningfully larger than the checklist itself — treat as a Phase 1/2 backlog item (`15-IMPLEMENTATION-BACKLOG.md`), not a blocker to running the checklist's own live walkthrough first.

## Employee acceptance test

Before relying on the checklist for real training:
1. Manager + one staff member run a full shift simulation covering: existing member, guest, walk-in, class attendee, expired waiver, unpaid booking, cash payment, card payment, no available lane, duplicate customer, manager override — against the **live** system, checklist in hand.
2. Every deviation between the checklist and actual on-screen behavior is logged and either the checklist or the code is corrected (not both left to silently disagree).
3. Only after a clean run: train the remaining staff from the corrected checklist.

This is exactly what the checklist's own header already asks for — this audit confirms it's still the correct next step and adds no additional blocking precondition beyond what the client has already been told.
