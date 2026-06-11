<?php

namespace G2A\POS\Range;

use G2A\POS\Database\AuditLogRepository;
use G2A\POS\Database\CustomerProfileRepository;
use G2A\POS\Database\RangeIncidentRepository;

/**
 * Range incident workflow with **customer-flag escalation**.
 *
 * Severity ladder: info → warning → serious → eject → banned.
 * When an incident is recorded at `eject` severity for a customer, the
 * customer's CRM profile gets `do_not_sell = 1` (warning gate at next
 * checkout). At `banned` severity the profile additionally gets
 * `denied_buyer = 1` with a reason that points back at the incident.
 *
 * All escalations are audited.
 */
final class IncidentService {

	public static function report( array $data ): array {
		$repo        = new RangeIncidentRepository();
		$incident_id = $repo->create( $data );

		$audit = new AuditLogRepository();
		$audit->add(
			'range_incident.report',
			'range_incident',
			(string) $incident_id,
			null,
			array(
				'severity'      => $data['severity'] ?? null,
				'lane_code'     => $data['lane_code'] ?? null,
				'incident_type' => $data['incident_type'] ?? null,
			)
		);

		$customers    = (array) ( $data['customers'] ?? array() );
		$flag_applied = false;
		foreach ( $customers as $c ) {
			$kind           = self::flag_kind_for_severity( (string) ( $data['severity'] ?? 'warning' ) );
			$c['flag_kind'] = $kind;
			$repo->attach_customer( $incident_id, $c );

			if ( $kind && ! empty( $c['customer_id'] ) ) {
				self::apply_customer_flag(
					(int) $c['customer_id'],
					$kind,
					sprintf( 'Range incident #%s — severity %s', $incident_id, $data['severity'] ),
					$incident_id
				);
				$flag_applied = true;
			}
		}

		if ( $flag_applied ) {
			$repo->mark_customer_flag_applied( $incident_id );
		}

		return array(
			'ok'          => true,
			'incident_id' => $incident_id,
			'incident'    => $repo->find( $incident_id ),
		);
	}

	public static function flag_kind_for_severity( string $severity ): ?string {
		switch ( $severity ) {
			case 'eject':
				return 'do_not_sell';
			case 'banned':
				return 'denied_buyer';
			default:
				return null;
		}
	}

	private static function apply_customer_flag( int $customer_id, string $flag_kind, string $reason, int $incident_id ): void {
		$profile = new CustomerProfileRepository();
		$fields  = array();
		if ( $flag_kind === 'do_not_sell' ) {
			$fields['do_not_sell'] = 1;
		} elseif ( $flag_kind === 'denied_buyer' ) {
			$fields['do_not_sell']   = 1;
			$fields['denied_buyer']  = 1;
			$fields['denied_reason'] = $reason;
		}
		if ( $fields ) {
			$profile->upsert( $customer_id, $fields );
			( new AuditLogRepository() )->add(
				'range_incident.customer_flagged',
				'customer_profile',
				(string) $customer_id,
				null,
				array(
					'flag_kind'   => $flag_kind,
					'reason'      => $reason,
					'incident_id' => $incident_id,
				)
			);
		}
	}
}
