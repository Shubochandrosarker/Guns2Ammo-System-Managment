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
