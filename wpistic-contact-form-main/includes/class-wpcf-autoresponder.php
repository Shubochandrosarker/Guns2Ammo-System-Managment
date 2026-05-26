<?php
/**
 * Auto-Responder — emails a confirmation to the submitter after a successful
 * capture. Listens for the `WPISTIC_CF_submission_captured` action emitted by
 * WPISTIC_CF_Capture::store().
 *
 * @package WPISTIC_CF
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends auto-responder emails.
 */
class WPISTIC_CF_Autoresponder {

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'WPISTIC_CF_submission_captured', [ $this, 'maybe_send' ], 20, 3 );
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
		if ( '1' !== get_option( 'WPISTIC_CF_ar_enabled', '0' ) ) {
			return;
		}

		$submission = WPISTIC_CF_Database::get_submission( (int) $submission_id );
		if ( ! $submission || ! is_email( $submission->sender_email ) ) {
			return;
		}

		$placeholders = [
			'{name}'      => $submission->sender_name !== '' ? $submission->sender_name : __( 'there', 'wpistic-contact-form' ),
			'{form}'      => $form_name,
			'{message}'   => $submission->message,
			'{site_name}' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{site_url}'  => home_url( '/' ),
			'{date}'      => date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
		];

		$subject = strtr( (string) get_option( 'WPISTIC_CF_ar_subject', '' ), $placeholders );
		$body    = strtr( (string) get_option( 'WPISTIC_CF_ar_body', '' ), $placeholders );
		if ( '' === trim( $subject ) || '' === trim( $body ) ) {
			return;
		}

		$from_name  = get_option( 'WPISTIC_CF_reply_from_name', get_bloginfo( 'name' ) );
		$from_email = get_option( 'WPISTIC_CF_reply_from_email', get_option( 'admin_email' ) );
		$headers    = [];
		if ( is_email( $from_email ) ) {
			$headers[] = sprintf( 'From: %s <%s>', $from_name, $from_email );
			$headers[] = 'Reply-To: ' . $from_email;
		}

		WPISTIC_CF_Capture::send_internal( $submission->sender_email, $subject, $body, $headers );
	}

	/**
	 * Replay the auto-responder for one existing submission.
	 *
	 * @param int $submission_id Submission ID.
	 */
	public static function replay_for_submission( $submission_id ) {
		$row = WPISTIC_CF_Database::get_submission( (int) $submission_id );
		if ( ! $row ) {
			return;
		}
		$fields = json_decode( (string) $row->fields, true );
		$fields = is_array( $fields ) ? $fields : [];
		( new self() )->maybe_send( (int) $row->id, (string) $row->form_name, $fields );
	}
}
