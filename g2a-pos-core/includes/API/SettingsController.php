<?php

namespace G2A\POS\API;

use G2A\POS\Database\AuditLogRepository;
use G2A\POS\Domain\TaxService;
use WP_REST_Request;

/**
 * Read/write surface for POS-wide configuration options (currently the
 * g2a_pos_tax config consumed by TaxService). Registers its own routes on
 * rest_api_init from Plugin::boot().
 */
final class SettingsController {

	public static function register_routes(): void {
		register_rest_route(
			'g2a-pos/v1',
			'/settings/tax',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'get_tax' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_settings' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/settings/tax',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'update_tax' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_settings' ),
			)
		);
	}

	public static function get_tax( WP_REST_Request $request ) {
		return TaxService::config();
	}

	public static function update_tax( WP_REST_Request $request ) {
		$body   = $request->get_json_params() ?: array();
		$before = TaxService::config();
		$config = TaxService::save_config( $body );
		( new AuditLogRepository() )->add( 'settings.tax.update', 'option', TaxService::OPTION, $before, $config );
		return $config;
	}
}
