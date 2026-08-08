<?php

namespace G2A\POS\Wholesalers;

/**
 * Vendor-agnostic compliance guards for wholesaler drop-ship orders.
 *
 * Drop-ship sends product from the wholesaler straight to a third party
 * (typically the customer's transferring FFL). Three classes of rule apply:
 *
 *  1. Universal (federal GCA): any order containing a firearm must ship to a
 *     licensed FFL. A firearm drop-ship with no destination FFL is rejected
 *     regardless of wholesaler. This guard is on by default.
 *  2. Program rules (per wholesaler): some wholesalers forbid mixing firearms
 *     and non-firearms on one drop-ship order. Opt in via
 *     `firearm_accessory_separation`.
 *  3. Ship-to-state restrictions (per wholesaler): some wholesalers will not
 *     drop-ship consumer-direct accessories to certain states. Configure via
 *     `restricted_accessory_states`.
 *
 * Each wholesaler provider supplies its own rule set (see
 * AbstractWholesalerProvider::dropShipRules), so the same enforcement runs the
 * same way across Lipsey's, RSR, Davidson's and Sports South. The class is a
 * pure function — no I/O or globals — so the rules are unit-testable in
 * isolation. Callers enrich each item with `item_type` / `ffl_required` first.
 */
final class WholesalerDropShipPolicy {

	/**
	 * @param array<int,array<string,mixed>> $items  Order items (item_type/ffl_required enriched).
	 * @param array<string,mixed>            $shipTo Destination (expects `state` and, for firearms, `ffl`).
	 * @param array<string,mixed>            $rules  {
	 *     @type bool                 $require_destination_ffl      Reject firearm orders with no ship-to FFL. Default true.
	 *     @type bool                 $firearm_accessory_separation Reject orders mixing firearms and non-firearms. Default false.
	 *     @type array<int,string>    $restricted_accessory_states  States that reject consumer-direct accessory drop-ship.
	 *     @type array<string,array{0:?string,1:?string}> $overrides Per-reason [code, message] overrides for vendor branding.
	 * }
	 * @return array{ok:bool,error?:string,message?:string}
	 */
	public static function validate( array $items, array $shipTo, array $rules = array() ): array {
		$requireFfl = $rules['require_destination_ffl'] ?? true;
		$separation = $rules['firearm_accessory_separation'] ?? false;
		$restricted = array_map( 'strtoupper', (array) ( $rules['restricted_accessory_states'] ?? array() ) );
		$overrides  = (array) ( $rules['overrides'] ?? array() );
		$state      = strtoupper( trim( (string) ( $shipTo['state'] ?? '' ) ) );
		$shipFfl    = trim( (string) ( $shipTo['ffl'] ?? '' ) );

		$hasFirearm    = false;
		$hasNonFirearm = false;
		$hasAccessory  = false;

		foreach ( $items as $item ) {
			if ( self::isFirearm( $item ) ) {
				$hasFirearm = true;
			} else {
				$hasNonFirearm = true;
				if ( self::isAccessory( $item ) ) {
					$hasAccessory = true;
				}
			}
		}

		if ( $requireFfl && $hasFirearm && $shipFfl === '' ) {
			return self::fail(
				$overrides,
				'firearm_dropship_requires_ffl',
				'Firearm drop-ship orders must ship to a licensed FFL — no destination FFL was provided.'
			);
		}

		if ( $separation && $hasFirearm && $hasNonFirearm ) {
			return self::fail(
				$overrides,
				'mixed_firearm_accessory',
				'Drop-ship orders cannot mix firearms and non-firearm items on a single order. Split this into separate orders.'
			);
		}

		if ( ! $hasFirearm && $hasAccessory && $state !== '' && in_array( $state, $restricted, true ) ) {
			return self::fail(
				$overrides,
				'accessory_dropship_state_blocked',
				sprintf( 'Accessory drop-ship orders cannot ship to %s.', $state )
			);
		}

		return array( 'ok' => true );
	}

	/**
	 * Firearms (and NFA items, which are FFL/SOT-bound) count as the firearm class.
	 *
	 * @param array<string,mixed> $item
	 */
	public static function isFirearm( array $item ): bool {
		$type = strtolower( (string) ( $item['item_type'] ?? '' ) );
		if ( $type === 'firearm' || $type === 'nfa' ) {
			return true;
		}
		return ! empty( $item['ffl_required'] );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public static function isAccessory( array $item ): bool {
		$type = strtolower( (string) ( $item['item_type'] ?? '' ) );
		return $type === 'accessory' || $type === 'optic';
	}

	/**
	 * Build a failure result, applying any per-reason vendor override.
	 *
	 * @param array<string,array{0:?string,1:?string}> $overrides
	 * @return array{ok:bool,error:string,message:string}
	 */
	private static function fail( array $overrides, string $code, string $message ): array {
		if ( isset( $overrides[ $code ] ) ) {
			$override = $overrides[ $code ];
			$code     = $override[0] ?? $code;
			$message  = $override[1] ?? $message;
		}
		return array(
			'ok'      => false,
			'error'   => $code,
			'message' => $message,
		);
	}
}
