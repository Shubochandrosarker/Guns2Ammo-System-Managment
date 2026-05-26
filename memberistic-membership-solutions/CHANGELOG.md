# Changelog

All notable changes are tracked here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## 1.10.0 — Operations dashboard release

A broad admin operations upgrade: pagination, KPI cards across every list page, a card-based Plans console, a permissive importer that never drops a row, and a React-rebuilt Email Directory.

### Added

- **Members page — KPI cards & MoM growth.** Eight cards: Total Members, Active, Pending, Past Due, Expired, Cancelled, New This Month (with month-over-month % growth indicator), and Waiver Missing. Powered by a new `GET /memberistic/v1/memberships/stats` endpoint.
- **Members page — server-side pagination.** `GET /memberistic/v1/memberships` accepts `limit` + `offset` and returns `X-WP-Total` / `X-WP-TotalPages` headers. New `MemberShips_Repository::count_all()` powers the totals. UI gains «/Prev/Next/» controls plus a per-page selector (25/50/100/200).
- **Members page — bulk waiver action.** New `Change waiver status…` bulk action with a waiver-status sub-dropdown. Posts to a new `POST /memberistic/v1/memberships/bulk-waiver` endpoint that updates every person on each selected membership and writes a `waiver_signed` / `waiver_expired` activity row per membership.
- **Plans page — card UI.** Replaces the table with an animated card grid. Each card surfaces price (monthly + annual), capacity, the top 4 benefits, status, "Featured" ribbon, and a footer with three live member-count tiles: Total / Active / Other. Powered by a new `GET /memberistic/v1/plans/stats` endpoint.
- **Payments page — KPI cards.** Six cards: Lifetime revenue, Revenue this month (with MoM growth %), New-member payments (first completed payment per membership), Renewal payments (second-or-later), Failed payments, Visible on page. Powered by a new `GET /memberistic/v1/payments/stats` endpoint and `Payments_Repository::stats_summary()` + `count_all()`.
- **Payments page — pagination.** Same headers + UI pattern as the Members page. Per-page picker, prev/next/first/last, with full filter context preserved.
- **Payments page — richer CSV.** Export now includes Payment ID and Created columns.
- **Emails page — React directory.** Brand-new `assets/admin-emails.js` console with KPI cards (Sent today, Sent this week, Sent this month, Delivery rate, People with email), filters by member status and waiver status, paginated list with status pills and "Open member" links. CSV export gathers the full filtered set (chunked at 1000/req) and lays it out in 13 properly labelled columns including waiver signed/expires dates.
- **Import — "No Plan" sentinel.** Auto-created hidden plan that catches imported members with no recognised tier so historic rows are never dropped.
- **Import — Instore stub creation for orphan payments.** Orders whose email does not match an existing member create a stub Instore member; emailless orders attach to a single shared "Instore Walk-in" membership. Disposition is logged per row in the dry-run preview.

### Changed

- **Import — members analyzer / committer.** Now imports every row including those with no email (kept as primary contact via full name), unrecognised levels (attached to No Plan), and expired memberships (status auto-set to `expired`). Dry-run preview adds counters for expired, no-plan, and no-email rows.
- **Import — payments analyzer / committer.** Replaces the silent skip path with the orphan-membership flow above. Status mapping now also recognises `failed`/`error`/`declined`. Disposition column added to sample table.
- **Email Directory PHP page.** Reduced to a React mount point; the legacy server-rendered `?memberistic_export=emails` URL still works (now exports 13 columns instead of 6) for bookmarks.

### REST API additions (v1)

- `GET  /memberships/stats`
- `POST /memberships/bulk-waiver`
- `GET  /plans/stats`
- `GET  /payments/stats`
- `GET  /emails/directory` — paginated email directory with search + filters; sets `X-WP-Total` / `X-WP-TotalPages`.
- `GET  /emails/stats` — sent today/week/month, delivery rate, contact coverage.
- `GET  /memberships` and `GET  /payments` — now accept `offset` and emit `X-WP-Total` / `X-WP-TotalPages`.

### Notes

- No DB schema changes. DB version stays at `1.2.0`.
- Version bumped to `1.10.0`.

## 1.9.0 — Admin-side waiver management

### Added

- **Per-person Edit action on the Members detail panel.** The People tab in the slide-in detail panel now exposes an inline editor for every linked person on a membership. Admins can update full name, email, phone, relationship, **waiver status** (`missing` / `signed` / `expired` / `needs_review` / `rejected`), **waiver signed date**, **waiver expiry date**, and the person's own active/inactive/removed status without leaving the page.
- **Remove action** for non-primary linked members directly from the People row (the primary member is still protected server-side).
- **Waiver expiry hint** in the People table — the waiver pill now shows the expiry date underneath when one is set, so admins can see at a glance which signed waivers are aging out.

### Changed

