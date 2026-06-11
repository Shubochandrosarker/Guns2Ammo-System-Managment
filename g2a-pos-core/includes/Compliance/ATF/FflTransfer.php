<?php

namespace G2A\POS\Compliance\ATF;

use G2A\POS\Database\AuditLogRepository;
use G2A\POS\Database\FflPartnerRepository;
use G2A\POS\Database\FflTransferRepository;
use G2A\POS\Webhooks\WebhookBus;

final class FflTransfer {

	public static function inbound_consignment( array $data ): array {
		$partners  = new FflPartnerRepository();
		$transfers = new FflTransferRepository();
		$audit     = new AuditLogRepository();

		foreach ( array( 'partner_ffl_number', 'manufacturer', 'model', 'serial_number', 'firearm_type', 'caliber' ) as $req ) {
			if ( empty( $data[ $req ] ) ) {
				return array( 'error' => sprintf( '%s is required.', $req ) );
			}
		}
		if ( ! FflPartnerRepository::is_valid_ffl_number( (string) $data['partner_ffl_number'] ) ) {
			return array( 'error' => 'partner_ffl_number does not match ATF FFL format.' );
		}

		$partner    = $partners->find_by_ffl( (string) $data['partner_ffl_number'] );
		$partner_id = $partner ? (int) $partner['id'] : null;

		$id = $transfers->create(
			array_merge(
				$data,
				array(
					'direction'      => 'inbound',
					'partner_ffl_id' => $partner_id,
					'status'         => 'received',
					'received_at'    => current_time( 'mysql' ),
				)
			)
		);

		// Record acquisition in bound book
		$bb = BoundBook::record_acquisition(
			array(
				'manufacturer'       => $data['manufacturer'],
				'model'              => $data['model'],
				'serial_number'      => $data['serial_number'],
				'firearm_type'       => $data['firearm_type'],
				'caliber'            => $data['caliber'],
				'location_id'        => $data['location_id'] ?? 1,
				'acq_date'           => current_time( 'Y-m-d' ),
				'acq_source_name'    => $partner['business_name'] ?? ( $data['partner_name'] ?? '' ),
				'acq_source_address' => $partner ? trim( ( $partner['address'] ?? '' ) . ', ' . ( $partner['city'] ?? '' ) . ' ' . ( $partner['state'] ?? '' ) . ' ' . ( $partner['zip'] ?? '' ) ) : '',
				'acq_source_ffl'     => (string) $data['partner_ffl_number'],
			)
		);
		if ( isset( $bb['id'] ) ) {
			$transfers->update( $id, array( 'bound_book_id' => $bb['id'] ) );
		}

		$audit->add( 'ffl.inbound', 'ffl_transfer', (string) $id, null, $data );
		WebhookBus::emit(
			'bound_book.acquired',
			array(
				'ffl_transfer_id' => $id,
				'serial_number'   => $data['serial_number'],
			)
		);

		return array(
			'id'         => $id,
			'transfer'   => $transfers->find( $id ),
			'bound_book' => $bb,
		);
	}

	public static function outbound_transfer( array $data ): array {
		$transfers = new FflTransferRepository();
		$audit     = new AuditLogRepository();

		foreach ( array( 'partner_ffl_number', 'serial_number' ) as $req ) {
			if ( empty( $data[ $req ] ) ) {
				return array( 'error' => sprintf( '%s is required.', $req ) );
			}
		}

		$id = $transfers->create(
			array_merge(
				$data,
				array(
					'direction'  => 'outbound',
					'status'     => 'shipped',
					'shipped_at' => current_time( 'mysql' ),
				)
			)
		);

		$bb = BoundBook::record_disposition(
			array(
				'serial_number'   => $data['serial_number'],
				'disp_date'       => current_time( 'Y-m-d' ),
				'disp_buyer_name' => $data['partner_name'] ?? '',
				'disp_buyer_ffl'  => (string) $data['partner_ffl_number'],
				'disp_method'     => 'ffl_transfer',
			)
		);

		$audit->add( 'ffl.outbound', 'ffl_transfer', (string) $id, null, $data );
		WebhookBus::emit(
			'bound_book.disposed',
			array(
				'ffl_transfer_id' => $id,
				'serial_number'   => $data['serial_number'],
			)
		);

		return array(
			'id'         => $id,
			'transfer'   => $transfers->find( $id ),
			'bound_book' => $bb,
		);
	}
}
