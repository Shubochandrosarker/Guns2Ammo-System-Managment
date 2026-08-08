<?php

namespace G2A\POS\API;

use G2A\POS\Catalog\IdentityService;
use G2A\POS\Database\MapRuleRepository;
use G2A\POS\Database\OrderRepository;
use G2A\POS\Pricing\SourcingSuggester;
use WP_REST_Request;

final class OrderSourcingController {

	/** GET /orders/{id}/sourcing — per-line shelf+vendor matrix + suggestion. */
	public static function for_order( WP_REST_Request $request ) {
		$order_id = (int) $request['id'];
		$order    = ( new OrderRepository() )->find_with_items( $order_id );
		if ( ! $order ) {
			return new \WP_Error( 'not_found', 'Order not found', array( 'status' => 404 ) );
		}

		$opts  = self::opts_from_request( $request );
		$lines = array();
		foreach ( (array) ( $order['items'] ?? array() ) as $item ) {
			$lines[] = self::evaluate_line( $item, $opts );
		}
		return array(
			'order_id'     => $order_id,
			'order_status' => $order['status'] ?? null,
			'lines'        => $lines,
			'opts'         => $opts,
		);
	}

	/** POST /cart/sourcing — evaluate an in-progress cart (not yet saved as an order). */
	public static function for_cart( WP_REST_Request $request ) {
		$body  = $request->get_json_params() ?: array();
		$items = (array) ( $body['items'] ?? array() );
		$opts  = self::opts_from_body( $body );
		$lines = array();
		foreach ( $items as $item ) {
			$lines[] = self::evaluate_line( (array) $item, $opts );
		}
		return array(
			'lines' => $lines,
			'opts'  => $opts,
		);
	}

	private static function evaluate_line( array $item, array $opts ): array {
		$identity = IdentityService::resolve_for_order_item( $item );
		if ( ! $identity ) {
			return array(
				'sku'            => $item['sku'] ?? null,
				'name'           => $item['name'] ?? null,
				'product_id'     => (int) ( $item['product_id'] ?? 0 ),
				'quantity'       => (float) ( $item['quantity'] ?? 1 ),
				'identity'       => null,
				'sources'        => null,
				'recommendation' => array(
					'source' => 'unresolved',
					'reason' => 'No canonical identity matched — run a backfill or attach a link in the Identity Graph.',
				),
			);
		}

		$sources = IdentityService::sources( (int) $identity['id'] );
		if ( empty( $sources['ok'] ) ) {
			return array(
				'sku'            => $item['sku'] ?? null,
				'name'           => $item['name'] ?? null,
				'product_id'     => (int) ( $item['product_id'] ?? 0 ),
				'quantity'       => (float) ( $item['quantity'] ?? 1 ),
				'identity'       => $identity,
				'sources'        => null,
				'recommendation' => array(
					'source' => 'identity_error',
					'reason' => $sources['error'] ?? 'unknown',
				),
			);
		}

		$map_rule = ( new MapRuleRepository() )->findForProduct(
			(int) ( $item['product_id'] ?? 0 ),
			(string) ( $item['sku'] ?? '' ) ?: null,
			(string) ( $item['upc'] ?? '' ) ?: null
		);

		$suggestion = SourcingSuggester::suggest(
			(array) $sources['shelf'],
			(array) $sources['vendors'],
			$map_rule,
			$opts
		);

		return array(
			'sku'            => $item['sku'] ?? null,
			'name'           => $item['name'] ?? null,
			'product_id'     => (int) ( $item['product_id'] ?? 0 ),
			'quantity'       => (float) ( $item['quantity'] ?? 1 ),
			'identity'       => array(
				'id'             => (int) $identity['id'],
				'canonical_code' => $identity['canonical_code'],
				'manufacturer'   => $identity['manufacturer'],
				'model'          => $identity['model'],
				'caliber'        => $identity['caliber'],
			),
			'sources'        => $suggestion,
			'recommendation' => $suggestion['recommendation'],
		);
	}

	private static function opts_from_request( WP_REST_Request $request ): array {
		return array(
			'markup_pct'      => $request->get_param( 'markup_pct' ) !== null
				? (float) $request->get_param( 'markup_pct' )
				: (float) get_option( 'g2a_pos_default_markup_pct', SourcingSuggester::DEFAULT_MARKUP_PCT ),
			'max_overage_pct' => (float) get_option( 'g2a_pos_max_overage_pct', SourcingSuggester::DEFAULT_MAX_OVERAGE_PCT ),
			'dropship_fee'    => (float) get_option( 'g2a_pos_dropship_fee', SourcingSuggester::DEFAULT_DROPSHIP_FEE ),
		);
	}

	private static function opts_from_body( array $body ): array {
		return array(
			'markup_pct'      => isset( $body['markup_pct'] )
				? (float) $body['markup_pct']
				: (float) get_option( 'g2a_pos_default_markup_pct', SourcingSuggester::DEFAULT_MARKUP_PCT ),
			'max_overage_pct' => isset( $body['max_overage_pct'] )
				? (float) $body['max_overage_pct']
				: (float) get_option( 'g2a_pos_max_overage_pct', SourcingSuggester::DEFAULT_MAX_OVERAGE_PCT ),
			'dropship_fee'    => isset( $body['dropship_fee'] )
				? (float) $body['dropship_fee']
				: (float) get_option( 'g2a_pos_dropship_fee', SourcingSuggester::DEFAULT_DROPSHIP_FEE ),
		);
	}
}
