# Guns 2 Ammo — Client Status Review & Master Plan

**Prepared:** 2026-07-15
**Basis:** full repo audit (plugins, theme, `docs/`), `Guns 2 Ammo Business System.pdf`, a live-site check via the Bridgistic connector, and the client's July 14 message.
**Status of this document:** research and planning only. No code was changed, nothing was deployed, and no vendor (Otter Text, Otter Waiver, Stripe) was contacted or cancelled while producing this.

---

## Bottom line

This is not a "missing features" project. Nearly everything the client is asking about — Stripe cancellation propagation, a backend class calendar, Lipsey's dual-account import, waiver import/matching, an AI chatbot layer, page-content editing controls — already has working code in this repository. What's actually missing is **verification that production matches the repo, evidence that each fix works against real data, and handoff** (staff training, client editing access, vendor cutover timing).

The `Guns 2 Ammo Business System.pdf` already reached this conclusion and laid out an 8-phase stabilization plan. The client's July 14 message confirms — issue for issue — that the same gaps are still live in production. This document adds three things the PDF didn't have: (1) findings I verified myself today, (2) every line of the client's message mapped to a root cause and a phase, and (3) the range counter checklist the client explicitly asked for.

---

## 1. What I verified directly today (new since the PDF)

- **Repo source code is on the July 13 "unified" versions.** Confirmed by reading the plugin headers myself, not just the docs: Advanced FFL Checkout 1.15.0, Booking Engine 1.9.9.12, POS Core 3.2.0, Memberistic 1.12.0, Messageistic 0.7.0, Formistic 2.1.0, theme (guns2ammo) 1.27.13.
- **`INSTALL.md` is stale.** It still documents Booking 1.9.9.11, Memberistic 1.10.7, POS 3.1.9, and FFL Checkout 1.9.4 — one release behind on 4 of 6 plugins. Anyone installing from `INSTALL.md` today would ship the wrong build. This is exactly the "documentation drift" the PDF warned about, and it is still unfixed as of today.
- **Bridgistic (our remote WordPress bridge) cannot currently get a clean response from guns2ammo.com.** Every read-only call I made (site info, plugin list) came back as HTTP 202 with a non-JSON body — a strong sign a security plugin or cache is intercepting the request. Practically: **I cannot remotely confirm what's actually running in production right now.** Unblocking this (or getting direct wp-admin/SFTP access) is the precondition for Phase 0 below — it's the same "verify what production is really running" step the PDF already flagged as necessary before any more feature work.
- **I could not identify which system is the "chatbot" the client says replaced Otter Text.** It is not Verifyistic (that only replaced the age-gate popup) and it does not appear to be the internal staff-facing AI agents in `g2a-business-api` (those are dashboard/back-office tools, not a visitor-facing widget). This needs to be confirmed before the Otter Text acceptance checklist below can actually be run.

---

## 2. Client's July 14 message, mapped item by item

