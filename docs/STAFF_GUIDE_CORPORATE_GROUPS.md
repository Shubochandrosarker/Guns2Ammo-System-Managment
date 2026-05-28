# Corporate / Group Memberships — Staff How-To

A plain-English walkthrough for front-desk staff. Everything lives
under **WP Admin → Guns 2 Ammo → Corporate Groups** (and
**Corporate Reports**).

---

## The big idea

One organization pays **once**, but **every person gets their own
account, waiver, and check-in QR**. You manage the group; each
member manages their own range visits. A group can start at 10
people and grow to 60 later with no rebuild.

> Membership money is recorded in **Guns 2 Ammo's membership
> system** — it is kept separate from WooCommerce product sales, so
> your shop reports stay clean.

---

## 1. Sell a group in-store (the $600 cash example)

1. Go to **Guns 2 Ammo → Corporate Groups → Create Group**.
2. Fill in:
   - **Group / Display Name** — e.g. "ABC Company".
   - **Company Name** — optional, the legal/company name.
   - **Primary Payer** — the WP user who paid (pick from the list;
     if they're not a user yet, create them first under
     **Users → Add New**, or just leave it and set it later).
   - **Seats Purchased** — e.g. `10`.
   - **Max Future Seats** — e.g. `60` (the ceiling they can grow to).
   - **Custom Price** — type **any amount**, e.g. `600.00`.
   - **Group Status** — `Active` once it's live.
   - **Payment Status** — `Paid` if they already paid.
   - **Payment Method** — `Cash` / `POS` / `Card` etc.
   - **Public Visibility** — leave `Private` (or `Call for Details`
     if you want a "call us" CTA on the site).
3. Under **Record initial payment**, enter the **Amount Paid**
   (`600.00`) and the **POS Receipt / Reference** number.
4. Click **Create Group**.

The group now shows in the list with the paid amount and status.

---

## 2. Add the 10 members

Open the group → **Members** tab.

- **One at a time:** fill Name + Email (+ phone) → **Add + Send
  Welcome Email**.
- **All at once:** paste into **Bulk add**, one per line:
  ```
  John Doe, john@example.com, 6025551212
  Jane Roe, jane@example.com
  ```
  → **Import + Send Welcome Emails**.

**What each member automatically gets, by email:**
- Their own account + a one-click **set-password** link.
- Their **digital member card** + **check-in QR**.
- A **waiver** link to sign before they arrive.
- A "Book a lane" link.

The seats counter updates (e.g. **10 / 10**). You can't add past
the seat count — raise **Seats Purchased** in the **Settings** tab
first.

---

## 3. Send a custom payment link (any amount)

Open the group → **Payments** tab.

1. Under **Generate payment link**, enter **any amount**
   (it pre-fills to the outstanding balance, but you can change it
   to anything), a description, and optionally the customer's email
   + an expiry.
2. Click **Create Link**. Copy the link or let it email the customer.
3. The customer opens a **branded Guns 2 Ammo invoice page**, taps
   **Pay Now**, and pays securely via **Stripe**.
4. When they pay, it's **recorded on the group automatically** and
   the balance updates.

You can also **Record a payment** manually (cash / POS / card /
comp) on the same tab — use this for in-store payments.

> Requires Stripe enabled in **Guns 2 Ammo → Settings**. Until
> then, links show a "call to pay" page and you record payments
> manually.

---

## 4. Waivers

Open the group → **Waivers** tab.

- See who has signed (green) vs pending (amber) and the % complete.
- **Resend waiver** emails the member a fresh signing link.
- **Open signing link** lets you sign on a desk tablet on their
  behalf.
- **Mark complete (exception)** records a signed waiver manually
  (e.g. paper waiver on file) — use sparingly.

Members sign from any phone, no login needed. Waivers are valid for
one year.

---

## 5. Check people in at the range (QR)

When a member (or guest) arrives:

1. Open the camera / QR scanner on their **digital card QR**
   (it's in their account + their welcome email).
2. **If you're logged in as staff**, the QR page shows a
   **Staff Check-In panel**: their name, membership status, plan,
   group, **waiver status**, last check-in, and a **Check In Now**
   button.
3. Tap **Check In Now** → it's logged. If their waiver isn't on
   file, you'll see a ⚠ warning to collect one first.

> A regular (non-staff) person scanning the same QR only sees the
> clean status card — no private info. One QR, two views.

---

## 6. Non-members (Guest Pass)

For walk-ins / online buyers who aren't members, put the
`[memberistic_guest_pass]` shortcode on a page (e.g. `/guest-pass/`).
They enter name + email and instantly get:
- A range **Guest Pass** account + **check-in QR**.
- An **emailed waiver** to sign before arriving.

You then scan their QR to check them in, exactly like a member.

---

## 7. Let a group owner self-manage (optional)

If a company wants to manage their own people:

1. Open the group → **Settings** → turn ON
   **"Allow the group owner to manage this group from their
   account."** → Save.
2. The **Primary Payer**, when logged into **/account/**, now sees
   a group panel: seats used/available, payment status, the member
   list, an **Invite** form (within their seat limit), and a
   **Request more seats** button (which emails you).

Leave it OFF and the owner only sees their own membership — you
keep full control.

---

## 8. Reports

**Guns 2 Ammo → Corporate Reports** gives you, at a glance:
- Active groups, total members, group revenue, waivers pending.
- **Unpaid balances** (who still owes).
- **Groups with open seats** (upsell opportunities).
- **Waiver-pending members** (chase before their visit).
- **Recent check-ins**.

---

## Quick reference

| I want to… | Go to |
|---|---|
| Create a group + record the cash payment | Corporate Groups → Create Group |
| Add / bulk-import members | a group → Members |
| Send a custom payment link | a group → Payments |
| Check who hasn't signed a waiver | a group → Waivers, or Corporate Reports |
| Add more seats | a group → Settings → Seats Purchased |
| Let the company self-manage | a group → Settings → owner toggle |
| See who owes money / has open seats | Corporate Reports |
| Check a member in | scan their QR while logged in as staff |
| Give a non-member a pass | `[memberistic_guest_pass]` page |

*Questions about the system? Contact WordPressistic.*
