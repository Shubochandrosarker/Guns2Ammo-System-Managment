<?php
/**
 * Google Business Profile client.
 *
 * Reads the "profile performance" report (views, actions like phone calls,
 * driving-direction requests, and website clicks) from the Business Profile
 * Performance API, plus a small snapshot of the latest reviews from the
 * (deprecated) Reviews API.
 *
 * NOTE — GBP APIs require per-account allowlisting by Google. We build the
 * exact call shape and secret storage, but always guard with is_configured()
 * so the plugin degrades cleanly on installs that don't have API access yet.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GBP_Client {
	private const LOCATION_OPTION = 'g2aba_gbp_location_id'; // e.g. "locations/1234567890"
	private const SCOPE           = 'https://www.googleapis.com/auth/business.manage';

	public function is_configured(): bool {
		return '' !== $this->location_id() && ( new Google_Service_Account() )->is_configured();
	}

	public function location_id(): string {
		return (string) get_option( self::LOCATION_OPTION, '' );
	}

	/**
	 * @return array{
	 *   viewsSearch:int, viewsMaps:int,
	 *   callsClicked:int, directionsRequested:int, websiteClicked:int,
	 *   averageRating:float, reviewCount:int
	 * }
	 * @throws \RuntimeException On transport or auth failure.
	 */
	public function performance( string $from, string $to ): array {
		$sa    = new Google_Service_Account();
		$token = $sa->access_token( self::SCOPE );

		// Business Profile Performance API multiDailyMetrics endpoint expects
		// a comma-separated `dailyMetrics` list. We aggregate the returned
		// dailyMetricTimeSeries into scalar counts.
		$loc  = rawurlencode( $this->location_id() );
		$url  = 'https://businessprofileperformance.googleapis.com/v1/'
			. $loc . ':fetchMultiDailyMetricsTimeSeries'
			. '?dailyMetrics=BUSINESS_IMPRESSIONS_DESKTOP_SEARCH'
			. '&dailyMetrics=BUSINESS_IMPRESSIONS_MOBILE_SEARCH'
			. '&dailyMetrics=BUSINESS_IMPRESSIONS_DESKTOP_MAPS'
			. '&dailyMetrics=BUSINESS_IMPRESSIONS_MOBILE_MAPS'
			. '&dailyMetrics=CALL_CLICKS'
			. '&dailyMetrics=BUSINESS_DIRECTION_REQUESTS'
			. '&dailyMetrics=WEBSITE_CLICKS'
			. '&dailyRange.startDate.year=' . rawurlencode( substr( $from, 0, 4 ) )
			. '&dailyRange.startDate.month=' . rawurlencode( ltrim( substr( $from, 5, 2 ), '0' ) )
			. '&dailyRange.startDate.day=' . rawurlencode( ltrim( substr( $from, 8, 2 ), '0' ) )
			. '&dailyRange.endDate.year=' . rawurlencode( substr( $to, 0, 4 ) )
			. '&dailyRange.endDate.month=' . rawurlencode( ltrim( substr( $to, 5, 2 ), '0' ) )
			. '&dailyRange.endDate.day=' . rawurlencode( ltrim( substr( $to, 8, 2 ), '0' ) );

		$perf = $this->get_json( $url, $token );

		// Best-effort reviews snapshot — the API here has been unstable in
		// recent GBP versions; failures don't fail the overall call.
		$avg   = 0.0;
		$count = 0;
		try {
			$reviews = $this->get_json(
				'https://mybusiness.googleapis.com/v4/accounts/-/' . $loc . '/reviews',
				$token
			);
			$avg   = (float) ( $reviews['averageRating'] ?? 0 );
			$count = (int) ( $reviews['totalReviewCount'] ?? 0 );
		} catch ( \Throwable $ignored ) {
			// Reviews are advisory here; don't error out the performance read.
		}

		return $this->shape( $perf, $avg, $count );
	}

	/**
	 * @internal Exposed for tests.
	 */
	public function shape( array $perf, float $average_rating, int $review_count ): array {
		$series = $perf['multiDailyMetricTimeSeries'] ?? array();
		$totals = array(
			'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH' => 0,
			'BUSINESS_IMPRESSIONS_MOBILE_SEARCH'  => 0,
			'BUSINESS_IMPRESSIONS_DESKTOP_MAPS'   => 0,
			'BUSINESS_IMPRESSIONS_MOBILE_MAPS'    => 0,
			'CALL_CLICKS'                         => 0,
			'BUSINESS_DIRECTION_REQUESTS'         => 0,
			'WEBSITE_CLICKS'                      => 0,
		);

		foreach ( $series as $bundle ) {
			foreach ( $bundle['dailyMetricTimeSeries'] ?? array() as $metric ) {
				$name = (string) ( $metric['dailyMetric'] ?? '' );
				if ( ! isset( $totals[ $name ] ) ) {
					continue;
				}
				foreach ( $metric['timeSeries']['datedValues'] ?? array() as $dv ) {
					$totals[ $name ] += (int) ( $dv['value'] ?? 0 );
				}
			}
		}

		return array(
			'viewsSearch'         => $totals['BUSINESS_IMPRESSIONS_DESKTOP_SEARCH']
				+ $totals['BUSINESS_IMPRESSIONS_MOBILE_SEARCH'],
			'viewsMaps'           => $totals['BUSINESS_IMPRESSIONS_DESKTOP_MAPS']
				+ $totals['BUSINESS_IMPRESSIONS_MOBILE_MAPS'],
			'callsClicked'        => $totals['CALL_CLICKS'],
			'directionsRequested' => $totals['BUSINESS_DIRECTION_REQUESTS'],
			'websiteClicked'      => $totals['WEBSITE_CLICKS'],
			'averageRating'       => round( $average_rating, 2 ),
			'reviewCount'         => $review_count,
		);
	}

	private function get_json( string $url, string $token ): array {
		$resp = wp_remote_get(
			$url,
			array(
				'timeout' => 12,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);
		if ( is_wp_error( $resp ) ) {
			throw new \RuntimeException( 'GBP transport: ' . $resp->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( 200 !== $code ) {
			throw new \RuntimeException( 'GBP HTTP ' . $code . ': ' . wp_remote_retrieve_body( $resp ) );
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		return is_array( $json ) ? $json : array();
	}
}
