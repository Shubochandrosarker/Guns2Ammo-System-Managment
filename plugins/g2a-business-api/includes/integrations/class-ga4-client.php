<?php
/**
 * Google Analytics Data API (GA4) client.
 *
 * Uses the shared Google_Service_Account for OAuth. Fetches the small set of
 * metrics the Insightistic Analytics page needs — sessions, engaged sessions,
 * total revenue (in USD micros → cents), bounce rate — plus a daily
 * sessions time-series.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GA4_Client {
	private const PROPERTY_OPTION = 'g2aba_ga4_property_id';
	private const SCOPE           = 'https://www.googleapis.com/auth/analytics.readonly';
	private const ENDPOINT_FMT    = 'https://analyticsdata.googleapis.com/v1beta/properties/%s:runReport';

	public function is_configured(): bool {
		return '' !== $this->property_id() && ( new Google_Service_Account() )->is_configured();
	}

	public function property_id(): string {
		return (string) get_option( self::PROPERTY_OPTION, '' );
	}

	/**
	 * Aggregate metrics + daily sessions for a date range.
	 *
	 * @return array{
	 *   sessions:int, engagedSessions:int, revenueCents:int,
	 *   bounceRate:float, sessionsSeries:array<int,array{date:string,value:int}>
	 * }
	 * @throws \RuntimeException On transport or 4xx/5xx from Google.
	 */
	public function metrics( string $from, string $to ): array {
		$sa    = new Google_Service_Account();
		$token = $sa->access_token( self::SCOPE );
		$url   = sprintf( self::ENDPOINT_FMT, rawurlencode( $this->property_id() ) );

		$aggregate = $this->run_report(
			$url,
			$token,
			array(
				'dateRanges' => array( array( 'startDate' => $from, 'endDate' => $to ) ),
				'metrics'    => array(
					array( 'name' => 'sessions' ),
					array( 'name' => 'engagedSessions' ),
					array( 'name' => 'totalRevenue' ),
					array( 'name' => 'bounceRate' ),
				),
			)
		);

		$series = $this->run_report(
			$url,
			$token,
			array(
				'dateRanges' => array( array( 'startDate' => $from, 'endDate' => $to ) ),
				'dimensions' => array( array( 'name' => 'date' ) ),
				'metrics'    => array( array( 'name' => 'sessions' ) ),
				'orderBys'   => array( array( 'dimension' => array( 'dimensionName' => 'date' ) ) ),
			)
		);

		return $this->shape( $aggregate, $series );
	}

	/**
	 * @internal Exposed for tests. Both aggregate + series payloads pass here.
	 */
	public function shape( array $aggregate, array $series ): array {
		$agg_row = $aggregate['rows'][0]['metricValues'] ?? array();
		// Metric order is preserved from the request in run_report above.
		$sessions         = (int) ( $agg_row[0]['value'] ?? 0 );
		$engaged_sessions = (int) ( $agg_row[1]['value'] ?? 0 );
		$revenue          = (float) ( $agg_row[2]['value'] ?? 0 );
		$bounce           = (float) ( $agg_row[3]['value'] ?? 0 );

		$sessions_series = array();
		foreach ( $series['rows'] ?? array() as $row ) {
			$raw_date = (string) ( $row['dimensionValues'][0]['value'] ?? '' );
			$date     = '' === $raw_date
				? ''
				: substr( $raw_date, 0, 4 ) . '-' . substr( $raw_date, 4, 2 ) . '-' . substr( $raw_date, 6, 2 );
			$sessions_series[] = array(
				'date'  => $date,
				'value' => (int) ( $row['metricValues'][0]['value'] ?? 0 ),
			);
		}

		return array(
			'sessions'        => $sessions,
			'engagedSessions' => $engaged_sessions,
			// GA4 returns revenue as a decimal string (USD). Convert to cents.
			'revenueCents'    => (int) round( $revenue * 100 ),
			// GA4 bounceRate is a 0..1 ratio. Promote to percent to match UI.
			'bounceRate'      => round( $bounce * 100, 1 ),
			'sessionsSeries'  => $sessions_series,
		);
	}

	private function run_report( string $url, string $token, array $body ): array {
		$resp = wp_remote_post(
			$url,
			array(
				'timeout' => 12,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $resp ) ) {
			throw new \RuntimeException( 'GA4 transport: ' . $resp->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( 200 !== $code ) {
			throw new \RuntimeException( 'GA4 HTTP ' . $code . ': ' . wp_remote_retrieve_body( $resp ) );
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		return is_array( $json ) ? $json : array();
	}
}
