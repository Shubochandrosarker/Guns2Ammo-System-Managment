<?php

namespace G2A\POS\Compliance\ATF;

use G2A\POS\Compliance\State\EligibilityCheck;
use G2A\POS\Compliance\State\StateRules;
use G2A\POS\Compliance\State\WaitingPeriod;
use G2A\POS\Database\AuditLogRepository;
use G2A\POS\Database\Form4473Repository;

final class Form4473 {

	public static function start( array $data ): array {
		$repo  = new Form4473Repository();
		$audit = new AuditLogRepository();

		foreach ( array(
			'transferee_last',
			'transferee_first',
			'transferee_dob',
			'transferee_address',
			'transferee_city',
			'transferee_state',
			'transferee_zip',
			'id_type',
			'id_number',
			'id_issuer',
			'firearms',
		) as $req ) {
			if ( empty( $data[ $req ] ) ) {
				return array( 'error' => sprintf( '%s is required to start Form 4473.', $req ) );
			}
		}

		$transferee = self::transferee_payload( $data );
		$firearms   = is_array( $data['firearms'] ) ? $data['firearms'] : array();

		$state_code = strtoupper( (string) $data['transferee_state'] );
		$thirty_day = $repo->recent_handgun_count_for_buyer(
			(string) $transferee['last'],
			(string) $transferee['first'],
			(string) $transferee['dob'],
			30
		);

		$eligibility = EligibilityCheck::evaluate(
			$transferee + array(
				'state'      => $state_code,
				'us_citizen' => (bool) ( $data['us_citizen'] ?? true ),
			),
			$firearms,
			array(
				'location_state'                  => strtoupper( (string) ( $data['location_state'] ?? $state_code ) ),
				'handguns_purchased_last_30_days' => $thirty_day,
				'dros_reference'                  => $data['dros_reference'] ?? null,
				'nics_method'                     => $data['nics_method'] ?? null,
			)
		);

		if ( ! $eligibility['allowed'] ) {
			return array(
				'error'       => 'Eligibility check failed.',
				'eligibility' => $eligibility,
			);
		}

		$row = array(
			'form_serial'               => Form4473Repository::generate_serial(),
			'transaction_status'        => 'in_progress',
			'pos_order_id'              => isset( $data['pos_order_id'] ) ? (int) $data['pos_order_id'] : null,
			'customer_id'               => isset( $data['customer_id'] ) ? (int) $data['customer_id'] : null,
			'transferee_last'           => sanitize_text_field( (string) $data['transferee_last'] ),
			'transferee_first'          => sanitize_text_field( (string) $data['transferee_first'] ),
			'transferee_middle'         => isset( $data['transferee_middle'] ) ? sanitize_text_field( (string) $data['transferee_middle'] ) : null,
			'transferee_suffix'         => isset( $data['transferee_suffix'] ) ? sanitize_text_field( (string) $data['transferee_suffix'] ) : null,
			'transferee_dob'            => sanitize_text_field( (string) $data['transferee_dob'] ),
			'transferee_place_of_birth' => isset( $data['transferee_place_of_birth'] ) ? sanitize_text_field( (string) $data['transferee_place_of_birth'] ) : null,
			'transferee_address'        => sanitize_text_field( (string) $data['transferee_address'] ),
			'transferee_city'           => sanitize_text_field( (string) $data['transferee_city'] ),
			'transferee_state'          => $state_code,
			'transferee_zip'            => sanitize_text_field( (string) $data['transferee_zip'] ),
			'transferee_county'         => isset( $data['transferee_county'] ) ? sanitize_text_field( (string) $data['transferee_county'] ) : null,
			'transferee_phone'          => isset( $data['transferee_phone'] ) ? sanitize_text_field( (string) $data['transferee_phone'] ) : null,
			'transferee_email'          => isset( $data['transferee_email'] ) ? sanitize_email( (string) $data['transferee_email'] ) : null,
			'transferee_height_in'      => isset( $data['transferee_height_in'] ) ? (int) $data['transferee_height_in'] : null,
			'transferee_weight_lb'      => isset( $data['transferee_weight_lb'] ) ? (int) $data['transferee_weight_lb'] : null,
			'transferee_sex'            => isset( $data['transferee_sex'] ) ? substr( (string) $data['transferee_sex'], 0, 1 ) : null,
			'transferee_ethnicity'      => isset( $data['transferee_ethnicity'] ) ? sanitize_key( (string) $data['transferee_ethnicity'] ) : null,
			'transferee_race'           => isset( $data['transferee_race'] ) ? sanitize_text_field( (string) $data['transferee_race'] ) : null,
			'us_citizen'                => isset( $data['us_citizen'] ) ? ( (int) (bool) $data['us_citizen'] ) : 1,
			'country_of_citizenship'    => isset( $data['country_of_citizenship'] ) ? sanitize_text_field( (string) $data['country_of_citizenship'] ) : null,
			'uscis_number'              => isset( $data['uscis_number'] ) ? sanitize_text_field( (string) $data['uscis_number'] ) : null,
			'i94_number'                => isset( $data['i94_number'] ) ? sanitize_text_field( (string) $data['i94_number'] ) : null,
			'id_type'                   => sanitize_key( (string) $data['id_type'] ),
			'id_number'                 => sanitize_text_field( (string) $data['id_number'] ),
			'id_issuer'                 => sanitize_text_field( (string) $data['id_issuer'] ),
			'id_expiration'             => isset( $data['id_expiration'] ) ? sanitize_text_field( (string) $data['id_expiration'] ) : null,
			'supplemental_id_type'      => isset( $data['supplemental_id_type'] ) ? sanitize_key( (string) $data['supplemental_id_type'] ) : null,
			'supplemental_id_number'    => isset( $data['supplemental_id_number'] ) ? sanitize_text_field( (string) $data['supplemental_id_number'] ) : null,
			'section_b_answers'         => isset( $data['section_b_answers'] ) && is_array( $data['section_b_answers'] ) ? $data['section_b_answers'] : array(),
			'firearms'                  => $firearms,
			'transaction_type'          => sanitize_key( (string) ( $data['transaction_type'] ?? 'over_counter' ) ),
			'is_private_sale'           => ! empty( $data['is_private_sale'] ) ? 1 : 0,
			'is_pawn_redemption'        => ! empty( $data['is_pawn_redemption'] ) ? 1 : 0,
			'is_loan_pledge'            => ! empty( $data['is_loan_pledge'] ) ? 1 : 0,
			'actor_id'                  => get_current_user_id(),
			'location_id'               => (int) ( $data['location_id'] ?? 1 ),
		);

		$rules = StateRules::for( $state_code );
		if ( $rules && (int) $rules['requires_state_nics_check'] === 1 ) {
			$row['state_form_attached']  = ! empty( $data['state_form_reference'] ) ? 1 : 0;
			$row['state_form_reference'] = isset( $data['state_form_reference'] ) ? sanitize_text_field( (string) $data['state_form_reference'] ) : null;
		}

		$id = $repo->create( $row );
		$audit->add( 'atf.4473.start', 'form_4473', (string) $id, null, $row );

		return array(
			'id'                        => $id,
			'form'                      => $repo->find( $id ),
			'eligibility'               => $eligibility,
			'waiting_period_release_at' => optional_waiting_release( $state_code, $firearms ),
		);
	}

