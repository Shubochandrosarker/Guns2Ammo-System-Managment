<?php

namespace G2A\POS\Compliance\ATF;

use G2A\POS\Database\AuditLogRepository;
use G2A\POS\Database\BoundBookRepository;

final class BoundBook {

	public static function record_acquisition( array $data ): array {
		$repo  = new BoundBookRepository();
		$audit = new AuditLogRepository();

		foreach ( array( 'manufacturer', 'model', 'serial_number', 'firearm_type', 'caliber' ) as $req ) {
			if ( empty( $data[ $req ] ) ) {
				return array( 'error' => sprintf( '%s is required for A&D acquisition entry.', $req ) );
			}
		}

		$existing = $repo->find_by_serial( (string) $data['serial_number'] );
		if ( $existing && (string) $existing['entry_type'] === 'acquisition' && empty( $existing['disp_date'] ) ) {
			return array(
				'error' => 'Serial already exists in bound book without disposition.',
				'entry' => $existing,
			);
		}

		$id = $repo->insert(
			array(
				'entry_type'         => 'acquisition',
				'manufacturer'       => sanitize_text_field( (string) $data['manufacturer'] ),
				'importer'           => isset( $data['importer'] ) ? sanitize_text_field( (string) $data['importer'] ) : null,
				'model'              => sanitize_text_field( (string) $data['model'] ),
				'serial_number'      => sanitize_text_field( (string) $data['serial_number'] ),
				'firearm_type'       => sanitize_key( (string) $data['firearm_type'] ),
				'caliber'            => sanitize_text_field( (string) $data['caliber'] ),
				'product_id'         => isset( $data['product_id'] ) ? (int) $data['product_id'] : null,
				'location_id'        => (int) ( $data['location_id'] ?? 1 ),
				'acq_date'           => $data['acq_date'] ?? current_time( 'Y-m-d' ),
				'acq_source_name'    => sanitize_text_field( (string) ( $data['acq_source_name'] ?? '' ) ),
				'acq_source_address' => sanitize_text_field( (string) ( $data['acq_source_address'] ?? '' ) ),
				'acq_source_ffl'     => sanitize_text_field( (string) ( $data['acq_source_ffl'] ?? '' ) ),
				'actor_id'           => get_current_user_id(),
				'notes'              => isset( $data['notes'] ) ? sanitize_textarea_field( (string) $data['notes'] ) : null,
			)
		);

		$audit->add( 'atf.bound_book.acquire', 'atf_bound_book', (string) $id, null, $data );
		return array(
			'id'    => $id,
			'entry' => $repo->find( $id ),
		);
	}

	public static function record_disposition( array $data ): array {
		$repo  = new BoundBookRepository();
		$audit = new AuditLogRepository();

		$serial = sanitize_text_field( (string) ( $data['serial_number'] ?? '' ) );
		if ( $serial === '' ) {
			return array( 'error' => 'serial_number is required.' );
		}

		$entry = $repo->find_by_serial( $serial );
		if ( ! $entry ) {
			return array( 'error' => 'No acquisition entry found for serial.' );
		}
		if ( ! empty( $entry['disp_date'] ) ) {
			return array(
				'error' => 'Disposition already recorded for this serial.',
				'entry' => $entry,
			);
		}

		$ok = $repo->record_disposition(
			(int) $entry['id'],
			array(
				'disp_date'          => $data['disp_date'] ?? current_time( 'Y-m-d' ),
				'disp_buyer_name'    => sanitize_text_field( (string) ( $data['disp_buyer_name'] ?? '' ) ),
				'disp_buyer_address' => sanitize_text_field( (string) ( $data['disp_buyer_address'] ?? '' ) ),
				'disp_buyer_ffl'     => sanitize_text_field( (string) ( $data['disp_buyer_ffl'] ?? '' ) ),
				'disp_form4473_id'   => isset( $data['disp_form4473_id'] ) ? (int) $data['disp_form4473_id'] : null,
				'disp_pos_order_id'  => isset( $data['disp_pos_order_id'] ) ? (int) $data['disp_pos_order_id'] : null,
				'disp_method'        => sanitize_key( (string) ( $data['disp_method'] ?? 'over_counter' ) ),
			)
		);

		if ( ! $ok ) {
			return array( 'error' => 'Failed to record disposition.' );
		}

		$audit->add( 'atf.bound_book.dispose', 'atf_bound_book', (string) $entry['id'], $entry, $data );
		return array(
			'id'    => (int) $entry['id'],
			'entry' => $repo->find( (int) $entry['id'] ),
		);
	}
}
