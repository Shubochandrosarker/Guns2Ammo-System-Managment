<?php

namespace G2A\POS\API;

use G2A\POS\Integrations\FflCheckoutBridge;
use G2A\POS\Integrations\WooCatalogSync;
use WP_REST_Request;

final class IntegrationsController {

	/** POST /integrations/woo/scan — full WooCommerce catalog scan into POS tables. */
	public static function woo_scan( WP_REST_Request $request ) {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return new \WP_Error( 'woocommerce_inactive', 'WooCommerce is not active.', array( 'status' => 422 ) );
		}
		$stats = WooCatalogSync::scan();
		return array(
			'ok'    => empty( $stats['error'] ),
			'stats' => $stats,
		);
	}

	/** GET /integrations/woo/scan — last scan info (for the Inventory view). */
	public static function woo_scan_status( WP_REST_Request $request ) {
		return array(
			'active'    => function_exists( 'wc_get_products' ),
			'last_scan' => get_option( 'g2a_pos_woo_catalog_last_scan', null ),
		);
	}

	/** GET /integrations/ffl/transfers — read-only advanced-ffl-checkout transfers. */
	public static function ffl_transfers( WP_REST_Request $request ) {
		return array(
			'active'    => FflCheckoutBridge::is_active(),
			'transfers' => FflCheckoutBridge::transfers(
				array(
					'q'      => $request->get_param( 'q' ),
					'status' => $request->get_param( 'status' ),
					'limit'  => (int) ( $request->get_param( 'limit' ) ?: 100 ),
					'offset' => (int) ( $request->get_param( 'offset' ) ?: 0 ),
				)
			),
		);
	}
}
