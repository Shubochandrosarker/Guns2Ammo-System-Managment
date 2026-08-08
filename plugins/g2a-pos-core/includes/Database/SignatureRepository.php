<?php

namespace G2A\POS\Database;

/**
 * Tamper-evident signature ledger. Each row hashes the prior row
 * (prev_hash → row_hash) so missing or altered signatures are detectable.
 */
final class SignatureRepository extends Repository {

	public function record( array $data ): int {
		global $wpdb;
		$now        = $this->now();
		$image      = (string) ( $data['image_data'] ?? '' );
		$image_hash = hash( 'sha256', $image );
		$prev_hash  = $wpdb->get_var(
			"SELECT row_hash FROM {$this->table('g2a_signatures')} ORDER BY id DESC LIMIT 1"
		);

		$payload             = array(
			'subject_type' => sanitize_key( $data['subject_type'] ?? '' ),
			'subject_id'   => (int) ( $data['subject_id'] ?? 0 ),
			'role'         => sanitize_key( $data['role'] ?? 'signer' ),
			'signer_name'  => sanitize_text_field( $data['signer_name'] ?? '' ),
			'image_data'   => $image,
			'image_hash'   => $image_hash,
			'ip_address'   => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ),
			'user_agent'   => sanitize_text_field( substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 255 ) ),
			'signed_at'    => $now,
			'actor_id'     => get_current_user_id() ?: null,
			'prev_hash'    => $prev_hash,
			'created_at'   => $now,
		);
		$row_hash            = hash( 'sha256', wp_json_encode( $payload + array( '_prev' => (string) $prev_hash ) ) );
		$payload['row_hash'] = $row_hash;

		$wpdb->insert( $this->table( 'g2a_signatures' ), $payload );
		return (int) $wpdb->insert_id;
	}

	public function for_subject( string $subject_type, int $subject_id ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, role, signer_name, signed_at, ip_address, row_hash
             FROM {$this->table('g2a_signatures')}
             WHERE subject_type = %s AND subject_id = %d
             ORDER BY id ASC",
				sanitize_key( $subject_type ),
				$subject_id
			),
			ARRAY_A
		) ?: array();
	}

	public function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_signatures')} WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		return $row ?: null;
	}
}
