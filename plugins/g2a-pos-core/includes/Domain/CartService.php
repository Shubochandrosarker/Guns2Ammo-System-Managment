<?php

namespace G2A\POS\Domain;

defined( 'ABSPATH' ) || exit;

final class CartService {

	private const META_KEY = 'g2a_pos_cart';

	public static function get( int $user_id ): array {
		$cart = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! is_array( $cart ) ) {
			return array(
				'items'         => array(),
				'customer_id'   => null,
				'register_code' => '',
				'location_id'   => 1,
			);
		}

		$cart['items']         = is_array( $cart['items'] ?? null ) ? array_values( $cart['items'] ) : array();
		$cart['customer_id']   = isset( $cart['customer_id'] ) ? (int) $cart['customer_id'] : null;
		$cart['register_code'] = sanitize_text_field( (string) ( $cart['register_code'] ?? '' ) );
		$cart['location_id']   = (int) ( $cart['location_id'] ?? 1 );
		return $cart;
	}

	public static function save( int $user_id, array $cart ): array {
		$normalized = self::normalize( $cart );
		update_user_meta( $user_id, self::META_KEY, $normalized );
		return $normalized;
	}

	public static function clear( int $user_id ): array {
		$empty = array(
			'items'         => array(),
			'customer_id'   => null,
			'register_code' => '',
			'location_id'   => 1,
		);
		update_user_meta( $user_id, self::META_KEY, $empty );
		return $empty;
	}

	public static function add_item( int $user_id, array $item ): array {
		$cart          = self::get( $user_id );
		$items         = $cart['items'];
		$item_id       = sanitize_text_field( (string) ( $item['id'] ?? wp_generate_uuid4() ) );
		$items[]       = array(
			'id'            => $item_id,
			'product_id'    => (int) ( $item['product_id'] ?? 0 ),
			'variation_id'  => isset( $item['variation_id'] ) ? (int) $item['variation_id'] : null,
			'sku'           => sanitize_text_field( (string) ( $item['sku'] ?? '' ) ),
			'name'          => sanitize_text_field( (string) ( $item['name'] ?? '' ) ),
			'item_type'     => sanitize_key( (string) ( $item['item_type'] ?? 'accessory' ) ),
			'serial_number' => sanitize_text_field( (string) ( $item['serial_number'] ?? '' ) ),
			'quantity'      => (float) ( $item['quantity'] ?? 1 ),
			'unit_price'    => (float) ( $item['unit_price'] ?? 0 ),
			'line_total'    => (float) ( $item['line_total'] ?? 0 ),
		);
		$cart['items'] = $items;
		return self::save( $user_id, $cart );
	}

	public static function update_item( int $user_id, string $item_id, array $changes ): array {
		$cart = self::get( $user_id );
		foreach ( $cart['items'] as &$item ) {
			if ( ( $item['id'] ?? '' ) !== $item_id ) {
				continue;
			}
			if ( isset( $changes['quantity'] ) ) {
				$item['quantity'] = max( 0, (float) $changes['quantity'] );
			}
			if ( isset( $changes['serial_number'] ) ) {
				$item['serial_number'] = sanitize_text_field( (string) $changes['serial_number'] );
			}
			if ( isset( $changes['unit_price'] ) ) {
				$item['unit_price'] = (float) $changes['unit_price'];
			}
			$item['line_total'] = (float) $item['quantity'] * (float) $item['unit_price'];
		}
		unset( $item );
		$cart['items'] = array_values( array_filter( $cart['items'], static fn( $i ) => (float) ( $i['quantity'] ?? 0 ) > 0 ) );
		return self::save( $user_id, $cart );
	}

	public static function remove_item( int $user_id, string $item_id ): array {
		$cart          = self::get( $user_id );
		$cart['items'] = array_values( array_filter( $cart['items'], static fn( $i ) => ( $i['id'] ?? '' ) !== $item_id ) );
		return self::save( $user_id, $cart );
	}

	public static function set_context( int $user_id, array $context ): array {
		$cart = self::get( $user_id );
		if ( isset( $context['customer_id'] ) ) {
			$cart['customer_id'] = (int) $context['customer_id'];
		}
		if ( isset( $context['register_code'] ) ) {
			$cart['register_code'] = sanitize_text_field( (string) $context['register_code'] );
		}
		if ( isset( $context['location_id'] ) ) {
			$cart['location_id'] = (int) $context['location_id'];
		}
		return self::save( $user_id, $cart );
	}

	public static function totals( array $cart ): array {
		$subtotal = 0.0;
		foreach ( $cart['items'] as $item ) {
			$subtotal += (float) ( $item['line_total'] ?? 0 );
		}
		$subtotal  = round( $subtotal, 2 );
		$tax_total = TaxService::tax_for_lines( $cart['items'] );
		return array(
			'subtotal'       => $subtotal,
			'tax_total'      => $tax_total,
			'discount_total' => 0.0,
			'grand_total'    => round( $subtotal + $tax_total, 2 ),
		);
	}

	private static function normalize( array $cart ): array {
		$cart['items']         = array_values(
			array_map(
				static function ( $item ) {
					return array(
						'id'            => sanitize_text_field( (string) ( $item['id'] ?? wp_generate_uuid4() ) ),
						'product_id'    => (int) ( $item['product_id'] ?? 0 ),
						'variation_id'  => isset( $item['variation_id'] ) ? (int) $item['variation_id'] : null,
						'sku'           => sanitize_text_field( (string) ( $item['sku'] ?? '' ) ),
						'name'          => sanitize_text_field( (string) ( $item['name'] ?? '' ) ),
						'item_type'     => sanitize_key( (string) ( $item['item_type'] ?? 'accessory' ) ),
						'serial_number' => sanitize_text_field( (string) ( $item['serial_number'] ?? '' ) ),
						'quantity'      => (float) ( $item['quantity'] ?? 0 ),
						'unit_price'    => (float) ( $item['unit_price'] ?? 0 ),
						'line_total'    => (float) ( $item['line_total'] ?? 0 ),
					);
				},
				is_array( $cart['items'] ?? null ) ? $cart['items'] : array()
			)
		);
		$cart['customer_id']   = isset( $cart['customer_id'] ) ? (int) $cart['customer_id'] : null;
		$cart['register_code'] = sanitize_text_field( (string) ( $cart['register_code'] ?? '' ) );
		$cart['location_id']   = (int) ( $cart['location_id'] ?? 1 );
		return $cart;
	}
}
