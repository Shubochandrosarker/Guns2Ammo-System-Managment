<?php

namespace G2A\POS\Database;

final class Form4473Repository extends Repository {

	public function create( array $data ): int {
		global $wpdb;
		$t = $this->table( 'g2a_form_4473' );

		$data['form_serial']     = $data['form_serial'] ?? self::generate_serial();
		$data['created_at']      = $this->now();
		$data['updated_at']      = $this->now();
		$data['retention_until'] = $data['retention_until'] ?? ( new \DateTimeImmutable( '+20 years' ) )->format( 'Y-m-d' );

		if ( isset( $data['section_b_answers'] ) && is_array( $data['section_b_answers'] ) ) {
			$data['section_b_answers'] = wp_json_encode( $data['section_b_answers'] );
		}
		if ( isset( $data['firearms'] ) && is_array( $data['firearms'] ) ) {
			$data['firearms'] = wp_json_encode( $data['firearms'] );
		}

		$prev              = $this->last_row_hash();
		$data['prev_hash'] = $prev;
		$data['row_hash']  = $this->hash_row( $data, $prev );

		$wpdb->insert( $t, $data );
		return (int) $wpdb->insert_id;
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$t   = $this->table( 'g2a_form_4473' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d LIMIT 1", $id ), ARRAY_A );
		return $row ? $this->expand( $row ) : null;
	}

	public function find_by_form_serial( string $serial ): ?array {
		global $wpdb;
		$t   = $this->table( 'g2a_form_4473' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE form_serial = %s LIMIT 1", $serial ), ARRAY_A );
		return $row ? $this->expand( $row ) : null;
	}

	public function find_by_pos_order( int $pos_order_id ): ?array {
		global $wpdb;
		$t   = $this->table( 'g2a_form_4473' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE pos_order_id = %d ORDER BY id DESC LIMIT 1", $pos_order_id ), ARRAY_A );
		return $row ? $this->expand( $row ) : null;
	}

	public function update( int $id, array $fields ): bool {
		global $wpdb;
		$t = $this->table( 'g2a_form_4473' );
		if ( isset( $fields['section_b_answers'] ) && is_array( $fields['section_b_answers'] ) ) {
			$fields['section_b_answers'] = wp_json_encode( $fields['section_b_answers'] );
		}
		if ( isset( $fields['firearms'] ) && is_array( $fields['firearms'] ) ) {
			$fields['firearms'] = wp_json_encode( $fields['firearms'] );
		}
		$fields['updated_at'] = $this->now();

		$existing = $this->find( $id );
		if ( ! $existing ) {
			return false;
		}
		$next                = array_merge( $existing, $fields );
		$prev                = $existing['row_hash'] ?? null;
		$fields['prev_hash'] = $prev;
		$fields['row_hash']  = $this->hash_row( $next, $prev );

		return (bool) $wpdb->update( $t, $fields, array( 'id' => $id ) );
	}

	/**
	 * Record a NICS bypass via qualifying state permit (18 USC 922(t)(3)).
	 * The form itself is still required and remains in_progress; this just
	 * stamps the exemption fields and removes the need for an NTN.
	 */
	public function record_ccw_exemption( int $id, array $ccw, int $actor_id ): bool {
		return $this->update(
			$id,
			array(
				'nics_exempt'                   => 1,
				'nics_exempt_reason'            => sanitize_text_field( (string) ( $ccw['reason'] ?? 'qualifying_state_permit' ) ),
				'nics_exempt_permit_type'       => sanitize_text_field( (string) ( $ccw['permit_type'] ?? '' ) ),
				'nics_exempt_permit_number'     => sanitize_text_field( (string) ( $ccw['permit_number'] ?? '' ) ),
				'nics_exempt_permit_state'      => strtoupper( substr( (string) ( $ccw['permit_state'] ?? '' ), 0, 2 ) ),
				'nics_exempt_permit_issued_on'  => $ccw['issued_on'] ?? null,
				'nics_exempt_permit_expires_on' => $ccw['expires_on'] ?? null,
				'nics_exempt_recorded_by'       => $actor_id,
				'nics_exempt_recorded_at'       => $this->now(),
			)
		);
	}

	public function clear_ccw_exemption( int $id ): bool {
		return $this->update(
			$id,
			array(
				'nics_exempt'                   => 0,
				'nics_exempt_reason'            => null,
				'nics_exempt_permit_type'       => null,
				'nics_exempt_permit_number'     => null,
				'nics_exempt_permit_state'      => null,
				'nics_exempt_permit_issued_on'  => null,
				'nics_exempt_permit_expires_on' => null,
				'nics_exempt_recorded_by'       => null,
				'nics_exempt_recorded_at'       => null,
			)
		);
	}

	public function list( array $filters = array() ): array {
		global $wpdb;
		$t     = $this->table( 'g2a_form_4473' );
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $filters['transaction_status'] ) ) {
			$where[] = 'transaction_status = %s';
			$args[]  = sanitize_key( $filters['transaction_status'] );
		}
		if ( ! empty( $filters['q'] ) ) {
			$like    = '%' . $wpdb->esc_like( (string) $filters['q'] ) . '%';
			$where[] = '(transferee_last LIKE %s OR transferee_first LIKE %s OR form_serial LIKE %s)';
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = $like;
		}
		$limit  = max( 1, min( 500, (int) ( $filters['limit'] ?? 50 ) ) );
		$sql    = "SELECT id, form_serial, transaction_status, pos_order_id, customer_id,
                       transferee_last, transferee_first, transferee_dob, transferee_state,
                       nics_response, nics_response_date, nics_exempt, transferred_at, created_at
                FROM {$t} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d';
		$args[] = $limit;
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) ?: array();
	}

