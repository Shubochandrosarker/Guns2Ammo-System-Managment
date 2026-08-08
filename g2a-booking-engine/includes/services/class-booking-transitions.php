<?php
/**
 * Central booking status transition service.
 *
 * Every status change flows through transition(): it validates the move
 * against an explicit allowed-transition map, enforces payment invariants,
 * records the previous status in the audit log, and fires
 * `g2ab_booking_status_changed` with the previous status — one place,
 * one contract.
 *
 * Invariants:
 *   - `paid` requires a successful payment-ledger entry with the correct
 *     booking, currency and amount.
 *   - `confirmed` requires either a $0 total with a valid zero reason
 *     (eligible member / free booking type / free event) or a verified
 *     successful payment.
 *   - `refunded` / `partially_refunded` require a confirmed gateway refund or
 *     an explicitly recorded offline refund transaction. A dropdown alone can
 *     never move a booking into a refunded state.
 *
 * @package G2AB\Services
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Booking_Transitions {

	/**
	 * Allowed transitions: from-status => list of to-statuses.
	 *
	 * `expired → pending` exists so an expired checkout hold can be revived
	 * when the customer resumes payment through a fresh Stripe session.
	 *
	 * @return array<string,string[]>
	 */
	public static function allowed() {
		$map = array(
			'pending'            => array( 'paid', 'confirmed', 'cancelled', 'expired', 'payment_failed' ),
			// A declined payment is recoverable: the customer may retry through
			// the resume-payment flow, which re-checks inventory first.
			'payment_failed'     => array( 'pending', 'paid', 'cancelled', 'expired' ),
			'reserved'           => array( 'paid', 'confirmed', 'completed', 'cancelled', 'expired', 'no_show' ),
			'confirmed'          => array( 'completed', 'cancelled', 'no_show', 'paid', 'refunded' ),
			'paid'               => array( 'completed', 'cancelled', 'no_show', 'refunded', 'partially_refunded' ),
			'partially_refunded' => array( 'refunded', 'completed', 'cancelled', 'no_show' ),
			'completed'          => array( 'refunded', 'partially_refunded', 'no_show' ),
			'cancelled'          => array( 'refunded', 'partially_refunded' ),
			'no_show'            => array( 'completed', 'refunded', 'partially_refunded' ),
			'expired'            => array( 'pending', 'paid', 'cancelled' ),
			'refunded'           => array(),
		);
		return (array) apply_filters( 'g2ab_allowed_booking_transitions', $map );
	}

	/**
	 * @param string $from Current status.
	 * @param string $to   Target status.
	 * @return bool
	 */
	public static function is_allowed( $from, $to ) {
		$from = sanitize_key( (string) $from );
		$to   = sanitize_key( (string) $to );
		if ( $from === $to ) {
			return true; // idempotent no-op
		}
		$map = self::allowed();
		return isset( $map[ $from ] ) && in_array( $to, $map[ $from ], true );
	}

	/**
	 * Perform a validated status transition.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $to         Target status.
	 * @param array  $context    {
	 *     Optional context.
	 *     @type string $source          Who initiated: gateway|admin|cron|frontdesk|system.
	 *     @type int    $actor_id        User id performing the change.
	 *     @type string $reason          Free-text reason (required for admin overrides).
	 *     @type int    $payment_id      Ledger row supporting a paid transition.
	 *     @type array  $offline_refund  ['amount'=>float,'method'=>string,'reference'=>string]
	 *                                   Records an offline refund transaction.
	 *     @type bool   $skip_invariants Dangerous; only for migration tooling.
	 * }
	 * @return array|WP_Error ['booking_id','previous_status','status']
	 */
	public static function transition( $booking_id, $to, array $context = array() ) {
		global $wpdb;

		$booking_id = absint( $booking_id );
		$to         = sanitize_key( (string) $to );
		$table      = $wpdb->prefix . 'g2ab_bookings';

		if ( ! $booking_id || ! class_exists( 'G2AB_Booking_Statuses' ) || ! G2AB_Booking_Statuses::is_valid( $to ) ) {
			return new WP_Error( 'g2ab_invalid_transition', __( 'Invalid booking status.', 'g2a-booking' ), array( 'status' => 400 ) );
		}

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'g2ab_transaction_failed', __( 'Could not start transaction.', 'g2a-booking' ), array( 'status' => 500 ) );
		}

		$booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d FOR UPDATE", $booking_id ) );
		if ( ! $booking ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'g2ab_not_found', __( 'Booking not found.', 'g2a-booking' ), array( 'status' => 404 ) );
		}

		$from = sanitize_key( (string) $booking->status );

		if ( $from === $to ) {
			$wpdb->query( 'ROLLBACK' );
			return array( 'booking_id' => $booking_id, 'previous_status' => $from, 'status' => $to, 'noop' => true );
		}

		if ( ! self::is_allowed( $from, $to ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error(
				'g2ab_transition_not_allowed',
				sprintf(
					/* translators: 1: from status, 2: to status */
					__( 'A booking cannot move from “%1$s” to “%2$s”.', 'g2a-booking' ),
					$from,
					$to
				),
				array( 'status' => 409 )
			);
		}

		if ( empty( $context['skip_invariants'] ) ) {
			$invariant = self::check_invariants( $booking, $to, $context );
			if ( is_wp_error( $invariant ) ) {
				$wpdb->query( 'ROLLBACK' );
				return $invariant;
			}
		}

		$update  = array( 'status' => $to, 'updated_at' => current_time( 'mysql' ) );
		$formats = array( '%s', '%s' );
		if ( 'paid' === $to && ! empty( $context['paid_amount'] ) ) {
			$update['paid_amount'] = round( (float) $context['paid_amount'], 2 );
			$formats[]             = '%f';
		}

		$updated = $wpdb->update( $table, $update, array( 'id' => $booking_id, 'status' => $from ), $formats, array( '%d', '%s' ) );
		if ( false === $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'g2ab_transition_failed', __( 'Could not update booking status.', 'g2a-booking' ), array( 'status' => 500 ) );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'g2ab_transition_failed', __( 'Could not commit booking status.', 'g2a-booking' ), array( 'status' => 500 ) );
		}

		// Record an explicitly captured offline refund as a real ledger entry
		// so refunded state always has payment evidence behind it.
		if ( in_array( $to, array( 'refunded', 'partially_refunded' ), true ) && ! empty( $context['offline_refund'] ) && is_array( $context['offline_refund'] ) ) {
			self::record_offline_refund( $booking, $context['offline_refund'], $context );
		}

		$wpdb->insert( $wpdb->prefix . 'g2ab_logs', array(
			'booking_id' => $booking_id,
			'user_id'    => absint( $context['actor_id'] ?? get_current_user_id() ),
			'event_type' => 'status_changed',
			'severity'   => 'info',
			'message'    => sprintf( 'Status %s → %s (%s)', $from, $to, sanitize_key( (string) ( $context['source'] ?? 'system' ) ) ),
			'context'    => wp_json_encode( array(
				'previous_status' => $from,
				'new_status'      => $to,
				'source'          => sanitize_key( (string) ( $context['source'] ?? 'system' ) ),
				'reason'          => sanitize_text_field( (string) ( $context['reason'] ?? '' ) ),
				'payment_id'      => absint( $context['payment_id'] ?? 0 ),
			) ),
			'created_at' => current_time( 'mysql' ),
		) );

		do_action( 'g2ab_booking_status_changed', $booking_id, $to, $from );
		do_action( 'g2ab_booking_transitioned', $booking_id, $to, $from, $context );

		return array( 'booking_id' => $booking_id, 'previous_status' => $from, 'status' => $to );
	}

	/**
	 * Payment-evidence invariants for sensitive target statuses.
	 *
	 * @param object $booking Booking row (locked).
	 * @param string $to      Target status.
	 * @param array  $context Transition context.
	 * @return true|WP_Error
	 */
	private static function check_invariants( $booking, $to, array $context ) {
		global $wpdb;
		$pt = $wpdb->prefix . 'g2ab_payments';

		if ( 'paid' === $to ) {
			$payment = self::successful_ledger_entry( $booking, absint( $context['payment_id'] ?? 0 ) );
			if ( ! $payment ) {
				return new WP_Error(
					'g2ab_paid_requires_ledger',
					__( 'A booking can only be marked paid with a successful payment record for the right amount and currency. Record the payment first.', 'g2a-booking' ),
					array( 'status' => 409 )
				);
			}
			return true;
		}

		if ( 'confirmed' === $to ) {
			$total = round( (float) $booking->total_amount, 2 );
			if ( $total <= 0 ) {
				return true; // Free booking (eligible member lane / free event).
			}
			if ( self::successful_ledger_entry( $booking, absint( $context['payment_id'] ?? 0 ) ) ) {
				return true;
			}
			return new WP_Error(
				'g2ab_confirm_requires_payment',
				__( 'A payable booking can only be confirmed after a verified successful payment.', 'g2a-booking' ),
				array( 'status' => 409 )
			);
		}

		if ( in_array( $to, array( 'refunded', 'partially_refunded' ), true ) ) {
			// Evidence path 1: a gateway refund already recorded on the ledger.
			$refund_row = $wpdb->get_row( $wpdb->prepare(
				"SELECT id FROM {$pt}
				  WHERE booking_id = %d
				    AND ( refund_amount > 0 OR status IN ('refunded','partial_refund','partially_refunded') )
				  LIMIT 1",
				(int) $booking->id
			) );
			if ( $refund_row ) {
				return true;
			}
			// Evidence path 2: an explicitly recorded offline refund in this call.
			$offline = $context['offline_refund'] ?? null;
			if ( is_array( $offline ) && (float) ( $offline['amount'] ?? 0 ) > 0 && '' !== trim( (string) ( $offline['method'] ?? '' ) ) ) {
				return true;
			}
			return new WP_Error(
				'g2ab_refund_requires_evidence',
				__( 'Refunded status requires a confirmed gateway refund or an explicitly recorded offline refund (amount + method).', 'g2a-booking' ),
				array( 'status' => 409 )
			);
		}

		return true;
	}

	/**
	 * A successful ledger row that matches this booking's currency and amount
	 * (full total or the recorded due_now for a staff-recorded deposit).
	 *
	 * @param object $booking    Booking row.
	 * @param int    $payment_id Optional specific ledger row id.
	 * @return object|null
	 */
	private static function successful_ledger_entry( $booking, $payment_id = 0 ) {
		global $wpdb;
		$pt = $wpdb->prefix . 'g2ab_payments';

		if ( $payment_id ) {
			$payment = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$pt} WHERE id = %d AND booking_id = %d AND status IN ('succeeded','paid','completed','captured') LIMIT 1",
				$payment_id,
				(int) $booking->id
			) );
		} else {
			$payment = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$pt} WHERE booking_id = %d AND status IN ('succeeded','paid','completed','captured') ORDER BY id DESC LIMIT 1",
				(int) $booking->id
			) );
		}
		if ( ! $payment ) {
			return null;
		}

		$booking_currency = strtoupper( (string) ( $booking->currency ?: 'USD' ) );
		$payment_currency = strtoupper( (string) ( $payment->currency ?: 'USD' ) );
		if ( $booking_currency !== $payment_currency ) {
			return null;
		}

		if ( function_exists( 'g2ab_validate_payment_amount' ) && ! g2ab_validate_payment_amount( $booking, (float) $payment->amount ) ) {
			// The single row doesn't cover the total (e.g. a split desk
			// payment). Accept when the SUM of successful ledger entries in
			// the booking currency covers the full total.
			$sum = (float) $wpdb->get_var( $wpdb->prepare(
				"SELECT COALESCE(SUM(amount), 0) FROM {$pt}
				  WHERE booking_id = %d AND status IN ('succeeded','paid','completed','captured') AND UPPER(currency) = %s",
				(int) $booking->id,
				$booking_currency
			) );
			if ( $sum + 0.01 < round( (float) $booking->total_amount, 2 ) ) {
				return null;
			}
		}

		return $payment;
	}

	/**
	 * Persist an offline refund as a ledger transaction.
	 *
	 * @param object $booking Booking row.
	 * @param array  $refund  ['amount','method','reference'].
	 * @param array  $context Transition context.
	 */
	private static function record_offline_refund( $booking, array $refund, array $context ) {
		global $wpdb;
		$amount = round( (float) ( $refund['amount'] ?? 0 ), 2 );
		if ( $amount <= 0 ) {
			return;
		}
		if ( function_exists( 'g2ab_insert_or_update_payment' ) ) {
			g2ab_insert_or_update_payment( $wpdb->prefix . 'g2ab_payments', array(
				'booking_id'     => (int) $booking->id,
				'gateway'        => 'offline_refund',
				'transaction_id' => 'offline_refund_' . (int) $booking->id . '_' . time(),
				'amount'         => -1 * $amount,
				'refund_amount'  => $amount,
				'currency'       => strtoupper( (string) ( $booking->currency ?: 'USD' ) ),
				'status'         => 'refunded',
				'payment_method' => sanitize_key( (string) ( $refund['method'] ?? 'offline' ) ),
				'metadata'       => wp_json_encode( array(
					'reference' => sanitize_text_field( (string) ( $refund['reference'] ?? '' ) ),
					'actor_id'  => absint( $context['actor_id'] ?? get_current_user_id() ),
					'reason'    => sanitize_text_field( (string) ( $context['reason'] ?? '' ) ),
				) ),
				'processed_at'   => current_time( 'mysql' ),
				'created_at'     => current_time( 'mysql' ),
			), array( '%d', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s' ) );
		}
	}
}
