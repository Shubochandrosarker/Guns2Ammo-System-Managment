<?php
/**
 * SEO analytics — pulls from Google Search Console when a service-account
 * refresh token is configured, otherwise returns an empty skeleton.
 *
 * Wiring the actual OAuth flow is out of scope for Phase 2 — this class
 * defines the boundary so the frontend contract is stable and Phase 3 can
 * drop in a real client without touching REST code.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Providers;

use WordPressistic\G2ABA\Range;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Provider {
	public function analytics( Range $range ): array {
		return array(
			'range'         => $range->to_array(),
			'clicks'        => 0,
			'impressions'   => 0,
			'ctr'           => 0.0,
			'position'      => 0.0,
			'topPages'      => array(),
			'droppingPages' => array(),
			'topQueries'    => array(),
			'clicksSeries'  => array(),
		);
	}
}