	public static function sign_transferee( int $id ): array {
		$repo  = new Form4473Repository();
		$audit = new AuditLogRepository();
		$form  = $repo->find( $id );
		if ( ! $form ) {
			return array( 'error' => '4473 not found.' ); }
		if ( $form['transaction_status'] === 'transferred' ) {
			return array( 'error' => '4473 already completed.' ); }
		$repo->update(
			$id,
			array(
				'transferee_certification_signed' => 1,
				'transferee_signature_at'         => current_time( 'mysql' ),
			)
		);
		$audit->add( 'atf.4473.sign_transferee', 'form_4473', (string) $id, $form, array( 'signed' => true ) );
		return array(
			'ok'   => true,
			'form' => $repo->find( $id ),
		);
	}

	public static function sign_transferor( int $id ): array {
		$repo  = new Form4473Repository();
		$audit = new AuditLogRepository();
		$form  = $repo->find( $id );
		if ( ! $form ) {
			return array( 'error' => '4473 not found.' ); }
		if ( ! $form['transferee_certification_signed'] ) {
			return array( 'error' => 'Transferee must sign first.' );
		}
		$repo->update(
			$id,
			array(
				'transferor_certification_signed' => 1,
				'transferor_signature_at'         => current_time( 'mysql' ),
				'transferor_user_id'              => get_current_user_id(),
			)
		);
		$audit->add( 'atf.4473.sign_transferor', 'form_4473', (string) $id, $form, array( 'signed_by' => get_current_user_id() ) );
		return array(
			'ok'   => true,
			'form' => $repo->find( $id ),
		);
	}

