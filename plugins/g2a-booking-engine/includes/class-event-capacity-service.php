<?php
/**
 * Canonical event occurrence capacity calculations.
 *
 * @package G2AB
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Event_Capacity_Service {

	/**
	 * Canonical hold duration for event-seat availability.
	 *
	 * @return int
	 */
	public static function hold_minutes() {
		$minutes = (int) get_option( 'g2ab_reservation_hold_minutes', 15 );
		$minutes = max( 1, min( 120, $minutes ) );
		return (int) apply_filters( 'g2ab_event_hold_minutes', $minutes );
	}

	/**
	 * Occurrence capacity, using occurrence override first, then event fallback.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return array|WP_Error
	 */
	public static function get_capacity( $occurrence_id ) {
		global $wpdb;
		$occ_table = $wpdb->prefix . 'g2ab_event_occurrences';
		$ev_table  = $wpdb->prefix . 'g2ab_events';
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT o.id AS occurrence_id, o.event_id, o.capacity AS occurrence_capacity, o.status AS occurrence_status,
			        o.start_at, o.end_at, e.capacity AS event_capacity, e.status AS event_status
			 FROM {$occ_table} o
			 LEFT JOIN {$ev_table} e ON e.id = o.event_id
			 WHERE o.id = %d LIMIT 1",
			(int) $occurrence_id
		) );
		if ( ! $row ) {
			return new WP_Error( 'g2ab_bad_occurrence', __( 'That date is no longer available.', 'g2a-booking' ) );
		}
		$occ_capacity = (int) $row->occurrence_capacity;
		$event_capacity = (int) $row->event_capacity;
		return array(
			'occurrence_id' => (int) $row->occurrence_id,
			'event_id'      => (int) $row->event_id,
			'capacity'      => $occ_capacity > 0 ? $occ_capacity : $event_capacity,
			'source'        => $occ_capacity > 0 ? 'occurrence' : 'event_fallback',
			'start_at'      => (string) $row->start_at,
			'end_at'        => (string) $row->end_at,
			'status'        => (string) $row->occurrence_status,
			'event_status'  => (string) $row->event_status,
		);
	}

	/**
	 * Seats currently consuming capacity for an occurrence.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return int
	 */
	public static function get_reserved_seats( $occurrence_id ) {
		$diagnostics = self::diagnostics( $occurrence_id );
		return is_wp_error( $diagnostics ) ? 0 : (int) $diagnostics['reserved'];
	}

	/**
	 * Remaining live seats for an occurrence.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return int
	 */
	public static function get_seats_left( $occurrence_id ) {
		$diagnostics = self::diagnostics( $occurrence_id );
		return is_wp_error( $diagnostics ) ? 0 : (int) $diagnostics['seats_left'];
	}

	/**
	 * Whether a requested party size fits the live occurrence capacity.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @param int $requested_seats Requested seats.
	 * @return array|WP_Error
	 */
	public static function can_reserve( $occurrence_id, $requested_seats ) {
		$d = self::diagnostics( $occurrence_id );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		$requested = max( 1, (int) $requested_seats );
		$d['requested'] = $requested;
		$d['can_reserve'] = $requested <= (int) $d['seats_left'];
		return $d;
	}

	/**
	 * Lock the concrete occurrence row, recalculate, and insert a booking.
	 *
	 * External gateway/email work must happen after this method returns.
	 *
	 * @param int   $occurrence_id Occurrence ID.
	 * @param int   $requested_seats Requested seats.
	 * @param array $booking_data wp_g2ab_bookings insert payload.
	 * @param array $formats wpdb insert formats.
	 * @return array|WP_Error
	 */
	public static function reserve_seats_atomically( $occurrence_id, $requested_seats, array $booking_data, array $formats ) {
		global $wpdb;
		$occurrence_id = absint( $occurrence_id );
		$requested     = max( 1, (int) $requested_seats );
		$occ_table     = $wpdb->prefix . 'g2ab_event_occurrences';
		$event_table   = $wpdb->prefix . 'g2ab_events';
		$bookings      = $wpdb->prefix . 'g2ab_bookings';

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'g2ab_transaction_failed', __( 'Could not start event reservation transaction.', 'g2a-booking' ), array( 'status' => 500 ) );
		}

		try {
			$locked = $wpdb->get_row( $wpdb->prepare(
				"SELECT o.*, e.capacity AS event_capacity, e.status AS event_status
				 FROM {$occ_table} o
				 LEFT JOIN {$event_table} e ON e.id = o.event_id
				 WHERE o.id = %d FOR UPDATE",
				$occurrence_id
			) );
			if ( ! $locked || 'cancelled' === (string) $locked->status || 'publish' !== (string) $locked->event_status ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'g2ab_bad_occurrence', __( 'That date is no longer available.', 'g2a-booking' ), array( 'status' => 400 ) );
			}
			if ( self::timestamp( $locked->start_at ) < time() - 60 ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'g2ab_event_past', __( 'That date has already started.', 'g2a-booking' ), array( 'status' => 400 ) );
			}

			$capacity = (int) $locked->capacity > 0 ? (int) $locked->capacity : (int) $locked->event_capacity;
			$reserved = self::calculate_reserved_seats( $occurrence_id, current_time( 'mysql' ) );
			$left = max( 0, $capacity - $reserved );
			if ( $requested > $left ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error(
					'g2ab_event_full',
					__( 'Not enough seats left for this date. Please reduce the number of seats or pick another date.', 'g2a-booking' ),
					array(
						'status'        => 409,
						'occurrence_id' => $occurrence_id,
						'capacity'      => $capacity,
						'reserved'      => $reserved,
						'seats_left'    => $left,
						'requested'     => $requested,
						'calculated_at' => current_time( 'mysql' ),
					)
				);
			}

			$inserted = $wpdb->insert( $bookings, $booking_data, $formats );
			if ( false === $inserted ) {
				if ( ! empty( $booking_data['idempotency_key'] ) && false !== stripos( (string) $wpdb->last_error, 'idempotency_key' ) ) {
					$existing_id = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT id FROM {$bookings} WHERE idempotency_key = %s LIMIT 1",
						(string) $booking_data['idempotency_key']
					) );
					$wpdb->query( 'ROLLBACK' );
					if ( $existing_id > 0 ) {
						return array( 'idempotent_replay_id' => $existing_id );
					}
				}
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'g2ab_insert_failed', __( 'Could not save your reservation.', 'g2a-booking' ), array( 'status' => 500 ) );
			}

			$booking_id = (int) $wpdb->insert_id;
			$wpdb->update(
				$occ_table,
				array( 'updated_at' => current_time( 'mysql' ) ),
				array( 'id' => $occurrence_id ),
				array( '%s' ),
				array( '%d' )
			);
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'g2ab_commit_failed', __( 'Could not commit event reservation.', 'g2a-booking' ), array( 'status' => 500 ) );
			}

			return array(
				'booking_id'    => $booking_id,
				'capacity'      => $capacity,
				'reserved'      => $reserved + $requested,
				'seats_left'    => max( 0, $left - $requested ),
				'requested'     => $requested,
				'calculated_at' => current_time( 'mysql' ),
			);
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'g2ab_transaction_exception', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Full diagnostic breakdown.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return array|WP_Error
	 */
	public static function diagnostics( $occurrence_id ) {
		$capacity = self::get_capacity( $occurrence_id );
		if ( is_wp_error( $capacity ) ) {
			return $capacity;
		}
		$now = current_time( 'mysql' );
		$rows = self::booking_rows( $occurrence_id );
		$out = array(
			'occurrence_id'      => (int) $occurrence_id,
			'event_id'           => (int) $capacity['event_id'],
			'capacity_source'    => $capacity['source'],
			'capacity'           => (int) $capacity['capacity'],
			'confirmed_seats'    => 0,
			'active_pending_holds' => 0,
			'expired_pending_holds' => 0,
			'reserved_pay_store_seats' => 0,
			'cancelled_seats'    => 0,
			'refunded_seats'     => 0,
			'released_seats'     => 0,
			'reserved'           => 0,
			'seats_left'         => 0,
			'calculated_at'      => $now,
			'bookings'           => array(),
		);
		foreach ( $rows as $row ) {
			$decision = self::booking_consumes_seat( $row, $now );
			$seats    = max( 0, (int) $row->party_size );
			if ( ! empty( $decision['consumes'] ) ) {
				$out['reserved'] += $seats;
				if ( in_array( (string) $row->status, array( 'confirmed', 'paid', 'completed' ), true ) ) {
					$out['confirmed_seats'] += $seats;
				} elseif ( 'pending' === (string) $row->status ) {
					$out['active_pending_holds'] += $seats;
				} elseif ( 'reserved' === (string) $row->status && 'in_store' === (string) $row->payment_mode ) {
					$out['reserved_pay_store_seats'] += $seats;
				}
			} else {
				if ( 'pending' === (string) $row->status ) {
					$out['expired_pending_holds'] += $seats;
				} elseif ( 'cancelled' === (string) $row->status ) {
					$out['cancelled_seats'] += $seats;
				} elseif ( 'refunded' === (string) $row->status ) {
					$out['refunded_seats'] += $seats;
				} else {
					$out['released_seats'] += $seats;
				}
			}
			$out['bookings'][] = array(
				'id'           => (int) $row->id,
				'status'       => (string) $row->status,
				'party_size'   => $seats,
				'payment_mode' => (string) $row->payment_mode,
				'hold_expires_at' => $decision['hold_expires_at'],
				'consumes'     => ! empty( $decision['consumes'] ),
				'reason'       => $decision['reason'],
			);
		}
		$out['seats_left'] = max( 0, (int) $out['capacity'] - (int) $out['reserved'] );
		return $out;
	}

	/**
	 * Expire stale unpaid online holds for one occurrence.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return int
	 */
	public static function expire_stale_holds( $occurrence_id ) {
		global $wpdb;
		$bookings = $wpdb->prefix . 'g2ab_bookings';
		$now = current_time( 'mysql' );
		$expired = 0;
		foreach ( self::booking_rows( $occurrence_id ) as $row ) {
			$decision = self::booking_consumes_seat( $row, $now );
			if ( in_array( (string) $row->status, array( 'pending', 'reserved' ), true ) && in_array( (string) $row->payment_mode, array( 'full', 'deposit' ), true ) && empty( $decision['consumes'] ) ) {
				$updated = $wpdb->update(
					$bookings,
					array( 'status' => 'expired', 'updated_at' => $now ),
					array( 'id' => (int) $row->id, 'status' => (string) $row->status ),
					array( '%s', '%s' ),
					array( '%d', '%s' )
				);
				if ( $updated ) {
					$expired++;
				}
			}
		}
		return $expired;
	}

	private static function calculate_reserved_seats( $occurrence_id, $now ) {
		$total = 0;
		foreach ( self::booking_rows( $occurrence_id ) as $row ) {
			$decision = self::booking_consumes_seat( $row, $now );
			if ( ! empty( $decision['consumes'] ) ) {
				$total += max( 0, (int) $row->party_size );
			}
		}
		return $total;
	}

	private static function booking_rows( $occurrence_id ) {
		global $wpdb;
		$bookings = $wpdb->prefix . 'g2ab_bookings';
		$payments = $wpdb->prefix . 'g2ab_payments';
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT b.id, b.status, b.party_size, b.payment_mode, b.created_at, b.start_at, b.end_at, b.metadata,
			        (SELECT p.checkout_expires_at FROM {$payments} p WHERE p.booking_id = b.id AND p.checkout_expires_at IS NOT NULL AND p.checkout_expires_at <> '' ORDER BY p.created_at DESC, p.id DESC LIMIT 1) AS checkout_expires_at
			 FROM {$bookings} b
			 WHERE b.event_occurrence_id = %d",
			(int) $occurrence_id
		) ) ?: array();
	}

	private static function booking_consumes_seat( $row, $now_mysql ) {
		$status = sanitize_key( (string) $row->status );
		$mode   = sanitize_key( (string) $row->payment_mode );
		$result = array( 'consumes' => false, 'reason' => 'released', 'hold_expires_at' => '' );

		if ( in_array( $status, array( 'cancelled', 'expired', 'refunded', 'payment_failed', 'abandoned', 'no_show' ), true ) ) {
			$result['reason'] = $status;
			return apply_filters( 'g2ab_event_booking_consumes_seat', $result, $row, $now_mysql );
		}
		if ( in_array( $status, array( 'confirmed', 'paid', 'partially_refunded' ), true ) ) {
			// partially_refunded attendees are still attending — seat stays taken.
			$result['consumes'] = true;
			$result['reason']   = $status;
			return apply_filters( 'g2ab_event_booking_consumes_seat', $result, $row, $now_mysql );
		}
		if ( 'completed' === $status ) {
			$result['consumes'] = self::timestamp( $row->end_at ) >= time();
			$result['reason']   = $result['consumes'] ? 'completed_future' : 'completed_past';
			return apply_filters( 'g2ab_event_booking_consumes_seat', $result, $row, $now_mysql );
		}
		if ( 'reserved' === $status && 'in_store' === $mode ) {
			$result['consumes'] = self::timestamp( $row->end_at ) >= time();
			$result['reason']   = $result['consumes'] ? 'pay_in_store_reserved' : 'pay_in_store_past';
			return apply_filters( 'g2ab_event_booking_consumes_seat', $result, $row, $now_mysql );
		}
		if ( in_array( $status, array( 'pending', 'reserved' ), true ) && in_array( $mode, array( 'full', 'deposit' ), true ) ) {
			$expires = self::effective_hold_expires_at( $row );
			$result['hold_expires_at'] = $expires;
			$result['consumes'] = $expires && strtotime( $expires ) > strtotime( $now_mysql );
			$result['reason'] = $result['consumes'] ? 'active_online_hold' : 'expired_online_hold';
			return apply_filters( 'g2ab_event_booking_consumes_seat', $result, $row, $now_mysql );
		}

		return apply_filters( 'g2ab_event_booking_consumes_seat', $result, $row, $now_mysql );
	}

	private static function effective_hold_expires_at( $row ) {
		$hold_minutes = self::hold_minutes();
		$created_ts = self::timestamp( $row->created_at );
		$fallback_ts = $created_ts ? $created_ts + ( $hold_minutes * MINUTE_IN_SECONDS ) : 0;
		$candidates = array();
		if ( ! empty( $row->checkout_expires_at ) ) {
			$candidates[] = self::timestamp( $row->checkout_expires_at );
		}
		$meta = json_decode( (string) $row->metadata, true );
		if ( is_array( $meta ) && ! empty( $meta['hold_expires_at'] ) ) {
			$candidates[] = self::timestamp( $meta['hold_expires_at'] );
		}
		if ( $fallback_ts > 0 ) {
			$candidates[] = $fallback_ts;
		}
		$candidates = array_filter( array_map( 'intval', $candidates ) );
		return $candidates ? wp_date( 'Y-m-d H:i:s', min( $candidates ), wp_timezone() ) : '';
	}

	private static function timestamp( $sql ) {
		return class_exists( 'G2AB_Events' ) ? G2AB_Events::timestamp( $sql ) : strtotime( (string) $sql );
	}
}
