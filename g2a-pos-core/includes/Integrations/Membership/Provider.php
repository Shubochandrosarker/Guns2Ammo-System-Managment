<?php

namespace G2A\POS\Integrations\Membership;

/**
 * Membership provider — looks up a customer's active membership and upcoming
 * bookings inside whatever membership plugin Guns 2 Ammo is running.
 *
 * Implementations should be cheap (1-2 SQL hits) — the cashier UI calls this
 * on every customer lookup.
 *
 * Return shape from membership():
 *   - active: bool
 *   - plan:   string (display label)
 *   - plan_slug: string
 *   - expires_at: ISO-8601 datetime|null
 *   - member_id:  string (provider's id)
 *   - discounts:  array of { type: 'percent'|'fixed', value: number, applies_to: array of slugs|'all' }
 *   - source: string (provider slug)
 *
 * Return shape from bookings():
 *   - array of { id, title, starts_at, ends_at, status }
 */
interface Provider {

	public function slug(): string;
	public function label(): string;
	public function detect(): bool;
	public function membership( int $customer_id ): ?array;
	public function bookings( int $customer_id, int $days_ahead = 30 ): array;
}
