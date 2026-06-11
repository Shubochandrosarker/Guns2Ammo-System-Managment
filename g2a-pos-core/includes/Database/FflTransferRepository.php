<?php

namespace G2A\POS\Database;

final class FflTransferRepository extends Repository {

	public function create( array $data ): int {
		global $wpdb;
		$t   = $this->table( 'g2a_ffl_transfers' );
		$now = $this->now();
		$row = array(
			'transfer_type'      => sanitize_key( (string) ( $data['transfer_type'] ?? 'firearm' ) ),
			'direction'          => sanitize_key( (string) ( $data['direction'] ?? 'inbound' ) ),
			'partner_ffl_id'     => isset( $data['partner_ffl_id'] ) ? (int) $data['partner_ffl_id'] : null,
			'partner_ffl_number' => isset( $data['partner_ffl_number'] ) ? strtoupper( preg_replace( '/[^0-9A-Z]/', '', (string) $data['partner_ffl_number'] ) ) : null,
			'partner_name'       => isset( $data['partner_name'] ) ? sanitize_text_field( (string) $data['partner_name'] ) : null,
			'customer_id'        => isset( $data['customer_id'] ) ? (int) $data['customer_id'] : null,
			'transferee_id'      => isset( $data['transferee_id'] ) ? (int) $data['transferee_id'] : null,
			'manufacturer'       => sanitize_text_field( (string) $data['manufacturer'] ),
			'model'              => sanitize_text_field( (string) $data['model'] ),
			'serial_number'      => sanitize_text_field( (string) $data['serial_number'] ),
			'firearm_type'       => sanitize_key( (string) $data['firearm_type'] ),
			'caliber'            => sanitize_text_field( (string) $data['caliber'] ),
			'status'             => sanitize_key( (string) ( $data['status'] ?? 'pending' ) ),
			'fee_charged'        => isset( $data['fee_charged'] ) ? (float) $data['fee_charged'] : null,
			'shipper'            => isset( $data['shipper'] ) ? sanitize_key( (string) $data['shipper'] ) : null,
			'tracking_number'    => isset( $data['tracking_number'] ) ? sanitize_text_field( (string) $data['tracking_number'] ) : null,
			'actor_id'           => get_current_user_id(),
			'location_id'        => (int) ( $data['location_id'] ?? 1 ),
			'notes'              => isset( $data['notes'] ) ? sanitize_textarea_field( (string) $data['notes'] ) : null,
			'created_at'         => $now,
			'updated_at'         => $now,
		);
		$wpdb->insert( $t, $row );
		return (int) $wpdb->insert_id;
	}

	public function update( int $id, array $fields ): bool {
		global $wpdb;
		$t                    = $this->table( 'g2a_ffl_transfers' );
		$fields['updated_at'] = $this->now();
		return (bool) $wpdb->update( $t, $fields, array( 'id' => $id ) );
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$t   = $this->table( 'g2a_ffl_transfers' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d LIMIT 1", $id ), ARRAY_A );
		return $row ?: null;
	}

	public function paginate( array $args = array() ): array {
		global $wpdb;
		$t      = $this->table( 'g2a_ffl_transfers' );
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['direction'] ) ) {
			$where[]  = 'direction = %s';
			$params[] = $args['direction']; }
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status']; }
		if ( ! empty( $args['serial'] ) ) {
			$where[]  = 'serial_number = %s';
			$params[] = $args['serial']; }
		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 50 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;
		$sql      = "SELECT * FROM {$t} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$params[] = $per_page;
		$params[] = $offset;
		return $params ? ( $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) ?: array() ) : ( $wpdb->get_results( $sql, ARRAY_A ) ?: array() );
	}
}
