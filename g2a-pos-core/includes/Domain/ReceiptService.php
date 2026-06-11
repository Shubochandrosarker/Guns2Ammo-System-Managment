<?php

namespace G2A\POS\Domain;

use G2A\POS\Database\OrderRepository;

defined( 'ABSPATH' ) || exit;

final class ReceiptService {

	public static function build( int $order_id ): ?array {
		$repo  = new OrderRepository();
		$order = $repo->find_with_items( $order_id );
		if ( ! $order ) {
			return null;
		}

		$lines = array();
		foreach ( ( $order['items'] ?? array() ) as $item ) {
			$p       = function_exists( 'wc_get_product' ) ? wc_get_product( (int) ( $item['variation_id'] ?: $item['product_id'] ) ) : null;
			$lines[] = array(
				'product_id'    => (int) $item['product_id'],
				'sku'           => (string) ( $item['sku'] ?? '' ),
				'name'          => $p ? (string) $p->get_name() : ( 'Product #' . (int) $item['product_id'] ),
				'item_type'     => (string) $item['item_type'],
				'serial_number' => (string) ( $item['serial_number'] ?? '' ),
				'quantity'      => (float) $item['quantity'],
				'unit_price'    => (float) $item['unit_price'],
				'line_total'    => (float) $item['line_total'],
			);
		}

		$receipt_no = 'G2A-POS-' . str_pad( (string) $order_id, 6, '0', STR_PAD_LEFT );
		$payload    = array(
			'receipt_number'        => $receipt_no,
			'generated_at'          => current_time( 'mysql' ),
			'order'                 => array(
				'id'                  => (int) $order['id'],
				'wc_order_id'         => (int) ( $order['wc_order_id'] ?? 0 ),
				'status'              => (string) $order['status'],
				'payment_status'      => (string) $order['payment_status'],
				'compliance_state'    => (string) $order['compliance_state'],
				'register_session_id' => (int) $order['register_session_id'],
				'location_id'         => (int) $order['location_id'],
				'created_at'          => (string) $order['created_at'],
			),
			'totals'                => array(
				'subtotal'       => (float) $order['subtotal'],
				'tax_total'      => (float) $order['tax_total'],
				'discount_total' => (float) $order['discount_total'],
				'grand_total'    => (float) $order['grand_total'],
			),
			'items'                 => $lines,
			'compliance_disclaimer' => 'Firearm transfers are subject to compliance approval and applicable waiting periods.',
			'pickup_instructions'   => 'Bring valid ID and required transfer documentation for pickup.',
		);

		$payload['html'] = self::to_html( $payload );
		return $payload;
	}

	private static function to_html( array $receipt ): string {
		$html  = '<div><h3>Guns2Ammo Receipt #' . esc_html( $receipt['receipt_number'] ) . '</h3>';
		$html .= '<p>Date: ' . esc_html( $receipt['generated_at'] ) . '</p><hr><ul>';
		foreach ( $receipt['items'] as $item ) {
			$html .= '<li>' . esc_html( (string) $item['item_type'] ) . ' x ' . esc_html( (string) $item['quantity'] ) . ' - $' . esc_html( number_format( (float) $item['line_total'], 2 ) );
			if ( $item['serial_number'] !== '' ) {
				$html .= ' (SN: ' . esc_html( (string) $item['serial_number'] ) . ')';
			}
			$html .= '</li>';
		}
		$html .= '</ul><p><strong>Total: $' . esc_html( number_format( (float) $receipt['totals']['grand_total'], 2 ) ) . '</strong></p>';
		$html .= '<p><small>' . esc_html( (string) $receipt['compliance_disclaimer'] ) . '</small></p></div>';
		return $html;
	}
}
