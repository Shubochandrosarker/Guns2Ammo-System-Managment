<?php

namespace G2A\POS\Database;

final class WholesalerOrderRepository extends Repository {

	public function create( array $data ): int {
		global $wpdb;
		$t   = $this->table( 'g2a_wholesaler_orders' );
		$now = $this->now();
		$wpdb->insert(
			$t,
			array(
				'wholesaler_id'   => (int) $data['wholesaler_id'],
				'pos_order_id'    => isset( $data['pos_order_id'] ) ? (int) $data['pos_order_id'] : null,
				'wc_order_id'     => isset( $data['wc_order_id'] ) ? (int) $data['wc_order_id'] : null,
				'order_type'      => (string) ( $data['order_type'] ?? 'dropship' ),
				'status'          => (string) ( $data['status'] ?? 'pending' ),
				'ship_to_ffl'     => $data['ship_to_ffl'] ?? null,
				'ship_to_name'    => $data['ship_to_name'] ?? null,
				'ship_to_address' => $data['ship_to_address'] ?? null,
				'ship_to_city'    => $data['ship_to_city'] ?? null,
				'ship_to_state'   => $data['ship_to_state'] ?? null,
				'ship_to_zip'     => $data['ship_to_zip'] ?? null,
				'ship_method'     => $data['ship_method'] ?? null,
				'items'           => isset( $data['items'] ) ? wp_json_encode( $data['items'] ) : null,
				'request_payload' => isset( $data['request_payload'] ) ? wp_json_encode( $data['request_payload'] ) : null,
				'actor_id'        => (int) ( $data['actor_id'] ?? get_current_user_id() ),
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function markSubmitted( int $id, string $externalRef, string $status, array $response ): void {
		global $wpdb;
		$wpdb->update(
			$this->table( 'g2a_wholesaler_orders' ),
			array(
				'external_order_ref' => $externalRef ?: null,
				'status'             => $status,
				'response_payload'   => wp_json_encode( $response ),
				'submitted_at'       => $this->now(),
				'updated_at'         => $this->now(),
			),
			array( 'id' => $id )
		);
	}

	public function markFailed( int $id, string $error ): void {
		global $wpdb;
		$wpdb->update(
			$this->table( 'g2a_wholesaler_orders' ),
			array(
				'status'        => 'failed',
				'error_message' => $error,
				'updated_at'    => $this->now(),
			),
			array( 'id' => $id )
		);
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$t   = $this->table( 'g2a_wholesaler_orders' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id=%d", $id ), ARRAY_A );
		return $row ?: null;
	}

	public function listRecent( int $limit = 50 ): array {
		global $wpdb;
		$t = $this->table( 'g2a_wholesaler_orders' );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$t} ORDER BY id DESC LIMIT %d",
				max( 1, min( 200, $limit ) )
			),
			ARRAY_A
		) ?: array();
	}
}
