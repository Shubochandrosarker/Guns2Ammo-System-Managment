<?php

namespace G2A\POS\Database;

final class RangeWaiverRepository extends Repository {

	public function upsertFromExternal( array $data ): array {
		global $wpdb;
		$t   = $this->table( 'g2a_range_waivers' );
		$now = $this->now();

		$source    = sanitize_key( (string) ( $data['source'] ?? 'otterwaiver' ) );
		$uniqueRef = (string) ( $data['unique_ref'] ?? '' );

		$existingId = null;
		if ( $uniqueRef !== '' ) {
			$existingId = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$t} WHERE source=%s AND unique_ref=%s",
					$source,
					$uniqueRef
				)
			);
		}
		// Fallback dedupe: same person + same waiver date.
		if ( ! $existingId && ! empty( $data['waiver_date'] ) && ! empty( $data['email'] ) ) {
			$existingId = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$t} WHERE email=%s AND waiver_date=%s LIMIT 1",
					(string) $data['email'],
					(string) $data['waiver_date']
				)
			);
		}

		$payload = array(
			'source'                  => $source,
			'unique_ref'              => $uniqueRef !== '' ? $uniqueRef : null,
			'first_name'              => (string) ( $data['first_name'] ?? '' ),
			'last_name'               => (string) ( $data['last_name'] ?? '' ),
			'dob'                     => $data['dob'] ?? null,
			'age'                     => isset( $data['age'] ) ? (int) $data['age'] : null,
			'email'                   => $data['email'] ?? null,
			'email_optin'             => (int) (bool) ( $data['email_optin'] ?? false ),
			'phone'                   => $data['phone'] ?? null,
			'phone_optin'             => (int) (bool) ( $data['phone_optin'] ?? false ),
			'country'                 => $data['country'] ?? null,
			'city'                    => $data['city'] ?? null,
			'state'                   => $data['state'] ?? null,
			'street'                  => $data['street'] ?? null,
			'postal_code'             => $data['postal_code'] ?? null,
			'participant_type'        => (string) ( $data['participant_type'] ?? 'Adult' ),
			'waiver_date'             => $data['waiver_date'] ?? null,
			'title'                   => $data['title'] ?? null,
			'flag'                    => $data['flag'] ?? null,
			'signed_on'               => $data['signed_on'] ?? null,
			'document_url'            => $data['document_url'] ?? null,
			'certificate_url'         => $data['certificate_url'] ?? null,
			'emergency_contact_name'  => $data['emergency_contact_name'] ?? null,
			'emergency_contact_phone' => $data['emergency_contact_phone'] ?? null,
			'minor_name'              => $data['minor_name'] ?? null,
			'minor_age'               => isset( $data['minor_age'] ) ? (int) $data['minor_age'] : null,
			'questions'               => isset( $data['questions'] ) ? wp_json_encode( $data['questions'] ) : null,
			'check_ins'               => isset( $data['check_ins'] ) ? wp_json_encode( $data['check_ins'] ) : null,
			'check_in_count'          => (int) ( $data['check_in_count'] ?? ( is_array( $data['check_ins'] ?? null ) ? count( $data['check_ins'] ) : 0 ) ),
			'second_signer_name'      => $data['second_signer_name'] ?? null,
			'second_signer_email'     => $data['second_signer_email'] ?? null,
			'countersign_status'      => $data['countersign_status'] ?? null,
			'status'                  => $data['status'] ?? 'active',
			'valid_until'             => $data['valid_until'] ?? null,
			'updated_at'              => $now,
		);

		if ( $existingId ) {
			$wpdb->update( $t, $payload, array( 'id' => (int) $existingId ) );
			return array(
				'id'     => (int) $existingId,
				'action' => 'updated',
			);
		}
		$payload['created_at'] = $now;
		$wpdb->insert( $t, $payload );
		return array(
			'id'     => (int) $wpdb->insert_id,
			'action' => 'created',
		);
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_range_waivers')} WHERE id=%d",
				$id
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	public function search( array $params ): array {
		global $wpdb;
		$t     = $this->table( 'g2a_range_waivers' );
		$where = array( '1=1' );
		$args  = array();

		if ( ! empty( $params['q'] ) ) {
			$like    = '%' . $wpdb->esc_like( (string) $params['q'] ) . '%';
			$where[] = '(first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR phone LIKE %s)';
			array_push( $args, $like, $like, $like, $like );
		}
		if ( ! empty( $params['email'] ) ) {
			$where[] = 'email=%s';
			$args[]  = (string) $params['email'];
		}
		if ( ! empty( $params['phone'] ) ) {
			$where[] = 'phone=%s';
			$args[]  = (string) $params['phone'];
		}
		if ( ! empty( $params['from'] ) ) {
			$where[] = 'waiver_date >= %s';
			$args[]  = (string) $params['from'];
		}
		if ( ! empty( $params['to'] ) ) {
			$where[] = 'waiver_date <= %s';
			$args[]  = (string) $params['to'];
		}

		$limit  = max( 1, min( 500, (int) ( $params['limit'] ?? 50 ) ) );
		$offset = max( 0, (int) ( $params['offset'] ?? 0 ) );
		$sql    = "SELECT * FROM {$t} WHERE " . implode( ' AND ', $where ) . ' ORDER BY waiver_date DESC, id DESC LIMIT %d OFFSET %d';
		array_push( $args, $limit, $offset );
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) ?: array();
	}

	public function findLatestForCustomer( ?string $email, ?string $phone ): ?array {
		global $wpdb;
		if ( ! $email && ! $phone ) {
			return null;
		}
		$t = $this->table( 'g2a_range_waivers' );
		if ( $email && $phone ) {
			$sql = $wpdb->prepare(
				"SELECT * FROM {$t} WHERE (email=%s OR phone=%s) ORDER BY waiver_date DESC, id DESC LIMIT 1",
				$email,
				$phone
			);
		} elseif ( $email ) {
			$sql = $wpdb->prepare( "SELECT * FROM {$t} WHERE email=%s ORDER BY waiver_date DESC, id DESC LIMIT 1", $email );
		} else {
			$sql = $wpdb->prepare( "SELECT * FROM {$t} WHERE phone=%s ORDER BY waiver_date DESC, id DESC LIMIT 1", $phone );
		}
		$row = $wpdb->get_row( $sql, ARRAY_A );
		return $row ?: null;
	}

	public function recordCheckin( int $waiverId, ?int $registerSessionId = null, ?string $notes = null ): int {
		global $wpdb;
		$now = $this->now();
		$wpdb->insert(
			$this->table( 'g2a_range_checkins' ),
			array(
				'waiver_id'           => $waiverId,
				'checked_in_at'       => $now,
				'register_session_id' => $registerSessionId,
				'actor_id'            => get_current_user_id() ?: null,
				'notes'               => $notes,
				'created_at'          => $now,
			)
		);
		$id = (int) $wpdb->insert_id;

		// Increment count + append to JSON history (cap at 10 visible like OtterWaiver does).
		$w = $this->find( $waiverId );
		if ( $w ) {
			$history = json_decode( (string) ( $w['check_ins'] ?? '[]' ), true );
			if ( ! is_array( $history ) ) {
				$history = array();
			}
			$history[] = $now;
			$wpdb->update(
				$this->table( 'g2a_range_waivers' ),
				array(
					'check_ins'      => wp_json_encode( $history ),
					'check_in_count' => count( $history ),
					'updated_at'     => $now,
				),
				array( 'id' => $waiverId )
			);
		}
		return $id;
	}

	public function attachCertificate( int $waiverId, int $attachmentId ): void {
		global $wpdb;
		$wpdb->update(
			$this->table( 'g2a_range_waivers' ),
			array(
				'certificate_attachment_id' => $attachmentId,
				'updated_at'                => $this->now(),
			),
			array( 'id' => $waiverId )
		);
	}
}
