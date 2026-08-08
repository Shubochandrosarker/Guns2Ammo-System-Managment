<?php

namespace G2A\POS\Inventory\Distributors;

/**
 * Distributor adapter — converts a raw CSV row from a wholesaler feed into the
 * normalized product schema the Importer understands.
 *
 * Normalized row shape (all keys optional unless noted):
 *   - source            (string, required) distributor slug (e.g. 'lipseys')
 *   - source_ref        (string) wholesaler's row id / stock number
 *   - upc               (string) 12-14 digit UPC/EAN
 *   - manufacturer_sku  (string)
 *   - manufacturer      (string)
 *   - model             (string)
 *   - name              (string, required) display name
 *   - description       (string)
 *   - item_type         ('firearm'|'ammunition'|'accessory'|'other')
 *   - firearm_type      ('handgun'|'rifle'|'shotgun'|'long_gun'|null)
 *   - caliber           (string|null)
 *   - magazine_capacity (int|null)
 *   - barrel_length_in  (float|null)
 *   - is_semi_auto      (bool|null)
 *   - has_detachable_magazine (bool|null)
 *   - msrp              (float|null)
 *   - dealer_cost       (float|null)
 *   - quantity          (int|null) — on-hand at distributor
 *   - metadata          (array) — anything else worth keeping
 */
interface Adapter {

	public function slug(): string;

	/** Human label. */
	public function label(): string;

	/**
	 * Column names the importer's CSV-header detector should match
	 * (case-insensitive). Used so the UI can auto-suggest an adapter
	 * when a CSV is uploaded.
	 */
	public function header_signature(): array;

	/**
	 * Map one raw CSV row (already keyed by header name) to a normalized row.
	 * Return null to skip the row.
	 */
	public function map_row( array $raw ): ?array;
}
