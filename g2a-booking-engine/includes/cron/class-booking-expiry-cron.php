<?php
/**
 * Booking-hold expiry cron.
 *
 * Runs every 5 minutes (see G2AB_Plugin::ensure_cron_scheduled). Finds any
 * online-payment booking that's still sitting in `pending` or `reserved`
 * after the configured hold window, marks it `expired`, and fires
 * `g2ab_booking_expired` so downstream listeners (email, slot release,
 * activity log) can react.
 *
 * Excluded from expiry:
 *   - in_store / free payment modes (no online-payment race to lose)
 *   - bookings whose start_at is already in the past — leaving those for the
 *     no-show flow instead of confusing them with "abandoned checkout"
 *
 * @package G2AB
 * @since   1.2.4
 */

if (! defined('ABSPATH')) {
	exit;
}

final class G2AB_Booking_Expiry_Cron
{

	public function run()
	{
		global $wpdb;

		$hold_minutes = (int) get_option('g2ab_reservation_hold_minutes', 15);
		if ($hold_minutes < 1) {
			return 0;
		}

		$bookings_table = $wpdb->prefix . 'g2ab_bookings';
		$logs_table     = $wpdb->prefix . 'g2ab_logs';

		// Bookings store created_at via current_time('mysql') — site-local.
		// Build the cutoff in the SAME timezone (site-local) so the comparison
		// is valid in non-UTC zones like Arizona/Phoenix (UTC-7 year-round).
		// Previous code used gmdate() against a site-local timestamp, which on
		// AZ produced a 7-hour skew.
		$now_ts = (int) current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$cutoff = date( 'Y-m-d H:i:s', $now_ts - $hold_minutes * MINUTE_IN_SECONDS );
		$now    = current_time( 'mysql' );

		$stale = $wpdb->get_results($wpdb->prepare(
			"SELECT id, uuid, status, payment_mode, start_at FROM {$bookings_table}
			 WHERE status IN ('pending','reserved')
			 AND payment_mode IN ('full','deposit')
			 AND created_at <= %s
			 AND start_at >= %s
			 LIMIT 200",
			$cutoff,
			$now
		));

		if (empty($stale)) {
			return 0;
		}

		$expired = 0;
		foreach ($stale as $booking) {
			$updated = $wpdb->update(
				$bookings_table,
				array('status' => 'expired', 'updated_at' => $now),
				array('id' => (int) $booking->id, 'status' => $booking->status), // optimistic lock
				array('%s', '%s'),
				array('%d', '%s')
			);
			if (! $updated) {
				continue;
			}
			$expired++;

			$wpdb->insert($logs_table, array(
				'booking_id' => (int) $booking->id,
				'event_type' => 'expired',
				'severity'   => 'info',
				'message'    => sprintf('Hold expired after %d minutes (was %s).', $hold_minutes, $booking->status),
				'context'    => wp_json_encode(array(
					'previous_status' => $booking->status,
					'payment_mode'    => $booking->payment_mode,
				)),
				'created_at' => $now,
			));

			do_action('g2ab_booking_expired', (int) $booking->id, array(
				'uuid'           => $booking->uuid,
				'previous_status' => $booking->status,
			));
			do_action('g2ab_booking_status_changed', (int) $booking->id, 'expired', $booking->status);
		}

		return $expired;
	}
}
