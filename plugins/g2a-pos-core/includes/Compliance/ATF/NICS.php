<?php

namespace G2A\POS\Compliance\ATF;

use G2A\POS\Database\AuditLogRepository;
use G2A\POS\Database\Form4473Repository;
use G2A\POS\Database\NICSRepository;

final class NICS {

	public static function initiate( int $form_4473_id, array $payload = array() ): array {
		$forms = new Form4473Repository();
		$nics  = new NICSRepository();
		$audit = new AuditLogRepository();

		$form = $forms->find( $form_4473_id );
		if ( ! $form ) {
			return array( 'error' => '4473 not found.' ); }
		if ( ! $form['transferee_certification_signed'] ) {
			return array( 'error' => 'Transferee must sign 4473 Section A/B before NICS initiation.' );
		}
		if ( (int) ( $form['nics_exempt'] ?? 0 ) === 1 ) {
			return array(
				'error'       => 'NICS check is not required for this 4473 — a qualifying state permit exemption has been recorded under 18 USC 922(t)(3). Clear the exemption first if you intended to run NICS.',
				'nics_exempt' => true,
				'permit'      => array(
					'state'  => $form['nics_exempt_permit_state'] ?? null,
					'type'   => $form['nics_exempt_permit_type'] ?? null,
					'number' => $form['nics_exempt_permit_number'] ?? null,
				),
			);
		}

		$method = sanitize_key( (string) ( $payload['nics_method'] ?? 'fbi' ) );
		$id     = $nics->create(
			array(
				'form_4473_id'    => $form_4473_id,
				'nics_method'     => $method,
				'operator_id'     => get_current_user_id(),
				'response_status' => 'pending',
			)
		);

		$forms->update(
			$form_4473_id,
			array(
				'nics_transaction_id' => $id,
			)
		);

		$audit->add(
			'atf.nics.initiate',
			'nics',
			(string) $id,
			null,
			array(
				'form_4473_id' => $form_4473_id,
				'method'       => $method,
			)
		);
		return array(
			'id'   => $id,
			'nics' => $nics->find( $id ),
		);
	}

	public static function record_response( int $nics_id, array $payload ): array {
		$nics  = new NICSRepository();
		$forms = new Form4473Repository();
		$audit = new AuditLogRepository();

		$tx = $nics->find( $nics_id );
		if ( ! $tx ) {
			return array( 'error' => 'NICS transaction not found.' ); }
		if ( $tx['response_status'] !== 'pending' && $tx['response_status'] !== 'delayed' ) {
			return array( 'error' => 'Response already recorded.' );
		}

		$status = sanitize_key( (string) ( $payload['response_status'] ?? '' ) );
		if ( ! in_array( $status, array( 'proceed', 'denied', 'delayed', 'cancelled' ), true ) ) {
			return array( 'error' => 'response_status must be one of proceed|denied|delayed|cancelled.' );
		}

		$ntn    = sanitize_text_field( (string) ( $payload['ntn'] ?? '' ) );
		$now    = current_time( 'mysql' );
		$fields = array(
			'response_status'      => $status,
			'response_received_at' => $now,
			'ntn'                  => $ntn !== '' ? $ntn : ( $tx['ntn'] ?? null ),
			'raw_response'         => isset( $payload['raw_response'] ) ? wp_json_encode( $payload['raw_response'] ) : null,
		);

		if ( $status === 'delayed' ) {
			$delayed_until                         = $payload['delayed_until'] ?? self::add_business_days( new \DateTimeImmutable( 'now', wp_timezone() ), 3 )->format( 'Y-m-d H:i:s' );
			$default                               = ( new \DateTimeImmutable( $delayed_until ) )->format( 'Y-m-d H:i:s' );
			$fields['delayed_until']               = $delayed_until;
			$fields['default_proceed_eligible_at'] = $default;
		}

		if ( $status === 'denied' ) {
			$fields['denied_at']     = $now;
			$fields['denied_reason'] = sanitize_text_field( (string) ( $payload['denied_reason'] ?? '' ) );
		}

		$nics->update( $nics_id, $fields );

		$form_update = array(
			'nics_response'      => $status,
			'nics_response_date' => $now,
			'nics_ntn'           => $fields['ntn'] ?? null,
		);
		if ( isset( $fields['default_proceed_eligible_at'] ) ) {
			$form_update['default_proceed_eligible_at'] = $fields['default_proceed_eligible_at'];
		}
		$forms->update( (int) $tx['form_4473_id'], $form_update );

		if ( $status === 'denied' ) {
			$forms->update( (int) $tx['form_4473_id'], array( 'transaction_status' => 'denied' ) );
		}

		$audit->add( 'atf.nics.response', 'nics', (string) $nics_id, $tx, $fields );
		return array(
			'ok'   => true,
			'nics' => $nics->find( $nics_id ),
			'form' => $forms->find( (int) $tx['form_4473_id'] ),
		);
	}

	public static function apply_default_proceeds(): array {
		$nics    = new NICSRepository();
		$forms   = new Form4473Repository();
		$audit   = new AuditLogRepository();
		$rows    = $nics->pending_delayed_ready_for_default_proceed();
		$updated = 0;
		foreach ( $rows as $tx ) {
			$nics->update( (int) $tx['id'], array( 'response_status' => 'proceed' ) );
			$forms->update(
				(int) $tx['form_4473_id'],
				array(
					'nics_response'      => 'default_proceed',
					'nics_response_date' => current_time( 'mysql' ),
				)
			);
			$audit->add( 'atf.nics.default_proceed', 'nics', (string) $tx['id'], $tx, array( 'action' => 'default_proceed' ) );
			++$updated;
		}
		return array( 'updated' => $updated );
	}

	/**
	 * Advance a date forward by N business days (Mon-Fri), skipping weekends.
	 */
	private static function add_business_days( \DateTimeImmutable $start, int $days ): \DateTimeImmutable {
		$cursor = $start;
		$added  = 0;
		while ( $added < $days ) {
			$cursor = $cursor->modify( '+1 day' );
			$dow    = (int) $cursor->format( 'N' ); // ISO-8601 weekday number, Monday is one through Sunday is seven.
			if ( $dow < 6 ) {
				++$added;
			}
		}
		return $cursor;
	}
}