| # | Client said | Root cause on file | Where it actually stands | Phase |
|---|---|---|---|---|
| 1 | Cancelling a membership on the page doesn't cancel the Stripe subscription | Memberistic marks the membership cancelled *before* confirming Stripe succeeded. If Stripe fails, the local record already reads "cancelled" while billing keeps running. | Stripe cancel-propagation code exists (added across 1.10.1–1.12.0) but the operation order is unsafe for a financial action. | **Phase 1 — top priority** |
| 2 | Address on booking/membership/waiver/chatbot emails is wrong | The booking plugin had its own incomplete address (`"Mesa, Arizona"`), separate from the theme's central business-info settings. | Fallback helpers were added so emails, invoices, and AI replies read the shared setting — **not yet verified with a real delivered email on production.** | Phase 1 |
| 3 | No calendar for classes on the backend | A full FullCalendar admin interface already exists — day/week/month views, drag-to-reschedule, resource/status filters — gated behind the `manage_g2ab_bookings` capability. | Most likely cause: production is running an older Booking Engine build, or the logged-in staff role is missing that capability. Needs a live check, not new code. | Phase 1 |
| 4, 5 | Page corrections still not done; control over the site hasn't been handed off; small per-page issues remain | Not a code gap. The theme + G2A Theme Control already expose page copy, images, and CTAs through normal WordPress editing screens. | What's actually missing is a **handoff process**: a page-by-page correction register, a restricted content-editor role (not full admin), and a recorded walkthrough. None of that exists yet. | Phase 7 |
| 6 | Lipsey's API attempted, same result; product categories didn't carry over | Confirmed defect: the code that resolves a Lipsey's account by provider code always returns the lowest-ID row, so the accessories account can be unreachable through that path. Blank account numbers can also let one account's credentials silently overwrite the other's. No category-mapping table exists yet. | This is a real, previously-identified bug — not something you did wrong. | Phase 5 |
| 7, 15, 17 | Otter Text + Otter Waiver still in use at the shop; new chatbot liked, want Otter Text cancelled ASAP but need 100% confirmation first; want to start closing down Otter Waiver | Otter Text is a thin embed — clean removal (deactivate the plugin, revoke its webhook/key) once the replacement passes an 8-point acceptance check. Otter Waiver already has a working importer (a prior run validated 1,922 CSV rows → 1,793 unique people, with PDF mirroring). | Need to (a) confirm what the replacement chatbot actually is and run the acceptance checklist against it, (b) confirm the waiver importer has been run against the **current, full** export on production — not just validated once earlier — before Otter Waiver is cancelled. | Phase 3 |
| 8 | Logins are better, but older customers still struggle | Root cause (Cloudflare edge-caching `/login/` and `/account/`, so a real login can redirect into a cached logged-out page) was already hardened in code. | Remaining work is UX, not bugs: bigger touch targets, a prominent Forgot Password flow, and likely a passwordless "email me a login link" option for this age group. | Phase 1 (cache verify) + Phase 7 (UX) |
| 9, 11 | Counter/walkthrough process still not detailed; not confident enough to teach employees the range check-in | The underlying check-in flow (QR self-check-in, waiver status banner, walk-in modal, lane assignment) is built and documented in full technical detail. | What was missing was the one-page floor version. **Delivered today** — see `RANGE_COUNTER_CHECKLIST.md`. Still needs a live walkthrough before staff rely on it (see Phase 4). | Phase 4 |
| 10 | Customer contacts are going to Formistic, working well | Formistic is the intended contact/inbox/newsletter system. | Confirmed working as designed. No action needed beyond normal monitoring. | — |
| 12, 20 | Merchant-service decision still open; want Stripe gone, replaced with something manageable in-house | Booking Engine already supports Fortis, PayPal, Authorize.Net, and pay-in-store. Memberistic's recurring billing is still Stripe-specific. | Agree with not ripping Stripe out first. Needs a gateway-abstraction layer built, then a controlled, gradual migration. | Phase 6 (needs your processor decision) |
| 13 | It's a live site, no chance to test first, solving problems as they're found | Confirmed: deploys are manual zip uploads straight to production; no staging clone exists today. | This is exactly why Phase 0 has to happen before more hotfixes land. | Phase 0 |
| 14, 22 | SEO performing well, but summer sales are down; lots of range use, little retail; want an email/SMS/social push | Not a code issue — a marketing-sequencing question. | Matches the existing read: strong range traffic with weak retail is a conversion opportunity (turn existing visitors into buyers/members), not a traffic problem. | Phase 8 |
| 18 | Need a cleanup of contacts already in the system and a check for duplicates | No database-wide reconciliation tool exists yet. Formistic's own dedupe only stops double-click submissions, not duplicate people across the whole system. | New build required: a Customer Identity Reconciliation Tool that suggests merges but never auto-merges. | Phase 2 |
| 19 | Some members have the wrong people attached to their memberships | Memberistic's flexible linking (individual / WP user / linked profile / corporate group) has never had an audit pass run against the live data. | New build required: membership audit rules + a staff review screen (detach / merge / reassign, with a full audit trail). | Phase 2 |
| 21 | Need waivers pulled from Otter Waiver to cross-check and show as "signed" on the right account | The importer already does exactly this — matching by email sets `waiver_status = signed` on the person record. | Needs confirmation this actually ran against the live/current export, plus the unmatched-waiver report so nothing falls through unnoticed. | Phase 3 |

Every line of your message is accounted for above.

---

## 3. Master plan

