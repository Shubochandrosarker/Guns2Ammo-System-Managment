<?php

namespace G2A\POS\Database;

final class FflPartnerRepository extends Repository {

	public function upsert( array $data ): int {
		global $wpdb;
		$t        = $this->table( 'g2a_ffl_partners' );
		$ffl      = $this->normalize_ffl_number( (string) $data['ffl_number'] );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$t} WHERE ffl_number = %s LIMIT 1", $ffl ), ARRAY_A );
		$now      = $this->now();
		$fields   = array(
			'ffl_number'      => $ffl,
			'business_name'   => sanitize_text_field( (string) $data['business_name'] ),
			'contact_name'    => isset( $data['contact_name'] ) ? sanitize_text_field( (string) $data['contact_name'] ) : null,
			'address'         => isset( $data['address'] ) ? sanitize_text_field( (string) $data['address'] ) : null,
			'city'            => isset( $data['city'] ) ? sanitize_text_field( (string) $data['city'] ) : null,
			'state'           => isset( $data['state'] ) ? strtoupper( substr( (string) $data['state'], 0, 2 ) ) : null,
			'zip'             => isset( $data['zip'] ) ? sanitize_text_field( (string) $data['zip'] ) : null,
			'phone'           => isset( $data['phone'] ) ? sanitize_text_field( (string) $data['phone'] ) : null,
			'email'           => isset( $data['email'] ) ? sanitize_email( (string) $data['email'] ) : null,
			'license_expires' => $data['license_expires'] ?? null,
			'verified_at'     => $data['verified_at'] ?? null,
			'status'          => sanitize_key( (string) ( $data['status'] ?? 'active' ) ),
			'notes'           => isset( $data['notes'] ) ? sanitize_textarea_field( (string) $data['notes'] ) : null,
			'updated_at'      => $now,
		);
		if ( $existing ) {
			$wpdb->update( $t, $fields, array( 'id' => (int) $existing['id'] ) );
			return (int) $existing['id'];
		}
		$fields['created_at'] = $now;
		$wpdb->insert( $t, $fields );
		return (int) $wpdb->insert_id;
	}

	public function find_by_ffl( string $ffl ): ?array {
		global $wpdb;
		$t   = $this->table( 'g2a_ffl_partners' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE ffl_number = %s LIMIT 1", $this->normalize_ffl_number( $ffl ) ), ARRAY_A );
		return $row ?: null;
	}

	public function search( string $q, int $limit = 20 ): array {
		global $wpdb;
		$t    = $this->table( 'g2a_ffl_partners' );
		$like = '%' . $wpdb->esc_like( $q ) . '%';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE business_name LIKE %s OR ffl_number LIKE %s ORDER BY business_name ASC LIMIT %d",
				$like,
				$like,
				$limit
			),
			ARRAY_A
		);
		return $rows ?: array();
	}

	public static function is_valid_ffl_number( string $ffl ): bool {
		// ATF FFL format: 1-23-LLLLLLLLL where 1=district, 23=region, ... 15 chars total typical
		$clean = preg_replace( '/[^0-9]/', '', $ffl );
		return $clean !== null && strlen( $clean ) >= 13 && strlen( $clean ) <= 16;
	}

	private function normalize_ffl_number( string $ffl ): string {
		return preg_replace( '/[^0-9A-Z]/i', '', strtoupper( $ffl ) ) ?: '';
	}
}
