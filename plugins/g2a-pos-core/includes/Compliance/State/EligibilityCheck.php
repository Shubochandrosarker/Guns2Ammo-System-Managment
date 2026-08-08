<?php

namespace G2A\POS\Compliance\State;

final class EligibilityCheck {

	public static function evaluate( array $transferee, array $firearms, array $context = array() ): array {
		$state_code = strtoupper( (string) ( $transferee['state'] ?? '' ) );
		if ( $state_code === '' ) {
			return self::fail( 'missing_state', 'Transferee state is required.' );
		}

		$rules = StateRules::for( $state_code );
		if ( ! $rules ) {
			return self::fail( 'unknown_state', sprintf( 'No compliance rules defined for state %s.', $state_code ) );
		}

		$dob = (string) ( $transferee['dob'] ?? '' );
		if ( $dob === '' ) {
			return self::fail( 'missing_dob', 'Transferee date of birth is required.' );
		}
		$age = self::age_at( $dob, $context['transfer_date'] ?? null );
		if ( $age === null ) {
			return self::fail( 'invalid_dob', 'Transferee date of birth is invalid.' );
		}

		$violations         = array();
		$warnings           = array();
		$has_handgun        = false;
		$has_long_gun       = false;
		$longest_wait_hours = 0;

		foreach ( $firearms as $firearm ) {
			$type = strtolower( (string) ( $firearm['firearm_type'] ?? '' ) );
			if ( $type === 'handgun' ) {
				$has_handgun = true;
				if ( $age < (int) $rules['handgun_min_age'] ) {
					$violations[] = self::violation( 'age_handgun', sprintf( 'Transferee age %d does not meet %s handgun min age %d.', $age, $state_code, (int) $rules['handgun_min_age'] ) );
				}
				$longest_wait_hours = max( $longest_wait_hours, (int) $rules['waiting_period_hours_handgun'] );
			} elseif ( in_array( $type, array( 'long_gun', 'rifle', 'shotgun' ), true ) ) {
				$has_long_gun = true;
				if ( $age < (int) $rules['long_gun_min_age'] ) {
					$violations[] = self::violation( 'age_long_gun', sprintf( 'Transferee age %d does not meet %s long-gun min age %d.', $age, $state_code, (int) $rules['long_gun_min_age'] ) );
				}
				$longest_wait_hours = max( $longest_wait_hours, (int) $rules['waiting_period_hours_long_gun'] );
			}
		}

		$resident_state = strtoupper( (string) ( $transferee['resident_state'] ?? $state_code ) );
		$location_state = strtoupper( (string) ( $context['location_state'] ?? $state_code ) );
		$is_nonresident = ( $resident_state !== $location_state );
		if ( $is_nonresident && $has_handgun && (int) $rules['ffl_to_ffl_required_for_nonresident'] === 1 ) {
			$violations[] = self::violation( 'nonresident_handgun', 'Nonresident handgun transfer must ship FFL-to-FFL to the buyer\'s home-state dealer (18 USC 922(b)(3)).' );
		}

		// Merchant-level out-of-state policy. Triggers only when at least one
		// firearm is on the cart and the customer is from a different state
		// than the store.
		if ( $is_nonresident && ( $has_handgun || $has_long_gun ) ) {
			$oos = OutOfStatePolicy::evaluate(
				$resident_state,
				$location_state,
				array(
					'location_id'      => $context['location_id'] ?? null,
					'override_granted' => ! empty( $context['oos_override_granted'] ),
				)
			);
			if ( $oos['blocked'] ) {
				$violations[] = self::violation(
					'oos_policy_blocked',
					$oos['reason']
					. ( $oos['override_allowed'] ? ' Manager override available.' : ' Override is not permitted under current policy.' )
				);
			} elseif ( $oos['applies'] && ! empty( $context['oos_override_granted'] ) ) {
				$warnings[] = self::violation(
					'oos_policy_overridden',
					sprintf(
						'Out-of-state customer (%s != %s) — manager override recorded under policy %s.',
						$resident_state,
						$location_state,
						$oos['mode']
					)
				);
			} elseif ( $oos['applies'] && $oos['mode'] === OutOfStatePolicy::ALLOW ) {
				$warnings[] = self::violation(
					'oos_policy_notice',
					sprintf(
						'Out-of-state customer (%s != %s); merchant policy is "allow" — federal/state rules still apply.',
						$resident_state,
						$location_state
					)
				);
			}
		}

		if ( $has_handgun && (int) $rules['requires_permit_to_purchase_handgun'] === 1 && empty( $transferee['permit_number'] ) ) {
			$violations[] = self::violation( 'permit_required_handgun', sprintf( '%s requires permit (%s) for handgun purchase.', $state_code, $rules['permit_label'] ?? 'state permit' ) );
		}
		if ( $has_long_gun && (int) $rules['requires_permit_to_purchase_long_gun'] === 1 && empty( $transferee['permit_number'] ) ) {
			$violations[] = self::violation( 'permit_required_long_gun', sprintf( '%s requires permit (%s) for long-gun purchase.', $state_code, $rules['permit_label'] ?? 'state permit' ) );
		}
		if ( (int) $rules['requires_foid'] === 1 && empty( $transferee['foid_number'] ) ) {
			$violations[] = self::violation( 'foid_required', 'Illinois FOID Card is required.' );
		}
		if ( (int) $rules['requires_safety_certificate'] === 1 && empty( $transferee['safety_certificate_number'] ) ) {
			$warnings[] = self::violation( 'safety_certificate_required', sprintf( '%s required: %s.', $rules['safety_certificate_label'] ?? 'Safety cert', $rules['safety_certificate_label'] ?? '' ) );
		}
		if ( (int) $rules['requires_dros'] === 1 && empty( $context['dros_reference'] ) ) {
			$warnings[] = self::violation( 'dros_required', 'California DROS entry must be filed before delivery.' );
		}
		if ( (int) $rules['requires_state_nics_check'] === 1 && empty( $context['nics_method'] ) ) {
			$warnings[] = self::violation( 'state_nics_required', sprintf( 'State-conducted background check required: %s.', $rules['state_nics_label'] ?? 'state agency' ) );
		}

		$mag_cap = $rules['magazine_capacity_limit'];
		if ( $mag_cap !== null && $mag_cap !== '' ) {
			foreach ( $firearms as $i => $firearm ) {
				$cap = isset( $firearm['magazine_capacity'] ) ? (int) $firearm['magazine_capacity'] : null;
				if ( $cap !== null && $cap > (int) $mag_cap ) {
					$violations[] = self::violation( 'magazine_capacity_exceeded', sprintf( 'Item %d magazine capacity %d exceeds %s limit of %d.', $i + 1, $cap, $state_code, (int) $mag_cap ) );
				}
			}
		}
		if ( (int) $rules['roster_required'] === 1 ) {
			foreach ( $firearms as $i => $firearm ) {
				if ( ( $firearm['firearm_type'] ?? '' ) === 'handgun' && empty( $firearm['on_state_roster'] ) ) {
					$violations[] = self::violation( 'not_on_roster', sprintf( 'Item %d is not on the %s approved handgun roster.', $i + 1, $state_code ) );
				}
			}
		}
		if ( (int) $rules['assault_weapon_restricted'] === 1 ) {
			foreach ( $firearms as $i => $firearm ) {
				if ( ! empty( $firearm['is_assault_weapon'] ) ) {
					$violations[] = self::violation( 'assault_weapon_restricted', sprintf( 'Item %d is restricted under %s assault-weapon law.', $i + 1, $state_code ) );
				}
			}
		}

		$thirty_day_handgun_count = (int) ( $context['handguns_purchased_last_30_days'] ?? 0 );
		if ( (int) $rules['one_handgun_per_30_days'] === 1 && $has_handgun && $thirty_day_handgun_count >= 1 ) {
			$violations[] = self::violation( 'one_handgun_30d', sprintf( '%s allows only one handgun purchase per 30 days; %d already on record.', $state_code, $thirty_day_handgun_count ) );
		}

		// ---- CCW NICS-bypass evaluation (18 USC 922(t)(3)) ----
		// The 4473 is still required; only the NICS check is skipped when
		// the permit qualifies. Caller passes context.ccw_permit when the
		// buyer has presented one at the counter.
		$ccw = null;
		if ( ! empty( $context['ccw_permit'] ) ) {
			$permit       = (array) $context['ccw_permit'];
			$permit_state = strtoupper( (string) ( $permit['state'] ?? $resident_state ) );
			$permit_rules = StateRules::for( $permit_state ) ?: $rules;
			$ccw          = CcwExemption::evaluate(
				$permit_rules,
				$permit,
				$resident_state,
				$firearms,
				$context['transfer_date'] ?? null
			);
			if ( ! $ccw['qualifies'] ) {
				// Not fatal on its own — the buyer can still proceed with a
				// standard NICS check. Just a warning so the cashier knows
				// why the permit they presented didn't apply.
				$warnings[] = self::violation( 'ccw_permit_not_qualifying', $ccw['message'] );
			}
		}

		if ( $transferee['us_citizen'] === false && empty( $transferee['uscis_number'] ) && empty( $transferee['i94_number'] ) ) {
			$violations[] = self::violation( 'alien_documentation', 'Non-citizen transferees must provide USCIS or I-94 number per ATF Form 4473.' );
		}

		return array(
			'allowed'              => empty( $violations ),
			'state'                => $state_code,
			'age'                  => $age,
			'violations'           => $violations,
			'warnings'             => $warnings,
			'waiting_period_hours' => $longest_wait_hours,
			'rule_set'             => $rules,
			'ccw_exemption'        => $ccw,
			'nics_required'        => ! ( $ccw['qualifies'] ?? false ),
		);
	}

	private static function fail( string $code, string $message ): array {
		return array(
			'allowed'    => false,
			'violations' => array( self::violation( $code, $message ) ),
			'warnings'   => array(),
		);
	}

	private static function violation( string $code, string $message ): array {
		return array(
			'code'    => $code,
			'message' => $message,
		);
	}

	private static function age_at( string $dob, ?string $on = null ): ?int {
		try {
			$dt_dob = new \DateTimeImmutable( $dob );
			$dt_on  = new \DateTimeImmutable( $on ?? 'now' );
			return $dt_on->diff( $dt_dob )->y;
		} catch ( \Throwable ) {
			return null;
		}
	}
}
