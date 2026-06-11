<?php

namespace G2A\POS\Wholesalers\Lipseys;

use G2A\POS\Wholesalers\WholesalerDropShipPolicy;

/**
 * Lipsey's drop-ship rules, derived from the Lipsey's Dropship Program
 * Guidelines (updated 1/1/2026):
 *
 *  - Firearms drop-ship orders may contain only firearms; accessory drop-ship
 *    orders may contain only accessories. The two cannot be mixed on one order.
 *  - As of 1/1/2026 (CA Bill 1263), Lipsey's no longer ships consumer-direct
 *    accessory drop-ship orders to California. Firearm drop-ships are unaffected.
 *  - Firearm drop-ships must ship to a licensed FFL (federal GCA).
 *
 * The actual enforcement lives in the vendor-agnostic
 * {@see WholesalerDropShipPolicy}; this class only supplies Lipsey's rule set
 * (including its branded error codes/messages) so the rules stay co-located
 * with the rest of the Lipsey's integration.
 */
final class LipseysDropShipPolicy {

	/** Ship-to states that reject consumer-direct accessory drop-ship orders. */
	public const RESTRICTED_ACCESSORY_STATES = array( 'CA' );

	/**
	 * Lipsey's rule set for {@see WholesalerDropShipPolicy::validate}.
	 *
	 * @return array<string,mixed>
	 */
	public static function rules(): array {
		return array(
			'require_destination_ffl'      => true,
			'firearm_accessory_separation' => true,
			'restricted_accessory_states'  => self::RESTRICTED_ACCESSORY_STATES,
			'overrides'                    => array(
				'mixed_firearm_accessory'          => array(
					null,
					"Lipsey's drop-ship orders must contain only firearms or only accessories — not both. Split this into separate orders.",
				),
				'accessory_dropship_state_blocked' => array(
					'ca_accessory_dropship_blocked',
					"Lipsey's no longer ships consumer-direct accessory drop-ship orders to California (CA Bill 1263, effective 1/1/2026). Firearm drop-ships are unaffected.",
				),
			),
		);
	}

	/**
	 * Backward-compatible item-level facade (no destination-FFL check, since the
	 * legacy signature carries no ship-to FFL). The live order path validates the
	 * full rule set via {@see LipseysProvider::dropShipRules}.
	 *
	 * @param array<int,array<string,mixed>> $items     Order items (item_type/ffl_required enriched).
	 * @param string                         $shipState Two-letter ship-to state code.
	 * @return array{ok:bool,error?:string,message?:string}
	 */
	public static function validate( array $items, string $shipState ): array {
		$rules                            = self::rules();
		$rules['require_destination_ffl'] = false;
		return WholesalerDropShipPolicy::validate( $items, array( 'state' => $shipState ), $rules );
	}
}
