<?php
/**
 * Google Search Console client.
 *
 * Uses a service-account JSON key (uploaded once, stored via Secrets) to
 * mint an access token, then hits the Search Analytics API for aggregate
 * clicks/impressions/CTR/position plus top-page and top-query breakdowns.
 *
 * The service-account email must be added as a user of the GSC property
 * (Property → Users and permissions) with Restricted read access. This is
 * a one-off manual step and is documented in the plugin README.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSC_Client {
	private const SITE_OPTION = 'g2aba_gsc_site_url';
	private const SCOPE       = 'https://www.googleapis.com/auth/webmasters.readonly';

	public function is_configured(): bool {
		return ( new Google_Service_Account() )->is_configured()
			&& '' !== (string) get_option( self::SITE_OPTION, '' );
	}

	public function site_url(): string {
		return (string) get_option( self::SITE_OPTION, '' );
	}

	/**
	 * Search Analytics for the range.
	 *
	 * Two calls: one aggregate (no dimensions) + one dimensions=page/query for
	 * the breakdown lists. Google recommends caching by day; we cache for 5min.
	 *
	 * @return array{
	 *   clicks: int, impressions: int, ctr: float, position: float,
	 *   topPages: array, droppingPages: array, topQueries: array,
	 *   clicksSeries: array
	 * }
	 * @throws \RuntimeException On transport or auth errors.
	 */
	public function search_analytics( string $from, string $to ): array {
		if ( ! $this->is_configured() ) {
			throw new \RuntimeException( 'GSC client is not configured' );
		}
		$token = $this->access_token();
		$site  = $this->site_url();

		$aggregate = $this->query(
			$site,
			$token,
			array(
				'startDate'  => $from,
				'endDate'    => $to,
				'dimensions' => array( 'date' ),
				'rowLimit'   => 500,
			)
		);

		$by_query = $this->query(
			$site,
			$token,
			array(
				'startDate'  => $from,
				'endDate'    => $to,
				'dimensions' => array( 'query' ),
				'rowLimit'   => 25,
			)
		);

		$by_page = $this->query(
			$site,
			$token,
			array(
				'startDate'  => $from,
				'endDate'    => $to,
				'dimensions' => array( 'page' ),
				'rowLimit'   => 25,
			)
		);

		return $this->shape( $aggregate, $by_query, $by_page );
	}

	private function shape( array $aggregate, array $by_query, array $by_page ): array {
		$rows          = $aggregate['rows'] ?? array();
		$clicks_series = array();
		$clicks_total  = 0;
		$imp_total     = 0;
		$pos_weighted  = 0.0;
		foreach ( $rows as $row ) {
			$date            = (string) ( $row['keys'][0] ?? '' );
			$clicks          = (int) ( $row['clicks'] ?? 0 );
			$impressions     = (int) ( $row['impressions'] ?? 0 );
			$position        = (float) ( $row['position'] ?? 0 );
			$clicks_total   += $clicks;
			$imp_total      += $impressions;
			$pos_weighted   += $position * $impressions;
			$clicks_series[] = array( 'date' => $date, 'value' => $clicks );
		}
		$ctr      = $imp_total > 0 ? round( ( $clicks_total / $imp_total ) * 100, 2 ) : 0.0;
		$position = $imp_total > 0 ? round( $pos_weighted / $imp_total, 1 ) : 0.0;

		$top_queries = array();
		foreach ( $by_query['rows'] ?? array() as $row ) {
			$top_queries[] = array(
				'query'    => (string) ( $row['keys'][0] ?? '' ),
				'clicks'   => (int) ( $row['clicks'] ?? 0 ),
				'position' => round( (float) ( $row['position'] ?? 0 ), 1 ),
			);
		}

		$top_pages = array();
		foreach ( $by_page['rows'] ?? array() as $row ) {
			$top_pages[] = array(
				'url'      => (string) ( $row['keys'][0] ?? '' ),
				'clicks'   => (int) ( $row['clicks'] ?? 0 ),
				'deltaPct' => 0.0, // TODO Phase 3.1 — compare against previous window.
			);
		}

		return array(
			'clicks'        => $clicks_total,
			'impressions'   => $imp_total,
			'ctr'           => $ctr,
			'position'      => $position,
			'topPages'      => array_slice( $top_pages, 0, 5 ),
			'droppingPages' => array(),
			'topQueries'    => array_slice( $top_queries, 0, 8 ),
			'clicksSeries'  => $clicks_series,
		);
	}

	private function query( string $site, string $token, array $body ): array {
		$url  = 'https://searchconsole.googleapis.com/webmasters/v3/sites/'
			. rawurlencode( $site ) . '/searchAnalytics/query';
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
			throw new \RuntimeException( 'GSC transport: ' . $resp->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( 200 !== $code ) {
			throw new \RuntimeException( 'GSC returned HTTP ' . $code . ': ' . wp_remote_retrieve_body( $resp ) );
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		return is_array( $json ) ? $json : array();
	}

	private function access_token(): string {
		return ( new Google_Service_Account() )->access_token( self::SCOPE );
	}
}
