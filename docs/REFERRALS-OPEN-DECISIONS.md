# Referrals — decisions still needed from Nicholas

Two items in the build brief were flagged rather than decided. Both are
built and shipping **off**, with the safe alternative running instead, so
nothing here blocks release — each is a single toggle in
**Referrals → Settings** once he says yes.

_Last updated: 20 August 2026._

---

## 1. "We'll put your lane fee toward the purchase"

**Where:** the Try At Range block on FFL product pages
(`g2a_sp_render_try_at_range()`, priority 34).

**Why it is not live:** this is a commercial promise, not marketing copy.
If a customer books a lane off the back of that sentence, the range owes
them the credit at the counter. That needs the owner's word before it is
printed next to a product.

**What ships instead:** the safe line, verbatim —

> Rent this exact model on our indoor range. **See how it shoots before you
> commit.**

**To turn it on:** Referrals → Settings → Product page → *Offer lane fee
toward the purchase*. The copy for both variants is editable in the same
screen (`try_at_range_body_safe` / `try_at_range_body_credit`), so the
wording can be his rather than ours.

**Open question for him:** if it is a real offer, what are its bounds — full
lane fee or one hour, same-day only or within N days, and does it stack with
a membership discount? The stacking engine enforces *best single offer wins*
with a configurable floor, so whatever he decides can be expressed, but it
has to be decided.

---

## 2. The 10% first-order banner offer

**Where:** the non-member variant of the top promotional banner.

**Why it is not live:** the stacking rule needs something real to enforce
against. A friend arriving through a referral could otherwise combine the
referral reward, this banner offer and a member discount on one membership.
`Stacking::resolve()` collapses that to the single largest offer and applies
a price floor — but it can only do that for an offer that actually exists in
checkout. Advertising a discount the checkout cannot honour is worse than
advertising none.

**What ships instead:** the non-member banner runs the membership pitch
without a discount claim (`banner_guest_body_plain`).

**To turn it on:** Referrals → Settings → Promotional banner → *Advertise
the first-order discount*, with the percentage alongside it.

**Open questions for him:**

1. Is the offer live today, and where is it redeemed — a WooCommerce coupon,
   a Memberistic discount code, or something the front desk applies by hand?
2. Does "first order" mean the membership purchase itself, or a retail order
   after joining? Worth noting that **zero members have ever placed a retail
   order** and there is one WooCommerce order all time, still `wc-pending` —
   so a retail-scoped offer would be pointing at a channel nobody uses.
3. Can it combine with the referral reward, or does best-single-offer apply?
   The engine currently assumes it does not combine.

---

## Already decided (built to these)

| Decision | Answer | Where it lives |
| --- | --- | --- |
| Do Guest Passes expire? | **Yes, 90 days.** Caps the liability carried on the books and gives the reward urgency. | `guest_pass_expiry_days`, `0` disables |
| Cap referrals per member per month? | **Yes, 5.** Bounds lane capacity as much as abuse — every pass is a free hour someone has to staff. | `referral_cap_per_month`, `0` disables |
| Can Guest Pass holders (plan 5) refer? | **Yes, but they earn a free month, not a pass** — they have no membership for a guest to be brought onto. | `non_member_plans_may_refer`, `non_member_plan_reward` |

A capped referral is **not** rejected: the conversion still records as
`qualified` and a `conversion_capped` audit event is written, so the front
desk can see a member hit their limit rather than wondering why a genuine
referral vanished.
