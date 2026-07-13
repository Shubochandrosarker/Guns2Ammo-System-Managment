<?php
/**
 * Integrations registry + toggle persistence.
 *
 * Turns the (formerly static) Memberistic Integrations page into a real
 * control panel: every connectable integration has a persisted on/off
 * toggle stored in the `memberistic_settings` option. "Coming soon"
 * integrations are listed but not togglable.
 *
 * Each definition maps to a canonical enable setting so the toggle and any
 * existing per-integration setting stay in sync (e.g. Stripe ⇄ stripe_enabled).
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Integrations_Registry {

	/**
	 * Definitions of every integration card.
	 *
	 * Keys:
	 *  - key         internal id (also the POST field name)
	 *  - name/desc   display
	 *  - icon        single-letter badge
	 *  - setting     memberistic_settings key holding the yes/no enable flag
	 *  - default     default enable value ('yes'|'no')
	 *  - available   callable → bool: is the dependency present?
	 *  - dep_label   text shown when not available
	 *  - coming_soon bool: listed but not togglable
	 *
	 * @return array<string,array>
	 */
	public static function definitions() {
		$defs = array(
			'booking'          => array(
				'name'      => __( 'G2A Booking Engine', 'memberistic' ),
				'desc'      => __( 'Member eligibility, free lane booking rules, booking activity, and front desk visibility.', 'memberistic' ),
				'icon'      => 'B',
				'setting'   => 'integration_booking_enabled',
				'default'   => 'yes',
				'available' => static function () { return class_exists( '\\G2AB_Plugin' ) || defined( 'G2AB_VERSION' ); },
				'dep_label' => __( 'Install & activate the G2A Booking Engine plugin to use this.', 'memberistic' ),
			),
			'verifyistic'      => array(
				'name'      => __( 'Verifyistic Age Verification', 'memberistic' ),
				'desc'      => __( 'Stamp member records with verified age/DOB from the Verifyistic popup, show verified status, and optionally require verification at signup.', 'memberistic' ),
				'icon'      => 'V',
				'setting'   => 'integration_verifyistic_enabled',
				'default'   => 'no',
				'available' => static function () { return class_exists( '\\Verifyistic_DB' ) || class_exists( '\\Verifyistic_Frontend' ); },
				'dep_label' => __( 'Install & activate the Verifyistic plugin to use this.', 'memberistic' ),
			),
			'stripe'           => array(
				'name'      => __( 'Stripe Checkout', 'memberistic' ),
				'desc'      => __( 'Hosted membership subscription checkout and webhooks.', 'memberistic' ),
				'icon'      => 'S',
				'setting'   => 'stripe_enabled',
				'default'   => 'no',
				'available' => static function () { return true; },
				'dep_label' => '',
			),
			'woocommerce'      => array(
				'name'      => __( 'WooCommerce', 'memberistic' ),
				'desc'      => __( 'Completed-order sync for membership purchases.', 'memberistic' ),
				'icon'      => 'W',
				'setting'   => 'woocommerce_enabled',
				'default'   => 'no',
				'available' => static function () { return class_exists( '\\WooCommerce' ); },
				'dep_label' => __( 'Install & activate WooCommerce to use this.', 'memberistic' ),
			),
			'email_automation' => array(
				'name'      => __( 'Email Automation', 'memberistic' ),
				'desc'      => __( 'Lifecycle notifications for checkout, activation, failed payment, cancellation, renewals, and waivers.', 'memberistic' ),
				'icon'      => 'M',
				'setting'   => 'integration_email_enabled',
				'default'   => 'yes',
				'available' => static function () { return true; },
				'dep_label' => '',
			),
			'waiver_manager'   => array(
				'name'      => __( 'Waiver Manager', 'memberistic' ),
				'desc'      => __( 'Built-in digital range waivers: tokenized member signing, guest + kiosk surfaces, immutable signature archive, expiry tracking, and booking engine check-in mirroring.', 'memberistic' ),
				'icon'      => 'W',
				'setting'   => 'integration_waiver_enabled',
				'default'   => 'yes',
				'available' => static function () { return true; },
				'dep_label' => '',
			),
			'pos_bridge'       => array(
				'name'      => __( 'POS Bridge', 'memberistic' ),
				'desc'      => __( 'Connect memberships with the G2A POS: live member lookup at the counter, tier + status on the customer profile, range bookings on the POS dashboard, and check-in sync.', 'memberistic' ),
				'icon'      => 'P',
				'setting'   => 'integration_pos_enabled',
				'default'   => 'no',
				'available' => static function () { return defined( 'G2A_POS_CORE_VERSION' ) || class_exists( '\\G2A\\POS\\Core\\Plugin' ); },
				'dep_label' => __( 'Install & activate the G2A POS Core plugin to use this.', 'memberistic' ),
			),
			'corestore'        => array(
				'name'      => __( 'coreSTORE (Coreware) POS', 'memberistic' ),
				'desc'      => __( 'Push membership state into the shop\'s hosted coreSTORE: price tier per plan for automatic in-store member discounts, plan/status/expiry on the cashier\'s customer screen. Independent from the G2A POS Bridge.', 'memberistic' ),
				'icon'      => 'C',
				'setting'   => 'integration_corestore_enabled',
				'default'   => 'no',
				'available' => static function () { return true; }, // remote API — no local plugin dependency
				'dep_label' => '',
			),
			'sms_reminders'    => array(
				'name'      => __( 'SMS Notifications (Messageistic)', 'memberistic' ),
				'desc'      => __( 'Send membership welcome, activation, renewal, and expiry texts through Messageistic — including the local SMS gateway. Booking confirmations ride along when the booking engine is active.', 'memberistic' ),
				'icon'      => 'T',
				'setting'   => 'integration_sms_enabled',
				'default'   => 'no',
				'available' => static function () { return defined( 'MESSAGEISTIC_VERSION' ) || class_exists( '\\Messageistic\\Core\\Plugin' ); },
				'dep_label' => __( 'Install & activate the Messageistic plugin to use this.', 'memberistic' ),
			),
			'ffl_checkout'     => array(
				'name'      => __( 'Advanced FFL Checkout', 'memberistic' ),
				'desc'      => __( 'Show a member\'s own online FFL firearm-transfer history on their account dashboard, and a quick heads-up on the staff verification card if they have one in flight. Read-only.', 'memberistic' ),
				'icon'      => 'F',
				'setting'   => 'integration_ffl_checkout_enabled',
				'default'   => 'no',
				'available' => static function () { return defined( 'WPISTIC_FFL_VERSION' ); },
				'dep_label' => __( 'Install & activate the Advanced FFL Checkout plugin to use this.', 'memberistic' ),
			),
		);

		$coming = array(
			'klaviyo'         => array( 'name' => __( 'Klaviyo Sync', 'memberistic' ), 'desc' => __( 'Export member segments, renewal windows, and failed payment audiences into marketing automation.', 'memberistic' ), 'icon' => 'K' ),
		);
		foreach ( $coming as $k => $c ) {
			$defs[ $k ] = array_merge( $c, array( 'setting' => '', 'default' => 'no', 'available' => static function () { return false; }, 'dep_label' => '', 'coming_soon' => true ) );
		}

		/** Allow add-ons to register their own integration cards. */
		return apply_filters( 'memberistic_integration_definitions', $defs );
	}

	/** Is a dependency present for this integration? */
	public static function is_available( $key ) {
		$defs = self::definitions();
		if ( ! isset( $defs[ $key ] ) ) {
			return false;
		}
		$cb = $defs[ $key ]['available'];
		return is_callable( $cb ) ? (bool) call_user_func( $cb ) : (bool) $cb;
	}

	/** Is the integration toggled on (and its dependency present)? */
	public static function is_enabled( $key ) {
		$defs = self::definitions();
		if ( ! isset( $defs[ $key ] ) || ! empty( $defs[ $key ]['coming_soon'] ) ) {
			return false;
		}
		if ( ! self::is_available( $key ) ) {
			return false;
		}
		$setting = $defs[ $key ]['setting'];
		$default = $defs[ $key ]['default'];
		$val     = $setting ? memberistic_get_setting( $setting, $default ) : $default;
		return 'yes' === $val || '1' === (string) $val || 1 === $val || true === $val;
	}

	/**
	 * Persist toggle state from a submitted form. Only connectable (non
	 * coming-soon, available) integrations are written.
	 *
	 * @param array $posted Raw $_POST['memberistic_integrations'] map (key => '1'|'0').
	 */
	public static function save( array $posted ) {
		$settings = get_option( 'memberistic_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		foreach ( self::definitions() as $key => $def ) {
			if ( ! empty( $def['coming_soon'] ) || empty( $def['setting'] ) ) {
				continue;
			}
			if ( ! self::is_available( $key ) ) {
				continue; // can't enable what isn't installed
			}
			$on                       = ! empty( $posted[ $key ] );
			$settings[ $def['setting'] ] = $on ? 'yes' : 'no';
		}
		update_option( 'memberistic_settings', $settings, false );
	}
}
