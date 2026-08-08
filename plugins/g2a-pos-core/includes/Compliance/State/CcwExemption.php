<?php

namespace G2A\POS\Compliance\State;

/**
 * NICS bypass via qualifying state permit (CCW / CHL / LTC etc.) under
 * 18 USC § 922(t)(3) — the "alternative permit" exemption.
 *
 * Critical: the FORM (ATF 4473) is still required for every transfer.
 * Only the NICS background check call is skipped, and the permit details
 * are recorded on the form in Section C (questions 26.d, 26.e) instead
 * of the NTN.
 *
 * The exemption qualifies when ALL of the following are true:
 *
 *   1. The buyer's state of residence equals the permit's issuing state.
 *      A CCW does not bypass NICS in a different state's FFL.
 *   2. The issuing state has been determined by ATF to issue
 *      NICS-equivalent permits (rule sheet flag `ccw_bypasses_nics`).
 *   3. The permit was issued within the state's max-age window
 *      (`ccw_max_age_years`, typically 5 years).
 *   4. The permit has not expired.
 *   5. For states that flag `ccw_handgun_only = 1`, the cart must
 *      contain only handgun-class items.
 *
 * NOTE: The list of qualifying states is maintained by ATF and changes
 * occasionally. The state-rules table is the source of truth — see
 * StateSeeder.
 */
final class CcwExemption {

	public static function evaluate( array $rules, array $permit, string $resident_state, array $firearms, ?string $on_date = null ): array {
		$permit_state = strtoupper( trim( (string) ( $permit['state'] ?? '' ) ) );
		$resident     = strtoupper( trim( $resident_state ) );

		if ( $permit_state === '' || $resident === '' ) {
			return self::no( 'missing_state', 'Permit state and resident state are required.' );
		}

		if ( $permit_state !== $resident ) {
			return self::no(
				'permit_state_mismatch',
				sprintf( 'Permit was issued by %s but buyer resides in %s. Under 18 USC 922(t)(3) the permit must be from the buyer\'s state of residence.', $permit_state, $resident )
			);
		}

		if ( (int) ( $rules['ccw_bypasses_nics'] ?? 0 ) !== 1 ) {
			return self::no(
				'state_not_qualifying',
				sprintf( '%s permits are not currently on the ATF qualifying-state list under 18 USC 922(t)(3).', $permit_state ),
				array( 'citation' => $rules['ccw_qualifying_citation'] ?? null )
			);
		}

		$permit_number = trim( (string) ( $permit['number'] ?? '' ) );
		if ( $permit_number === '' ) {
			return self::no( 'missing_permit_number', 'Permit number is required to record the NICS bypass on the 4473.' );
		}

		$now_ts = strtotime( $on_date ?? date( 'Y-m-d' ) ) ?: time();

		$expires_on = (string) ( $permit['expires_on'] ?? '' );
		if ( $expires_on !== '' ) {
			$exp_ts = strtotime( $expires_on );
			if ( $exp_ts === false ) {
				return self::no( 'invalid_expires_on', 'Permit expiration date is invalid.' );
			}
			if ( $exp_ts < $now_ts ) {
				return self::no( 'permit_expired', sprintf( 'Permit expired on %s.', $expires_on ) );
			}
		}

		$issued_on     = (string) ( $permit['issued_on'] ?? '' );
		$max_age_years = max( 1, (int) ( $rules['ccw_max_age_years'] ?? 5 ) );
		if ( $issued_on !== '' ) {
			$iss_ts = strtotime( $issued_on );
			if ( $iss_ts === false ) {
				return self::no( 'invalid_issued_on', 'Permit issue date is invalid.' );
			}
			$window_ts = strtotime( "-{$max_age_years} years", $now_ts );
			if ( $iss_ts < $window_ts ) {
				return self::no(
					'permit_too_old',
					sprintf( 'Permit was issued on %s; %s requires issuance within the past %d years for the NICS bypass.', $issued_on, $permit_state, $max_age_years )
				);
			}
		}

		if ( (int) ( $rules['ccw_handgun_only'] ?? 0 ) === 1 ) {
			foreach ( $firearms as $f ) {
				$type = strtolower( (string) ( $f['firearm_type'] ?? '' ) );
				if ( ! in_array( $type, array( 'handgun', '' ), true ) ) {
					return self::no(
						'long_gun_not_eligible',
						sprintf( '%s\'s permit bypass applies only to handgun purchases under state policy; cart contains a %s.', $permit_state, $type )
					);
				}
			}
		}

		return array(
			'qualifies'     => true,
			'permit_state'  => $permit_state,
			'permit_type'   => trim( (string) ( $permit['type'] ?? ( $rules['ccw_permit_label'] ?? 'CCW' ) ) ),
			'permit_number' => $permit_number,
			'issued_on'     => $issued_on ?: null,
			'expires_on'    => $expires_on ?: null,
			'reason'        => 'qualifying_state_permit',
			'citation'      => $rules['ccw_qualifying_citation'] ?? '18 USC 922(t)(3)',
			'message'       => sprintf( 'NICS background check bypassed under 18 USC 922(t)(3); %s permit recorded on 4473 in place of NTN. Form 4473 is still required.', $permit_state ),
		);
	}

	private static function no( string $code, string $message, array $extra = array() ): array {
		return array_merge(
			array(
				'qualifies' => false,
				'reason'    => $code,
				'message'   => $message,
			),
			$extra
		);
	}
}
