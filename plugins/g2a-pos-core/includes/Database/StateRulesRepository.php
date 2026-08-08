<?php

namespace G2A\POS\Database;

final class StateRulesRepository extends Repository {

	public function find( string $state_code ): ?array {
		global $wpdb;
		$t   = $this->table( 'g2a_state_compliance_rules' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE state_code = %s LIMIT 1", strtoupper( $state_code ) ), ARRAY_A );
		return $row ?: null;
	}

	public function all(): array {
		global $wpdb;
		$t = $this->table( 'g2a_state_compliance_rules' );
		return $wpdb->get_results( "SELECT * FROM {$t} ORDER BY state_code ASC", ARRAY_A ) ?: array();
	}

	public function update( string $state_code, array $fields ): bool {
		global $wpdb;
		$t                    = $this->table( 'g2a_state_compliance_rules' );
		$fields['updated_at'] = $this->now();
		if ( isset( $fields['rules_payload'] ) && is_array( $fields['rules_payload'] ) ) {
			$fields['rules_payload'] = wp_json_encode( $fields['rules_payload'] );
		}
		return (bool) $wpdb->update( $t, $fields, array( 'state_code' => strtoupper( $state_code ) ) );
	}
}
