<?php

namespace G2A\POS\Compliance\ATF;

use G2A\POS\Compliance\State\StateRules;
use G2A\POS\Database\AtfReportRepository;
use G2A\POS\Database\AuditLogRepository;
use G2A\POS\Database\Form4473Repository;

final class ReportingService {

	public static function evaluate_after_transfer( int $form_4473_id ): array {
		$forms   = new Form4473Repository();
		$reports = new AtfReportRepository();
		$audit   = new AuditLogRepository();

		$form = $forms->find( $form_4473_id );
		if ( ! $form ) {
			return array( 'error' => '4473 not found.' ); }

		$created = array();

		// ATF Form 3310.4 - Multiple Handgun Sale (two or more handguns to same person within 5 consecutive business days)
		$multi_handgun_in_form = self::handguns_in_form( $form );
		if ( $multi_handgun_in_form >= 2 ) {
			$created[] = self::create_report( $reports, '3310.4', 'multi_handgun_in_single_transaction', array( $form ), $form );
		} else {
			$other         = $forms->find_multi_long_gun_transfers_within(
				(string) $form['transferee_last'],
				(string) $form['transferee_first'],
				(string) $form['transferee_dob'],
				5
			);
			$handgun_count = 0;
			$involved      = array();
			foreach ( $other as $o ) {
				$h              = self::handguns_in_form( $o );
				$handgun_count += $h;
				if ( $h > 0 ) {
					$involved[] = $o; }
			}
			if ( $handgun_count >= 2 ) {
				$created[] = self::create_report( $reports, '3310.4', 'multi_handgun_5_business_days', $involved, $form );
			}
		}

		// ATF Form 3310.12 - Multiple Sale of Rifles (border states; two or more rifles 0.22+ semi-automatic w/ detachable mag in 5 consecutive business days)
		$rules = StateRules::for( (string) $form['transferee_state'] );
		if ( $rules && (int) $rules['requires_long_gun_multi_sale_report'] === 1 ) {
			$five_day   = $forms->find_multi_long_gun_transfers_within(
				(string) $form['transferee_last'],
				(string) $form['transferee_first'],
				(string) $form['transferee_dob'],
				5
			);
			$qualifying = self::qualifying_long_guns( $five_day );
			if ( count( $qualifying ) >= 2 ) {
				$created[] = self::create_report( $reports, '3310.12', 'multi_rifle_border_state', $five_day, $form );
			}
		}

		foreach ( $created as $report_id ) {
			$audit->add( 'atf.report.create', 'atf_report', (string) $report_id, null, array( 'form_4473_id' => $form_4473_id ) );
		}

		return array( 'created' => $created );
	}

	public static function create_theft_loss_report( array $payload ): array {
		$reports = new AtfReportRepository();
		$audit   = new AuditLogRepository();
		$serials = is_array( $payload['firearm_serial_numbers'] ?? null ) ? $payload['firearm_serial_numbers'] : array();
		if ( empty( $serials ) ) {
			return array( 'error' => 'firearm_serial_numbers required.' );
		}
		$id = $reports->create(
			array(
				'report_type'            => '3310.11A',
				'trigger_reason'         => sanitize_text_field( (string) ( $payload['reason'] ?? 'theft_or_loss' ) ),
				'status'                 => 'pending',
				'firearm_serial_numbers' => array_map( 'sanitize_text_field', $serials ),
				'payload'                => array(
					'incident_date'        => $payload['incident_date'] ?? current_time( 'Y-m-d' ),
					'discovered_date'      => $payload['discovered_date'] ?? current_time( 'Y-m-d' ),
					'description'          => sanitize_textarea_field( (string) ( $payload['description'] ?? '' ) ),
					'police_report_number' => sanitize_text_field( (string) ( $payload['police_report_number'] ?? '' ) ),
				),
				'actor_id'               => get_current_user_id(),
				'location_id'            => isset( $payload['location_id'] ) ? (int) $payload['location_id'] : null,
				'due_by'                 => ( new \DateTimeImmutable( '+48 hours' ) )->format( 'Y-m-d H:i:s' ),
			)
		);
		$audit->add( 'atf.report.theft_loss', 'atf_report', (string) $id, null, $payload );
		return array(
			'id'     => $id,
			'report' => $reports->find( $id ),
		);
	}

	private static function handguns_in_form( array $form ): int {
		$count = 0;
		foreach ( (array) ( $form['firearms'] ?? array() ) as $f ) {
			if ( strtolower( (string) ( $f['firearm_type'] ?? '' ) ) === 'handgun' ) {
				++$count;
			}
		}
		return $count;
	}

	private static function qualifying_long_guns( array $forms ): array {
		$qualifying = array();
		foreach ( $forms as $form ) {
			foreach ( (array) ( $form['firearms'] ?? array() ) as $f ) {
				$type           = strtolower( (string) ( $f['firearm_type'] ?? '' ) );
				$caliber        = (string) ( $f['caliber'] ?? '' );
				$is_semi_auto   = ! empty( $f['is_semi_auto'] );
				$detachable_mag = ! empty( $f['has_detachable_magazine'] );
				$caliber_22plus = self::caliber_is_22_or_greater( $caliber );
				if ( in_array( $type, array( 'rifle', 'long_gun' ), true ) && $is_semi_auto && $detachable_mag && $caliber_22plus ) {
					$qualifying[] = array(
						'form_4473_id' => $form['id'],
						'firearm'      => $f,
					);
				}
			}
		}
		return $qualifying;
	}

	private static function caliber_is_22_or_greater( string $caliber ): bool {
		if ( preg_match( '/(\d+(?:\.\d+)?)\s*(mm|cal|caliber|x)/i', $caliber, $m ) ) {
			$val = (float) $m[1];
			if ( stripos( $caliber, 'mm' ) !== false ) {
				return $val >= 5.6;
			}
			return $val >= 0.22;
		}
		if ( preg_match( '/0?\.(\d+)/', $caliber, $m ) ) {
			return ( (int) $m[1] ) >= 22;
		}
		return true;
	}

	private static function create_report( AtfReportRepository $reports, string $report_type, string $reason, array $forms_involved, array $context_form ): int {
		return $reports->create(
			array(
				'report_type'              => $report_type,
				'trigger_reason'           => $reason,
				'status'                   => 'pending',
				'transferee_form_4473_ids' => array_map( static fn( $f ) => (int) $f['id'], $forms_involved ),
				'firearm_serial_numbers'   => self::collect_serials( $forms_involved ),
				'payload'                  => array(
					'transferee'     => array(
						'last'  => $context_form['transferee_last'],
						'first' => $context_form['transferee_first'],
						'dob'   => $context_form['transferee_dob'],
						'state' => $context_form['transferee_state'],
					),
					'transferred_at' => $context_form['transferred_at'] ?? null,
				),
				'actor_id'                 => get_current_user_id(),
				'location_id'              => (int) ( $context_form['location_id'] ?? 0 ),
				'due_by'                   => ( new \DateTimeImmutable( '+1 day' ) )->format( 'Y-m-d H:i:s' ),
			)
		);
	}

	private static function collect_serials( array $forms ): array {
		$serials = array();
		foreach ( $forms as $form ) {
			foreach ( (array) ( $form['firearms'] ?? array() ) as $f ) {
				if ( ! empty( $f['serial_number'] ) ) {
					$serials[] = (string) $f['serial_number'];
				}
			}
		}
		return $serials;
	}
}
