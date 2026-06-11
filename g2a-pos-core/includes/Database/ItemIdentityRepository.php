<?php

namespace G2A\POS\Database;

final class ItemIdentityRepository extends Repository {

	public function create( array $data ): int {
		global $wpdb;
		$now = $this->now();
		$wpdb->insert(
			$this->table( 'g2a_item_identities' ),
			array(
				'canonical_code'    => $data['canonical_code'] ?? $this->generate_code(),
				'item_type'         => sanitize_text_field( $data['item_type'] ?? 'firearm' ),
				'firearm_type'      => sanitize_text_field( $data['firearm_type'] ?? '' ),
				'manufacturer'      => sanitize_text_field( $data['manufacturer'] ?? '' ),
				'model'             => sanitize_text_field( $data['model'] ?? '' ),
				'family'            => sanitize_text_field( $data['family'] ?? '' ),
				'caliber'           => sanitize_text_field( $data['caliber'] ?? '' ),
				'barrel_length_in'  => isset( $data['barrel_length_in'] ) ? (float) $data['barrel_length_in'] : null,
				'capacity_rounds'   => isset( $data['capacity_rounds'] ) ? (int) $data['capacity_rounds'] : null,
				'country_of_origin' => sanitize_text_field( $data['country_of_origin'] ?? '' ),
				'msrp'              => isset( $data['msrp'] ) ? (float) $data['msrp'] : null,
				'default_image_url' => esc_url_raw( $data['default_image_url'] ?? '' ),
				'description'       => sanitize_textarea_field( $data['description'] ?? '' ),
				'tags'              => sanitize_text_field( $data['tags'] ?? '' ),
				'attributes'        => ! empty( $data['attributes'] ) ? wp_json_encode( $data['attributes'] ) : null,
				'status'            => sanitize_key( $data['status'] ?? 'active' ),
				'review_required'   => ! empty( $data['review_required'] ) ? 1 : 0,
				'created_by'        => get_current_user_id() ?: null,
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_item_identities')} WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	public function update( int $id, array $fields ): bool {
		global $wpdb;
		$allowed = array(
			'firearm_type',
			'manufacturer',
			'model',
			'family',
			'caliber',
			'barrel_length_in',
			'capacity_rounds',
			'country_of_origin',
			'msrp',
			'default_image_url',
			'description',
			'tags',
			'attributes',
			'status',
			'review_required',
		);
		$set     = array();
		foreach ( $allowed as $k ) {
			if ( array_key_exists( $k, $fields ) ) {
				$set[ $k ] = $k === 'attributes' ? wp_json_encode( $fields[ $k ] ) : $fields[ $k ];
			}
		}
		if ( ! $set ) {
			return false;
		}
		$set['updated_at'] = $this->now();
		return (bool) $wpdb->update( $this->table( 'g2a_item_identities' ), $set, array( 'id' => $id ) );
	}

	public function search( array $filters = array() ): array {
		global $wpdb;
		$t     = $this->table( 'g2a_item_identities' );
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $filters['q'] ) ) {
			$like    = '%' . $wpdb->esc_like( $filters['q'] ) . '%';
			$where[] = '(manufacturer LIKE %s OR model LIKE %s OR canonical_code LIKE %s OR caliber LIKE %s)';
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = $like;
		}
		if ( ! empty( $filters['manufacturer'] ) ) {
			$where[] = 'manufacturer = %s';
			$args[]  = sanitize_text_field( $filters['manufacturer'] );
		}
		if ( ! empty( $filters['item_type'] ) ) {
			$where[] = 'item_type = %s';
			$args[]  = sanitize_text_field( $filters['item_type'] );
		}
		if ( ! empty( $filters['review_required'] ) ) {
			$where[] = 'review_required = 1';
		}
		$limit  = max( 1, min( 200, (int) ( $filters['limit'] ?? 50 ) ) );
		$sql    = "SELECT * FROM {$t} WHERE " . implode( ' AND ', $where ) .
				' ORDER BY id DESC LIMIT %d';
		$args[] = $limit;
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) ?: array();
	}

	/** Coarse candidate pull for the matcher — anything sharing manufacturer or UPC. */
	public function candidates( array $needle, int $cap = 50 ): array {
		global $wpdb;
		$t     = $this->table( 'g2a_item_identities' );
		$where = array();
		$args  = array();
		if ( ! empty( $needle['manufacturer'] ) ) {
			$where[] = 'manufacturer = %s';
			$args[]  = sanitize_text_field( $needle['manufacturer'] );
		}
		if ( ! $where ) {
			return array();
		}
		$sql    = "SELECT * FROM {$t} WHERE " . implode( ' OR ', $where ) . ' LIMIT %d';
		$args[] = $cap;
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) ?: array();
	}

	public function link( int $identity_id, string $source_type, string $source_ref, array $opts = array() ): int {
		global $wpdb;
		$now           = $this->now();
		$wholesaler_id = isset( $opts['wholesaler_id'] ) ? (int) $opts['wholesaler_id'] : null;
		$existing      = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->table('g2a_item_identity_links')}
             WHERE source_type = %s AND source_ref = %s AND wholesaler_id <=> %d",
				sanitize_key( $source_type ),
				sanitize_text_field( $source_ref ),
				$wholesaler_id
			)
		);
		if ( $existing ) {
			$wpdb->update(
				$this->table( 'g2a_item_identity_links' ),
				array(
					'identity_id'  => $identity_id,
					'last_seen_at' => $now,
					'confidence'   => (float) ( $opts['confidence'] ?? 1.0 ),
					'matched_by'   => sanitize_text_field( $opts['matched_by'] ?? 'manual' ),
				),
				array( 'id' => (int) $existing )
			);
			return (int) $existing;
		}
		$wpdb->insert(
			$this->table( 'g2a_item_identity_links' ),
			array(
				'identity_id'   => $identity_id,
				'source_type'   => sanitize_key( $source_type ),
				'source_ref'    => sanitize_text_field( $source_ref ),
				'wholesaler_id' => $wholesaler_id,
				'confidence'    => (float) ( $opts['confidence'] ?? 1.0 ),
				'matched_by'    => sanitize_text_field( $opts['matched_by'] ?? 'manual' ),
				'metadata'      => ! empty( $opts['metadata'] ) ? wp_json_encode( $opts['metadata'] ) : null,
				'first_seen_at' => $now,
				'last_seen_at'  => $now,
				'created_at'    => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function links_for( int $identity_id ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_item_identity_links')}
             WHERE identity_id = %d ORDER BY confidence DESC, id ASC",
				$identity_id
			),
			ARRAY_A
		) ?: array();
	}

	public function find_by_source( string $source_type, string $source_ref, ?int $wholesaler_id = null ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT i.* FROM {$this->table('g2a_item_identities')} i
             JOIN {$this->table('g2a_item_identity_links')} l ON l.identity_id = i.id
             WHERE l.source_type = %s AND l.source_ref = %s AND l.wholesaler_id <=> %d
             LIMIT 1",
				sanitize_key( $source_type ),
				sanitize_text_field( $source_ref ),
				$wholesaler_id
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	public function merge( int $from_id, int $into_id, string $reason ): bool {
		global $wpdb;
		if ( $from_id === $into_id || ! $this->find( $from_id ) || ! $this->find( $into_id ) ) {
			return false;
		}
		$now = $this->now();
		// Re-point every link.
		$wpdb->update(
			$this->table( 'g2a_item_identity_links' ),
			array(
				'identity_id'  => $into_id,
				'last_seen_at' => $now,
			),
			array( 'identity_id' => $from_id )
		);
		// Audit the merge.
		$wpdb->insert(
			$this->table( 'g2a_item_identity_merges' ),
			array(
				'from_identity_id' => $from_id,
				'into_identity_id' => $into_id,
				'reason'           => sanitize_text_field( $reason ),
				'actor_id'         => get_current_user_id(),
				'created_at'       => $now,
			)
		);
		// Tombstone the source.
		$wpdb->update(
			$this->table( 'g2a_item_identities' ),
			array(
				'status'     => 'merged',
				'updated_at' => $now,
			),
			array( 'id' => $from_id )
		);
		return true;
	}

	private function generate_code(): string {
		return 'ITEM-' . strtoupper( wp_generate_password( 8, false, false ) );
	}
}
