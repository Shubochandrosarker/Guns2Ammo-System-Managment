# Guns 2 Ammo — Roadmap (what's intentionally invisible + what's next)

## A. Built but invisible (waiting on client sign-off)

These features exist in the data model / templates but are NOT
surfaced publicly yet. Flip the switch when the client confirms
specs.

### 1. Training Room
- Status: Spec pending.
- Needs: label, hourly rate, capacity per block, included gear,
  any add-ons (snacks, A/V, instructor surcharge).
- Where it'll land: bookable resource in G2A Booking Engine + a
  "Training Room" card on `/training/`.

### 2. Members Lounge
- Status: Spec pending.
- Needs: label, pricing (free for members? hourly?), capacity,
  beverage / snack policy, hours.
- Where it'll land: amenity card on `/memberships/` + optional
  bookable resource if reservations needed.

### 3. Instructor Membership ($49.99/mo)
- Status: Plan slot reserved.
- Needs: full benefit list (e.g., $15/lane/hr discount, training
  room access, CME credits, member discount on instructor sessions).
- Where it'll land: 4th plan card on Memberships + Pricing pages,
  data row in `Memberistic → Plans`.

### 4. Per-tier guest-pricing auto-apply at checkout
- Status: Surfaced on pricing pages + member-dashboard.
- Still pending: booking-engine should auto-detect the logged-in
  member's tier and apply the matrix at checkout (Defender $15,
  Patriot/Guardian $10 per extra shooter / hr).
- Currently: staff applies the discount manually at check-in.

## B. Larger improvements ("next quarter")

### 1. Member-only events + early access
- Member-only event-booking flow (range events, member shoots,
  ladies-only nights, training intensives).
- Hooks into the existing G2A Booking Engine; just need an
  audience filter (`audience=members_only`).

### 2. Booking confirmation modal with "Book Another Lane" CTA
- Hook into the booking engine's successful-confirmation event
  (`showInlineNotice('Booking confirmed.', 'success')`).
- Trigger a confetti + recap modal with three CTAs: View Booking,
  Add to Calendar, Book Another Lane.
- File to edit: `g2a-booking-engine/assets/js/frontend.js`,
  emit a `CustomEvent('g2ab:booking-confirmed', {detail: {uuid}})`
  on the document. Theme listens and renders the modal.

### 3. Push notifications for renewal / lane-availability
- Membership renewal 30/7/1-day reminders already in
  Memberistic (cron). Add browser push subscription on the
  account dashboard.
- Optional: SMS via Twilio for renewal-failed events.

### 4. Range loyalty points
- 1 point per dollar spent on lane, ammo, training.
- Redemption: free lane time, training class credit, gear.
- Schema: `wp_g2a_loyalty_points` + transactions table.

### 5. Group / corporate bookings
- Multi-person reservation flow with a single payer.
- "Reserve a private lane block for your crew" copy on
  `/memberships/` + a `/groups/` landing page.

### 6. Range gun rental gallery with availability
- Inventory list pulled from G2A Theme Control rental repeater.
- Real-time availability indicator (uses booking engine occupancy).

### 7. Instructor profile pages + class-by-instructor browsing
- Custom post type `instructor` with bio, certifications, photo,
  class types they teach, schedule.
- Profiles link from `/training/` class cards.

### 8. Customer reviews + after-action sharing
- Post-booking email asks for a review at the venue's Google
  listing AND collects an on-site review for `/about/`.
- AggregateRating JSON-LD updates from on-site reviews.

### 9. Inventory feed → Google Merchant Center
- Auto-generated XML/CSV product feed.
- Tied into the existing WooCommerce shop.

### 10. Auto-detect / suggest the right CCW track
- Quiz: "Where do you carry?" → AZ-only / AZ+CA / multi-state
  → route to the right course page with prefilled enrollment.

## C. Documentation / process

### 1. Client onboarding video walkthrough
- 8-minute screencast covering: editing a page, adding a machine
  gun to inventory, exporting the newsletter, viewing bookings,
  member dashboard.

### 2. Staff how-to for the member verification QR
- One-pager that lives at the front desk: "scan member QR, see
  status + face, allow lane access." Photo + link to a 30-sec
  loom.

### 3. Annual SEO + audit checklist
- Quarterly: re-run broken-links audit, regenerate llms.txt,
  verify schema in Search Console.

## D. Tech debt / cleanup

- Move all hard-coded membership prices to a single source
  (Memberistic plan rows) so the pricing template doesn't hard-
  code numbers.
- Move the live-status pill hours from JS array → theme-mod / WP
  option so the client can change hours in Customizer.
- Replace remaining hardcoded brand strings with
  `memberistic_get_brand_label()` / `get_bloginfo('name')`.
- Booking engine should expose a `data-prefill="..."` API on its
  shortcode so the auto-fill JS doesn't need to guess input
  selectors.

## E. Known constraints / decisions on file

- AZ does NOT observe DST. All hour math uses
  `America/Phoenix`.
- WP user creation is deferred to Stripe webhook (security:
  avoid email-spray on checkout-start).
- Site is on `https://guns2ammo.com` (no www). www.* requests
  are 301'd at the PHP layer.
- All emails carry a kill-switch
  (`MEMBERISTIC_EMAIL_DISABLED`, `G2AB_EMAIL_DISABLED`,
  `WPISTIC_FORMISTIC_EMAIL_DISABLED`) so staging can mirror prod safely.
