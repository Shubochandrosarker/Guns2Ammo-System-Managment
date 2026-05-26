<?php
/**
 * G2AB_Checkin_Service — front-desk check-in operations.
 *
 * Wraps the `g2ab_checkins` table plus the bookings.checked_in_at column
 * and emits the lifecycle hooks the email automation / activity log
 * already listen for.
 *
 * Hooks fired:
 *   g2ab_booking_checked_in       (booking_id, checkin_row, user_id)
 *   g2ab_customer_checked_in      (booking_id, customer_id, user_id)
 *   g2ab_waiver_verified          (booking_id, user_id)
 *   g2ab_payment_collected        (booking_id, amount, method, user_id)
 *
 * @package G2AB\Services
 * @since   1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Checkin_Service {

	/**
	 * Mark a booking as checked in. Idempotent — re-running just refreshes
	 * the staff note + verification flags.
	 *
	 * @param int   $booking_id
	 * @param array $args   { 'waiver_verified' => bool, 'payment_verified' => bool, 'note' => string }
	 * @param int   $user_id Staff member performing the check-in.
	 * @return array|WP_Error checkin row data on success.
	 */
	public static function check_in( $booking_id, $args = array(), $user_id = null ) {
		global $wpdb;
		$booking = self::booking( $booking_id );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		if ( in_array( $booking->status, array( 'cancelled', 'expired', 'refunded', 'no_show' ), true ) ) {
			return new WP_Error( 'g2ab_terminal', __( 'Booking is closed; cannot check in.', 'g2a-booking' ), array( 'status' => 409 ) );
		}

		if ( null === $user_id ) {
			$user_id = get_current_user_id();
		}

		$now              = current_time( 'mysql' );
		$waiver_verified  = ! empty( $args['waiver_verified'] ) ? 1 : 0;
		$payment_verified = ! empty( $args['payment_verified'] ) ? 1 : 0;
		$note             = isset( $args['note'] ) ? sanitize_textarea_field( (string) $args['note'] ) : '';

		$ci_table = $wpdb->prefix . 'g2ab_checkins';
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$ci_table} WHERE booking_id = %d", (int) $booking_id ) );

		if ( $existing ) {
			$wpdb->update(
				$ci_table,
				array(
					'checked_in_at'    => $existing->checked_in_at, // preserve original time
					'waiver_verified'  => $waiver_verified ?: (int) $existing->waiver_verified,
					'payment_verified' => $payment_verified ?: (int) $existing->payment_verified,
					'note'             => '' !== $note ? $note : $existing->note,
					'checked_in_by'    => $existing->checked_in_by ?: ( $user_id ?: null ),
				),
				array( 'id' => (int) $existing->id ),
				array( '%s', '%d', '%d', '%s', '%d' ),
				array( '%d' )
			);
			$row_id = (int) $existing->id;
			$first  = false;
		} else {
			$wpdb->insert(
				$ci_table,
				array(
					'booking_id'       => (int) $booking_id,
					'checked_in_by'    => $user_id ?: null,
					'checked_in_at'    => $now,
					'waiver_verified'  => $waiver_verified,
					'payment_verified' => $payment_verified,
					'note'             => $note,
					'created_at'       => $now,
				),
				array( '%d', '%d', '%s', '%d', '%d', '%s', '%s' )
			);
			$row_id = (int) $wpdb->insert_id;
			$first  = true;

			$wpdb->update(
				$wpdb->prefix . 'g2ab_bookings',
				array( 'checked_in_at' => $now, 'updated_at' => $now ),
				array( 'id' => (int) $booking_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$ci_table} WHERE id = %d", $row_id ), ARRAY_A );

		if ( class_exists( 'G2AB_Booking_Activity' ) ) {
			G2AB_Booking_Activity::log(
				(int) $booking_id,
				$first ? 'checked_in' : 'checkin_updated',
				null,
				array(
					'waiver_verified'  => (bool) $row['waiver_verified'],
					'payment_verified' => (bool) $row['payment_verified'],
				),
				$note,
				$user_id ?: null
			);
		}

		if ( $first ) {
			do_action( 'g2ab_booking_checked_in', (int) $booking_id, $row, $user_id ?: null );
			if ( ! empty( $booking->user_id ) ) {
				do_action( 'g2ab_customer_checked_in', (int) $booking_id, (int) $booking->user_id, $user_id ?: null );
			}
		}
		if ( $waiver_verified ) {
			do_action( 'g2ab_waiver_verified', (int) $booking_id, $user_id ?: null );
		}

		return $row;
	}

	/**
	 * Record a payment captured at the front desk (cash / card terminal /
	 * comp). Updates `paid_amount` and inserts a payments row so the
	 * Payments report stays consistent.
	 *
	 * @param int    $booking_id
	 * @param float  $amount  Dollars (NOT cents).
	 * @param string $method  cash|card_terminal|admin_comp|other
	 * @param string $note    Optional staff note.
	 * @param int    $user_id Staff member.
	 * @return array|WP_Error
	 */
	public static function collect_payment( $booking_id, $amount, $method = 'cash', $note = '', $user_id = null ) {
		global $wpdb;
		$booking = self::booking( $booking_id );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}

		$amount = round( max( 0, (float) $amount ), 2 );
		$method = sanitize_key( $method );
		if ( ! in_array( $method, array( 'cash', 'card_terminal', 'admin_comp', 'other' ), true ) ) {
			$method = 'cash';
		}
		if ( $amount <= 0 && 'admin_comp' !== $method ) {
			return new WP_Error( 'g2ab_bad_amount', __( 'Amount must be greater than zero.', 'g2a-booking' ), array( 'status' => 400 ) );
		}
		if ( null === $user_id ) {
			$user_id = get_current_user_id();
		}

		$now      = current_time( 'mysql' );
		$new_paid = round( (float) $booking->paid_amount + $amount, 2 );

		$wpdb->update(
			$wpdb->prefix . 'g2ab_bookings',
			array( 'paid_amount' => $new_paid, 'updated_at' => $now ),
			array( 'id' => (int) $booking_id ),
			array( '%f', '%s' ),
			array( '%d' )
		);

		$wpdb->insert(
			$wpdb->prefix . 'g2ab_payments',
			array(
				'booking_id'       => (int) $booking_id,
				'gateway'          => 'admin_' . $method,
				'transaction_id'   => 'frontdesk-' . wp_generate_password( 12, false ),
				'amount'           => $amount,
				'currency'         => $booking->currency ?: 'USD',
				'status'           => 'captured',
				'payment_method'   => $method,
				'gateway_response' => wp_json_encode( array( 'collected_by' => $user_id, 'note' => $note ) ),
				'metadata'         => wp_json_encode( array( 'source' => 'frontdesk' ) ),
				'processed_at'     => $now,
				'created_at'       => $now,
			),
			array( '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		// If booking is now fully paid, advance status.
		$total_due = (float) $booking->total_amount;
		$new_status = $booking->status;
		if ( $total_due > 0 && $new_paid >= $total_due && in_array( $booking->status, array( 'pending', 'reserved', 'confirmed' ), true ) ) {
			$new_status = 'paid';
			$wpdb->update(
				$wpdb->prefix . 'g2ab_bookings',
				array( 'status' => 'paid', 'updated_at' => $now ),
				array( 'id' => (int) $booking_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			do_action( 'g2ab_booking_status_changed', (int) $booking_id, 'paid', $booking->status );
			do_action( 'g2ab_booking_paid', (int) $booking_id, array( 'amount' => $amount, 'method' => $method ) );
		}

		if ( class_exists( 'G2AB_Booking_Activity' ) ) {
			G2AB_Booking_Activity::log(
				(int) $booking_id,
				'payment_collected',
				array( 'paid_amount' => (float) $booking->paid_amount ),
				array( 'paid_amount' => $new_paid, 'amount' => $amount, 'method' => $method ),
				$note,
				$user_id ?: null
			);
		}

		do_action( 'g2ab_payment_collected', (int) $booking_id, $amount, $method, $user_id ?: null );

		return array(
			'booking_id' => (int) $booking_id,
			'paid_amount' => $new_paid,
			'total_amount' => $total_due,
			'status'     => $new_status,
			'amount'     => $amount,
			'method'     => $method,
		);
	}

	/**
	 * Append a staff note to the booking's notes column. Notes are
	 * concatenated with a timestamp + author prefix so the audit trail is
	 * readable in the bookings list.
	 */
	public static function add_note( $booking_id, $note, $user_id = null ) {
		global $wpdb;
		$booking = self::booking( $booking_id );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		$note = sanitize_textarea_field( (string) $note );
		if ( '' === $note ) {
			return new WP_Error( 'g2ab_empty_note', __( 'Note is empty.', 'g2a-booking' ), array( 'status' => 400 ) );
		}
		if ( null === $user_id ) {
			$user_id = get_current_user_id();
		}
		$user      = $user_id ? get_userdata( $user_id ) : null;
		$author    = $user ? $user->display_name : 'Staff';
		$now       = current_time( 'mysql' );
		$prefix    = sprintf( '[%s — %s] ', $now, $author );
		$existing  = (string) $booking->notes;
		$new_value = trim( $existing . "\n" . $prefix . $note );

		$wpdb->update(
			$wpdb->prefix . 'g2ab_bookings',
			array( 'notes' => $new_value, 'updated_at' => $now ),
			array( 'id' => (int) $booking_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( class_exists( 'G2AB_Booking_Activity' ) ) {
			G2AB_Booking_Activity::log( (int) $booking_id, 'noted', null, null, $note, $user_id ?: null );
		}

		return array( 'booking_id' => (int) $booking_id, 'notes' => $new_value );
	}

	private static function booking( $booking_id ) {
		global $wpdb;
		$booking_id = (int) $booking_id;
		if ( $booking_id < 1 ) {
			return new WP_Error( 'g2ab_bad_id', __( 'Invalid booking id.', 'g2a-booking' ), array( 'status' => 400 ) );
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}g2ab_bookings WHERE id = %d", $booking_id ) );
		if ( ! $row ) {
			return new WP_Error( 'g2ab_not_found', __( 'Booking not found.', 'g2a-booking' ), array( 'status' => 404 ) );
		}
		return $row;
	}
}
