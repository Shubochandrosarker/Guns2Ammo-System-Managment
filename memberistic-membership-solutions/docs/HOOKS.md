# Developer hook reference

Memberistic ships a stable set of action and filter hooks so integrators can
extend behaviour without forking the plugin. Hook names are stable across
minor releases.

> Memberistic is co-developed by [WordPressistic](https://www.wordpressistic.com) and launch partner [Guns 2 Ammo](https://guns2ammo.com) — see [`PARTNERS.md`](PARTNERS.md).

## Actions

| Action | Args | When it fires |
|--------|------|---------------|
| `memberistic_loaded` | — | After all classes are loaded and hooks are registered. |
| `memberistic_plan_created` | `int $plan_id` | After a plan row is inserted. |
| `memberistic_plan_updated` | `int $plan_id` | After a plan row is updated. |
| `memberistic_membership_created` | `int $membership_id` | After a membership row is inserted (online checkout, staff dashboard, REST). |
| `memberistic_membership_activated` | `int $membership_id` | When a membership flips to `active` (Stripe webhook, manual renew, upgrade). |
| `memberistic_membership_payment_recorded` | `int $membership_id, int $payment_id, string $gateway` | After a payment row is recorded by a gateway integration. |
| `memberistic_person_added` | `int $person_id, int $membership_id` | After a linked / primary person row is inserted. |
| `memberistic_stripe_webhook_event` | `string $type, array $object, array $event` | For each Stripe webhook event before Memberistic dispatches. |

## Filters

| Filter | Default | Purpose |
|--------|---------|---------|
| `memberistic_default_plans` | The three G2A tiers | Replace or extend the plans seeded on first install. |
| `memberistic_email_templates` | The 13 transactional templates | Add custom email templates (id + label). |
| `memberistic_email_template_subject` | Default subject string | Override the subject for one template. |
| `memberistic_email_template_body` | Default body string | Override the body for one template. |
| `memberistic_email_merge_tags` | 21 default merge tags | Add custom merge tags. |
| `memberistic_should_send_email` | `true` | Short-circuit individual email sends per membership. |
| `memberistic_brand_label` | Setting `brand_label` | Replace the visible brand name in admin + emails. |
| `memberistic_admin_menu_label` | Brand label | Override the admin menu title. |
| `memberistic_roles` | The six custom roles | Add or rename custom roles. |
| `memberistic_capabilities` | 13 default capabilities | Extend the capability set. |
| `memberistic_required_pages` | The eight default pages | Customise the auto-created frontend pages. |
| `memberistic_staff_dashboard_capabilities` | `memberistic_checkin_members` and friends | Customise which roles can render the staff dashboard. |
| `memberistic_woocommerce_enabled` | Setting `woocommerce_enabled === 'yes'` | Force-enable / disable the Woo bridge. |
| `memberistic_can_book_as_member` | `false` | Allow custom logic to grant member status to a user during booking. |
| `memberistic_booking_discount` | `0` | Apply a custom plan-based discount during booking. |

## Cron hooks

The Scheduler registers three daily hooks. Override the schedule or callback
by unschedule + re-schedule:

- `memberistic_daily_renewal_reminders` — emails members 30 / 7 / 1 day out.
- `memberistic_daily_expire_memberships` — flips active memberships past their renewal date into `expired`.
- `memberistic_daily_waiver_followup` — nudges active members with missing or expired waivers (max once per week per membership).

## REST extension

Every controller extends `WordPressistic\Memberistic\REST\REST_Controller` so
new routes can re-use the four permission helpers:

- `admin_permissions_check()` — staff / manager / admin.
- `manage_members_permissions_check()` — staff that can create / edit members.
- `manage_payments_permissions_check()` — cashier / manager.
- `checkin_permissions_check()` — anyone with `memberistic_checkin_members`.

To register a new route in your own plugin, hook `rest_api_init` after
priority 10 and call `register_rest_route( 'memberistic/v1', ... )`.
