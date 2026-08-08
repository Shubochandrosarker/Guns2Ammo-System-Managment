<?php
/**
 * Google Business Profile provider.
 *
 * Sits between the GBP client and the Gaps engine. Returns zeros when the
 * client isn't configured — never throws — so the rest of the plugin has a
 * stable contract to depend on.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Providers;

use WordPressistic\G2ABA\Integrations\GBP_Client;
use WordPressistic\G2ABA\Range;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GBP_Provider {
	public function performance( Range $range ): array {
		$client = new GBP_Client();
		if ( ! $client->is_configured() ) {
			return $this->empty_payload();
		}

		try {
			$data = $client->performance( $range->from, $range->to );
		} catch ( \Throwable $e ) {
			update_option( 'g2aba_gbp_last_error', $e->getMessage(), false );
			return $this->empty_payload();
		}
		update_option( 'g2aba_gbp_last_error', '', false );
		return $data;
	}

	private function empty_payload(): array {
		return array(
			'viewsSearch'         => 0,
			'viewsMaps'           => 0,
			'callsClicked'        => 0,
			'directionsRequested' => 0,
			'websiteClicked'      => 0,
			'averageRating'       => 0.0,
			'reviewCount'         => 0,
		);
	}
}
