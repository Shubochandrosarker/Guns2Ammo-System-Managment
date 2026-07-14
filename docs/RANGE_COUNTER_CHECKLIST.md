# Front Counter — Daily Workflow Checklist

**⚠ Before this goes in front of staff:** this reflects how the system is *designed and documented* to work (`SYSTEM_WORKFLOW_v1.12.2.md`, `Guns 2 Ammo Business System.pdf` §1.8). It has not yet been walked through live on production. Confirm every button/status below actually matches what you see on screen before training anyone from this sheet — that live check is the last step of Phase 4 in the master plan.

---

## Opening shift

- [ ] Go to `guns2ammo.com/staff/` and log in with your staff account
- [ ] Confirm the dashboard loads: lanes in use, today's revenue, active members
- [ ] Open **Check-In Station** — confirm the QR code is displayed
- [ ] Glance at **Reservations** for today's bookings

## Every person at the counter — the 10 steps

1. **Search** the customer — by email, phone, name, or QR scan
2. **Confirm identity** — does the name/photo match the person in front of you?
3. **Check membership status** — active / expired / guest / walk-in
4. **Check waiver status:**
   - 🟢 **Green (current)** → continue
   - 🔴 **Red (expired)** or **⚪ Gray (missing)** → **STOP.** See "Never do" below — do not allow range access until a waiver is signed
5. **Confirm the booking**, or start a **walk-in** if there isn't one
6. **Assign a lane**
7. **Confirm payment status** — must show paid; see "Never do" below if it doesn't
8. **Complete check-in** (the Confirm/Check-in button)
9. **Send the receipt** — email or print
10. **Note anything unusual** in staff notes (late arrival, dispute, damaged gear, etc.)

## Two ways people arrive

**Walk-in, no reservation:**
Click orange **Walk-in Check-In** → enter name, email, phone, lane, party size, minutes (default 60) → **Check in**.

**Customer scans the dashboard QR on their phone:**
Wait for the confirm popup (2–3 seconds) → check the waiver banner color → compare their photo to the person in front of you → pick their lane → **Confirm check-in**.
If their waiver is expired/missing, click **Decline** instead — their phone will tell them to see the front desk; handle the waiver in person before trying again.

## Never do

- Never let anyone onto the range with an expired or missing waiver — regardless of membership tier or how well you know them
- Never manually mark a booking "paid" to move someone along faster — payment status only comes from Stripe/POS
- Never share another customer's email, phone number, or waiver document with anyone but that customer
- Never cancel a membership through anything other than the official Cancel Membership button
- Never delete a customer or contact record — flag it for review instead
- Never leave the staff console logged in and unattended, or share your login with another staff member

## Escalate to a manager when

- Payment shows unpaid/failed but the customer insists they already paid
- A waiver won't pull up, or you get a system error
- Any dispute, safety concern, or intoxicated/underage customer
- Anything on this list you're genuinely unsure how to handle

## End of day

- [ ] Review the roster — mark any no-shows
- [ ] Sign out of the staff console

---
*Companion to `SYSTEM_WORKFLOW_v1.12.2.md` (full technical reference) and `CLIENT_STATUS_AND_MASTER_PLAN_2026-07-15.md` (Phase 4).*
