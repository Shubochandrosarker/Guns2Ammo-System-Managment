<?php
/**
 * GDPR — consent enforcement on the bundled shortcode, WP Personal Data
 * Exporter & Eraser integration, and a daily auto-purge cron.
 *
 * @package Wpistic_Formistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GDPR helpers.
 */
class Wpistic_Formistic_Gdpr {

	/** Cron hook name. */
	const CRON_HOOK = 'wpistic_formistic_daily_cleanup';

	/**
	 * Register hooks.
	 */
	public function register() {
		add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers',   [ $this, 'register_eraser' ] );
		add_action( self::CRON_HOOK, [ $this, 'daily_cleanup' ] );
	}

	/* ------------------------------------------------------------------
	 * Consent
	 * ------------------------------------------------------------------ */

	/**
	 * Is the consent checkbox enabled on the bundled shortcode?
	 *
	 * @return bool
	 */
	public static function consent_enabled() {
		return '1' === get_option( 'wpistic_formistic_gdpr_consent_enabled', '0' );
	}

	/**
	 * Is consent strictly required (vs. optional checkbox)?
	 *
	 * @return bool
	 */
	public static function consent_required() {
		return '1' === get_option( 'wpistic_formistic_gdpr_required', '1' );
	}

	/**
	 * The consent statement shown next to the checkbox.
	 *
	 * @return string
	 */
	public static function consent_text() {
		$default = __( 'I agree to the processing of my personal data as described in the privacy policy.', 'formistic' );
		return (string) get_option( 'wpistic_formistic_gdpr_consent_text', $default );
	}

	/**
	 * Build the human-readable record stored in the submission's fields.
	 *
	 * @return string
	 */
	public static function consent_record_value() {
		return sprintf(
			/* translators: %s: date and time */
			__( 'Yes — accepted %s', 'formistic' ),
			date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
		);
	}

	/* ------------------------------------------------------------------
	 * WP Personal Data Exporter / Eraser
	 * ------------------------------------------------------------------ */

	/**
	 * Register the personal data exporter.
	 *
	 * @param array $exporters Existing exporters.
	 * @return array
	 */
	public function register_exporter( $exporters ) {
		$exporters['formistic'] = [
			'exporter_friendly_name' => __( 'Formistic Submissions', 'formistic' ),
			'callback'               => [ $this, 'export_personal_data' ],
		];
		return $exporters;
	}

	/**
	 * Register the personal data eraser.
	 *
	 * @param array $erasers Existing erasers.
	 * @return array
	 */
	public function register_eraser( $erasers ) {
		$erasers['formistic'] = [
			'eraser_friendly_name' => __( 'Formistic Submissions', 'formistic' ),
			'callback'             => [ $this, 'erase_personal_data' ],
		];
		return $erasers;
	}

	/**
	 * Export all submissions for the given email address.
	 *
	 * @param string $email_address Subject email.
	 * @param int    $page          Pagination cursor (we return everything in one page).
	 * @return array WP-format export structure.
	 */
	public function export_personal_data( $email_address, $page = 1 ) {
		$page  = max( 1, (int) $page );
		$items = [];

		if ( 1 === $page ) {
			foreach ( Wpistic_Formistic_Database::ids_by_email( $email_address ) as $id ) {
				$row    = Wpistic_Formistic_Database::get_submission( $id );
				if ( ! $row ) {
					continue;
				}
				$fields = json_decode( (string) $row->fields, true );
				$fields = is_array( $fields ) ? $fields : [];

				$data = [
					[ 'name' => __( 'Submission ID', 'formistic' ),  'value' => (int) $row->id ],
					[ 'name' => __( 'Form Name',    'formistic' ),   'value' => (string) $row->form_name ],
					[ 'name' => __( 'Submitted At', 'formistic' ),   'value' => (string) $row->created_at ],
					[ 'name' => __( 'Sender Name',  'formistic' ),   'value' => (string) $row->sender_name ],
					[ 'name' => __( 'Sender Email', 'formistic' ),   'value' => (string) $row->sender_email ],
					[ 'name' => __( 'Sender Phone', 'formistic' ),   'value' => (string) $row->sender_phone ],
					[ 'name' => __( 'Subject',      'formistic' ),   'value' => (string) $row->subject ],
					[ 'name' => __( 'Message',      'formistic' ),   'value' => (string) $row->message ],
					[ 'name' => __( 'Source URL',   'formistic' ),   'value' => (string) $row->source_url ],
					[ 'name' => __( 'IP Address',   'formistic' ),   'value' => (string) $row->ip_address ],
				];
				foreach ( $fields as $label => $value ) {
					$data[] = [ 'name' => (string) $label, 'value' => is_array( $value ) ? wp_json_encode( $value ) : (string) $value ];
				}

				$items[] = [
					'group_id'    => 'formistic',
					'group_label' => __( 'Formistic Submissions', 'formistic' ),
					'item_id'     => 'wpistic-formistic-submission-' . (int) $row->id,
					'data'        => $data,
				];
			}
		}

		return [
			'data' => $items,
			'done' => true,
		];
	}

	/**
	 * Erase all submissions for the given email address.
	 *
	 * @param string $email_address Subject email.
	 * @param int    $page          Pagination cursor.
	 * @return array WP-format erase structure.
	 */
	public function erase_personal_data( $email_address, $page = 1 ) {
		$items_removed  = false;
		$items_retained = false;
		$messages       = [];

		$ids = Wpistic_Formistic_Database::ids_by_email( $email_address );
		foreach ( $ids as $id ) {
			if ( Wpistic_Formistic_Database::delete_submission( $id ) ) {
				$items_removed = true;
			} else {
				$items_retained = true;
			}
		}
		if ( $ids ) {
			/* translators: %d: number of submissions */
			$messages[] = sprintf( _n( '%d submission removed.', '%d submissions removed.', count( $ids ), 'formistic' ), count( $ids ) );
		}

		return [
			'items_removed'  => $items_removed,
			'items_retained' => $items_retained,
			'messages'       => $messages,
			'done'           => true,
		];
	}

	/* ------------------------------------------------------------------
	 * Auto-purge cron
	 * ------------------------------------------------------------------ */

	/**
	 * Schedule the daily cleanup event on activation/boot.
	 */
	public static function maybe_schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Remove the cron event on uninstall.
	 */
	public static function unschedule() {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	/**
	 * Cron callback — purge old submissions if auto-purge is enabled.
	 */
	public function daily_cleanup() {
		if ( '1' !== get_option( 'wpistic_formistic_gdpr_autopurge_enabled', '0' ) ) {
			return;
		}
		$days = (int) get_option( 'wpistic_formistic_gdpr_autopurge_days', 365 );
		if ( $days < 1 ) {
			return;
		}
		Wpistic_Formistic_Database::purge_older_than( $days );
	}
}