	public static function complete_transfer( int $id, array $context = array() ): array {
		$repo  = new Form4473Repository();
		$audit = new AuditLogRepository();
		$form  = $repo->find( $id );
		if ( ! $form ) {
			return array( 'error' => '4473 not found.' ); }

		if ( ! $form['transferee_certification_signed'] || ! $form['transferor_certification_signed'] ) {
			return array( 'error' => 'Both transferee and transferor must sign before completing transfer.' );
		}
		if ( ! $form['nics_response'] || ! in_array( $form['nics_response'], array( 'proceed', 'default_proceed' ), true ) ) {
			return array( 'error' => 'NICS Proceed (or eligible default proceed) is required before completing transfer.' );
		}

		$release = WaitingPeriod::compute_release(
			(string) $form['transferee_state'],
			(array) $form['firearms'],
			new \DateTimeImmutable( (string) ( $form['nics_response_date'] ?? 'now' ), wp_timezone() )
		);
		if ( $release && $release > new \DateTimeImmutable( 'now', wp_timezone() ) ) {
			return array( 'error' => sprintf( 'State waiting period not yet elapsed. Earliest pickup: %s.', $release->format( DATE_ATOM ) ) );
		}

		$repo->update(
			$id,
			array(
				'transaction_status' => 'transferred',
				'transferred_at'     => current_time( 'mysql' ),
			)
		);
		$audit->add( 'atf.4473.transfer', 'form_4473', (string) $id, $form, $context );

		ReportingService::evaluate_after_transfer( $id );

		return array(
			'ok'   => true,
			'form' => $repo->find( $id ),
		);
	}

	private static function transferee_payload( array $data ): array {
		return array(
			'last'                      => (string) ( $data['transferee_last'] ?? '' ),
			'first'                     => (string) ( $data['transferee_first'] ?? '' ),
			'dob'                       => (string) ( $data['transferee_dob'] ?? '' ),
			'permit_number'             => $data['permit_number'] ?? null,
			'foid_number'               => $data['foid_number'] ?? null,
			'safety_certificate_number' => $data['safety_certificate_number'] ?? null,
			'resident_state'            => strtoupper( (string) ( $data['resident_state'] ?? ( $data['transferee_state'] ?? '' ) ) ),
			'uscis_number'              => $data['uscis_number'] ?? null,
			'i94_number'                => $data['i94_number'] ?? null,
		);
	}
}

function optional_waiting_release( string $state_code, array $firearms ): ?string {
	$release = \G2A\POS\Compliance\State\WaitingPeriod::compute_release( $state_code, $firearms );
	return $release ? $release->format( DATE_ATOM ) : null;
}
