<?php

namespace G2A\POS\Domain;

defined( 'ABSPATH' ) || exit;

final class ProductCatalog {

	public static function normalize_line( array $line ): array {
		$product_id = (int) ( $line['variation_id'] ?? $line['product_id'] ?? 0 );
		$qty        = max( 0, (float) ( $line['quantity'] ?? 0 ) );
		$product    = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

		$price     = $product ? (float) $product->get_price() : (float) ( $line['unit_price'] ?? 0 );
		$sku       = $product ? (string) $product->get_sku() : (string) ( $line['sku'] ?? '' );
		$name      = $product ? (string) $product->get_name() : (string) ( $line['name'] ?? '' );
		$item_type = self::detect_item_type( (int) ( $line['product_id'] ?? $product_id ), $product );

		return array(
			'product_id'    => (int) ( $line['product_id'] ?? $product_id ),
			'variation_id'  => isset( $line['variation_id'] ) ? (int) $line['variation_id'] : null,
			'sku'           => $sku,
			'name'          => $name,
			'item_type'     => $item_type,
			'serial_number' => sanitize_text_field( (string) ( $line['serial_number'] ?? '' ) ),
			'quantity'      => $qty,
			'unit_price'    => $price,
			'line_total'    => round( $qty * $price, 2 ),
		);
	}

	public static function detect_item_type( int $product_id, $product = null ): string {
		$name = '';
		$sku  = '';
		if ( $product ) {
			$name = strtolower( (string) $product->get_name() );
			$sku  = strtolower( (string) $product->get_sku() );
		}

		$is_firearm = has_term( 'firearm', 'product_cat', $product_id )
			|| has_term( 'firearms', 'product_cat', $product_id )
			|| str_contains( $name, 'glock' )
			|| str_contains( $name, 'rifle' )
			|| str_contains( $name, 'pistol' )
			|| str_contains( $sku, 'ffl' );

		if ( $is_firearm ) {
			return 'firearm';
		}
		if ( has_term( 'ammo', 'product_cat', $product_id ) || has_term( 'ammunition', 'product_cat', $product_id ) ) {
			return 'ammo';
		}
		return 'accessory';
	}
}