The phase structure below is carried over from the existing Business System analysis (it's still the right shape) with two changes: each phase now names the client's specific July-14 items it resolves, and I've flagged the two items the client explicitly wants moved up.

**Moved up at the client's request:**
- Range counter checklist — delivered today (originally a Phase 4 output).
- Otter Text acceptance check — doesn't need to wait on Phase 0/1 since it's a self-contained verification; can run in parallel once the chatbot system is identified.

### Phase 0 — Establish one source of deployment truth *(blocked — needs your input, see §5)*
Back up production files + database, stand up a staging clone, record every live plugin/theme version, correct `INSTALL.md`, stop untracked direct edits on production. Nothing else here can be trusted as "done" until this runs, because right now nobody — including me — can confirm what production is actually executing.

### Phase 1 — Revenue and trust hotfixes
In order: membership cancellation with confirmed remote sync (item 1), correct business info in every email with proof of delivery (item 2), class/calendar visibility (item 3), login/cache verification (item 8), contact-form and chatbot delivery checks. Every item needs an acceptance test and evidence — not just "should be fixed now."

### Phase 2 — Reconcile business data
Build and run (don't delete on the first pass, just flag): a customer-duplicate report, a membership-duplicate report (item 19), a linked-member exception report, a waiver-to-customer match report, a contact/subscriber cleanup report (item 18).

### Phase 3 — Complete the Otter migrations
Confirm the replacement chatbot's identity and run its 8-point acceptance check before cancelling Otter Text (items 7, 15). Re-run/confirm the Otter Waiver export + PDF mirror + member-matching against the current live data, produce the unmatched-waiver report, and only then cancel Otter Waiver (items 7, 17, 21).

### Phase 4 — Launch the range counter system *(in progress — checklist delivered today)*
Configure staff roles, confirm scanner/QR operation live, test member/guest/walk-in/expired-waiver/unpaid scenarios, walk the one-page checklist with real staff and a manager shift simulation (items 9, 11). The checklist itself is attached; the live walkthrough is still outstanding.

### Phase 5 — Stabilize Lipsey's and retail
Split into two clearly identified wholesaler connections (Firearms / Accessories) with distinct account numbers, build the category-mapping table, validate images, import to a staging catalog before touching WooCommerce (item 6).

### Phase 6 — Introduce the new merchant service safely
Build the Memberistic payment-gateway abstraction, add the new processor as an adapter, run new signups through it while existing Stripe subscriptions keep running untouched, migrate or reauthorize existing members only once that's proven, retire Stripe only when its active-subscription count hits zero (items 12, 20). **Needs your decision on which processor.**

### Phase 7 — Website corrections and client handoff
Inventory every public page, log each requested correction with an approved/completed/accepted column, create a restricted content-manager role (not full admin), record a short editing walkthrough (items 4, 5), fold in the older-customer login UX polish (item 8).

### Phase 8 — Email, SMS, and social growth
Segment the audience (active/expired members, range visitors, class attendees, retail customers, FFL customers, newsletter subscribers, non-purchasing leads) and build campaigns around the actual insight from item 14: strong range traffic, weak retail — the push should convert existing visitors into product/training/membership/repeat purchases, not just drive more top-of-funnel traffic during a seasonally slow month (items 14, 22).

---

## 4. Definition of "operational"

Carried over from the Business System analysis — this is still the right bar. The system should only be called operational once all of these are true:

- A membership cancellation stops remote billing, and failed cancellations are visible and actionable.
- Every customer-facing email shows correct business information.
- Staff can see and manage classes from the backend calendar.
- A customer can register, log in, reset a password, and reach their account — including older, less tech-comfortable customers.
- A walk-in can complete the entire range check-in process from a written checklist.
- Waiver status is accurate, traceable, and Otter Waiver's data has been fully imported and backed up before cancellation.
- Contacts and memberships have been checked for duplicates and incorrect linked people.
- Lipsey's firearms and accessories feeds operate independently, with correct categories and images.
- The client can edit approved website content without needing a developer.
- Payment, booking, membership, email, SMS, and check-in actions all leave a log, and every core process has a rollback or manual fallback.

---

## 5. What I need from you before Phase 0 can start

- **Live-site access.** Either get Bridgistic reading guns2ammo.com cleanly again (check whether a security plugin or WAF rule is blocking REST requests from it — same family of issue as the login-cache problem already on file) or give me direct wp-admin/SFTP access. Nothing in Phase 0/1 can be verified without this.
- **Confirm the chatbot's identity** — is it Chatbotistic, or something else wired in directly? I need this to build the Otter Text acceptance checklist correctly.
- **Stripe Dashboard access** (or a screen-share) for a ~10-minute webhook configuration check — this is often the actual reason payments/cancellations look "stuck."
- **Merchant-processor decision** for Phase 6 — which provider are you leaning toward (Fortis was already scoped as a gateway option in the codebase)?
- **Sign-off on vendor timing** — Otter Text and Otter Waiver are your accounts; I'll tell you when each acceptance check has passed, but you make the actual cancellation call.

---

## 6. Delivered today

- This document.
- `RANGE_COUNTER_CHECKLIST.md` — the one-page front-counter workflow, ready to review before it goes in front of staff.

No code changes, deployments, or vendor cancellations have been made. Phase 0 (production verification) is the recommended next step once live-site access is sorted out.
