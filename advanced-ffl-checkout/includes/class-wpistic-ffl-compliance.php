<?php
/**
 * State compliance checker.
 *
 * Hooks into WooCommerce validation to warn (or block) checkout
 * if the customer's billing state has restrictions for the item type
 * in their cart.
 *
 * Also provides the REST endpoint handler for compliance lookups.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class Compliance {

	public function __construct() {
		add_action( 'woocommerce_checkout_process', [ $this, 'validate_state_compliance' ] );
		add_filter( 'woocommerce_available_payment_gateways', [ $this, 'maybe_block_gateways' ] );
	}

	/**
	 * Run at checkout — add WC notices for state restrictions.
	 */
	public function validate_state_compliance(): void {
		if ( ! WC()->cart || ! WC()->customer ) {
			return;
		}

		$state = WC()->customer->get_billing_state();
		if ( ! $state ) {
			return;
		}

		$ffl_item_types = $this->get_cart_ffl_item_types();
		if ( empty( $ffl_item_types ) ) {
			return;
		}

		$rules = $this->get_applicable_rules( $state, $ffl_item_types );

		foreach ( $rules as $rule ) {
			// Waiting periods and permit requirements are warnings (don't block)
			// Outright bans might block — extend this logic per business need
			wc_add_notice(
				sprintf(
					'<strong>' . __( 'FFL Compliance Notice (%s)', 'advanced-ffl-checkout' ) . ':</strong> %s',
					esc_html( $state ),
					esc_html( $rule->description )
				),
				'notice'
			);
		}
	}

	/**
	 * Optionally block payment gateways for non-compliant states.
	 * Currently only logs; hook can be extended by site owners via filter.
	 *
	 * @param array<string,mixed> $gateways
	 * @return array<string,mixed>
	 */
	public function maybe_block_gateways( array $gateways ): array {
		return apply_filters( 'wpistic_ffl_filter_gateways', $gateways );
	}

	/**
	 * Get all active compliance rules that apply to a state + item types.
	 *
	 * @param  string   $state          Two-letter state code.
	 * @param  string[] $ffl_item_types e.g. ['handgun','rifle']
	 * @return object[]
	 */
	public static function get_applicable_rules( string $state, array $ffl_item_types = [] ): array {
		global $wpdb;

		$rules = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT rule_type, description, item_types
			 FROM ' . DB::table( 'state_rules' ) . '
			 WHERE state_code = %s AND is_active = 1',
			strtoupper( $state )
		) );

		if ( empty( $ffl_item_types ) ) {
			return (array) $rules;
		}

		// Filter by item type relevance
		return array_values( array_filter( (array) $rules, function ( $rule ) use ( $ffl_item_types ) {
			if ( ! $rule->item_types ) {
				return true;
			}
			$rule_types = array_map( 'trim', explode( ',', $rule->item_types ) );
			return ! empty( array_intersect( $ffl_item_types, $rule_types ) );
		} ) );
	}

	/**
	 * Check if a specific item type is restricted in a state.
	 *
	 * @param string $state     Two-letter state code.
	 * @param string $item_type e.g. 'handgun'.
	 * @return bool
	 */
	public static function is_restricted( string $state, string $item_type ): bool {
		return count( self::get_applicable_rules( $state, [ $item_type ] ) ) > 0;
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Get FFL item types currently in the WC cart.
	 *
	 * @return string[]
	 */
	private function get_cart_ffl_item_types(): array {
		$types = [];
		foreach ( WC()->cart->get_cart() as $item ) {
			$product_id = $item['variation_id'] ?: $item['product_id'];
			if ( 'yes' !== get_post_meta( $product_id, '_wpistic_ffl_required', true ) ) {
				continue;
			}
			$type = get_post_meta( $product_id, '_wpistic_ffl_item_type', true ) ?: 'handgun';
			if ( ! in_array( $type, $types, true ) ) {
				$types[] = $type;
			}
		}
		return $types;
	}
}
