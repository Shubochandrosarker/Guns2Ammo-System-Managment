# How Members & WordPress Users Work (for the client)

A short explainer answering: *"members don't generate WordPress
users — and can I delete a group?"* Both are addressed in
Memberistic 1.32.0.

## Two different things: a "Member" vs a "WordPress User"

- A **Member** in Memberistic = a membership record + a person
  record (name, email, phone, waiver status). This can exist
  **without** a WordPress login account.
- A **WordPress User** = an actual login account (so the person can
  sign in to `/account/`, download their digital card, sign their
  waiver online, and carry a check-in QR).

By default, adding a member through **Memberistic → Members** (or
importing) creates the *member record only* — **not** a WordPress
user. That's why you saw "members don't generate WordPress users."
It's by design for walk-in/POS records that never need a login.

## When a WordPress user IS created automatically

A login account is created (or reused if the email already exists)
in these flows — because these members genuinely need to log in,
sign a waiver, and carry a QR:

1. **Corporate group — Add member / Bulk add** (group → Members
   tab). Each person gets a WP account + a welcome email with a
   one-click **set-password** link, their **digital card + QR**, and
   a **waiver** link.
2. **Corporate group — "Create group from selected members"**
   (Members screen bulk action). **NEW in 1.32.0:** if a selected
   member has no WordPress user yet, one is now **created from their
   email automatically** and linked to them, then they're added to
   the group. (Previously these were skipped — which is why the
   bulk action "didn't pull them in.")
3. **Guest Pass** (`[memberistic_guest_pass]`). Non-members get an
   account + QR + waiver email.
4. **Online membership checkout** (Stripe). The buyer's account is
   created when payment completes.
5. **Range booking by a non-member** (NEW in 1.33.0). When someone
   who is not yet a member books a lane, they're saved automatically
   as a **Guest member** — login account + Digital Card with dynamic
   QR + waiver — so next time the desk can pull them up by QR scan and
   their details are remembered.
6. **Buying any product without a membership** (NEW in 1.33.0). Any
   WooCommerce purchase by someone with no membership level also
   creates a Guest member (account + QR card + waiver). Membership and
   group-invoice payments are excluded — those aren't product sales.

> These are **idempotent**: if the booker/buyer already has a
> membership (paid member *or* an existing guest), nothing is
> duplicated and no welcome/waiver email is re-sent. A repeat
> customer is recognised by their email every time.

> A member must have a valid **email** for an account to be created.
> If a selected member has no email, the bulk action safely skips
> them and tells you (so you can add their email and retry).

## So: should every member have a WordPress user?

- **Range members / group members / online buyers** → **Yes** —
  they need to log in, sign waivers, and check in. The system now
  provisions these automatically.
- **Quick POS / walk-in records with no email** → optional. They
  stay as member records without a login until you give them an
  email and add them to a group (or a guest pass), at which point an
  account is created.

## Deleting a group (new in 1.32.0)

You can now delete a corporate group two ways:

- **Corporate Groups list** → hover the group name → **Delete**.
- **Inside a group** → **Settings** tab → **Danger zone** →
  **Delete this group**.

**What deleting does:**
- Removes the **group container**, its group-level **payment
  records**, and its **payment links**.

**What deleting does NOT do (safe):**
- It does **not** delete the members' **WordPress accounts**, their
  **individual memberships**, their **waivers**, or their **QR
  codes**. Those people simply stop being part of that group and
  remain as standalone members.

So deleting a test group is safe — you won't lose any real member
accounts.

## Quick answers to the client's questions

> "the bulk group deal did not pull them into the group"
Fixed. The selected members had no WordPress user, so the old code
skipped them. Now it creates the account automatically and links
them in.

> "members don't generate WordPress users"
Correct for plain member records — but anyone added to a group,
given a Guest Pass, or who buys online **does** get a WP account
automatically now. Plain POS/walk-in records stay login-less until
they need an account.

> "can I delete a group that was created?"
Yes — list row **Delete** or Settings → **Danger zone**. Members
keep their own accounts; only the group + its payment records are
removed.
