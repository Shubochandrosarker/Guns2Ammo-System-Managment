<?php

namespace G2A\POS\Database;

final class AtfReportRepository extends Repository {

	public function create( array $data ): int {
		global $wpdb;
		$t                  = $this->table( 'g2a_atf_reports' );
		$data['created_at'] = $data['created_at'] ?? $this->now();
		$data['updated_at'] = $data['updated_at'] ?? $this->now();
		foreach ( array( 'transferee_form_4473_ids', 'firearm_serial_numbers', 'payload' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
				$data[ $key ] = wp_json_encode( $data[ $key ] );
			}
		}
		$wpdb->insert( $t, $data );
		return (int) $wpdb->insert_id;
	}

	public function update( int $id, array $fields ): bool {
		global $wpdb;
		$t                    = $this->table( 'g2a_atf_reports' );
		$fields['updated_at'] = $this->now();
		foreach ( array( 'transferee_form_4473_ids', 'firearm_serial_numbers', 'payload' ) as $key ) {
			if ( isset( $fields[ $key ] ) && is_array( $fields[ $key ] ) ) {
				$fields[ $key ] = wp_json_encode( $fields[ $key ] );
			}
		}
		return (bool) $wpdb->update( $t, $fields, array( 'id' => $id ) );
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$t   = $this->table( 'g2a_atf_reports' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d LIMIT 1", $id ), ARRAY_A );
		return $row ?: null;
	}

	public function paginate( array $args = array() ): array {
		global $wpdb;
		$t      = $this->table( 'g2a_atf_reports' );
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status']; }
		if ( ! empty( $args['report_type'] ) ) {
			$where[]  = 'report_type = %s';
			$params[] = $args['report_type']; }
		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 50 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;
		$sql      = "SELECT * FROM {$t} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$params[] = $per_page;
		$params[] = $offset;
		return $params ? ( $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) ?: array() ) : ( $wpdb->get_results( $sql, ARRAY_A ) ?: array() );
	}
}