- `PUT /memberistic/v1/people/{id}` now declares a typed argument schema covering `full_name`, `email`, `phone`, `date_of_birth`, `relationship`, `waiver_status` (enum), `waiver_signed_at`, `waiver_expires_at`, `status`, and `notes`. Previously the route accepted those fields but did not validate them at the REST layer.
- When `waiver_status` is set to `signed` and no `waiver_signed_at` is provided (and none was previously recorded), the API auto-stamps `waiver_signed_at` to the current time.
- Whenever `waiver_status` changes via `PUT /people/{id}`, an activity row is written: `waiver_signed` when the new status is `signed`, `waiver_expired` for `expired`/`rejected`/`needs_review`, and `membership_status_changed` otherwise. This makes the change visible on the Activity tab and Activity admin page.

### Notes

- UI-only update. Database schema unchanged (`memberistic_people` already had `waiver_status`, `waiver_signed_at`, `waiver_expires_at`).
- Version bumped to `1.9.0`. DB schema version stays at `1.2.0`.

## 1.8.0 — Member import

### Added

- **Import page** (`Memberistic → Import`) — upload a Paid Memberships Pro (PMPro) members CSV, or a payment/orders export, and bring the data into Memberistic. Columns are auto-detected through an alias map; legacy PMPro levels are mapped to Defender / Patriot / Guardian plans; "Additional Member" levels import as linked people attached to a primary membership with open capacity.
- Two-step flow with a dry-run preview (row counts, plan breakdown, linked-member and duplicate detection, sample rows) before anything is committed.
- Imported members are linked to existing WordPress user accounts by email when one is found.

### Changed

- Version bumped to `1.8.0`. DB schema unchanged.

## 1.7.1 — Partnership documentation release

