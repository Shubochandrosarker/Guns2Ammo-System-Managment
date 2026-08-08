<?php

namespace G2A\POS\Ai\Tools;

use G2A\POS\Ai\ActionPolicy;
use G2A\POS\Ai\ToolRegistry;

final class InventoryFindTool {

	public static function register(): void {
		ToolRegistry::register(
			'inventory.find',
			array(
				'description' => 'Search the local inventory + vendor catalog for products. Returns up to 20 matches with stock, price, and SKU.',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'q'             => array(
							'type'        => 'string',
							'description' => 'Free-text query (e.g. "9mm Federal HST", "Glock 19 Gen 6", "red dot for 1911")',
						),
						'item_type'     => array(
							'type' => 'string',
							'enum' => array( 'firearm', 'ammo', 'optic', 'accessory', 'holster', 'any' ),
						),
						'in_stock_only' => array( 'type' => 'boolean' ),
						'limit'         => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 20,
						),
					),
					'required'   => array( 'q' ),
				),
			),
			array( self::class, 'run' ),
			'g2a_pos_access',
			ActionPolicy::SILENT
		);
	}

	public static function run( array $args ): array {
		global $wpdb;
		$q = trim( (string) ( $args['q'] ?? '' ) );
		if ( $q === '' ) {
			return array(
				'ok'    => false,
				'error' => 'query_required',
			);
		}
		$like   = '%' . $wpdb->esc_like( $q ) . '%';
		$where  = array( '(name LIKE %s OR vendor_sku LIKE %s OR upc LIKE %s OR mfg_part LIKE %s OR caliber LIKE %s)' );
		$params = array( $like, $like, $like, $like, $like );
		if ( ! empty( $args['item_type'] ) && $args['item_type'] !== 'any' ) {
			$where[]  = 'item_type = %s';
			$params[] = sanitize_text_field( $args['item_type'] );
		}
		if ( ! empty( $args['in_stock_only'] ) ) {
			$where[] = 'stock_qty > 0';
		}
		$limit    = max( 1, min( 20, (int) ( $args['limit'] ?? 10 ) ) );
		$sql      = "SELECT id, vendor_sku, upc, name, manufacturer, model, item_type, caliber,
                       current_price, msrp, stock_qty, can_dropship
                FROM {$wpdb->prefix}g2a_wholesaler_products
                WHERE " . implode( ' AND ', $where ) . ' ORDER BY stock_qty DESC, name ASC LIMIT %d';
		$params[] = $limit;
		$rows     = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) ?: array();
		return array(
			'ok'    => true,
			'count' => count( $rows ),
			'items' => $rows,
		);
	}
}
