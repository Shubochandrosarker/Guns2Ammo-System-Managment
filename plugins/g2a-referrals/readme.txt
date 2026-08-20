=== G2A Referrals ===
Contributors: wordpressistic
Tags: referrals, membership, rewards, guns2ammo
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Membership referral rewards for Guns 2 Ammo: the friend gets a free month, the referrer gets a Guest Pass.

== Description ==

A referred friend who buys any membership gets an extra month added to their
first term. The member who referred them earns a Guest Pass — one free lane
hour for a guest.

Rewards fire on the friend's confirmed membership payment, never on signup.
Every grant, redemption, expiry and reversal is a row in an append-only
ledger, and balances are always derived from it.

Features:

* Crockford base32 referral codes staff can read aloud at the counter
* First-touch attribution with a configurable cookie window
* Self-referral blocking on user, email, device and payment instrument
* Guest Pass redemption on lane bookings — opt-in per booking, and never
  consumed when the booking total is already $0
* Refund and cancellation reversal inside a configurable hold window
* Member dashboard "Rewards" tab, contributed to Memberistic by filter
* Admin overview with outstanding reward liability in dollars, plus a
  front-desk code lookup
* Hash-chained audit log with in-admin chain verification

== Changelog ==

= 1.0.0 =
* Initial release.
