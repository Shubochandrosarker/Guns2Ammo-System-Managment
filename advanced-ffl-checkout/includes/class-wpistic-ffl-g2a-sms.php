<?php
/**
 * G2A — Customer notifications via Verifyistic webhook dispatcher.
 *
 * Verifyistic (sibling plugin in this repo) ships a multi-endpoint webhook
 * delivery system that any SMS gateway (Twilio, OtterText, etc.) can be wired
 * into. When a transfer hits a customer-facing milestone we dispatch a
 * `ffl_transfer_status` event with the customer + transfer payload. If
 * Verifyistic is absent the call no-ops silently.
 *
 * Events dispatched:
 *   - shipped_to_dealer    (gun is on its way)
 *   - received_by_dealer   (ready for pickup — highest-value SMS)
 *   - delayed              (NICS delay)
 *   - approved             (NICS cleared)
 *   - transferred          (complete)
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_SMS {

	/** Statuses we consider worth notifying the customer about by SMS. */
	const NOTIFY_STATUSES = [
		'shipped_to_dealer',
		'received_by_dealer',
		'dealer_received',
		'delayed',
		'nics_delayed',
		'approved',
		'transferred',
	];

	public function __construct() {
		add_action( 'wpistic_ffl_transfer_status_changed', [ $this, 'dispatch' ], 20, 3 );
		add_action( 'admin_notices',                       [ $this, 'maybe_warn_missing_verifyistic' ] );
	}

	/**
	 * Surface a visible warning when SMS is enabled but Verifyistic is absent —
	 * otherwise dispatches silently no-op and admins assume SMS is working.
	 */
	public function maybe_warn_missing_verifyistic(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$portal = get_option( 'wpistic_ffl_portal_settings', [] );
		$sms_enabled = ! empty( $portal['sms_enabled'] )
			|| (bool) apply_filters( 'wpistic_ffl_sms_enabled', true );
		if ( ! $sms_enabled ) {
			return;
		}
		if ( class_exists( '\\Verifyistic_Webhooks' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>Advanced FFL Checkout —</strong> SMS notifications are enabled but the Verifyistic plugin is not active. SMS will not be sent.</p></div>';
	}

	public function dispatch( int $transfer_id, string $old_status, string $new_status ): void {
		if ( ! in_array( $new_status, self::NOTIFY_STATUSES, true ) ) {
			return;
		}
		if ( ! class_exists( '\Verifyistic_Webhooks' ) ) {
			return; // Verifyistic not installed — silent no-op.
		}

		global $wpdb;
		$transfer = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT t.*, d.business_name AS dealer_name, d.phone AS dealer_phone,
			        d.premise_street AS dealer_street, d.premise_city AS dealer_city,
			        d.premise_state AS dealer_state, d.premise_zip AS dealer_zip
			 FROM ' . DB::table( 'transfers' ) . ' t
			 LEFT JOIN ' . DB::table( 'dealers' ) . ' d ON d.id = t.dealer_id
			 WHERE t.id = %d',
			$transfer_id
		) );
		if ( ! $transfer ) {
			return;
		}
		if ( empty( $transfer->customer_phone ) ) {
			return;
		}

		$message = self::message_for( $new_status, $transfer );
		if ( ! $message ) {
			return;
		}

		$payload = [
			'transfer_id'    => (int) $transfer->id,
			'transfer_ref'   => (string) $transfer->transfer_ref,
			'order_id'       => (int) $transfer->order_id,
			'customer_name'  => (string) $transfer->customer_name,
			'customer_phone' => (string) $transfer->customer_phone,
			'customer_email' => (string) $transfer->customer_email,
			'status'         => $new_status,
			'old_status'     => $old_status,
			'message'        => $message,
			'dealer'         => [
				'name'    => (string) ( $transfer->dealer_name ?? '' ),
				'phone'   => (string) ( $transfer->dealer_phone ?? '' ),
				'address' => trim( ( $transfer->dealer_street ?? '' ) . ', ' . ( $transfer->dealer_city ?? '' ) . ', ' . ( $transfer->dealer_state ?? '' ) . ' ' . ( $transfer->dealer_zip ?? '' ), ', ' ),
			],
		];

		\Verifyistic_Webhooks::dispatch( 'ffl_transfer_status', $payload );
	}

	/**
	 * Build the SMS body for a given status. Kept short — 160 char target.
	 * Filterable so per-site copy can be customized without touching the plugin.
	 */
	public static function message_for( string $status, object $transfer ): string {
		$store     = get_bloginfo( 'name' );
		$ref       = (string) ( $transfer->transfer_ref ?? '' );
		$dealer    = (string) ( $transfer->dealer_name ?? 'your FFL' );
		$tracking  = trim( strtoupper( (string) ( $transfer->shipment_carrier ?? '' ) ) . ' ' . (string) ( $transfer->shipment_tracking ?? '' ) );

		$messages = [
			'shipped_to_dealer'  => "{$store}: Your firearm (#{$ref}) shipped to {$dealer}." . ( $tracking ? " Tracking: {$tracking}" : '' ),
			'received_by_dealer' => "{$store}: Good news! {$dealer} has your firearm (#{$ref}). Contact them to schedule pickup + background check.",
			'dealer_received'    => "{$store}: Good news! {$dealer} has your firearm (#{$ref}). Contact them to schedule pickup + background check.",
			'delayed'            => "{$store}: Heads up — NICS background check on #{$ref} is delayed. This is normal. We'll update you when it clears.",
			'nics_delayed'       => "{$store}: Heads up — NICS background check on #{$ref} is delayed. This is normal. We'll update you when it clears.",
			'approved'           => "{$store}: NICS approved for #{$ref}! {$dealer} will finalize the transfer.",
			'transferred'        => "{$store}: Transfer complete on #{$ref}. Thanks for choosing us — stay safe.",
		];

		$msg = $messages[ $status ] ?? '';
		return (string) apply_filters( 'wpistic_ffl_sms_message', $msg, $status, $transfer );
	}
}
