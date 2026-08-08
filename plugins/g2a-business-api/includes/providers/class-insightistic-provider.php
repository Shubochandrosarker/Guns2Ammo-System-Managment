<?php
/**
 * Insightistic Analytics provider — combined view over GA4 + existing sources.
 *
 * The dashboard's Insightistic Analytics page wants engagement + attribution
 * numbers that the transactional providers don't have (they own money and
 * customers; they don't own web-session data). GA4 supplies that layer.
 *
 * Never surfaces a 5xx to REST — failures degrade to a zeroed payload and
 * write the error to `g2aba_ga4_last_error` for System Health.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Providers;

use WordPressistic\G2ABA\Integrations\GA4_Client;
use WordPressistic\G2ABA\Range;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Insightistic_Provider {
	public function analytics( Range $range ): array {
		$client = new GA4_Client();
		if ( ! $client->is_configured() ) {
			return $this->empty_payload( $range );
		}

		try {
			$ga4 = $client->metrics( $range->from, $range->to );
		} catch ( \Throwable $e ) {
			update_option( 'g2aba_ga4_last_error', $e->getMessage(), false );
			return $this->empty_payload( $range );
		}
		update_option( 'g2aba_ga4_last_error', '', false );

		return array(
			'range'              => $range->to_array(),
			'sessions'           => $ga4['sessions'],
			'engagedSessions'    => $ga4['engagedSessions'],
			'revenueAttributed'  => $ga4['revenueCents'],
			'bounceRate'         => $ga4['bounceRate'],
			'sessionsSeries'     => $ga4['sessionsSeries'],
		);
	}

	private function empty_payload( Range $range ): array {
		return array(
			'range'              => $range->to_array(),
			'sessions'           => 0,
			'engagedSessions'    => 0,
			'revenueAttributed'  => 0,
			'bounceRate'         => 0.0,
			'sessionsSeries'     => array(),
		);
	}
}
