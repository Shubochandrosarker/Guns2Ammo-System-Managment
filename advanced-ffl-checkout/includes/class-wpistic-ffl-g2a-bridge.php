<?php
/**
 * G2A — Theme-bridge.
 *
 * Hooks the existing Guns2Ammo theme `admin_post_g2a_request` handler so a
 * "Transfer Request" form submission also creates a placeholder transfer
 * record in the FFL plugin. The theme keeps emailing the admin and logging
 * to WPistic Contact Form (unchanged); the FFL plugin gains a tracked record
 * that admins can promote into a real transfer.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Bridge {

	public function __construct() {
		// Priority 5 — run before the theme handler redirects so we still
		// have $_POST. The theme handler is at priority 10.
		add_action( 'admin_post_g2a_request',        [ $this, 'capture_transfer_request' ], 5 );
		add_action( 'admin_post_nopriv_g2a_request', [ $this, 'capture_transfer_request' ], 5 );
	}

	public function capture_transfer_request(): void {
		// Honeypot + nonce already checked by the theme handler. We re-check
		// minimally to be defensive (the theme handler hasn't run yet at p5).
		if ( ! empty( $_POST['g2a_hp'] ) ) {
			return;
		}
		if ( ! isset( $_POST['g2a_nonce'] ) || ! wp_verify_nonce( (string) wp_unslash( $_POST['g2a_nonce'] ), 'g2a_request' ) ) {
			return;
		}

		$subject   = strtolower( trim( sanitize_text_field( wp_unslash( $_POST['g2a_subject'] ?? '' ) ) ) );
		$form_type = strtolower( trim( sanitize_text_field( wp_unslash( $_POST['form_type'] ?? '' ) ) ) );
		// Strict match — the shared handler is used by contact/sell/reservation/
		// support forms too. We only want the canonical Transfer Request form.
		$is_transfer = ( 'ffl transfer request' === $subject ) || ( 'ffl_transfer' === $form_type );
		if ( ! $is_transfer ) {
			return;
		}

		$fields = self::collect_fields();
		if ( empty( $fields['email'] ) ) {
			return;
		}

		global $wpdb;
		$ref = apply_filters( 'wpistic_ffl_transfer_ref_prefix', 'G2A' ) . '-' . strtoupper( substr( uniqid( '', true ), -8 ) );

		$wpdb->insert( DB::table( 'transfers' ), [ // phpcs:ignore WordPress.DB
			'transfer_ref'     => $ref,
			'order_id'         => 0,
			'customer_id'      => get_current_user_id() ?: null,
			'customer_name'    => trim( ( $fields['name'] ?? '' ) ),
			'customer_email'   => $fields['email'],
			'customer_phone'   => $fields['phone'] ?? '',
			'dealer_id'        => 0,
			'status'           => 'dealer_selected', // placeholder — admin promotes from here
			'item_description' => $fields['item'] ?? $fields['firearm'] ?? $fields['details'] ?? '',
			'item_make'        => $fields['make'] ?? '',
			'item_model'       => $fields['model'] ?? '',
			'item_caliber'     => $fields['caliber'] ?? '',
			'item_type'        => $fields['type'] ?? 'handgun',
		] );
		$transfer_id = (int) $wpdb->insert_id;
		if ( ! $transfer_id ) {
			return;
		}

		$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB
			'transfer_id' => $transfer_id,
			'event_type'  => 'created',
			'new_status'  => 'dealer_selected',
			'notes'       => 'Created from theme Transfer Request form.',
			'actor'       => 'theme-bridge',
			'actor_ip'    => Token::client_ip(),
		] );

		do_action( 'wpistic_ffl_transfer_created', $transfer_id, 0 );
	}

	/**
	 * Collect g2a_f_* fields and normalise label keys to lowercase no-space.
	 * Returns ['email' => '…', 'name' => '…', 'phone' => '…', …].
	 */
	private static function collect_fields(): array {
		$out = [];
		foreach ( $_POST as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification
			if ( 0 !== strpos( $key, 'g2a_f_' ) ) {
				continue;
			}
			$label = strtolower( str_replace( [ 'g2a_f_', '_', '-' ], '', $key ) );
			$clean = is_array( $value )
				? implode( ', ', array_map( 'sanitize_text_field', wp_unslash( $value ) ) )
				: sanitize_textarea_field( wp_unslash( $value ) );
			if ( '' === $clean ) {
				continue;
			}
			if ( 'email' === $label ) {
				$clean = sanitize_email( $clean );
			}
			$out[ $label ] = $clean;
		}
		return $out;
	}
}
