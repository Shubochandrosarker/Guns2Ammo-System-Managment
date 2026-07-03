<?php
/**
 * SEO analytics — Google Search Console.
 *
 * Reads real data when a service-account key has been uploaded (via
 * Secrets::put('gsc:service-account', $json) plus the site URL option
 * `g2aba_gsc_site_url`). Falls back to an empty skeleton otherwise so the
 * REST contract stays stable.
 *
 * Errors from the Google side are swallowed here and reported via System
 * Health — they must NOT bubble up as 500s in a dashboard route.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Providers;

use WordPressistic\G2ABA\Integrations\GSC_Client;
use WordPressistic\G2ABA\Range;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Provider {
	public function analytics( Range $range ): array {
		$client = new GSC_Client();
		if ( ! $client->is_configured() ) {
			return $this->empty_payload( $range );
		}

		try {
			$data = $client->search_analytics( $range->from, $range->to );
		} catch ( \Throwable $e ) {
			// Record so System Health can surface it, but return a clean shape.
			update_option( 'g2aba_gsc_last_error', $e->getMessage(), false );
			return $this->empty_payload( $range );
		}

		update_option( 'g2aba_gsc_last_error', '', false );
		$data['range'] = $range->to_array();
		return $data;
	}

	private function empty_payload( Range $range ): array {
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
