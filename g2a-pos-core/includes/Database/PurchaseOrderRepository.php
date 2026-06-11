<?php

namespace G2A\POS\Database;

final class PurchaseOrderRepository extends Repository {

	public function create( array $data ): int {
		global $wpdb;
		$now      = $this->now();
		$items    = $data['items'] ?? array();
		$subtotal = 0.0;
		foreach ( $items as $i ) {
			$subtotal += (float) ( $i['unit_cost'] ?? 0 ) * (float) ( $i['quantity_ordered'] ?? 0 );
		}
		$shipping = (float) ( $data['shipping_total'] ?? 0 );
		$tax      = (float) ( $data['tax_total'] ?? 0 );
		$grand    = $subtotal + $shipping + $tax;

		$wpdb->insert(
			$this->table( 'g2a_purchase_orders' ),
			array(
				'po_number'      => $data['po_number'] ?? $this->generate_number(),
				'wholesaler_id'  => isset( $data['wholesaler_id'] ) ? (int) $data['wholesaler_id'] : null,
				'vendor_name'    => sanitize_text_field( $data['vendor_name'] ?? '' ),
				'location_id'    => (int) ( $data['location_id'] ?? 1 ),
				'status'         => sanitize_key( $data['status'] ?? 'draft' ),
				'subtotal'       => $subtotal,
				'shipping_total' => $shipping,
				'tax_total'      => $tax,
				'grand_total'    => $grand,
				'expected_at'    => $data['expected_at'] ?? null,
				'actor_id'       => get_current_user_id(),
				'notes'          => sanitize_textarea_field( $data['notes'] ?? '' ),
				'created_at'     => $now,
				'updated_at'     => $now,
			)
		);
		$po_id = (int) $wpdb->insert_id;

		foreach ( $items as $item ) {
			$qty  = (float) ( $item['quantity_ordered'] ?? 0 );
			$cost = (float) ( $item['unit_cost'] ?? 0 );
			$wpdb->insert(
				$this->table( 'g2a_purchase_order_items' ),
				array(
					'po_id'            => $po_id,
					'product_id'       => isset( $item['product_id'] ) ? (int) $item['product_id'] : null,
					'vendor_sku'       => sanitize_text_field( $item['vendor_sku'] ?? '' ),
					'upc'              => sanitize_text_field( $item['upc'] ?? '' ),
					'description'      => sanitize_text_field( $item['description'] ?? '' ),
					'quantity_ordered' => $qty,
					'unit_cost'        => $cost,
					'line_total'       => $qty * $cost,
					'created_at'       => $now,
				)
			);
		}
		return $po_id;
	}

	public function submit( int $po_id, ?string $external_ref = null ): bool {
		global $wpdb;
		return (bool) $wpdb->update(
			$this->table( 'g2a_purchase_orders' ),
			array(
				'status'       => 'submitted',
				'submitted_at' => $this->now(),
				'external_ref' => $external_ref ? sanitize_text_field( $external_ref ) : null,
				'updated_at'   => $this->now(),
			),
			array( 'id' => $po_id )
		);
	}

	public function receive( int $po_id, int $po_item_id, float $qty, array $serial_numbers = array() ): array {
		global $wpdb;
		$now = $this->now();
		$wpdb->insert(
			$this->table( 'g2a_po_receipts' ),
			array(
				'po_id'          => $po_id,
				'po_item_id'     => $po_item_id,
				'quantity'       => $qty,
				'serial_numbers' => $serial_numbers ? wp_json_encode( array_values( $serial_numbers ) ) : null,
				'actor_id'       => get_current_user_id(),
				'received_at'    => $now,
				'created_at'     => $now,
			)
		);
		$receipt_id = (int) $wpdb->insert_id;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table('g2a_purchase_order_items')}
             SET quantity_received = quantity_received + %f, received_at = %s
             WHERE id = %d",
				$qty,
				$now,
				$po_item_id
			)
		);

		$remaining = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table('g2a_purchase_order_items')}
             WHERE po_id = %d AND quantity_received < quantity_ordered",
				$po_id
			)
		);
		$status    = $remaining === 0 ? 'received' : 'partial';
		$wpdb->update(
			$this->table( 'g2a_purchase_orders' ),
			array(
				'status'      => $status,
				'received_at' => $status === 'received' ? $now : null,
				'updated_at'  => $now,
			),
			array( 'id' => $po_id )
		);

		return array(
			'receipt_id' => $receipt_id,
			'po_status'  => $status,
		);
	}

	public function find( int $po_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_purchase_orders')} WHERE id = %d",
				$po_id
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		$row['items']    = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_purchase_order_items')} WHERE po_id = %d ORDER BY id",
				$po_id
			),
			ARRAY_A
		) ?: array();
		$row['receipts'] = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_po_receipts')} WHERE po_id = %d ORDER BY id",
				$po_id
			),
			ARRAY_A
		) ?: array();
		return $row;
	}

	public function list( array $filters = array() ): array {
		global $wpdb;
		$t     = $this->table( 'g2a_purchase_orders' );
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $filters['status'] ) ) {
			$where[] = 'status = %s';
			$args[]  = sanitize_key( $filters['status'] );
		}
		$sql = "SELECT * FROM {$t} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT 100';
		return $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A ) ?: array();
	}

	private function generate_number(): string {
		return 'PO-' . date( 'Ymd' ) . '-' . strtoupper( wp_generate_password( 4, false, false ) );
	}
}
