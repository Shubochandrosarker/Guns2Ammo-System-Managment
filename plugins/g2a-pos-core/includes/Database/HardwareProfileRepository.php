<?php

namespace G2A\POS\Database;

final class HardwareProfileRepository extends Repository {

	public function upsert( array $data ): int {
		global $wpdb;
		$reg = sanitize_text_field( $data['register_code'] ?? '' );
		$loc = (int) ( $data['location_id'] ?? 1 );
		if ( ! $reg ) {
			return 0;
		}
		$now = $this->now();

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->table('g2a_hardware_profiles')}
             WHERE register_code = %s AND location_id = %d",
				$reg,
				$loc
			)
		);
		$payload  = array(
			'register_code'             => $reg,
			'location_id'               => $loc,
			'receipt_printer_driver'    => sanitize_text_field( $data['receipt_printer_driver'] ?? 'escpos_network' ),
			'receipt_printer_endpoint'  => sanitize_text_field( $data['receipt_printer_endpoint'] ?? '' ),
			'cash_drawer_kick_code'     => sanitize_text_field( $data['cash_drawer_kick_code'] ?? '1B7000FA' ),
			'cash_drawer_via_printer'   => ! empty( $data['cash_drawer_via_printer'] ) ? 1 : 0,
			'barcode_scanner_profile'   => sanitize_text_field( $data['barcode_scanner_profile'] ?? 'usb_hid' ),
			'customer_display_driver'   => sanitize_text_field( $data['customer_display_driver'] ?? '' ),
			'customer_display_endpoint' => sanitize_text_field( $data['customer_display_endpoint'] ?? '' ),
			'signature_pad_driver'      => sanitize_text_field( $data['signature_pad_driver'] ?? 'web_canvas' ),
			'signature_pad_endpoint'    => sanitize_text_field( $data['signature_pad_endpoint'] ?? '' ),
			'id_scanner_driver'         => sanitize_text_field( $data['id_scanner_driver'] ?? 'aamva_pdf417_keyboard' ),
			'id_scanner_endpoint'       => sanitize_text_field( $data['id_scanner_endpoint'] ?? '' ),
			'settings'                  => ! empty( $data['settings'] ) ? wp_json_encode( $data['settings'] ) : null,
			'updated_by'                => get_current_user_id() ?: null,
			'updated_at'                => $now,
		);
		if ( $existing ) {
			$wpdb->update( $this->table( 'g2a_hardware_profiles' ), $payload, array( 'id' => (int) $existing ) );
			return (int) $existing;
		}
		$payload['created_at'] = $now;
		$wpdb->insert( $this->table( 'g2a_hardware_profiles' ), $payload );
		return (int) $wpdb->insert_id;
	}

	public function find( string $register_code, int $location_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_hardware_profiles')}
             WHERE register_code = %s AND location_id = %d",
				sanitize_text_field( $register_code ),
				$location_id
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	public function list(): array {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT * FROM {$this->table('g2a_hardware_profiles')} ORDER BY location_id, register_code",
			ARRAY_A
		) ?: array();
	}
}
