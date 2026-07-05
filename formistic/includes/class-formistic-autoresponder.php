<?php
/**
 * Auto-Responder — emails a confirmation to the submitter after a successful
 * capture. Listens for the `wpistic_formistic_submission_captured` action emitted by
 * Wpistic_Formistic_Capture::store().
 *
 * @package Wpistic_Formistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends auto-responder emails.
 */
class Wpistic_Formistic_Autoresponder {

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'wpistic_formistic_submission_captured', [ $this, 'maybe_send' ], 20, 3 );
	}

	/**
	 * Send the auto-responder email if enabled and the submitter has a valid
	 * email address.
	 *
	 * @param int    $submission_id Submission ID.
	 * @param string $form_name     Form name.
	 * @param array  $fields        Captured fields (label => value).
	 */
	public function maybe_send( $submission_id, $form_name, $fields ) {
		// Global kill-switch. Set WPISTIC_FORMISTIC_EMAIL_DISABLED in wp-config.php on
		// staging/dev to suppress all outbound auto-responders without
		// touching the per-form admin toggle.
		if ( ( defined( 'WPISTIC_FORMISTIC_EMAIL_DISABLED' ) && WPISTIC_FORMISTIC_EMAIL_DISABLED )
			|| (bool) get_option( 'wpistic_formistic_emails_disabled', false ) ) {
			return;
		}

		if ( '1' !== get_option( 'wpistic_formistic_ar_enabled', '0' ) ) {
			return;
		}

		$submission = Wpistic_Formistic_Database::get_submission( (int) $submission_id );
		if ( ! $submission || ! is_email( $submission->sender_email ) ) {
			return;
		}

		// Per-target throttle: do not re-send to the same email more than
		// once per hour, even if a script floods the capture endpoint.
		$throttle_key = 'wpistic_formistic_ar_' . md5( strtolower( trim( $submission->sender_email ) ) );
		if ( false !== get_transient( $throttle_key ) ) {
			return;
		}
		set_transient( $throttle_key, 1, HOUR_IN_SECONDS );

		$placeholders = [
			'{name}'      => $submission->sender_name !== '' ? $submission->sender_name : __( 'there', 'formistic' ),
			'{form}'      => $form_name,
			'{message}'   => $submission->message,
			'{site_name}' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{site_url}'  => home_url( '/' ),
			'{date}'      => date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
		];

		$subject = strtr( (string) get_option( 'wpistic_formistic_ar_subject', '' ), $placeholders );
		$body    = strtr( (string) get_option( 'wpistic_formistic_ar_body', '' ), $placeholders );
		if ( '' === trim( $subject ) || '' === trim( $body ) ) {
			return;
		}

		$from_name  = get_option( 'wpistic_formistic_reply_from_name', get_bloginfo( 'name' ) );
		$from_email = get_option( 'wpistic_formistic_reply_from_email', get_option( 'admin_email' ) );
		$headers    = [];
		if ( is_email( $from_email ) ) {
			$headers[] = sprintf( 'From: %s <%s>', $from_name, $from_email );
			$headers[] = 'Reply-To: ' . $from_email;
		}

		Wpistic_Formistic_Capture::send_internal( $submission->sender_email, $subject, $body, $headers );
	}

	/**
	 * Replay the auto-responder for one existing submission.
	 *
	 * @param int $submission_id Submission ID.
	 */
	public static function replay_for_submission( $submission_id ) {
		$row = Wpistic_Formistic_Database::get_submission( (int) $submission_id );
		if ( ! $row ) {
			return;
		}
		$fields = json_decode( (string) $row->fields, true );
		$fields = is_array( $fields ) ? $fields : [];
		( new self() )->maybe_send( (int) $row->id, (string) $row->form_name, $fields );
	}
}
