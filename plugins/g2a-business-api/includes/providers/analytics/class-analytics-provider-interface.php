<?php
/**
 * Contract every Phase-C analytics provider fulfils.
 *
 * A provider owns one upstream data source (booking engine, Memberistic,
 * WooCommerce, waiver stores, this plugin's own ops tables) and reports:
 *
 *  - is_available(): the source plugin is active AND its schema/tables are
 *    actually present. No provider ever fatals when the answer is "no" —
 *    it degrades to an explicit unavailable payload instead.
 *  - source_slug(): the plugin slug listed in the envelope's meta.source.
 *  - freshness(): {status: fresh|stale|unavailable, lastUpdatedAt: ISO UTC|null}.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Providers\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Analytics_Provider_Interface {
	/**
	 * Source plugin active + schema/table check.
	 */
	public function is_available(): bool;

	/**
	 * Plugin slug this provider reads from (for meta.source).
	 */
	public function source_slug(): string;

	/**
	 * Freshness block for the envelope:
	 * {status: "fresh"|"stale"|"unavailable", lastUpdatedAt: "<ISO UTC>"|null}.
	 *
	 * @return array{status:string, lastUpdatedAt:?string}
	 */
	public function freshness(): array;
}
