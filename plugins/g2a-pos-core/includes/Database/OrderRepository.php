<?php

namespace G2A\POS\Database;

final class OrderRepository extends Repository {

	public function serial_already_sold( string $serial ): bool {
		global $wpdb;

		$serial = sanitize_text_field( $serial );
		if ( $serial === '' ) {
			return false;
		}

		$items_table  = $this->table( 'g2a_pos_order_items' );
		$orders_table = $this->table( 'g2a_pos_orders' );
		$count        = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(1)
             FROM {$items_table} i
             INNER JOIN {$orders_table} o ON o.id = i.pos_order_id
             WHERE i.serial_number = %s
             AND o.status NOT IN ('void', 'cancelled', 'refunded')",
				$serial
			)
		);

		return $count > 0;
	}

	public function create( array $order, array $items ): int {
		global $wpdb;

		$wpdb->insert(
			$this->table( 'g2a_pos_orders' ),
			array(
				'wc_order_id'         => $order['wc_order_id'] ?? null,
				'register_session_id' => $order['register_session_id'] ?? null,
				'customer_id'         => $order['customer_id'] ?? null,
				'employee_id'         => (int) $order['employee_id'],
				'location_id'         => (int) $order['location_id'],
				'status'              => sanitize_key( $order['status'] ?? 'draft' ),
				'subtotal'            => (float) $order['subtotal'],
				'tax_total'           => (float) ( $order['tax_total'] ?? 0 ),
				'discount_total'      => (float) ( $order['discount_total'] ?? 0 ),
				'grand_total'         => (float) $order['grand_total'],
				'payment_status'      => sanitize_key( $order['payment_status'] ?? 'pending' ),
				'payment_method'      => sanitize_text_field( $order['payment_method'] ?? '' ),
				'compliance_state'    => sanitize_key( $order['compliance_state'] ?? 'pending_review' ),
				'created_at'          => $this->now(),
				'updated_at'          => $this->now(),
			)
		);

		$order_id = (int) $wpdb->insert_id;

		foreach ( $items as $item ) {
			$wpdb->insert(
				$this->table( 'g2a_pos_order_items' ),
				array(
					'pos_order_id'  => $order_id,
					'product_id'    => (int) $item['product_id'],
					'variation_id'  => $item['variation_id'] ?? null,
					'sku'           => sanitize_text_field( $item['sku'] ?? '' ),
					'serial_number' => $item['serial_number'] ?? null,
					'item_type'     => sanitize_key( $item['item_type'] ?? 'accessory' ),
					'quantity'      => (float) $item['quantity'],
					'unit_price'    => (float) $item['unit_price'],
					'line_total'    => (float) $item['line_total'],
					'metadata'      => ! empty( $item['metadata'] ) ? wp_json_encode( $item['metadata'] ) : null,
					'created_at'    => $this->now(),
				)
			);
		}

		return $order_id;
	}

	public function find_with_items( int $order_id ): ?array {
		global $wpdb;

		$order_table = $this->table( 'g2a_pos_orders' );
		$item_table  = $this->table( 'g2a_pos_order_items' );

		$order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$order_table} WHERE id = %d LIMIT 1", $order_id ), ARRAY_A );
		if ( ! $order ) {
			return null;
		}

		$items          = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$item_table} WHERE pos_order_id = %d ORDER BY id ASC", $order_id ), ARRAY_A ) ?: array();
		$order['items'] = $items;

		return $order;
	}

	public function list( array $filters = array() ): array {
		global $wpdb;
		$t     = $this->table( 'g2a_pos_orders' );
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $filters['status'] ) ) {
			$where[] = 'status = %s';
			$args[]  = sanitize_key( $filters['status'] );
		}
		if ( ! empty( $filters['payment_status'] ) ) {
			$where[] = 'payment_status = %s';
			$args[]  = sanitize_key( $filters['payment_status'] );
		}
		if ( ! empty( $filters['compliance_state'] ) ) {
			$where[] = 'compliance_state = %s';
			$args[]  = sanitize_key( $filters['compliance_state'] );
		}
		if ( ! empty( $filters['customer_id'] ) ) {
			$where[] = 'customer_id = %d';
			$args[]  = (int) $filters['customer_id'];
		}
		$limit  = max( 1, min( 500, (int) ( $filters['limit'] ?? 50 ) ) );
		$sql    = "SELECT * FROM {$t} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d';
		$args[] = $limit;
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) ?: array();
	}

	public function update_states( int $order_id, array $fields ): bool {
		global $wpdb;

		$update = array();
		if ( isset( $fields['status'] ) ) {
			$update['status'] = sanitize_key( (string) $fields['status'] );
		}
		if ( isset( $fields['payment_status'] ) ) {
			$update['payment_status'] = sanitize_key( (string) $fields['payment_status'] );
		}
		if ( isset( $fields['compliance_state'] ) ) {
			$update['compliance_state'] = sanitize_key( (string) $fields['compliance_state'] );
		}
		if ( isset( $fields['payment_method'] ) ) {
			$update['payment_method'] = sanitize_text_field( (string) $fields['payment_method'] );
		}
		if ( ! $update ) {
			return false;
		}

		$update['updated_at'] = $this->now();

		return (bool) $wpdb->update( $this->table( 'g2a_pos_orders' ), $update, array( 'id' => $order_id ) );
	}
}
