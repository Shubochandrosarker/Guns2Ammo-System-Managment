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

		$cutoff = gmdate('Y-m-d H:i:s', current_time('timestamp') - $hold_minutes * MINUTE_IN_SECONDS); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$now    = current_time('mysql');

		$stale = $wpdb->get_results($wpdb->prepare(
			"SELECT id, uuid, status, payment_mode, start_at FROM {$bookings_table}
			 WHERE status IN ('pending','reserved')
			 AND payment_mode IN ('full','deposit')
			 AND paid_amount <= 0
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
			if ($this->maybe_confirm_gateway_payment($booking)) {
				continue;
			}

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

	private function maybe_confirm_gateway_payment($booking)
	{
		global $wpdb;

		if (! is_object($booking) || empty($booking->id)) {
			return false;
		}

		$payments_table = $wpdb->prefix . 'g2ab_payments';
		$logs_table     = $wpdb->prefix . 'g2ab_logs';
		$payment        = $wpdb->get_row($wpdb->prepare(
			"SELECT gateway, transaction_id FROM {$payments_table}
			 WHERE booking_id = %d
			 AND status IN ('pending','created','requires_action')
			 AND transaction_id IS NOT NULL
			 AND transaction_id <> ''
			 ORDER BY id DESC
			 LIMIT 1",
			(int) $booking->id
		));

		if (! $payment || empty($payment->gateway) || empty($payment->transaction_id)) {
			return false;
		}

		if (! class_exists('G2AB_Gateway_Manager')) {
			return false;
		}

		$gateway = G2AB_Gateway_Manager::instance()->get((string) $payment->gateway);
		if (! $gateway || ! method_exists($gateway, 'confirm_payment')) {
			return false;
		}

		$result = $gateway->confirm_payment((string) $payment->transaction_id, array(
			'source'     => 'expiry_cron',
			'booking_id' => (int) $booking->id,
			'uuid'       => (string) $booking->uuid,
		));

		if (is_wp_error($result)) {
			$wpdb->insert($logs_table, array(
				'booking_id' => (int) $booking->id,
				'event_type' => 'expiry_payment_check_deferred',
				'severity'   => 'warning',
				'message'    => sprintf('Skipped hold expiry while %s payment status could not be confirmed: %s', (string) $payment->gateway, $result->get_error_message()),
				'context'    => wp_json_encode(array(
					'gateway'         => (string) $payment->gateway,
					'transaction_id'  => (string) $payment->transaction_id,
					'previous_status' => (string) $booking->status,
				)),
				'created_at' => current_time('mysql'),
			));
			return true;
		}

		if (true === $result || (is_array($result) && ! empty($result['handled']) && 'paid' === ($result['status'] ?? ''))) {
			$wpdb->insert($logs_table, array(
				'booking_id' => (int) $booking->id,
				'event_type' => 'expiry_payment_confirmed',
				'severity'   => 'info',
				'message'    => sprintf('Skipped hold expiry because %s confirmed payment before expiry.', (string) $payment->gateway),
				'context'    => wp_json_encode(array(
					'gateway'        => (string) $payment->gateway,
					'transaction_id' => (string) $payment->transaction_id,
				)),
				'created_at' => current_time('mysql'),
			));
			return true;
		}

		return false;
	}
}
