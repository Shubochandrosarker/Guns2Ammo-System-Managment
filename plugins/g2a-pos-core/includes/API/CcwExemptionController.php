<?php

namespace G2A\POS\API;

use G2A\POS\Compliance\State\CcwExemption;
use G2A\POS\Compliance\State\StateRules;
use G2A\POS\Database\AuditLogRepository;
use G2A\POS\Database\Form4473Repository;
use G2A\POS\Database\StateRulesRepository;
use WP_REST_Request;

final class CcwExemptionController {

	/** GET /atf/ccw/qualifying-states — list of states whose CCW currently bypasses NICS. */
	public static function qualifying_states( WP_REST_Request $request ) {
		$rows = ( new StateRulesRepository() )->all();
		$out  = array();
		foreach ( $rows as $r ) {
			if ( (int) ( $r['ccw_bypasses_nics'] ?? 0 ) !== 1 ) {
				continue;
			}
			$out[] = array(
				'state_code'    => $r['state_code'],
				'state_name'    => $r['state_name'],
				'permit_label'  => $r['ccw_permit_label'] ?? 'Concealed Carry Permit',
				'max_age_years' => (int) ( $r['ccw_max_age_years'] ?? 5 ),
				'handgun_only'  => (int) ( $r['ccw_handgun_only'] ?? 0 ) === 1,
				'citation'      => $r['ccw_qualifying_citation'] ?? '18 USC 922(t)(3)',
			);
		}
		return array( 'qualifying_states' => $out );
	}

	/** POST /compliance/ccw/evaluate — pure-logic evaluation. */
	public static function evaluate( WP_REST_Request $request ) {
		$body     = $request->get_json_params() ?: array();
		$permit   = (array) ( $body['permit'] ?? array() );
		$resident = strtoupper( (string) ( $body['resident_state'] ?? '' ) );
		$firearms = (array) ( $body['firearms'] ?? array() );
		$on_date  = $body['on_date'] ?? null;

		$permit_state = strtoupper( (string) ( $permit['state'] ?? $resident ) );
		$rules        = StateRules::for( $permit_state );
		if ( ! $rules ) {
			return new \WP_Error( 'unknown_state', sprintf( 'No state rules for %s.', $permit_state ), array( 'status' => 400 ) );
		}
		return CcwExemption::evaluate( $rules, $permit, $resident, $firearms, $on_date );
	}

	/** POST /atf/4473/{id}/ccw-exemption — record exemption on the 4473. */
	public static function record_on_form( WP_REST_Request $request ) {
		$form_id = (int) $request['id'];
		if ( $form_id <= 0 ) {
			return new \WP_Error( 'invalid_input', 'form id required', array( 'status' => 400 ) );
		}
		$body     = $request->get_json_params() ?: array();
		$permit   = (array) ( $body['permit'] ?? array() );
		$firearms = (array) ( $body['firearms'] ?? array() );

		$forms = new Form4473Repository();
		$form  = $forms->find( $form_id );
		if ( ! $form ) {
			return new \WP_Error( 'not_found', '4473 not found', array( 'status' => 404 ) );
		}
		$resident     = strtoupper( (string) ( $form['transferee_state'] ?? '' ) );
		$permit_state = strtoupper( (string) ( $permit['state'] ?? $resident ) );
		$rules        = StateRules::for( $permit_state );
		if ( ! $rules ) {
			return new \WP_Error( 'unknown_state', sprintf( 'No state rules for %s.', $permit_state ), array( 'status' => 400 ) );
		}

		$result = CcwExemption::evaluate( $rules, $permit, $resident, $firearms, $body['on_date'] ?? null );
		if ( empty( $result['qualifies'] ) ) {
			return new \WP_Error(
				$result['reason'] ?? 'not_qualifying',
				$result['message'] ?? 'Permit does not qualify for NICS bypass.',
				array(
					'status' => 422,
					'detail' => $result,
				)
			);
		}

		$ok = $forms->record_ccw_exemption( $form_id, $result, get_current_user_id() );
		( new AuditLogRepository() )->add(
			'ccw_exemption.record',
			'form_4473',
			(string) $form_id,
			null,
			array(
				'permit_state' => $result['permit_state'],
				'permit_type'  => $result['permit_type'],
				'citation'     => $result['citation'],
			)
		);
		return array(
			'ok'        => $ok,
			'exemption' => $result,
			'form'      => $forms->find( $form_id ),
		);
	}

	/** DELETE /atf/4473/{id}/ccw-exemption — undo. */
	public static function clear_on_form( WP_REST_Request $request ) {
		$form_id = (int) $request['id'];
		$forms   = new Form4473Repository();
		if ( ! $forms->find( $form_id ) ) {
			return new \WP_Error( 'not_found', '4473 not found', array( 'status' => 404 ) );
		}
		$ok = $forms->clear_ccw_exemption( $form_id );
		( new AuditLogRepository() )->add( 'ccw_exemption.clear', 'form_4473', (string) $form_id, null, array() );
		return array(
			'ok'   => $ok,
			'form' => $forms->find( $form_id ),
		);
	}
}
