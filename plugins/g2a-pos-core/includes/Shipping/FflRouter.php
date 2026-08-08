<?php

namespace G2A\POS\Shipping;

/**
 * Customer-to-FFL routing helper. Given a customer ZIP / state, picks the
 * nearest active FFL partner from `g2a_ffl_partners`. We don't run a real
 * distance calc here — the ATF's eZcheck doesn't expose lat/lon — so we
 * rank by state match first, then ZIP prefix match length.
 */
final class FflRouter {

	public static function suggest( string $state, string $zip ): array {
		global $wpdb;
		$st      = strtoupper( substr( $state, 0, 2 ) );
		$matches = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}g2a_ffl_partners
             WHERE status = 'active' AND state = %s ORDER BY business_name ASC LIMIT 100",
				$st
			),
			ARRAY_A
		) ?: array();

		if ( ! $matches ) {
			return array();
		}

		$zip3 = substr( preg_replace( '/[^0-9]/', '', $zip ), 0, 3 );
		usort(
			$matches,
			static function ( $a, $b ) use ( $zip3 ) {
				$az3     = substr( preg_replace( '/[^0-9]/', '', (string) ( $a['zip'] ?? '' ) ), 0, 3 );
				$bz3     = substr( preg_replace( '/[^0-9]/', '', (string) ( $b['zip'] ?? '' ) ), 0, 3 );
				$a_score = ( $az3 === $zip3 ) ? 2 : ( substr( $az3, 0, 2 ) === substr( $zip3, 0, 2 ) ? 1 : 0 );
				$b_score = ( $bz3 === $zip3 ) ? 2 : ( substr( $bz3, 0, 2 ) === substr( $zip3, 0, 2 ) ? 1 : 0 );
				return $b_score <=> $a_score;
			}
		);

		return array_slice( $matches, 0, 5 );
	}
}