	public function recent_handgun_count_for_buyer( string $last, string $first, string $dob, int $days = 30 ): int {
		global $wpdb;
		$t     = $this->table( 'g2a_form_4473' );
		$since = ( new \DateTimeImmutable( "-{$days} days" ) )->format( 'Y-m-d H:i:s' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT firearms FROM {$t}
             WHERE transferee_last = %s
               AND transferee_first = %s
               AND transferee_dob = %s
               AND transferred_at IS NOT NULL
               AND transferred_at >= %s",
				$last,
				$first,
				$dob,
				$since
			),
			ARRAY_A
		) ?: array();
		$count = 0;
		foreach ( $rows as $row ) {
			$arr = json_decode( (string) $row['firearms'], true ) ?: array();
			foreach ( $arr as $f ) {
				if ( strtolower( (string) ( $f['firearm_type'] ?? '' ) ) === 'handgun' ) {
					++$count;
				}
			}
		}
		return $count;
	}

	public function find_multi_long_gun_transfers_within( string $last, string $first, string $dob, int $business_days ): array {
		global $wpdb;
		$t     = $this->table( 'g2a_form_4473' );
		$since = ( new \DateTimeImmutable( "-{$business_days} days" ) )->format( 'Y-m-d H:i:s' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$t}
             WHERE transferee_last = %s
               AND transferee_first = %s
               AND transferee_dob = %s
               AND transferred_at IS NOT NULL
               AND transferred_at >= %s",
				$last,
				$first,
				$dob,
				$since
			),
			ARRAY_A
		) ?: array();
		return array_map( array( $this, 'expand' ), $rows );
	}

	private function expand( array $row ): array {
		if ( ! empty( $row['section_b_answers'] ) ) {
			$decoded                  = json_decode( (string) $row['section_b_answers'], true );
			$row['section_b_answers'] = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! empty( $row['firearms'] ) ) {
			$decoded         = json_decode( (string) $row['firearms'], true );
			$row['firearms'] = is_array( $decoded ) ? $decoded : array();
		}
		return $row;
	}

	public static function generate_serial(): string {
		return sprintf( 'G2A-4473-%s-%s', current_time( 'Ymd' ), strtoupper( wp_generate_password( 6, false, false ) ) );
	}

	private function last_row_hash(): ?string {
		global $wpdb;
		$t   = $this->table( 'g2a_form_4473' );
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
