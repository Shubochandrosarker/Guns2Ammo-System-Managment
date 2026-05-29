# Members diff: PMPro source vs live Memberistic export (May 29, 2026)

Compared the live Memberistic members export (311 rows) against the PMPro
source export (335 rows / 293 primary members + 42 "Additional Member"
linked rows). Matched on full name, falling back to email.

## Headline

| Check | Result |
|---|---|
| PMPro primary members | 293 |
| Matched in live Memberistic | 286 |
| **Missing from live (not imported)** | **7** |
| Plan mismatches | 1 (see note) |
| PMPro recurring members (Stripe `sub_…`) matched live | 139 / 140 |
| Live status | 254 active · 55 expired · 1 cancelled · 1 trial |

## 1. Missing members — 7 PMPro members not in the live export ❌
These should be re-imported (or confirmed intentionally dropped). One is a
**recurring (paying) member** — highest priority.

| Name | Email | PMPro level | Recurring? |
|---|---|---|---|
| Norman Townsel | mrtownsel1225@gmail.com | Bronze | **YES — has Stripe sub** |
| James DeMaria | jdemaria7140@yahoo.com | Bronze Yearly | no |
| James Weaver | jamesweaver57701@yahoo.com | Bronze Yearly | no |
| Anthony Pagano | anthony.v.pagano@gmail.com | Bronze Yearly | no |
| Elton Holtsoi | seanmailphx@yahoo.com | Bronze Yearly | no |
| Terry & Rachel Cussins | cussinspartyof5@gmail.com | Silver Yearly | no |
| Kash Kerstetter | cpkn3dkar@gmail.com | Bronze | no |

> **Norman Townsel** has a live Stripe subscription but no Memberistic
> record — Stripe is (or was) charging him with nothing tracking it. Import
> him and run the customer-ID backfill.

## 2. Plan mismatch — 1
- **Shad Schafer**: PMPro level `Silver` → expected **Patriot**. Live has a
  Defender record *and* a Patriot record under this name (two memberships).
  The Patriot one matches PMPro; the Defender one is a separate/newer signup.
  Verify he isn't double-counted.

## 3. Active members with NO renewal date — 35 ⚠️
35 active members have a blank `Renewal`, e.g. Michael Subiran, Philip Lee,
George Hernandez, cory struth, Mikel Guerrero, Kai Rollins, Kevin Soto, Julio
Melendez, Joseph Olding, Cody Flint, James Propeck, Richard Salmon, Owen
Bell, Larry Jefferson, Kevin Keilholtz, Shubo Sarker, and the block of
annual signups (Aaron Kuck, Troy Sessions, Amador Ortega, Ryan Scott, Cory
Williams, Chandler/Conner Monks, Clay Wagner, …).
→ **Fixed by the new "Set renewal dates from activation" tool** (Memberistic
→ Import): computes each one from their activation date + billing cycle.

## 4. Overdue actives (renewal already passed) — 5
Darrin Hilligoss, Mark Granato, Philip Mineo, Adrian Gantar,
marcus.italo12@gmail.com — renewal date 2026-05-28 (just passed). With a
working Stripe webhook these renew automatically; otherwise they'll flip to
past-due/expired. Confirm each has an active Stripe subscription.

## 5. Data anomalies
- **Nicholas Steigert** — renewal date **2053-06-20** (clearly wrong; likely
  a lifetime/again-misparsed value). Correct manually.
- 50 of the 55 expired members lapsed in 2024–2025 (genuinely churned long
  ago; not a migration fault).

## Recommended actions
1. Re-import the 7 missing members (Members → Import), then run **Backfill
   Stripe customer IDs** so Norman Townsel's subscription is tracked.
2. Run **Set renewal dates from activation** to fix the 35 blank renewals.
3. Verify the 5 overdue actives have live Stripe subscriptions; fix
   Nicholas Steigert's 2053 date.
4. Confirm Shad Schafer isn't a duplicate.
