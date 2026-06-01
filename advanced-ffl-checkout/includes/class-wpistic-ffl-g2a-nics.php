<?php
/**
 * G2A — NICS 3-business-day rule helper.
 *
 * When a transfer enters the `delayed` state, federal law allows the dealer
 * to proceed at their discretion 3 business days later. This class fills in
 * the `nics_delay_expires` column when the status flips to delayed and runs
 * a nightly sweep to surface delays that have reached the threshold.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_NICS {

	public function __construct() {
		// Auto-set nics_delay_expires when status changes to a delayed bucket.
		add_action( 'wpistic_ffl_transfer_status_changed', [ __CLASS__, 'maybe_set_expiry' ], 5, 3 );
	}

	/**
	 * @param int    $transfer_id
	 * @param string $old_status
	 * @param string $new_status
	 */
	public static function maybe_set_expiry( int $transfer_id, string $old_status, string $new_status ): void {
		if ( ! in_array( $new_status, [ 'delayed', 'nics_delayed' ], true ) ) {
			return;
		}
		global $wpdb;
		$expires = self::three_business_days_from_now();
		$wpdb->update( // phpcs:ignore WordPress.DB
			DB::table( 'transfers' ),
			[
				'nics_delay_expires' => $expires,
				'nics_check_date'    => current_time( 'Y-m-d' ),
				'updated_at'         => current_time( 'mysql' ),
			],
			[ 'id' => $transfer_id ]
		);
	}

	/**
	 * Daily sweep — for every delayed transfer whose 3-day window has passed,
	 * email the admin once (using transfer staff_notes to suppress duplicates).
	 * Wired from the bootstrap via wpistic_ffl_daily_portal_runner.
	 */
	public static function maybe_flag_delays(): void {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT t.id, t.transfer_ref, t.customer_name, t.customer_email, t.nics_delay_expires
			 FROM ' . DB::table( 'transfers' ) . ' t
			 WHERE t.status IN (%s, %s)
			   AND t.nics_delay_expires IS NOT NULL
			   AND t.nics_delay_expires <= %s
			   AND (t.staff_notes IS NULL OR t.staff_notes NOT LIKE %s)
			 LIMIT 50',
			'delayed',
			'nics_delayed',
			current_time( 'Y-m-d' ),
			'%[nics-3day-flagged]%'
		) );

		if ( ! $rows ) {
			return;
		}

		$admin_email = get_option( 'wpistic_ffl_settings', [] )['notification_email'] ?? get_option( 'admin_email' );

		foreach ( (array) $rows as $row ) {
			$subject = sprintf( '[G2A] ⏰ NICS 3-Day Rule Reached — Transfer #%s', $row->transfer_ref );
			$body    = '<p>The federal NICS 3-day delay window has elapsed for the following transfer.</p>' .
				'<p><strong>Transfer:</strong> #' . esc_html( $row->transfer_ref ) . '<br>' .
				'<strong>Customer:</strong> ' . esc_html( $row->customer_name ) . '<br>' .
				'<strong>Delay expired:</strong> ' . esc_html( $row->nics_delay_expires ) . '</p>' .
				'<p>The dealer may, at their discretion, proceed with the transfer per 18 U.S.C. § 922(t)(1)(B)(ii).</p>' .
				'<p><a href="' . esc_url( admin_url( 'admin.php?page=wpistic-ffl-transfers&id=' . (int) $row->id ) ) . '">View transfer</a></p>';

			wp_mail( $admin_email, $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ] );

			// Append a flag marker so we don't re-email on the next run.
			$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
				'UPDATE ' . DB::table( 'transfers' ) . '
				 SET staff_notes = CONCAT( COALESCE(staff_notes,""), %s )
				 WHERE id = %d',
				"\n[nics-3day-flagged " . current_time( 'mysql' ) . ']',
				(int) $row->id
			) );

			$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB
				'transfer_id' => (int) $row->id,
				'event_type'  => 'nics_3day_reached',
				'notes'       => 'Federal 3-day delay window elapsed.',
				'actor'       => 'system',
				'actor_ip'    => '',
			] );
		}
	}

	/**
	 * Add 3 business days (Mon-Fri) to today. ATF 4473 instructions count
	 * "business days" as days the FFL is open — we approximate with Mon-Fri.
	 */
	private static function three_business_days_from_now(): string {
		$ts    = current_time( 'timestamp' );
		$added = 0;
		while ( $added < 3 ) {
			$ts  = strtotime( '+1 day', $ts );
			$dow = (int) date( 'N', $ts ); // 1=Mon..7=Sun
			if ( $dow < 6 ) {
				$added++;
			}
		}
		return date( 'Y-m-d', $ts );
	}
}
