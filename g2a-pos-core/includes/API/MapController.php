<?php

namespace G2A\POS\API;

use G2A\POS\Database\MapRuleRepository;
use G2A\POS\Pricing\MapPricingService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class MapController {

	public static function list( WP_REST_Request $req ): WP_REST_Response {
		$repo = new MapRuleRepository();
		return new WP_REST_Response(
			array(
				'ok'    => true,
				'rules' => $repo->list( (int) ( $req->get_param( 'limit' ) ?: 100 ), (int) ( $req->get_param( 'offset' ) ?: 0 ) ),
			)
		);
	}

	public static function upsert( WP_REST_Request $req ) {
		$body      = $req->get_json_params() ?: $req->get_params();
		$productId = (int) ( $body['wc_product_id'] ?? 0 );
		if ( $productId <= 0 ) {
			return new WP_Error( 'invalid_product', 'wc_product_id is required', array( 'status' => 400 ) );
		}
		$price = (float) ( $body['map_price'] ?? 0 );
		if ( $price <= 0 ) {
			return new WP_Error( 'invalid_price', 'map_price must be greater than 0', array( 'status' => 400 ) );
		}
		$repo = new MapRuleRepository();
		$id   = $repo->setForWcProduct(
			$productId,
			array(
				'map_price'      => $price,
				'display_mode'   => (string) ( $body['display_mode'] ?? 'click_to_reveal' ),
				'override_label' => sanitize_text_field( (string) ( $body['override_label'] ?? '' ) ),
				'notes'          => sanitize_textarea_field( (string) ( $body['notes'] ?? '' ) ),
				'effective_at'   => $body['effective_at'] ?? null,
				'expires_at'     => $body['expires_at'] ?? null,
				'source'         => 'admin_ui',
			)
		);
		return new WP_REST_Response(
			array(
				'ok'      => true,
				'rule_id' => $id,
			)
		);
	}

	public static function evaluate( WP_REST_Request $req ): WP_REST_Response {
		$productId = (int) $req->get_param( 'wc_product_id' );
		$price     = $req->get_param( 'price' ) !== null
			? (float) $req->get_param( 'price' )
			: 0.0;
		if ( $price <= 0 && function_exists( 'wc_get_product' ) ) {
			$p = wc_get_product( $productId );
			if ( $p ) {
				$price = (float) $p->get_price();
			}
		}
		return new WP_REST_Response(
			array(
				'ok'     => true,
				'result' => MapPricingService::evaluate( $productId, $price ),
			)
		);
	}
}
