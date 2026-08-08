<?php

namespace G2A\POS\Database;

use G2A\POS\Security\Crypto;

final class TransfereeRepository extends Repository {

	public static function blind_index( string $last, string $first, string $dob ): string {
		return Crypto::blind_index(
			strtolower( trim( $last ) ) . '|' . strtolower( trim( $first ) ) . '|' . trim( $dob ),
			'transferee'
		);
	}

	public function upsert( array $data ): int {
		global $wpdb;
		$t = $this->table( 'g2a_transferees' );

		$idx       = self::blind_index( (string) $data['last'], (string) $data['first'], (string) $data['dob'] );
		$display   = sprintf( '%s, %s', $data['last'], $data['first'] );
		$pii       = wp_json_encode(
			array(
				'last'            => $data['last'],
				'first'           => $data['first'],
				'middle'          => $data['middle'] ?? null,
				'dob'             => $data['dob'],
				'address'         => $data['address'] ?? null,
				'license_number'  => $data['license_number'] ?? null,
				'license_state'   => $data['license_state'] ?? null,
				'license_expires' => $data['license_expires'] ?? null,
				'phone'           => $data['phone'] ?? null,
				'email'           => $data['email'] ?? null,
			)
		);
		$encrypted = Crypto::encrypt( (string) $pii );
		$scan      = isset( $data['id_scan_payload'] ) ? Crypto::encrypt( (string) $data['id_scan_payload'] ) : null;

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$t} WHERE blind_index = %s LIMIT 1",
				$idx
			),
			ARRAY_A
		);

		$now = $this->now();
		if ( $existing ) {
			$wpdb->update(
				$t,
				array(
					'display_name'     => $display,
					'transferee_state' => isset( $data['address']['state'] ) ? strtoupper( substr( (string) $data['address']['state'], 0, 2 ) ) : null,
					'pii_payload'      => $encrypted,
					'id_scan_payload'  => $scan,
					'last_seen_at'     => $now,
					'updated_at'       => $now,
				),
				array( 'id' => (int) $existing['id'] )
			);
			return (int) $existing['id'];
		}

		$wpdb->insert(
			$t,
			array(
				'blind_index'      => $idx,
				'display_name'     => $display,
				'customer_id'      => isset( $data['customer_id'] ) ? (int) $data['customer_id'] : null,
				'transferee_state' => isset( $data['address']['state'] ) ? strtoupper( substr( (string) $data['address']['state'], 0, 2 ) ) : null,
				'pii_payload'      => $encrypted,
				'id_scan_payload'  => $scan,
				'last_seen_at'     => $now,
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$t   = $this->table( 'g2a_transferees' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d LIMIT 1", $id ), ARRAY_A );
		return $row ? $this->expand( $row ) : null;
	}

	public function find_by_identity( string $last, string $first, string $dob ): ?array {
		global $wpdb;
		$t   = $this->table( 'g2a_transferees' );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE blind_index = %s LIMIT 1",
				self::blind_index( $last, $first, $dob )
			),
			ARRAY_A
		);
		return $row ? $this->expand( $row ) : null;
	}

	public function search_display( string $q, int $limit = 20 ): array {
		global $wpdb;
		$t    = $this->table( 'g2a_transferees' );
		$like = '%' . $wpdb->esc_like( $q ) . '%';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, display_name, transferee_state, last_seen_at FROM {$t} WHERE display_name LIKE %s ORDER BY last_seen_at DESC LIMIT %d",
				$like,
				$limit
			),
			ARRAY_A
		);
		return $rows ?: array();
	}

	private function expand( array $row ): array {
		if ( ! empty( $row['pii_payload'] ) ) {
			$plain      = Crypto::decrypt( (string) $row['pii_payload'] );
			$row['pii'] = $plain ? ( json_decode( $plain, true ) ?: array() ) : array();
			unset( $row['pii_payload'] );
		}
		unset( $row['id_scan_payload'] );
		return $row;
	}
}
