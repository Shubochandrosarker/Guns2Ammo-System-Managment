<?php

namespace G2A\POS\Compliance\State;

use G2A\POS\Database\AuditLogRepository;

/**
 * Merchant-level "out-of-state customer" firearm-sale policy. Sits ON TOP
 * of federal nonresident-handgun rules (18 USC 922(b)(3) handled in
 * EligibilityCheck) and any state-specific block (CA).
 *
 * Reflects the standing policy a single-state FFL like Guns 2 Ammo
 * typically runs ("we do not sell firearms to out-of-state residents")
 * while still letting the merchant flex it as they expand to more states.
 *
 * Three modes:
 *   allow                — no extra block; only federal/state rules apply
 *   block_with_override  — block by default, a manager can override per sale
 *   block_all            — hard block; no override path
 *
 * Per-location override: the policy can be set globally OR per location_id
 * via `g2a_pos_out_of_state_policy_by_location` (map of loc_id => mode).
 */
final class OutOfStatePolicy {

	public const ALLOW               = 'allow';
	public const BLOCK_WITH_OVERRIDE = 'block_with_override';
	public const BLOCK_ALL           = 'block_all';

	public const MODES = array( self::ALLOW, self::BLOCK_WITH_OVERRIDE, self::BLOCK_ALL );

	public const DEFAULT_MODE = self::BLOCK_WITH_OVERRIDE;

	public static function mode_for( ?int $location_id = null ): string {
		$per_loc = (array) get_option( 'g2a_pos_out_of_state_policy_by_location', array() );
		if ( $location_id !== null && isset( $per_loc[ $location_id ] )
			&& in_array( $per_loc[ $location_id ], self::MODES, true ) ) {
			return (string) $per_loc[ $location_id ];
		}
		$global = (string) get_option( 'g2a_pos_out_of_state_policy', self::DEFAULT_MODE );
		return in_array( $global, self::MODES, true ) ? $global : self::DEFAULT_MODE;
	}

	public static function set_global( string $mode ): bool {
		if ( ! in_array( $mode, self::MODES, true ) ) {
			return false;
		}
		update_option( 'g2a_pos_out_of_state_policy', $mode );
		return true;
	}

	public static function set_for_location( int $location_id, ?string $mode ): void {
		$per_loc = (array) get_option( 'g2a_pos_out_of_state_policy_by_location', array() );
		if ( $mode === null ) {
			unset( $per_loc[ $location_id ] );
		} elseif ( in_array( $mode, self::MODES, true ) ) {
			$per_loc[ $location_id ] = $mode;
		}
		update_option( 'g2a_pos_out_of_state_policy_by_location', $per_loc );
	}

	/**
	 * Evaluate the policy for a single customer/transferee.
	 *
	 * Returns:
	 *   {
	 *     applies: bool        - true if customer is out-of-state relative to store
	 *     mode: string         - the effective mode
	 *     blocked: bool        - whether the sale is blocked (after override)
	 *     override_allowed: bool
	 *     reason: string|null
	 *   }
	 *
	 * Pass override_granted = true (only if the caller verified the manager
	 * has g2a_pos_manage_compliance) to record + return blocked=false.
	 */
	public static function evaluate( string $customer_state, string $store_state, array $opts = array() ): array {
		$cs = strtoupper( trim( $customer_state ) );
		$ss = strtoupper( trim( $store_state ) );
		if ( $cs === '' || $ss === '' || $cs === $ss ) {
			return array(
				'applies'          => false,
				'mode'             => self::ALLOW,
				'blocked'          => false,
				'override_allowed' => false,
				'reason'           => null,
			);
		}

		$mode             = self::mode_for( $opts['location_id'] ?? null );
		$override_granted = ! empty( $opts['override_granted'] );

		if ( $mode === self::ALLOW ) {
			return array(
				'applies'          => true,
				'mode'             => $mode,
				'blocked'          => false,
				'override_allowed' => false,
				'reason'           => null,
			);
		}

		$blocked          = true;
		$override_allowed = $mode === self::BLOCK_WITH_OVERRIDE;
		if ( $override_allowed && $override_granted ) {
			$blocked = false;
		}

		return array(
			'applies'          => true,
			'mode'             => $mode,
			'blocked'          => $blocked,
			'override_allowed' => $override_allowed,
			'reason'           => sprintf(
				'Customer state %s does not match store state %s; merchant policy = %s.',
				$cs,
				$ss,
				$mode
			),
		);
	}

	/**
	 * Audit a manager override. Caller must have already verified the
	 * actor's capability — this just records the decision.
	 */
	public static function record_override( int $actor_id, string $customer_state, string $store_state, ?int $order_id, string $reason ): void {
		( new AuditLogRepository() )->add(
			'oos_policy.override',
			$order_id ? 'pos_order' : 'compliance_policy',
			$order_id ? (string) $order_id : 'oos',
			null,
			array(
				'actor_id'       => $actor_id,
				'customer_state' => strtoupper( $customer_state ),
				'store_state'    => strtoupper( $store_state ),
				'reason'         => $reason,
			)
		);
	}
}
