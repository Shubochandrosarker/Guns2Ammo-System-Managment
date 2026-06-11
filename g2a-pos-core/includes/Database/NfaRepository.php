<?php

namespace G2A\POS\Database;

/**
 * NFA Class III / Title II inventory: suppressors, SBRs, SBSs, machine guns,
 * AOWs, destructive devices. Each item travels with an ATF Form 3 / 4 / 5
 * plus tax-stamp status, kept in a ledger separate from the Title I bound
 * book (A&D record, 27 CFR 478.125) so an ACE audit isn't muddled.
 */
final class NfaRepository extends Repository {

	public function create_item( array $data ): int {
		global $wpdb;
		$now = $this->now();
		$wpdb->insert(
			$this->table( 'g2a_nfa_items' ),
			array(
				'nfa_class'         => sanitize_text_field( $data['nfa_class'] ?? 'suppressor' ),
				'manufacturer'      => sanitize_text_field( $data['manufacturer'] ?? '' ),
				'model'             => sanitize_text_field( $data['model'] ?? '' ),
				'serial_number'     => sanitize_text_field( $data['serial_number'] ?? '' ),
				'caliber'           => sanitize_text_field( $data['caliber'] ?? '' ),
				'barrel_length_in'  => isset( $data['barrel_length_in'] ) ? (float) $data['barrel_length_in'] : null,
				'overall_length_in' => isset( $data['overall_length_in'] ) ? (float) $data['overall_length_in'] : null,
				'two_stamp'         => ! empty( $data['two_stamp'] ) ? 1 : 0,
				'location_id'       => (int) ( $data['location_id'] ?? 1 ),
				'current_holder'    => sanitize_text_field( $data['current_holder'] ?? '' ),
				'holder_type'       => sanitize_text_field( $data['holder_type'] ?? 'dealer' ),
				'trust_id'          => isset( $data['trust_id'] ) ? (int) $data['trust_id'] : null,
				'status'            => sanitize_key( $data['status'] ?? 'in_inventory' ),
				'notes'             => sanitize_textarea_field( $data['notes'] ?? '' ),
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function find_item( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_nfa_items')} WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		$row['forms'] = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_nfa_forms')} WHERE nfa_item_id = %d ORDER BY id ASC",
				$id
			),
			ARRAY_A
		) ?: array();
		return $row;
	}

	public function list_items( array $filters = array() ): array {
		global $wpdb;
		$t     = $this->table( 'g2a_nfa_items' );
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $filters['nfa_class'] ) ) {
			$where[] = 'nfa_class = %s';
			$args[]  = sanitize_text_field( $filters['nfa_class'] );
		}
		if ( ! empty( $filters['status'] ) ) {
			$where[] = 'status = %s';
			$args[]  = sanitize_key( $filters['status'] );
		}
		$sql = "SELECT * FROM {$t} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT 200';
		return $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A ) ?: array();
	}

	public function file_form( int $item_id, array $data ): int {
		global $wpdb;
		$now = $this->now();
		$wpdb->insert(
			$this->table( 'g2a_nfa_forms' ),
			array(
				'nfa_item_id'        => $item_id,
				'form_type'          => sanitize_text_field( $data['form_type'] ?? 'Form 3' ),
				'form_serial'        => sanitize_text_field( $data['form_serial'] ?? '' ),
				'tax_stamp_amount'   => isset( $data['tax_stamp_amount'] ) ? (float) $data['tax_stamp_amount'] : null,
				'tax_paid_at'        => $data['tax_paid_at'] ?? null,
				'cle_notified_at'    => $data['cle_notified_at'] ?? null,
				'submitted_at'       => $data['submitted_at'] ?? $now,
				'status'             => sanitize_key( $data['status'] ?? 'pending' ),
				'transferee_name'    => sanitize_text_field( $data['transferee_name'] ?? '' ),
				'transferee_address' => sanitize_text_field( $data['transferee_address'] ?? '' ),
				'transferor_ffl'     => sanitize_text_field( $data['transferor_ffl'] ?? '' ),
				'transferee_ffl'     => sanitize_text_field( $data['transferee_ffl'] ?? '' ),
				'actor_id'           => get_current_user_id(),
				'notes'              => sanitize_textarea_field( $data['notes'] ?? '' ),
				'created_at'         => $now,
				'updated_at'         => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function update_form( int $form_id, array $fields ): bool {
		global $wpdb;
		$allowed = array( 'status', 'approved_at', 'disapproved_at', 'stamp_pdf_url', 'tax_paid_at', 'cle_notified_at', 'notes' );
		$set     = array();
		foreach ( $allowed as $k ) {
			if ( array_key_exists( $k, $fields ) ) {
				$set[ $k ] = $fields[ $k ];
			}
		}
		if ( ! $set ) {
			return false;
		}
		$set['updated_at'] = $this->now();
		return (bool) $wpdb->update( $this->table( 'g2a_nfa_forms' ), $set, array( 'id' => $form_id ) );
	}

	public function create_trust( array $data ): int {
		global $wpdb;
		$now = $this->now();
		$wpdb->insert(
			$this->table( 'g2a_nfa_trusts' ),
			array(
				'trust_name'    => sanitize_text_field( $data['trust_name'] ?? '' ),
				'trust_type'    => sanitize_text_field( $data['trust_type'] ?? 'gun_trust' ),
				'grantor_name'  => sanitize_text_field( $data['grantor_name'] ?? '' ),
				'grantor_phone' => sanitize_text_field( $data['grantor_phone'] ?? '' ),
				'grantor_email' => sanitize_email( $data['grantor_email'] ?? '' ),
				'trust_address' => sanitize_text_field( $data['trust_address'] ?? '' ),
				'trust_state'   => substr( sanitize_text_field( $data['trust_state'] ?? '' ), 0, 2 ),
				'trustees'      => ! empty( $data['trustees'] ) ? wp_json_encode( $data['trustees'] ) : null,
				'documents'     => ! empty( $data['documents'] ) ? wp_json_encode( $data['documents'] ) : null,
				'customer_id'   => isset( $data['customer_id'] ) ? (int) $data['customer_id'] : null,
				'status'        => sanitize_key( $data['status'] ?? 'active' ),
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function list_trusts(): array {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT * FROM {$this->table('g2a_nfa_trusts')} ORDER BY trust_name ASC",
			ARRAY_A
		) ?: array();
	}
}
