<?php

namespace G2A\POS\Database;

final class BoundBookRepository extends Repository {

	public function insert( array $row ): int {
		global $wpdb;
		$t = $this->table( 'g2a_atf_bound_book' );

		$row['entry_number'] = $row['entry_number'] ?? $this->next_entry_number();
		$row['created_at']   = $row['created_at'] ?? $this->now();
		$row['updated_at']   = $row['updated_at'] ?? $this->now();

		$prev             = $this->last_row_hash();
		$row['prev_hash'] = $prev;
		$row['row_hash']  = $this->hash_row( $row, $prev );

		$wpdb->insert( $t, $row );
		return (int) $wpdb->insert_id;
	}

	public function find_by_serial( string $serial ): ?array {
		global $wpdb;
		$t   = $this->table( 'g2a_atf_bound_book' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE serial_number = %s ORDER BY id DESC LIMIT 1", $serial ), ARRAY_A );
		return $row ?: null;
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$t   = $this->table( 'g2a_atf_bound_book' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d LIMIT 1", $id ), ARRAY_A );
		return $row ?: null;
	}

	public function record_disposition( int $id, array $fields ): bool {
		global $wpdb;
		$t                    = $this->table( 'g2a_atf_bound_book' );
		$fields['updated_at'] = $this->now();
		$existing             = $this->find( $id );
		if ( ! $existing ) {
			return false;
		}
		$next                = array_merge( $existing, $fields );
		$prev                = $existing['row_hash'] ?? null;
		$next['prev_hash']   = $prev;
		$next['row_hash']    = $this->hash_row( $next, $prev );
		$fields['prev_hash'] = $next['prev_hash'];
		$fields['row_hash']  = $next['row_hash'];
		return (bool) $wpdb->update( $t, $fields, array( 'id' => $id ) );
	}

	public function paginate( array $args = array() ): array {
		global $wpdb;
		$t      = $this->table( 'g2a_atf_bound_book' );
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['serial'] ) ) {
			$where[]  = 'serial_number = %s';
			$params[] = $args['serial']; }
		if ( ! empty( $args['entry_type'] ) ) {
			$where[]  = 'entry_type = %s';
			$params[] = $args['entry_type']; }
		if ( ! empty( $args['firearm_type'] ) ) {
			$where[]  = 'firearm_type = %s';
			$params[] = $args['firearm_type']; }
		if ( ! empty( $args['date_from'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = $args['date_from']; }
		if ( ! empty( $args['date_to'] ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = $args['date_to']; }
		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 50 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;
		$sql      = "SELECT * FROM {$t} WHERE " . implode( ' AND ', $where ) . ' ORDER BY entry_number ASC, id ASC LIMIT %d OFFSET %d';
		$params[] = $per_page;
		$params[] = $offset;
		$rows     = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );
		return $rows ?: array();
	}

	public function verify_chain( int $limit = 5000 ): array {
		global $wpdb;
		$t      = $this->table( 'g2a_atf_bound_book' );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} ORDER BY id ASC LIMIT %d", $limit ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$prev   = null;
		$broken = array();
		foreach ( $rows as $row ) {
			$expected = $this->hash_row( $row, $prev );
			if ( ! hash_equals( (string) ( $row['row_hash'] ?? '' ), $expected ) || (string) ( $row['prev_hash'] ?? '' ) !== (string) ( $prev ?? '' ) ) {
				$broken[] = (int) $row['id'];
			}
			$prev = $row['row_hash'] ?? null;
		}
		return array(
			'rows_checked' => count( $rows ),
			'broken'       => $broken,
			'ok'           => empty( $broken ),
		);
	}

	private function next_entry_number(): int {
		global $wpdb;
		$t   = $this->table( 'g2a_atf_bound_book' );
		$max = (int) $wpdb->get_var( "SELECT COALESCE(MAX(entry_number),0) FROM {$t}" );
		return $max + 1;
	}

	private function last_row_hash(): ?string {
		global $wpdb;
		$t   = $this->table( 'g2a_atf_bound_book' );
		$val = $wpdb->get_var( "SELECT row_hash FROM {$t} ORDER BY id DESC LIMIT 1" );
		return $val ? (string) $val : null;
	}

	private function hash_row( array $row, ?string $prev ): string {
		$copy = $row;
		unset( $copy['row_hash'], $copy['prev_hash'] );
		ksort( $copy );
		$payload = ( $prev ?? '' ) . '|' . wp_json_encode( $copy );
		return hash_hmac( 'sha256', $payload, wp_salt( 'logged_in' ) );
	}
}