Documentation-only release. Recognises the WordPressistic × [Guns 2 Ammo](https://guns2ammo.com) joint venture across all plugin documentation and the plugin header. No functional code changes — safe upgrade from 1.7.0.

### Added

- **[`docs/PARTNERS.md`](docs/PARTNERS.md)** — dedicated partners page describing the WordPressistic × Guns 2 Ammo joint venture, what each company contributes, and the businesses Memberistic is designed for.
- **README.md** — new "Built with our launch partner" section near the top, expanded credits & partners section at the bottom, and clearer framing throughout that Memberistic is co-developed with and battle-tested at [Guns2Ammo](https://guns2ammo.com).
- **Plugin header** — `Description` and `Author` fields updated to acknowledge the partnership. `Plugin URI` and `Author URI` continue to point to WordPressistic.
- **`readme.txt`** — description rewritten to lead with the partnership and link to [guns2ammo.com](https://guns2ammo.com); FAQ adds a "Who built this plugin?" entry.

### Changed

- Version bumped to `1.7.1`. DB schema unchanged at `1.2.0`.

## 1.7.0 — Audit + spec-completion pass

Full audit against the canonical Memberistic feature spec. Fixes 20 bugs and gaps and adds the remaining spec-required features so the engine is feature-complete against `CORE_MEMBERSHIP_PLAN_FEATURES.txt`.

### Added

- **Default G2A plans seed** — Defender, Patriot, Guardian are now created automatically on first install with the canonical pricing and included-people limits.
- **Email automation overhaul**
  - Six new transactional templates: `membership_renewed`, `expiring_30_days`, `expiring_7_days`, `expiring_tomorrow`, `membership_expired`, `linked_member_added`, `staff_manual`. The required-template set is now 12 of 12.
  - Full merge-tag rendering with all 20 documented tags (`{member_name}` … `{logo_url}`) plus a `memberistic_email_merge_tags` filter.
  - `wp_memberistic_email_logs` table records every send with template, recipient, subject, status, and any failure message.
- **Daily cron jobs** via the new `Scheduler` class:
  - Renewal reminders at 30 / 7 / 1 day windows.
  - Auto-expire of active memberships past their renewal date.
  - Waiver follow-up nudges for active memberships still missing a signed waiver.
- **REST API completions**
  - `PUT /people/{id}` and `DELETE /people/{id}` (the primary member is protected).
  - `POST /webhooks/woocommerce` with HMAC SHA-256 signature validation.
  - `GET /memberships/{id}/bookings` now returns `{ bookings, checkins }` instead of just check-ins.
  - `GET /memberships` accepts 9 filter args: `billing_cycle`, `waiver_status`, `expiring_in_days`, `checked_in_today`, `created_from`, `created_to`, `limit` (in addition to the existing `search`, `status`, `plan_id`).
- **WooCommerce bridge**
  - `WooCommerce_Bridge::ensure_default_products()` creates the six hidden virtual products (Defender / Patriot / Guardian × Monthly / Annual) and matches them to existing SKUs on re-run.
  - Refund and cancel hooks flip the linked membership to `cancelled` and log the activity.
- **New tables**: `wp_memberistic_email_logs`, `wp_memberistic_integrations`. Migration `1.2.0` creates them on existing installs.
- **New roles**: `memberistic_kiosk_operator`, `memberistic_pos_staff` placeholder roles for the future KIOSK and POS modules.
- **New statuses**: `suspended`, `needs_review` are now accepted everywhere and rendered as proper badges.
- **New activity event types**: `membership_expired`, `membership_upgraded`, `membership_downgraded`, `waiver_expired`.
- **Settings** — added `timezone`, `logo_url`, `accent_brand_color`, `woocommerce_webhook_secret` to the sanitizer.

### Fixed

- **Stripe `invoice.payment_succeeded` now handled** — recurring renewals advance `renewal_date`, record a payment row, fire the `membership_renewed` activity, and send the renewal receipt.
- **Stripe `customer.subscription.deleted` fallback** — looks up the membership by `stripe_subscription_id` if the metadata is absent.
- **`memberistic_validate_status()` accepts the full 10-status set** — previously it silently coerced `suspended` and `needs_review` to `pending`.
- **`People_Repository::update()` partial updates preserve existing values** — previously a partial update would clobber `status` / `waiver_status` back to defaults.
- **Member search now joins linked-member names and matches Stripe / Woo / POS customer IDs** — previously search only matched the primary person.
- **People sanitizer accepts `waiver_signed_at` and `waiver_expires_at`** — schema columns are now writable.

### Changed

- `Email_Service` rewritten around merge-tag substitution and email-log persistence.
- `Scheduler` is wired into activation (schedules) and deactivation (clears scheduled events).
- `Activity_Repository::types()` and the internal whitelist now cover the full 21 spec event types.

### Documentation

- `docs/AUDIT_REPORT.md` — complete, sectioned audit against the canonical spec. Every spec section reports `Done` / `Partial` / `Roadmap` and lists fixes shipped in this release.
- `README.md` rewritten for client delivery: what's in the box, install steps, REST surface, filter map, repo layout.
- `CHANGELOG.md` introduced as the durable history.

---

## 1.6.0 — Phase 5

- Retired the legacy server-rendered views in favour of React consoles.
- Email automation foundation.
- Saved-filter views per user.
- React Settings app on top of the new Settings REST controller.

## 1.5.6

- Membership user roles, plan-specific roles, and post / page restriction controls.
- Modern frontend restriction overlay for visitors without the required plan.

## 1.5.5

- Polished admin plan cards and smoother branded card motion.
- Expanded integrations panel with connected and coming-soon addon cards.
- G2A public-booking integration: active members detected by email for free lane bookings.

## 1.5.4

- Hardened public checkout redirects, restyled default login/result pages, and improved staff / admin operational screens.

## 1.5.3

- Staff frontend dashboard for walk-in membership creation, member search, check-ins, and recent booking visibility.
- Fixed Settings screen tabs.
- Added frontend dashboard page mapping.

## 1.5.2

- Routed public Stripe checkout submissions through the mapped Memberistic Checkout page to avoid login redirects before Stripe Checkout opens.

## 1.5.1

- Memberistic checkout form submits via a public frontend route so Stripe Checkout is not blocked by wp-admin/login rules.
- Connected to real G2A Booking Engine hooks.

## 1.5.0

- Linked online Stripe checkout memberships to WordPress users or email-matched accounts.
- Real frontend account, people, payment history, and booking / check-in history shortcode views.
- Lifecycle email foundation for membership creation, activation, payment failure, cancellation, renewal, and waiver reminders.
- Booking Engine eligibility, discount, and activity hooks.
- Optional WooCommerce completed-order bridge foundation.
- Expanded REST endpoints for plan management, membership update / delete, payments, activity, bookings, renew, cancel, upgrade, and dashboard queues.
- Dashboard reporting with waiver / payment follow-up, expiring renewals, and revenue by plan.

## 1.4.3

- Ignored known legacy / common membership page mappings when branded Memberistic pages are available.

## 1.4.2

- Branded page URL fallbacks for checkout buttons and Stripe return URLs.

## 1.4.1

- Branded Memberistic URL slugs; remap action for existing installs.

## 1.4.0

- Stripe Checkout settings.
- Frontend membership checkout form.
- Pending membership creation before Stripe redirect.
- Stripe Checkout Session creation without the Stripe PHP SDK.
- Stripe webhook endpoint for checkout completion, failed invoices, and subscription cancellation.

## 1.3.0

- Frontend shortcode foundation.
- Public membership plan cards with monthly / annual toggle.
- Checkout, account, login, and renewal shortcode foundations.
- Page mapping settings and required-page creation action.

## 1.2.1

- Rounded dashboard revenue values to clean currency precision.

## 1.2.0

- Staff operation foundation: manual payments, check-ins, and staff notes.
- Functional Payments, Check-Ins, and Activity admin pages.
- Staff operation forms on the member profile screen.
- Authenticated REST endpoints for payment, check-in, and note creation.

## 1.1.0

- Phase 2 membership creation foundation.
- Primary person creation and linked member management.
- Member profile admin screen with people and activity sections.
- Authenticated memberships REST endpoints.

## 1.0.1

- Hardened REST controller compatibility with WordPress core inheritance.
- Removed PHP 8-only type syntax from runtime code for broader staging-host safety.

## 1.0.0

- Initial Phase 1 foundation build.
