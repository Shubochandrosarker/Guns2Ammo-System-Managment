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

	/** Cookie set by the Verifyistic age-verification plugin on a passed check. */
	const VERIFY_COOKIE = 'verifyistic_verified';

	public function __construct() {
		add_action( 'woocommerce_checkout_process', [ $this, 'validate_age_verification' ], 5 );
		add_action( 'woocommerce_checkout_process', [ $this, 'validate_state_compliance' ] );
		add_filter( 'woocommerce_available_payment_gateways', [ $this, 'maybe_block_gateways' ] );
	}

	/**
	 * Block checkout for any cart containing an FFL-required (firearm) item unless
	 * the current visitor has passed Verifyistic age verification.
	 *
	 * This is the actual point-of-sale enforcement point: the Verifyistic popup
	 * shown elsewhere on the site is only a UI prompt, not a server-side gate, so
	 * without this check a firearm purchase could complete with no age check at
	 * all. Runs before validate_state_compliance() (priority 5) so a customer
	 * sees the age-verification error first.
	 */
	public function validate_age_verification(): void {
		if ( ! WC()->cart ) {
			return;
		}

		// Site owner can turn this off (e.g. a different in-house age-gate mechanism
		// is used instead) but it defaults ON — a firearms checkout should never ship
		// with age verification optional.
		if ( '1' !== (string) get_option( 'wpistic_ffl_require_age_verification', '1' ) ) {
			return;
		}

		if ( empty( $this->get_cart_ffl_item_types() ) ) {
			return;
		}

		if ( $this->is_current_visitor_age_verified() ) {
			return;
		}

		wc_add_notice(
			__( 'Please complete age verification before purchasing a firearm-related item. Use the age-verification prompt on the site, then return to checkout.', 'advanced-ffl-checkout' ),
			'error'
		);
	}

	/**
	 * Check whether the current visitor holds a valid Verifyistic "passed"
	 * verification, mirroring the same cookie + wp_verifyistic_logs lookup the
	 * booking engine's Verifyistic module uses so both enforcement points agree.
	 */
	private function is_current_visitor_age_verified(): bool {
		$cookie_token = isset( $_COOKIE[ self::VERIFY_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::VERIFY_COOKIE ] ) ) : '';
		if ( '' === $cookie_token ) {
			return false;
		}
		// Some Verifyistic versions store a bare "1" rather than a lookup token.
		if ( '1' === $cookie_token ) {
			return true;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'verifyistic_logs';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $exists !== $table ) {
			// Verifyistic isn't installed/active — fail closed. A range cannot sell
			// firearms with no way to verify age at all.
			return false;
		}

		$row = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT id FROM {$table} WHERE verify_token = %s AND status = 'passed' ORDER BY id DESC LIMIT 1",
			$cookie_token
		) );

		return null !== $row;
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

		// Strict mode (default) blocks checkout so customers cannot bypass state restrictions
		// by ignoring a passive notice. Site owners can opt back into legacy warning behavior.
		$strict = (string) get_option( 'wpistic_ffl_compliance_strict', '1' ) === '1';
		$level  = $strict ? 'error' : 'notice';

		foreach ( $rules as $rule ) {
			wc_add_notice(
				sprintf(
					'<strong>' . __( 'FFL Compliance Notice (%s)', 'advanced-ffl-checkout' ) . ':</strong> %s',
					esc_html( $state ),
					esc_html( $rule->description )
				),
				$level
			);
		}
	}

	/**
	 * Advisory check that the chosen dealer's state is compatible with the buyer's
	 * billing state. Logs (does not block) so admins can spot likely-illegal pairings.
	 *
	 * @param object|null     $dealer Dealer row (must expose ->state and ->id when present).
	 * @param \WC_Order|null  $order  Order whose billing state to compare.
	 */
	public static function validate_dealer_for_buyer( $dealer, $order ): void {
		if ( ! $dealer || ! $order || ! is_object( $dealer ) ) {
			return;
		}

		$dealer_state = isset( $dealer->state ) ? strtoupper( (string) $dealer->state ) : '';
		$buyer_state  = method_exists( $order, 'get_billing_state' ) ? strtoupper( (string) $order->get_billing_state() ) : '';
		if ( '' === $dealer_state || '' === $buyer_state ) {
			return;
		}
		if ( $dealer_state === $buyer_state ) {
			return;
		}

		$allow = apply_filters( 'wpistic_ffl_dealer_buyer_state_allowlist', [], $dealer_state, $buyer_state );
		$pair  = $dealer_state . ':' . $buyer_state;
		if ( is_array( $allow ) && in_array( $pair, $allow, true ) ) {
			return;
		}

		$msg = sprintf(
			'[FFL] Dealer state %s does not match buyer state %s on order #%s (dealer #%s).',
			$dealer_state,
			$buyer_state,
			method_exists( $order, 'get_id' ) ? (string) $order->get_id() : '?',
			isset( $dealer->id ) ? (string) $dealer->id : '?'
		);
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( $msg, [ 'source' => 'wpistic-ffl-compliance' ] );
		} else {
			error_log( $msg );
		}
		do_action( 'wpistic_ffl_dealer_buyer_state_mismatch', $dealer, $order );
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
