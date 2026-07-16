<?php

namespace G2A\POS\API;

use G2A\POS\Database\AuditLogRepository;
use G2A\POS\Domain\TaxService;
use G2A\POS\Support\SecretStore;
use WP_REST_Request;

/**
 * Read/write surface for POS-wide configuration options: tax (g2a_pos_tax),
 * Stripe Terminal (g2a_pos_stripe), and CA DROS (g2a_pos_dros_credentials).
 * Registers its own routes on rest_api_init from Plugin::boot().
 */
final class SettingsController {

	private const CAP = 'g2a_pos_manage_settings';

	public static function register_routes(): void {
		register_rest_route(
			'g2a-pos/v1',
			'/settings/tax',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'get_tax' ),
				'permission_callback' => static fn() => current_user_can( self::CAP ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/settings/tax',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'update_tax' ),
				'permission_callback' => static fn() => current_user_can( self::CAP ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/settings/stripe',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'get_stripe' ),
				'permission_callback' => static fn() => current_user_can( self::CAP ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/settings/stripe',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'update_stripe' ),
				'permission_callback' => static fn() => current_user_can( self::CAP ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/settings/dros',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'get_dros' ),
				'permission_callback' => static fn() => current_user_can( self::CAP ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/settings/dros',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'update_dros' ),
				'permission_callback' => static fn() => current_user_can( self::CAP ),
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

	public static function get_stripe( WP_REST_Request $request ) {
		return self::maskSecrets( (array) get_option( 'g2a_pos_stripe', array() ), array( 'secret_key' ) );
	}

	public static function update_stripe( WP_REST_Request $request ) {
		$body    = $request->get_json_params() ?: array();
		$current = (array) get_option( 'g2a_pos_stripe', array() );
		$mode    = sanitize_key( (string) ( $body['mode'] ?? ( $current['mode'] ?? 'test' ) ) );
		if ( ! in_array( $mode, array( 'test', 'live' ), true ) ) {
			return new \WP_Error( 'invalid_mode', 'mode must be test or live', array( 'status' => 400 ) );
		}
		$current['mode']              = $mode;
		$current['publishable_key']   = sanitize_text_field( (string) ( $body['publishable_key'] ?? ( $current['publishable_key'] ?? '' ) ) );
		$current['account_id']        = sanitize_text_field( (string) ( $body['account_id'] ?? ( $current['account_id'] ?? '' ) ) );
		$current                      = self::sealSecretField( $current, $body, 'secret_key' );
		update_option( 'g2a_pos_stripe', $current, false );
		( new AuditLogRepository() )->add( 'settings.stripe.update', 'option', 'g2a_pos_stripe', null, array( 'mode' => $mode ) );
		return self::maskSecrets( $current, array( 'secret_key' ) );
	}

	public static function get_dros( WP_REST_Request $request ) {
		return self::maskSecrets( (array) get_option( 'g2a_pos_dros_credentials', array() ), array( 'api_key' ) );
	}

	public static function update_dros( WP_REST_Request $request ) {
		$body    = $request->get_json_params() ?: array();
		$current = (array) get_option( 'g2a_pos_dros_credentials', array() );
		if ( array_key_exists( 'endpoint', $body ) ) {
			$url = esc_url_raw( trim( (string) $body['endpoint'] ) );
			if ( $url !== '' && ! preg_match( '#^https://#i', $url ) ) {
				return new \WP_Error( 'unsafe_url', 'endpoint must use HTTPS.', array( 'status' => 400 ) );
			}
			$current['endpoint'] = $url;
		}
		$current['dealer_id'] = sanitize_text_field( (string) ( $body['dealer_id'] ?? ( $current['dealer_id'] ?? '' ) ) );
		$current               = self::sealSecretField( $current, $body, 'api_key' );
		update_option( 'g2a_pos_dros_credentials', $current, false );
		( new AuditLogRepository() )->add( 'settings.dros.update', 'option', 'g2a_pos_dros_credentials', null, array( 'endpoint' => $current['endpoint'] ?? '' ) );
		return self::maskSecrets( $current, array( 'api_key' ) );
	}

	/**
	 * A blank secret field in the request means "keep what's saved" — never
	 * clear a working credential just because the browser round-tripped an
	 * empty password input. A non-blank value is sealed at rest; an existing
	 * legacy plaintext value is transparently upgraded to sealed on next save
	 * even if the operator only touched an unrelated field.
	 */
	private static function sealSecretField( array $current, array $body, string $key ): array {
		$value = trim( (string) ( $body[ $key ] ?? '' ) );
		if ( $value !== '' ) {
			$current[ $key ] = SecretStore::seal( $value );
		} elseif ( ! empty( $current[ $key ] ) && ! str_starts_with( (string) $current[ $key ], 'enc2:' ) ) {
			$current[ $key ] = SecretStore::seal( SecretStore::open( (string) $current[ $key ] ) );
		}
		return $current;
	}

	/** Never echo secret values back to the browser — only whether they're set. */
	private static function maskSecrets( array $config, array $secretKeys ): array {
		foreach ( $secretKeys as $key ) {
			$config[ $key . '_configured' ] = (string) ( $config[ $key ] ?? '' ) !== '';
			$config[ $key ]                 = '';
		}
		return $config;
	}
}
