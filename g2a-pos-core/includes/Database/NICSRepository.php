<?php

namespace G2A\POS\Database;

final class NICSRepository extends Repository {

	public function create( array $data ): int {
		global $wpdb;
		$t                    = $this->table( 'g2a_nics_transactions' );
		$data['created_at']   = $this->now();
		$data['updated_at']   = $this->now();
		$data['initiated_at'] = $data['initiated_at'] ?? $this->now();
		$wpdb->insert( $t, $data );
		return (int) $wpdb->insert_id;
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$t   = $this->table( 'g2a_nics_transactions' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d LIMIT 1", $id ), ARRAY_A );
		return $row ?: null;
	}

	public function find_by_ntn( string $ntn ): ?array {
		global $wpdb;
		$t   = $this->table( 'g2a_nics_transactions' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE ntn = %s LIMIT 1", $ntn ), ARRAY_A );
		return $row ?: null;
	}

	public function update( int $id, array $fields ): bool {
		global $wpdb;
		$t                    = $this->table( 'g2a_nics_transactions' );
		$fields['updated_at'] = $this->now();
		return (bool) $wpdb->update( $t, $fields, array( 'id' => $id ) );
	}

	/**
	 * @param array{status?:string,limit?:int} $filters
	 * @return array<int,array<string,mixed>>
	 */
	public function list( array $filters = array() ): array {
		global $wpdb;
		$t     = $this->table( 'g2a_nics_transactions' );
		$f     = $this->table( 'g2a_form_4473' );
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $filters['status'] ) ) {
			$where[] = 'n.response_status = %s';
			$args[]  = sanitize_key( (string) $filters['status'] );
		}
		$limit  = max( 1, min( 500, (int) ( $filters['limit'] ?? 100 ) ) );
		$sql    = "SELECT n.id, n.form_4473_id, n.ntn, n.response_status, n.initiated_at,
                       n.default_proceed_eligible_at, n.transferred,
                       f.form_serial, f.transferee_last, f.transferee_first
                FROM {$t} n
                LEFT JOIN {$f} f ON f.id = n.form_4473_id
                WHERE " . implode( ' AND ', $where ) . ' ORDER BY n.initiated_at DESC LIMIT %d';
		$args[] = $limit;
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) ?: array();
	}

	public function pending_delayed_ready_for_default_proceed(): array {
		global $wpdb;
		$t   = $this->table( 'g2a_nics_transactions' );
		$now = $this->now();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE response_status = 'delayed' AND default_proceed_eligible_at IS NOT NULL AND default_proceed_eligible_at <= %s AND transferred = 0",
				$now
			),
			ARRAY_A
		) ?: array();
	}
}
