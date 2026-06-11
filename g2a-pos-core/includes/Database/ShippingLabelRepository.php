<?php

namespace G2A\POS\Database;

final class ShippingLabelRepository extends Repository {

	public function record( array $data ): int {
		global $wpdb;
		$now = $this->now();
		$wpdb->insert(
			$this->table( 'g2a_shipping_labels' ),
			array(
				'carrier'                  => sanitize_key( $data['carrier'] ?? 'ups' ),
				'service'                  => sanitize_text_field( $data['service'] ?? '' ),
				'tracking_number'          => sanitize_text_field( $data['tracking_number'] ?? '' ),
				'label_url'                => esc_url_raw( $data['label_url'] ?? '' ),
				'label_format'             => sanitize_key( $data['label_format'] ?? 'pdf' ),
				'from_name'                => sanitize_text_field( $data['from_name'] ?? '' ),
				'from_address'             => sanitize_text_field( $data['from_address'] ?? '' ),
				'from_city'                => sanitize_text_field( $data['from_city'] ?? '' ),
				'from_state'               => sanitize_text_field( $data['from_state'] ?? '' ),
				'from_zip'                 => sanitize_text_field( $data['from_zip'] ?? '' ),
				'to_name'                  => sanitize_text_field( $data['to_name'] ?? '' ),
				'to_address'               => sanitize_text_field( $data['to_address'] ?? '' ),
				'to_city'                  => sanitize_text_field( $data['to_city'] ?? '' ),
				'to_state'                 => sanitize_text_field( $data['to_state'] ?? '' ),
				'to_zip'                   => sanitize_text_field( $data['to_zip'] ?? '' ),
				'weight_oz'                => isset( $data['weight_oz'] ) ? (float) $data['weight_oz'] : null,
				'length_in'                => isset( $data['length_in'] ) ? (float) $data['length_in'] : null,
				'width_in'                 => isset( $data['width_in'] ) ? (float) $data['width_in'] : null,
				'height_in'                => isset( $data['height_in'] ) ? (float) $data['height_in'] : null,
				'insured_value'            => isset( $data['insured_value'] ) ? (float) $data['insured_value'] : null,
				'adult_signature_required' => ! empty( $data['adult_signature_required'] ) ? 1 : 0,
				'requires_21'              => ! empty( $data['requires_21'] ) ? 1 : 0,
				'cost_amount'              => isset( $data['cost_amount'] ) ? (float) $data['cost_amount'] : null,
				'related_order_id'         => isset( $data['related_order_id'] ) ? (int) $data['related_order_id'] : null,
				'related_transfer_id'      => isset( $data['related_transfer_id'] ) ? (int) $data['related_transfer_id'] : null,
				'request_payload'          => ! empty( $data['request_payload'] ) ? wp_json_encode( $data['request_payload'] ) : null,
				'response_payload'         => ! empty( $data['response_payload'] ) ? wp_json_encode( $data['response_payload'] ) : null,
				'status'                   => sanitize_key( $data['status'] ?? 'created' ),
				'actor_id'                 => get_current_user_id(),
				'created_at'               => $now,
				'updated_at'               => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function void( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->update(
			$this->table( 'g2a_shipping_labels' ),
			array(
				'status'     => 'voided',
				'voided_at'  => $this->now(),
				'updated_at' => $this->now(),
			),
			array( 'id' => $id )
		);
	}

	public function list( int $limit = 100 ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_shipping_labels')} ORDER BY id DESC LIMIT %d",
				max( 1, min( 500, $limit ) )
			),
			ARRAY_A
		) ?: array();
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_shipping_labels')} WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		return $row ?: null;
	}
}
