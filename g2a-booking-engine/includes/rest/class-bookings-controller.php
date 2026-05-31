<?php
/**
 * REST: bookings + availability + payments glue.
 *
 * Endpoints:
 *   GET  /availability                      — slot grid
 *   POST /bookings                          — create booking + start payment intent
 *   GET  /bookings/{uuid}/status            — poll status (used by success page)
 *   POST /bookings/{uuid}/confirm-payment   — fallback confirm if webhook lags
 *   GET  /resources                         — list active resources
 *   GET  /payment-methods                   — list available gateways for frontend chooser
 *
 * Datetime convention: all DATETIME values in `g2ab_bookings` are stored as
 * site-local wall-clock time (matches `current_time('mysql')`). All parsing
 * uses `wp_timezone()` so DST and non-UTC sites behave correctly.
 *
 * @package G2AB
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class G2AB_REST_Bookings_Controller {

	public function register_routes() {
		register_rest_route( G2AB_REST_NAMESPACE, '/availability', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array( $this, 'get_availability' ),
			'permission_callback' => array( $this, 'permission_public_read' ),
			'args' => array(
				'resource_id'     => array( 'required' => true, 'sanitize_callback' => 'absint', 'validate_callback' => static fn( $v ) => $v > 0 ),
				'date'            => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field', 'validate_callback' => static fn( $v ) => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $v ) ),
				'duration'        => array( 'required' => false, 'sanitize_callback' => 'absint', 'default' => 60 ),
				// Optional — when supplied we honor the booking type's
				// buffer_before/buffer_after and capacity_mode in the slot grid.
				'booking_type_id' => array( 'required' => false, 'sanitize_callback' => 'absint', 'default' => 0 ),
				'party_size'      => array( 'required' => false, 'sanitize_callback' => 'absint', 'default' => 1 ),
			),
		) );
		// Event-driven availability: only the dates/times that have a
		// published Event of the given type are bookable. Powers the Ladies
		// Tuesday (and any other event-gated) calendar.
		register_rest_route( G2AB_REST_NAMESPACE, '/event-availability', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array( $this, 'get_event_availability' ),
			'permission_callback' => array( $this, 'permission_public_read' ),
			'args' => array(
				'resource_id'     => array( 'required' => true, 'sanitize_callback' => 'absint', 'validate_callback' => static fn( $v ) => $v > 0 ),
				'event_type'      => array( 'required' => false, 'sanitize_callback' => 'sanitize_key', 'default' => '' ),
				'booking_type_id' => array( 'required' => false, 'sanitize_callback' => 'absint', 'default' => 0 ),
				'party_size'      => array( 'required' => false, 'sanitize_callback' => 'absint', 'default' => 1 ),
			),
		) );
		register_rest_route( G2AB_REST_NAMESPACE, '/resources', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array( $this, 'list_resources' ),
			'permission_callback' => array( $this, 'permission_public_read' ),
		) );
		register_rest_route( G2AB_REST_NAMESPACE, '/payment-methods', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array( $this, 'list_payment_methods' ),
			'permission_callback' => array( $this, 'permission_public_read' ),
		) );
		register_rest_route( G2AB_REST_NAMESPACE, '/bookings', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'create_booking' ),
			'permission_callback' => array( $this, 'permission_create_booking' ),
		) );
		register_rest_route( G2AB_REST_NAMESPACE, '/bookings/(?P<uuid>[a-f0-9-]{36})/status', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array( $this, 'get_booking_status' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( G2AB_REST_NAMESPACE, '/bookings/(?P<uuid>[a-f0-9-]{36})/confirm-payment', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'confirm_payment' ),
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * Permission gate for the three public read endpoints
	 * (/availability, /resources, /payment-methods). Logged-in users
	 * pass through; anonymous callers get a per-IP transient rate
	 * limit so a scraper can't enumerate the full schedule, resource
	 * list, or gateway config in a tight loop.
	 *
	 * Window: 60 hits / 60 seconds / IP. Adjust via the
	 * g2ab_public_read_rate_limit filter (array of [hits, window_seconds]).
	 */
	public function permission_public_read( WP_REST_Request $request ) {
		if ( is_user_logged_in() ) {
			return true;
		}
		list( $hits_cap, $window ) = (array) apply_filters( 'g2ab_public_read_rate_limit', array( 60, 60 ) );
		$hits_cap = max( 1, (int) $hits_cap );
		$window   = max( 5, (int) $window );

		$ip  = function_exists( 'g2ab_get_client_ip' ) ? g2ab_get_client_ip() : (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		$key = 'g2ab_rlpub_' . md5( $ip );
		$hits = (int) get_transient( $key );

		if ( $hits >= $hits_cap ) {
			return new WP_Error(
				'g2ab_rate_limited',
				__( 'Too many requests. Try again in a moment.', 'g2a-booking' ),
				array( 'status' => 429 )
			);
		}

		// WordPress REST may invoke permission_callback more than once per
		// request (route matching + dispatch). Increment the transient at
		// most once per request — keyed by the request object identity
		// so a long-lived PHP-FPM process still increments on every new
		// HTTP request.
		static $seen = array();
		$rid = spl_object_id( $request );
		if ( ! isset( $seen[ $rid ] ) ) {
			$seen[ $rid ] = true;
			set_transient( $key, $hits + 1, $window );
		}
		return true;
	}

	public function permission_create_booking( WP_REST_Request $request ) {
		// Nonce is required for everyone. Guests get one via the rendered shortcode.
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'g2ab_invalid_nonce', __( 'Invalid or missing nonce. Please refresh the page and try again.', 'g2a-booking' ), array( 'status' => 403 ) );
		}

		// Skip rate-limit for authenticated users; they're far less abusive than scripted bots.
		if ( is_user_logged_in() ) {
			return true;
		}

		$resource_id = (int) $request->get_param( 'resource_id' );
		$ip          = function_exists( 'g2ab_get_client_ip' ) ? g2ab_get_client_ip() : (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		$key         = 'g2ab_rl_' . md5( $ip . '|' . $resource_id );
		$hits        = (int) get_transient( $key );

		if ( $hits >= 5 ) {
			return new WP_Error( 'g2ab_rate_limited', __( 'Too many booking attempts. Try again in a few minutes.', 'g2a-booking' ), array( 'status' => 429 ) );
		}
		set_transient( $key, $hits + 1, 10 * MINUTE_IN_SECONDS );
		return true;
	}

	public function list_payment_methods() {
		$out = array();
		if ( class_exists( 'G2AB_Gateway_Manager' ) ) {
			foreach ( G2AB_Gateway_Manager::instance()->available() as $id => $gw ) {
				$out[] = array( 'id' => $id, 'label' => method_exists( $gw, 'label' ) ? $gw->label() : $id );
			}
		}
		if ( empty( $out ) ) $out[] = array( 'id' => 'pay_in_store', 'label' => 'Pay In Store' );
		return rest_ensure_response( array( 'success' => true, 'data' => $out ) );
	}

	/**
	 * Site timezone (cached on first call).
	 */
	private function tz() {
		static $tz = null;
		if ( null === $tz ) {
			$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( date_default_timezone_get() );
		}
		return $tz;
	}

	/**
	 * Parse a Y-m-d H:i:s string as site-local time. Returns DateTimeImmutable or null.
	 */
	private function parse_site_datetime( $str ) {
		if ( ! is_string( $str ) || '' === $str ) {
			return null;
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $str ) ) {
			return null;
		}
		try {
			$dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $str, $this->tz() );
			if ( ! $dt ) {
				return null;
			}
			// Reject roll-overs (e.g. "2025-02-30 10:00:00" parses but normalises).
			if ( $dt->format( 'Y-m-d H:i:s' ) !== $str ) {
				return null;
			}
			return $dt;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	private function now_site() {
		return new DateTimeImmutable( 'now', $this->tz() );
	}

	public function get_availability( WP_REST_Request $request ) {
		global $wpdb;
		$resource_id     = (int) $request->get_param( 'resource_id' );
		$date            = $request->get_param( 'date' );
		$duration        = max( 15, min( 480, (int) $request->get_param( 'duration' ) ) );
		$booking_type_id = (int) $request->get_param( 'booking_type_id' );
		$party_size      = max( 1, (int) $request->get_param( 'party_size' ) );

		$tz       = $this->tz();
		$day_anchor = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $date . ' 12:00:00', $tz );
		if ( ! $day_anchor ) {
			return new WP_Error( 'g2ab_bad_date', __( 'Invalid date.', 'g2a-booking' ), array( 'status' => 400 ) );
		}
		$dow = (int) $day_anchor->format( 'w' );

		$rules_table = $wpdb->prefix . 'g2ab_availability_rules';
		$hours = $wpdb->get_row( $wpdb->prepare(
			"SELECT start_time, end_time FROM {$rules_table} WHERE rule_type = 'business_hours' AND day_of_week = %d AND is_active = 1 ORDER BY priority DESC LIMIT 1",
			$dow
		) );
		$blackout = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$rules_table} WHERE rule_type = 'blackout' AND is_active = 1 AND start_date <= %s AND end_date >= %s LIMIT 1",
			$date, $date
		) );
		if ( $blackout ) {
			return rest_ensure_response( array( 'success' => true, 'data' => array( 'date' => $date, 'closed' => true, 'reason' => 'blackout', 'slots' => array() ) ) );
		}
		if ( ! $hours ) {
			return rest_ensure_response( array( 'success' => true, 'data' => array( 'date' => $date, 'closed' => true, 'slots' => array() ) ) );
		}

		$open  = $this->parse_site_datetime( $date . ' ' . substr( (string) $hours->start_time, 0, 8 ) );
		$close = $this->parse_site_datetime( $date . ' ' . substr( (string) $hours->end_time, 0, 8 ) );
		if ( ! $open || ! $close || $close <= $open ) {
			return rest_ensure_response( array( 'success' => true, 'data' => array( 'date' => $date, 'closed' => true, 'slots' => array() ) ) );
		}

		// Resource capacity (used for "available" calculation).
		$resource = $wpdb->get_row( $wpdb->prepare(
			"SELECT capacity FROM {$wpdb->prefix}g2ab_resources WHERE id = %d AND is_active = 1",
			$resource_id
		) );
		$capacity = $resource ? max( 1, (int) $resource->capacity ) : 1;

		// Buffer + capacity_mode default to "no buffer, count-based" so the
		// public availability call (no booking type) keeps its old behavior.
		$buffer_before = 0;
		$buffer_after  = 0;
		$capacity_mode = 'booking_count';
		if ( $booking_type_id ) {
			$bt_table     = $wpdb->prefix . 'g2ab_booking_types';
			$booking_type = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$bt_table} WHERE id = %d AND is_active = 1", $booking_type_id ) );
			if ( $booking_type ) {
				$bt_settings = $this->booking_type_settings( $booking_type );
				$buffer_before = (int) $booking_type->buffer_before;
				$buffer_after  = (int) $booking_type->buffer_after;
				$capacity_mode = $this->normalize_capacity_mode( $booking_type->capacity_mode ?? '' );
				$duration      = max( 15, min( 480, (int) $booking_type->duration_min ) );
				if ( 'party_size' === $capacity_mode && ! empty( $bt_settings['event_total_seats'] ) ) {
					$capacity = max( 1, (int) $bt_settings['event_total_seats'] );
				}
			}
		}

		$existing = $this->fetch_overlapping_bookings_for_day( $resource_id, $date, $day_anchor );

		$min_lead_min = (int) get_option( 'g2ab_min_booking_lead_minutes', 30 );
		$earliest     = $this->now_site()->modify( '+' . $min_lead_min . ' minutes' );
		$max_advance  = (int) get_option( 'g2ab_max_booking_advance_days', 90 );
		$latest       = $this->now_site()->modify( '+' . max( 1, $max_advance ) . ' days' );

		$slots         = array();
		$step          = $duration * 60;
		$slot          = $open;
		$close_ts      = $close->getTimestamp();
		$earliest_ts   = $earliest->getTimestamp();
		$latest_ts     = $latest->getTimestamp();

		while ( $slot->getTimestamp() + $step <= $close_ts ) {
			$slot_start_ts = $slot->getTimestamp();
			$slot_end_ts   = $slot_start_ts + $step;
			$slot_start_sql = $slot->format( 'Y-m-d H:i:s' );

			// Apply candidate buffer when checking overlap.
			$cand_start_eff = $slot_start_ts - ( $buffer_before * 60 );
			$cand_end_eff   = $slot_end_ts + ( $buffer_after * 60 );

			$load = $this->compute_overlap_load( $existing, $cand_start_eff, $cand_end_eff, $capacity_mode );

			$is_past   = $slot_start_ts < $earliest_ts;
			$too_far   = $slot_start_ts > $latest_ts;
			// Different load semantics per capacity_mode:
			//   booking_count → load is # bookings
			//   party_size    → load is sum of party_size
			$projected_load = $load + ( 'party_size' === $capacity_mode ? $party_size : 1 );
			$is_full        = $projected_load > $capacity;
			$available      = ! $is_past && ! $too_far && ! $is_full;

			$slots[] = array(
				'start'      => $slot_start_sql,
				'end'        => $slot->modify( '+' . $duration . ' minutes' )->format( 'Y-m-d H:i:s' ),
				'label'      => $slot->format( get_option( 'time_format', 'g:i A' ) ),
				'available'  => $available,
				'seats_left' => max( 0, $capacity - $load ),
				'reason'     => $is_past ? 'past' : ( $too_far ? 'too_far' : ( $is_full ? 'full' : '' ) ),
			);

			$slot = $slot->modify( '+' . $duration . ' minutes' );
		}

		return rest_ensure_response( array( 'success' => true, 'data' => array(
			'date'          => $date,
			'closed'        => false,
			'capacity'      => $capacity,
			'capacity_mode' => $capacity_mode,
			'buffer_before' => $buffer_before,
			'buffer_after'  => $buffer_after,
			'slots'         => $slots,
		) ) );
	}

	/**
	 * Parse a free-text event time ("10:00 AM", "14:30") into a site-tz
	 * DateTimeImmutable anchored on $date. Returns null when unparseable.
	 *
	 * Unlike strtotime(), this never drifts across the server/site timezone
	 * gap — it extracts wall-clock hour/minute and rebuilds the moment in
	 * the site timezone.
	 *
	 * @param string $date     Y-m-d.
	 * @param string $time_str Free-text time.
	 * @param string $fallback Time to use when $time_str is empty.
	 * @return DateTimeImmutable|null
	 */
	private function parse_event_datetime( $date, $time_str, $fallback = '' ) {
		$time_str = trim( (string) $time_str );
		if ( '' === $time_str && '' !== $fallback ) {
			$time_str = $fallback;
		}
		if ( '' === $time_str ) {
			return null;
		}
		$parts = date_parse( $time_str );
		if ( ! empty( $parts['errors'] ) || ! isset( $parts['hour'] ) || false === $parts['hour'] || null === $parts['hour'] ) {
			return null;
		}
		$hour   = (int) $parts['hour'];
		$minute = (int) ( $parts['minute'] ?: 0 );
		return $this->parse_site_datetime( sprintf( '%s %02d:%02d:00', $date, $hour, $minute ) );
	}

	/**
	 * Event-driven availability. Returns ONLY the dates that have a published
	 * Event of the requested type, each with the time slots that fall inside
	 * the event's start→end window. Booked/over-capacity slots are flagged
	 * unavailable using the same overlap math as the regular grid.
	 *
	 * Shape: { dates: ['Y-m-d', …], by_date: { 'Y-m-d': { title, slots[] } } }
	 */
	public function get_event_availability( WP_REST_Request $request ) {
		if ( ! class_exists( 'G2AB_Events' ) ) {
			return rest_ensure_response( array( 'success' => true, 'data' => array( 'dates' => array(), 'by_date' => (object) array() ) ) );
		}

		global $wpdb;
		$resource_id     = (int) $request->get_param( 'resource_id' );
		$event_type      = sanitize_key( (string) $request->get_param( 'event_type' ) );
		$booking_type_id = (int) $request->get_param( 'booking_type_id' );
		$party_size      = max( 1, (int) $request->get_param( 'party_size' ) );

		// Booking-type config (duration, capacity model, buffers).
		$duration      = 60;
		$capacity_mode = 'booking_count';
		$buffer_before = 0;
		$buffer_after  = 0;
		if ( $booking_type_id ) {
			$bt = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}g2ab_booking_types WHERE id = %d AND is_active = 1", $booking_type_id ) );
			if ( $bt ) {
				$duration      = max( 15, min( 480, (int) $bt->duration_min ) );
				$capacity_mode = $this->normalize_capacity_mode( $bt->capacity_mode ?? '' );
				$buffer_before = (int) $bt->buffer_before;
				$buffer_after  = (int) $bt->buffer_after;
			}
		}

		$resource = $wpdb->get_row( $wpdb->prepare(
			"SELECT capacity FROM {$wpdb->prefix}g2ab_resources WHERE id = %d AND is_active = 1",
			$resource_id
		) );
		$resource_capacity = $resource ? max( 1, (int) $resource->capacity ) : 1;

		$tz           = $this->tz();
		$min_lead_min = (int) get_option( 'g2ab_min_booking_lead_minutes', 30 );
		$earliest_ts  = $this->now_site()->modify( '+' . $min_lead_min . ' minutes' )->getTimestamp();
		$max_advance  = (int) get_option( 'g2ab_max_booking_advance_days', 90 );
		$latest_ts    = $this->now_site()->modify( '+' . max( 1, $max_advance ) . ' days' )->getTimestamp();
		$time_format  = get_option( 'time_format', 'g:i A' );

		$events  = G2AB_Events::instance()->get_bookable_events_by_type( $event_type, 100 );
		$dates   = array();
		$by_date = array();

		foreach ( $events as $event ) {
			$date = $event['date'];
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ) {
				continue;
			}

			$start_dt = $this->parse_event_datetime( $date, $event['time'], '10:00 AM' );
			if ( ! $start_dt ) {
				continue;
			}
			$end_dt = $this->parse_event_datetime( $date, $event['end'], '' );
			if ( ! $end_dt || $end_dt <= $start_dt ) {
				$end_dt = $start_dt->modify( '+' . $duration . ' minutes' );
			}

			// Per-event seat cap wins over the resource capacity.
			$capacity = ( ! empty( $event['seats'] ) && (int) $event['seats'] > 0 ) ? (int) $event['seats'] : $resource_capacity;

			$day_anchor = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $date . ' 12:00:00', $tz );
			if ( ! $day_anchor ) {
				continue;
			}
			$existing = $this->fetch_overlapping_bookings_for_day( $resource_id, $date, $day_anchor );

			$slots   = array();
			$step    = $duration * 60;
			$cursor  = $start_dt;
			$end_ts  = $end_dt->getTimestamp();
			$made    = 0;
			while ( ( $cursor->getTimestamp() + $step <= $end_ts ) || 0 === $made ) {
				$s_start_ts = $cursor->getTimestamp();
				$s_end_ts   = $s_start_ts + $step;
				if ( $made > 0 && $s_end_ts > $end_ts ) {
					break;
				}
				$cand_start_eff = $s_start_ts - ( $buffer_before * 60 );
				$cand_end_eff   = $s_end_ts + ( $buffer_after * 60 );
				$load           = $this->compute_overlap_load( $existing, $cand_start_eff, $cand_end_eff, $capacity_mode );
				$projected      = $load + ( 'party_size' === $capacity_mode ? $party_size : 1 );
				$is_past        = $s_start_ts < $earliest_ts;
				$too_far        = $s_start_ts > $latest_ts;
				$is_full        = $projected > $capacity;

				$slots[] = array(
					'start'      => $cursor->format( 'Y-m-d H:i:s' ),
					'end'        => $cursor->modify( '+' . $duration . ' minutes' )->format( 'Y-m-d H:i:s' ),
					'label'      => $cursor->format( $time_format ),
					'available'  => ! $is_past && ! $too_far && ! $is_full,
					'seats_left' => max( 0, $capacity - $load ),
					'reason'     => $is_past ? 'past' : ( $too_far ? 'too_far' : ( $is_full ? 'full' : '' ) ),
				);

				$cursor = $cursor->modify( '+' . $duration . ' minutes' );
				$made++;
				if ( $made > 48 ) {
					break; // hard safety cap
				}
			}

			// Skip events whose every slot is in the past / unbookable.
			$has_live = false;
			foreach ( $slots as $s ) {
				if ( $s['available'] ) {
					$has_live = true;
					break;
				}
			}
			if ( empty( $slots ) || ! $has_live ) {
				continue;
			}

			$dates[]           = $date;
			$by_date[ $date ]  = array(
				'title' => $event['title'],
				'slots' => $slots,
			);
		}

		$dates = array_values( array_unique( $dates ) );
		sort( $dates );

		return rest_ensure_response( array( 'success' => true, 'data' => array(
			'dates'   => $dates,
			'by_date' => empty( $by_date ) ? (object) array() : $by_date,
		) ) );
	}

	/**
	 * Pull every relevant booking for the requested resource on the given day,
	 * joined with each booking's own buffer_before/buffer_after so we honor
	 * each existing booking's clean-up window when checking overlap.
	 *
	 * Returns rows with effective start/end (already buffer-expanded) plus the
	 * raw party_size — caller decides how to combine them.
	 */
	private function fetch_overlapping_bookings_for_day( $resource_id, $date, DateTimeImmutable $day_anchor ) {
		global $wpdb;
		$bookings_table = $wpdb->prefix . 'g2ab_bookings';
		$bt_table       = $wpdb->prefix . 'g2ab_booking_types';

		// Pull a generous window: yesterday through tomorrow so a buffer that
		// pushes a booking across midnight still gets caught.
		$window_start = $day_anchor->modify( '-1 day' )->setTime( 0, 0, 0 )->format( 'Y-m-d H:i:s' );
		$window_end   = $day_anchor->modify( '+2 days' )->setTime( 0, 0, 0 )->format( 'Y-m-d H:i:s' );

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT b.id, b.start_at, b.end_at, b.party_size,
			        COALESCE(bt.buffer_before, 0) AS buffer_before,
			        COALESCE(bt.buffer_after, 0)  AS buffer_after
			   FROM {$bookings_table} b
			   LEFT JOIN {$bt_table} bt ON bt.id = b.booking_type_id
			  WHERE b.resource_id = %d
			    AND b.status IN ('pending','reserved','confirmed','paid')
			    AND b.start_at < %s
			    AND b.end_at > %s",
			$resource_id,
			$window_end,
			$window_start
		) );
	}

	/**
	 * Combine cached existing bookings into a single load number for the
	 * candidate window, given a capacity_mode.
	 *
	 * @param array  $existing       Rows from fetch_overlapping_bookings_for_day().
	 * @param int    $cand_start_eff Candidate start timestamp (already buffered).
	 * @param int    $cand_end_eff   Candidate end timestamp (already buffered).
	 * @param string $capacity_mode  booking_count|party_size (aliases already normalized).
	 */
	private function compute_overlap_load( $existing, $cand_start_eff, $cand_end_eff, $capacity_mode ) {
		$load = 0;
		foreach ( $existing as $b ) {
			$b_start_dt = $this->parse_site_datetime( (string) $b->start_at );
			$b_end_dt   = $this->parse_site_datetime( (string) $b->end_at );
			if ( ! $b_start_dt || ! $b_end_dt ) {
				continue;
			}
			$b_start_eff = $b_start_dt->getTimestamp() - ( (int) $b->buffer_before * 60 );
			$b_end_eff   = $b_end_dt->getTimestamp() + ( (int) $b->buffer_after * 60 );
			if ( $b_start_eff < $cand_end_eff && $b_end_eff > $cand_start_eff ) {
				$load += ( 'party_size' === $capacity_mode ) ? max( 1, (int) $b->party_size ) : 1;
			}
		}
		return $load;
	}

	/**
	 * Map every supported capacity_mode alias onto one of the two real
	 * implementations. Anything unknown defaults to booking_count.
	 *
	 *   booking_count, lane_based  → booking_count
	 *   party_size,    seat_based  → party_size
	 *
	 * @param string $mode Raw value from booking_types.capacity_mode.
	 * @return string One of: booking_count, party_size.
	 */
	private function normalize_capacity_mode( $mode ) {
		$mode = sanitize_key( (string) $mode );
		if ( in_array( $mode, array( 'party_size', 'seat_based' ), true ) ) {
			return 'party_size';
		}
		return 'booking_count';
	}

	public function list_resources() {
		global $wpdb;
		$rt = $wpdb->prefix . 'g2ab_resources';
		$rows = $wpdb->get_results( "SELECT id, name, slug, type, capacity, sort_order FROM {$rt} WHERE is_active = 1 ORDER BY sort_order ASC, name ASC LIMIT 200" );
		return rest_ensure_response( array( 'success' => true, 'data' => $rows ) );
	}

	private function resource_type_for_booking_type( $booking_type ) {
		$settings = isset( $booking_type->settings ) ? json_decode( (string) $booking_type->settings, true ) : array();
		$configured = is_array( $settings ) && ! empty( $settings['resource_type'] ) ? sanitize_key( $settings['resource_type'] ) : '';
		if ( in_array( $configured, array( 'lane', 'classroom', 'instructor', 'package' ), true ) ) {
			return $configured;
		}
		$category = isset( $booking_type->category ) ? sanitize_key( $booking_type->category ) : 'lane';
		return 'class' === $category ? 'classroom' : 'lane';
	}

	private function booking_type_settings( $booking_type ) {
		$settings = isset( $booking_type->settings ) ? json_decode( (string) $booking_type->settings, true ) : array();
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Resolve whether a booking type is "event-gated" — i.e. only bookable
	 * on dates/times that have a published Event. Driven by the booking
	 * type's own settings (event_source / event_type), with a built-in
	 * convention so the bundled Ladies Tuesday type works out of the box.
	 *
	 * @return array{enabled:bool,type:string}
	 */
	private function event_gate_for_booking_type( $booking_type, $settings = null ) {
		$settings = is_array( $settings ) ? $settings : $this->booking_type_settings( $booking_type );
		$enabled  = ! empty( $settings['event_source'] ) && 'events' === sanitize_key( (string) $settings['event_source'] );
		$type     = ! empty( $settings['event_type'] ) ? sanitize_key( (string) $settings['event_type'] ) : '';

		// Convention fallback: the bundled "ladies-tuesday" type maps to the
		// "ladies-day" event type even with no explicit settings row.
		$slug = isset( $booking_type->slug ) ? sanitize_title( $booking_type->slug ) : '';
		if ( ! $enabled && 'ladies-tuesday' === $slug ) {
			$enabled = true;
			$type    = $type ?: 'ladies-day';
		}

		$gate = array( 'enabled' => (bool) $enabled, 'type' => $type );
		/** Allow integrations to flag any booking type as event-gated. */
		return apply_filters( 'g2ab_event_gate', $gate, $booking_type, $settings );
	}

	/**
	 * True when [$start_dt, $end_dt] fits inside the window of a published
	 * Event of $event_type on that calendar date.
	 */
	private function slot_matches_event_window( $event_type, DateTimeImmutable $start_dt, DateTimeImmutable $end_dt ) {
		if ( ! class_exists( 'G2AB_Events' ) ) {
			return false;
		}
		$date   = $start_dt->format( 'Y-m-d' );
		$events = G2AB_Events::instance()->get_bookable_events_by_type( $event_type, 100 );
		foreach ( $events as $event ) {
			if ( $event['date'] !== $date ) {
				continue;
			}
			$ev_start = $this->parse_event_datetime( $date, $event['time'], '10:00 AM' );
			if ( ! $ev_start ) {
				continue;
			}
			$ev_end = $this->parse_event_datetime( $date, $event['end'], '' );
			if ( ! $ev_end || $ev_end <= $ev_start ) {
				// No explicit end → allow the booking's own duration to define it.
				$ev_end = $end_dt > $ev_start ? $end_dt : $ev_start->modify( '+60 minutes' );
			}
			if ( $start_dt >= $ev_start && $end_dt <= $ev_end ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Single source of truth for "is this user an active paid member?"
	 */
	private function is_active_member( $user_id ) {
		if ( ! $user_id ) {
			return false;
		}
		if ( function_exists( 'pmpro_hasMembershipLevel' ) ) {
			return (bool) pmpro_hasMembershipLevel( null, $user_id );
		}
		if ( function_exists( 'memberistic_user_has_active_membership' ) ) {
			return (bool) memberistic_user_has_active_membership( $user_id );
		}
		if ( get_user_meta( $user_id, 'memberistic_active_plan_id', true ) ) {
			return true;
		}
		return user_can( $user_id, 'manage_g2ab_bookings' );
	}

	private function user_can_book_member_type( $booking_type, $customer_email = '' ) {
		if ( empty( $booking_type->members_only ) ) {
			return true;
		}
		$user_id = get_current_user_id();
		$allowed = $this->is_active_member( $user_id );
		return (bool) apply_filters( 'g2ab_user_is_member', $allowed, $user_id, $booking_type, $customer_email );
	}

	private function is_member_for_discount( $booking_type, $customer_email = '' ) {
		$user_id   = get_current_user_id();
		$is_member = $this->is_active_member( $user_id );
		return (bool) apply_filters( 'g2ab_user_is_member', $is_member, $user_id, $booking_type, $customer_email );
	}

	private function payment_modes_for_type( $booking_type ) {
		$modes = array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', (string) $booking_type->payment_modes ) ) ) );
		return $modes ? $modes : array( 'full', 'in_store' );
	}

	private function calculate_pricing( $booking_type, $party_size, $user_id, $customer_email = '' ) {
		$is_member    = $this->is_member_for_discount( $booking_type, $customer_email );
		$context_type = $this->booking_context_type( $booking_type );

		$pricing = g2ab_get_price(
			$user_id,
			array(
				'booking_type' => $booking_type,
				'party_size'   => $party_size,
				'duration_min' => (int) $booking_type->duration_min,
				'is_member'    => $is_member,
				'context_type' => $context_type,
			)
		);

		$pricing = apply_filters( 'g2ab_booking_pricing', $pricing, $booking_type, $party_size, $user_id, $customer_email );
		$pricing['subtotal']                = max( 0, round( (float) ( $pricing['subtotal'] ?? 0 ), 2 ) );
		$pricing['discount_amount']         = max( 0, min( $pricing['subtotal'], round( (float) ( $pricing['discount_amount'] ?? 0 ), 2 ) ) );
		$pricing['total']                   = max( 0, round( (float) ( $pricing['total'] ?? ( $pricing['subtotal'] - $pricing['discount_amount'] ) ), 2 ) );
		$pricing['member_discount_percent'] = max( 0, min( 100, (float) ( $pricing['member_discount_percent'] ?? 0 ) ) );
		$pricing['membership_level_id']     = absint( $pricing['membership_level_id'] ?? 0 );
		$pricing['membership_source']       = sanitize_key( $pricing['membership_source'] ?? '' );
		$pricing['discount_label']          = sanitize_text_field( $pricing['discount_label'] ?? '' );
		return $pricing;
	}

	private function booking_context_type( $booking_type ) {
		$slug     = sanitize_title( (string) ( $booking_type->slug ?? '' ) );
		$category = sanitize_key( (string) ( $booking_type->category ?? '' ) );
		if ( false !== strpos( $slug, 'ladies-tuesday' ) || false !== strpos( $slug, 'ladies_tuesday' ) || 'ladies_tuesday' === $category ) {
			return 'ladies_tuesday';
		}
		if ( 'lane' === $category || false !== strpos( $slug, 'lane' ) ) {
			return 'lane';
		}
		if ( 'class' === $category || false !== strpos( $slug, 'ccw' ) || false !== strpos( $slug, 'event' ) ) {
			return 'event';
		}
		return 'resource';
	}

	private function public_fallback_booking_type( $booking_type ) {
		if ( empty( $booking_type->members_only ) ) {
			return null;
		}
		global $wpdb;
		$table    = $wpdb->prefix . 'g2ab_booking_types';
		$fallback = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE slug = %s AND is_active = 1 AND members_only = 0 LIMIT 1",
			'lane-booking'
		) );
		return $fallback ?: null;
	}

	private function prefer_stripe_for_public_payment( $gateway, $gateway_id, $booking_type ) {
		if ( $gateway_id || ! class_exists( 'G2AB_Gateway_Manager' ) || ! empty( $booking_type->members_only ) ) {
			return $gateway;
		}
		$mgr    = G2AB_Gateway_Manager::instance();
		$stripe = $mgr->get( 'stripe' );
		return $stripe ?: $gateway;
	}

	private function split_customer_name( $customer_name ) {
		$parts = preg_split( '/\s+/', trim( (string) $customer_name ) );
		$first = $parts ? array_shift( $parts ) : '';
		return array(
			'first_name' => $first,
			'last_name'  => $parts ? implode( ' ', $parts ) : '',
		);
	}

	/**
	 * Resolve a booking customer to a WP user.
	 *
	 * Security: never authenticate the request as a pre-existing user matched by email
	 * (account takeover via email-guessing). For new users we create the account but
	 * do NOT log them in here; password is delivered via wp_new_user_notification.
	 */
	private function ensure_customer_user( $customer_name, $customer_email, $customer_phone ) {
		$current_user_id = get_current_user_id();
		if ( $current_user_id ) {
			update_user_meta( $current_user_id, 'billing_phone', $customer_phone );
			return (int) $current_user_id;
		}

		$existing = get_user_by( 'email', $customer_email );
		if ( $existing instanceof WP_User ) {
			// Do NOT auto-login. Attach the booking to the existing user only.
			update_user_meta( $existing->ID, 'billing_phone', $customer_phone );
			update_user_meta( $existing->ID, 'g2ab_last_booking_contact_phone', $customer_phone );
			return (int) $existing->ID;
		}

		// Guest booking — create a WP user with the "Walk-in Customer"
		// role by default. Staff can filter walk-ins in the WP user
		// list separately from members + administrators, and the same
		// account is automatically upgraded to the matching member
		// role if the customer later buys a membership.
		//
		// Sites that want to skip user creation entirely (older
		// behavior) can set g2ab_create_user_on_booking = '0' /
		// false / 'no' or hook the g2ab_create_user_on_booking
		// filter to return false.
		$create_user = (bool) apply_filters(
			'g2ab_create_user_on_booking',
			(bool) get_option( 'g2ab_create_user_on_booking', true )
		);
		if ( ! $create_user ) {
			return 0;
		}

		$name_parts = $this->split_customer_name( $customer_name );
		$base_login = sanitize_user( current( explode( '@', $customer_email ) ), true );
		if ( '' === $base_login ) {
			$base_login = 'g2a_customer';
		}

		$user_login = $base_login;
		$suffix     = 1;
		while ( username_exists( $user_login ) ) {
			$user_login = $base_login . $suffix;
			$suffix++;
		}

		$password = wp_generate_password( 20, true, true );
		// Role: walk-in by default. If the g2a_walkin role doesn't
		// exist yet (plugin activator didn't run after upgrade) fall
		// back to subscriber so the insert still succeeds.
		$role = get_role( 'g2a_walkin' ) ? 'g2a_walkin' : 'subscriber';
		$user_id = wp_insert_user( array(
			'user_login'   => $user_login,
			'user_pass'    => $password,
			'user_email'   => $customer_email,
			'display_name' => $customer_name,
			'first_name'   => $name_parts['first_name'],
			'last_name'    => $name_parts['last_name'],
			'role'         => $role,
		) );

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		update_user_meta( $user_id, 'billing_first_name', $name_parts['first_name'] );
		update_user_meta( $user_id, 'billing_last_name', $name_parts['last_name'] );
		update_user_meta( $user_id, 'billing_email', $customer_email );
		update_user_meta( $user_id, 'billing_phone', $customer_phone );
		update_user_meta( $user_id, 'g2ab_customer_created_from_booking', current_time( 'mysql' ) );

		// Email the password-set link so the customer can log in to
		// view their bookings.
		wp_new_user_notification( $user_id, null, 'user' );

		return (int) $user_id;
	}

	public function create_booking( WP_REST_Request $request ) {
		global $wpdb;

		// Idempotency: the caller may supply an Idempotency-Key header
		// (or X-Idempotency-Key). If the same key has succeeded in the
		// last 5 minutes we return the original response instead of
		// creating a second booking. Protects against:
		//   - user double-clicking the submit button before the JS
		//     spinner kicks in,
		//   - a flaky 502 mid-request triggering a client retry,
		//   - rapid network hiccup that resends the same POST.
		$idem_key = sanitize_text_field( (string) ( $request->get_header( 'idempotency-key' ) ?: $request->get_header( 'x-idempotency-key' ) ?: '' ) );
		// SECURITY: scope the idempotency cache to the calling client so
		// a key collision (intentional or accidental) can't surface
		// another customer's confirmation. Combines IP + logged-in user id.
		$idem_scope = ( function_exists( 'g2ab_get_client_ip' ) ? g2ab_get_client_ip() : '' ) . '|' . get_current_user_id();
		if ( '' !== $idem_key && strlen( $idem_key ) <= 128 ) {
			$idem_cache_key = 'g2ab_idem_' . md5( $idem_scope . '|' . $idem_key );
			$cached         = get_transient( $idem_cache_key );
			if ( is_array( $cached ) && isset( $cached['booking_id'], $cached['response'] ) ) {
				// Return the original successful response verbatim.
				return rest_ensure_response( $cached['response'] );
			}
		}

		$booking_type_id = (int) $request->get_param( 'booking_type_id' );
		$resource_id     = (int) $request->get_param( 'resource_id' );
		$form_id         = absint( $request->get_param( 'form_id' ) );
		$start_at        = sanitize_text_field( (string) $request->get_param( 'start_at' ) );
		$party_size      = max( 1, (int) ( $request->get_param( 'party_size' ) ?: 1 ) );
		$gateway_id      = sanitize_key( (string) ( $request->get_param( 'gateway' ) ?: '' ) );
		$fields          = $request->get_param( 'fields' );
		$fields          = is_array( $fields ) ? $fields : array();

		$bt_table     = $wpdb->prefix . 'g2ab_booking_types';
		$booking_type = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$bt_table} WHERE id = %d AND is_active = 1", $booking_type_id ) );
		if ( ! $booking_type ) {
			return new WP_Error( 'g2ab_invalid_type', __( 'Invalid booking type.', 'g2a-booking' ), array( 'status' => 400 ) );
		}

		$res_table = $wpdb->prefix . 'g2ab_resources';
		$resource  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$res_table} WHERE id = %d AND is_active = 1", $resource_id ) );
		if ( ! $resource ) {
			return new WP_Error( 'g2ab_invalid_resource', __( 'Invalid resource.', 'g2a-booking' ), array( 'status' => 400 ) );
		}
		if ( sanitize_key( $resource->type ) !== $this->resource_type_for_booking_type( $booking_type ) ) {
			return new WP_Error( 'g2ab_resource_type_mismatch', __( 'This resource does not match the selected booking type.', 'g2a-booking' ), array( 'status' => 400 ) );
		}
		$capacity = max( 1, (int) $resource->capacity );
		$context_type = $this->booking_context_type( $booking_type );
		$booking_type_settings = $this->booking_type_settings( $booking_type );
		if ( 'event' === $context_type && ! empty( $booking_type_settings['event_total_seats'] ) ) {
			$capacity = max( 1, (int) $booking_type_settings['event_total_seats'] );
		}

		// ─── Strict start_at validation ─────────────────────────────────────
		$start_dt = $this->parse_site_datetime( $start_at );
		if ( ! $start_dt ) {
			return new WP_Error( 'g2ab_invalid_time', __( 'Invalid start time format. Expected YYYY-MM-DD HH:MM:SS.', 'g2a-booking' ), array( 'status' => 400 ) );
		}
		$duration_min = (int) $booking_type->duration_min;
		$end_dt       = $start_dt->modify( '+' . $duration_min . ' minutes' );

		$now           = $this->now_site();
		$min_lead_min  = (int) get_option( 'g2ab_min_booking_lead_minutes', 30 );
		$max_adv_days  = (int) get_option( 'g2ab_max_booking_advance_days', 90 );
		$earliest      = $now->modify( '+' . max( 0, $min_lead_min ) . ' minutes' );
		$latest        = $now->modify( '+' . max( 1, $max_adv_days ) . ' days' );

		if ( $start_dt < $earliest ) {
			return new WP_Error( 'g2ab_start_too_soon', __( 'This time has already passed or is too soon to book.', 'g2a-booking' ), array( 'status' => 400 ) );
		}
		if ( $start_dt > $latest ) {
			return new WP_Error( 'g2ab_start_too_far', __( 'Booking is too far in the future.', 'g2a-booking' ), array( 'status' => 400 ) );
		}

		// Slot validation. Event-gated booking types (e.g. Ladies Tuesday) are
		// validated against the published Event window for that date; everyone
		// else must fall inside the weekday business hours. Blackouts always
		// apply.
		$rules_table = $wpdb->prefix . 'g2ab_availability_rules';
		$date_str    = $start_dt->format( 'Y-m-d' );
		$event_gate  = $this->event_gate_for_booking_type( $booking_type, $booking_type_settings );

		if ( ! empty( $event_gate['enabled'] ) ) {
			if ( ! $this->slot_matches_event_window( $event_gate['type'], $start_dt, $end_dt ) ) {
				return new WP_Error( 'g2ab_not_event_slot', __( 'This time is not part of a scheduled event. Please pick a highlighted date and time.', 'g2a-booking' ), array( 'status' => 400 ) );
			}
		} else {
			$dow   = (int) $start_dt->format( 'w' );
			$hours = $wpdb->get_row( $wpdb->prepare(
				"SELECT start_time, end_time FROM {$rules_table} WHERE rule_type = 'business_hours' AND day_of_week = %d AND is_active = 1 ORDER BY priority DESC LIMIT 1",
				$dow
			) );
			if ( ! $hours ) {
				return new WP_Error( 'g2ab_closed', __( 'Bookings are not available on this day.', 'g2a-booking' ), array( 'status' => 400 ) );
			}
			$open_dt  = $this->parse_site_datetime( $date_str . ' ' . substr( (string) $hours->start_time, 0, 8 ) );
			$close_dt = $this->parse_site_datetime( $date_str . ' ' . substr( (string) $hours->end_time, 0, 8 ) );
			if ( ! $open_dt || ! $close_dt || $start_dt < $open_dt || $end_dt > $close_dt ) {
				return new WP_Error( 'g2ab_outside_hours', __( 'This time is outside business hours.', 'g2a-booking' ), array( 'status' => 400 ) );
			}
		}

		$blackout = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$rules_table} WHERE rule_type = 'blackout' AND is_active = 1 AND start_date <= %s AND end_date >= %s LIMIT 1",
			$date_str, $date_str
		) );
		if ( $blackout ) {
			return new WP_Error( 'g2ab_blackout', __( 'This date is closed.', 'g2a-booking' ), array( 'status' => 400 ) );
		}

		// ─── Field validation ───────────────────────────────────────────────
		$customer_name  = isset( $fields['customer_name'] ) ? sanitize_text_field( wp_unslash( $fields['customer_name'] ) ) : '';
		$customer_email = isset( $fields['customer_email'] ) ? sanitize_email( wp_unslash( $fields['customer_email'] ) ) : '';
		$customer_phone = isset( $fields['customer_phone'] ) ? sanitize_text_field( wp_unslash( $fields['customer_phone'] ) ) : '';

		if ( '' === $customer_name ) {
			return new WP_Error( 'g2ab_missing_name', __( 'Name is required.', 'g2a-booking' ), array( 'status' => 400 ) );
		}
		if ( ! is_email( $customer_email ) ) {
			return new WP_Error( 'g2ab_invalid_email', __( 'Valid email is required.', 'g2a-booking' ), array( 'status' => 400 ) );
		}
		if ( '' === $customer_phone ) {
			return new WP_Error( 'g2ab_missing_phone', __( 'Contact number is required.', 'g2a-booking' ), array( 'status' => 400 ) );
		}
		// Waiver acceptance — satisfied by the form checkbox OR by an existing
		// signed waiver on file (Memberistic answers via this filter, matching
		// the customer's email/name against the imported waiver archive so
		// returning customers don't have to re-sign).
		$waiver_ok = ! empty( $fields['waiver_acceptance'] );
		/**
		 * Filter: is the waiver requirement satisfied for this booking?
		 *
		 * @param bool   $waiver_ok    Whether the form checkbox was ticked.
		 * @param array  $fields       Submitted fields (customer_email, customer_name, …).
		 * @param object $booking_type The booking type row.
		 */
		$waiver_ok = (bool) apply_filters( 'g2ab_waiver_satisfied', $waiver_ok, $fields, $booking_type );
		if ( (int) $booking_type->requires_waiver === 1 && ! $waiver_ok ) {
			return new WP_Error( 'g2ab_waiver_required', __( 'Waiver acceptance is required.', 'g2a-booking' ), array( 'status' => 400 ) );
		}
		if ( $party_size > $capacity ) {
			return new WP_Error( 'g2ab_party_too_large', sprintf(
				/* translators: %d capacity */
				__( 'Party size exceeds the resource capacity of %d.', 'g2a-booking' ),
				$capacity
			), array( 'status' => 400 ) );
		}
		if ( 'lane' === $context_type && $party_size > 3 ) {
			return new WP_Error( 'g2ab_lane_party_limit', __( 'Lane booking allows maximum 3 users per lane.', 'g2a-booking' ), array( 'status' => 400 ) );
		}
		if ( 'ladies_tuesday' === $context_type ) {
			if ( 2 !== (int) $start_dt->format( 'w' ) ) {
				return new WP_Error( 'g2ab_ladies_tuesday_only', __( 'Ladies Tuesday booking is available only on Tuesday.', 'g2a-booking' ), array( 'status' => 400 ) );
			}
			if ( (int) $booking_type->duration_min !== 60 ) {
				return new WP_Error( 'g2ab_ladies_tuesday_duration', __( 'Ladies Tuesday booking must be a 1-hour lane session.', 'g2a-booking' ), array( 'status' => 400 ) );
			}
		}
		if ( 'event' === $context_type ) {
			$allowed_days = isset( $booking_type_settings['event_weekdays'] ) && is_array( $booking_type_settings['event_weekdays'] )
				? array_map( 'intval', $booking_type_settings['event_weekdays'] )
				: array();
			if ( ! empty( $allowed_days ) && ! in_array( (int) $start_dt->format( 'w' ), $allowed_days, true ) ) {
				return new WP_Error( 'g2ab_event_day_not_allowed', __( 'This class is not available on the selected day.', 'g2a-booking' ), array( 'status' => 400 ) );
			}
			$event_start = isset( $booking_type_settings['event_start_time'] ) ? (string) $booking_type_settings['event_start_time'] : '';
			$event_end   = isset( $booking_type_settings['event_end_time'] ) ? (string) $booking_type_settings['event_end_time'] : '';
			if ( preg_match( '/^\d{2}:\d{2}$/', $event_start ) && preg_match( '/^\d{2}:\d{2}$/', $event_end ) ) {
				$start_hm = $start_dt->format( 'H:i' );
				$end_hm   = $end_dt->format( 'H:i' );
				if ( $start_hm < $event_start || $end_hm > $event_end ) {
					return new WP_Error( 'g2ab_event_time_window', __( 'Selected time is outside class schedule window.', 'g2a-booking' ), array( 'status' => 400 ) );
				}
			}
		}

		// Members-only check (with public fallback to lane-booking).
		if ( ! $this->user_can_book_member_type( $booking_type, $customer_email ) ) {
			$fallback_booking_type = $this->public_fallback_booking_type( $booking_type );
			if ( $fallback_booking_type ) {
				$booking_type    = $fallback_booking_type;
				$booking_type_id = (int) $booking_type->id;
			} else {
				return new WP_Error( 'g2ab_membership_required', __( 'This booking is available to active members only.', 'g2a-booking' ), array( 'status' => 403 ) );
			}
		}

		$start_sql = $start_dt->format( 'Y-m-d H:i:s' );
		$end_sql   = $end_dt->format( 'Y-m-d H:i:s' );

		// Buffer + capacity mode for overlap checks below.
		$buffer_before    = (int) $booking_type->buffer_before;
		$buffer_after     = (int) $booking_type->buffer_after;
		$capacity_mode    = $this->normalize_capacity_mode( $booking_type->capacity_mode ?? '' );
		$start_eff_sql    = $start_dt->modify( '-' . max( 0, $buffer_before ) . ' minutes' )->format( 'Y-m-d H:i:s' );
		$end_eff_sql      = $end_dt->modify( '+' . max( 0, $buffer_after ) . ' minutes' )->format( 'Y-m-d H:i:s' );

		// ─── Customer record ────────────────────────────────────────────────
		$user_id = $this->ensure_customer_user( $customer_name, $customer_email, $customer_phone );
		if ( is_wp_error( $user_id ) ) {
			return new WP_Error( 'g2ab_customer_account_failed', sprintf(
				/* translators: %s error message */
				__( 'Could not create customer account: %s', 'g2a-booking' ),
				$user_id->get_error_message()
			), array( 'status' => 500 ) );
		}
		$user_id = (int) $user_id;

		$pricing         = $this->calculate_pricing( $booking_type, $party_size, $user_id, $customer_email );
		$subtotal        = (float) $pricing['subtotal'];
		$discount_amount = (float) $pricing['discount_amount'];
		$total           = (float) $pricing['total'];

		// ─── Gateway selection ──────────────────────────────────────────────
		$gateway = null;
		if ( class_exists( 'G2AB_Gateway_Manager' ) ) {
			$mgr = G2AB_Gateway_Manager::instance();
			if ( $gateway_id ) {
				$gateway = $mgr->get( $gateway_id );
			}
			if ( ! $gateway ) {
				$gateway = $mgr->pick_for_type( $booking_type );
			}
			$gateway = $this->prefer_stripe_for_public_payment( $gateway, $gateway_id, $booking_type );
		}
		$gateway_used_id = ( $gateway && method_exists( $gateway, 'id' ) ) ? $gateway->id() : 'pay_in_store';

		$modes = $this->payment_modes_for_type( $booking_type );
		if ( $total <= 0 || ( in_array( 'free', $modes, true ) && $total <= 0 ) ) {
			$payment_mode    = 'free';
			$due_now         = 0.0;
			$gateway_used_id = $total <= 0 ? 'free' : $gateway_used_id;
		} elseif ( 'pay_in_store' === $gateway_used_id || ( in_array( 'in_store', $modes, true ) && empty( $gateway_id ) ) ) {
			$payment_mode    = 'in_store';
			$gateway_used_id = 'pay_in_store';
			$due_now         = 0.0;
		} elseif ( in_array( 'deposit', $modes, true ) && (float) $booking_type->deposit_amount > 0 ) {
			$payment_mode = 'deposit';
			$due_now      = min( $total, (float) $booking_type->deposit_amount );
		} else {
			$payment_mode = 'full';
			$due_now      = $total;
		}
		$initial_status = ( $total <= 0 ) ? 'confirmed' : ( 'in_store' === $payment_mode ? 'reserved' : 'pending' );

		$clean_fields = array();
		foreach ( $fields as $k => $v ) {
			$clean_fields[ sanitize_key( $k ) ] = is_scalar( $v ) ? sanitize_text_field( wp_unslash( (string) $v ) ) : '';
		}

		$now_mysql      = current_time( 'mysql' );
		$uuid           = wp_generate_uuid4();
		// Confirm-payment token bound to this booking. The customer's success
		// page receives it in the create response and replays it back to the
		// confirm-payment endpoint so a guess of the UUID alone can't drive a
		// state change.
		$confirm_token  = wp_generate_password( 32, false );
		$bookings_table = $wpdb->prefix . 'g2ab_bookings';

		// ─── Race-safe + capacity-aware insert ──────────────────────────────
		// One transaction, one FOR UPDATE load query, one INSERT. Concurrent
		// submitters see a consistent view and are serialised by row/gap locks.
		// Load = COUNT(*) for booking_count, SUM(party_size) for party_size.
		// Buffers are honored on both sides via DATE_SUB / DATE_ADD on the
		// existing bookings' booking_type buffer columns.
		$bt_table_for_lock = $wpdb->prefix . 'g2ab_booking_types';
		$wpdb->query( 'START TRANSACTION' );

		if ( 'party_size' === $capacity_mode ) {
			$load_select = 'COALESCE(SUM(b.party_size), 0)';
		} else {
			$load_select = 'COUNT(*)';
		}

		$current_load = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT {$load_select} FROM {$bookings_table} b
			   LEFT JOIN {$bt_table_for_lock} bt ON bt.id = b.booking_type_id
			  WHERE b.resource_id = %d
			    AND b.status IN ('pending','reserved','confirmed','paid')
			    AND DATE_SUB(b.start_at, INTERVAL COALESCE(bt.buffer_before, 0) MINUTE) < %s
			    AND DATE_ADD(b.end_at,   INTERVAL COALESCE(bt.buffer_after,  0) MINUTE) > %s
			  FOR UPDATE",
			$resource_id, $end_eff_sql, $start_eff_sql
		) );

		$projected_load = $current_load + ( 'party_size' === $capacity_mode ? $party_size : 1 );

		if ( $projected_load > $capacity ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'g2ab_slot_full', __( 'This time slot is full. Please pick another time.', 'g2a-booking' ), array( 'status' => 409 ) );
		}

		$inserted = $wpdb->insert( $bookings_table, array(
			'uuid'             => $uuid,
			'booking_type_id'  => $booking_type_id,
			'resource_id'      => $resource_id,
			'form_id'          => $form_id ?: null,
			'user_id'          => $user_id ?: null,
			'customer_name'    => $customer_name,
			'customer_email'   => $customer_email,
			'customer_phone'   => $customer_phone,
			'start_at'         => $start_sql,
			'end_at'           => $end_sql,
			'duration_min'     => $duration_min,
			'party_size'       => $party_size,
			'status'           => $initial_status,
			'payment_mode'     => $payment_mode,
			'total_amount'     => $total,
			'paid_amount'      => 0,
			'currency'         => get_option( 'g2ab_currency', 'USD' ),
			'form_data'        => wp_json_encode( $clean_fields ),
			'metadata'         => wp_json_encode( array(
				'gateway'         => $gateway_used_id,
				'subtotal'        => $subtotal,
				'discount_amount' => $discount_amount,
				'due_now'         => $due_now,
				'discount_label'  => $pricing['discount_label'],
				'confirm_token'   => $confirm_token,
				'membership'      => array(
					'source'   => $pricing['membership_source'],
					'level_id' => $pricing['membership_level_id'],
					'percent'  => $pricing['member_discount_percent'],
				),
			) ),
			'waiver_signed'    => empty( $fields['waiver_acceptance'] ) ? 0 : 1,
			'source'           => 'web',
			'created_by'       => $user_id ?: null,
			'created_at'       => $now_mysql,
			'updated_at'       => $now_mysql,
		), array( '%s','%d','%d','%d','%d','%s','%s','%s','%s','%s','%d','%d','%s','%s','%f','%f','%s','%s','%s','%d','%s','%d','%s','%s' ) );

		if ( false === $inserted ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'g2ab_insert_failed', __( 'Could not save booking.', 'g2a-booking' ), array( 'status' => 500 ) );
		}
		$booking_id = (int) $wpdb->insert_id;
		$wpdb->query( 'COMMIT' );

		// Audit log (outside the transaction; cheap insert).
		$wpdb->insert( $wpdb->prefix . 'g2ab_logs', array(
			'booking_id' => $booking_id,
			'user_id'    => $user_id ?: null,
			'event_type' => 'created',
			'severity'   => 'info',
			'message'    => sprintf( 'Booking created via web (gateway=%s) for %s', $gateway_used_id, $customer_email ),
			'context'    => wp_json_encode( array( 'resource_id' => $resource_id, 'booking_type_id' => $booking_type_id, 'gateway' => $gateway_used_id ) ),
			'ip_address' => function_exists( 'g2ab_get_client_ip' ) ? g2ab_get_client_ip() : '',
			'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '',
			'created_at' => $now_mysql,
		) );

		do_action( 'g2ab_booking_created', $booking_id, array(
			'uuid' => $uuid, 'resource_id' => $resource_id, 'booking_type_id' => $booking_type_id,
			'start_at' => $start_sql, 'gateway' => $gateway_used_id,
		) );
		if ( in_array( $initial_status, array( 'confirmed', 'paid' ), true ) ) {
			do_action( 'g2ab_booking_status_changed', $booking_id, $initial_status, 'created' );
		}

		// ─── Payment intent ────────────────────────────────────────────────
		$response = array(
			'id'               => $booking_id,
			'uuid'             => $uuid,
			'status'           => $initial_status,
			'start_at'         => $start_sql,
			'end_at'           => $end_sql,
			'resource'         => $resource->name,
			'total'            => number_format( $total, 2 ),
			'subtotal'         => number_format( $subtotal, 2 ),
			'discount'         => number_format( $discount_amount, 2 ),
			'due_now'          => number_format( $due_now, 2 ),
			'discount_label'   => $pricing['discount_label'],
			'gateway'          => $gateway_used_id,
			'payment_required' => $due_now > 0,
			'redirect_url'     => '',
			'message'          => '',
			'confirm_token'    => $confirm_token,
		);

		if ( $due_now > 0 && $gateway && method_exists( $gateway, 'create_intent' ) ) {
			$booking_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$bookings_table} WHERE id = %d", $booking_id ) );
			$intent      = $gateway->create_intent( $booking_row, $due_now );

			if ( is_wp_error( $intent ) ) {
				$wpdb->insert( $wpdb->prefix . 'g2ab_logs', array(
					'booking_id' => $booking_id,
					'event_type' => 'payment_intent_failed',
					'severity'   => 'error',
					'message'    => $intent->get_error_message(),
					'context'    => wp_json_encode( array( 'gateway' => $gateway_used_id ) ),
					'created_at' => current_time( 'mysql' ),
				) );
				$response['message'] = sprintf(
					/* translators: %s error message */
					__( 'Booking saved, but payment setup failed: %s. Staff will contact you.', 'g2a-booking' ),
					$intent->get_error_message()
				);
			} elseif ( is_array( $intent ) ) {
				$response['redirect_url'] = $intent['redirect_url'] ?? '';
				$response['message']      = $intent['message'] ?? '';
				$response['gateway_ref']  = $intent['gateway_ref'] ?? '';
			}
		} elseif ( $total <= 0 ) {
			$response['message'] = __( 'Booking confirmed (no payment required).', 'g2a-booking' );
		} else {
			$response['message'] = __( 'Reservation received. Pay at the front desk on arrival.', 'g2a-booking' );
		}

		// Only send the basic fallback email when the email-automation addon is
		// not active. When active, the module already handled `g2ab_booking_created`
		// and the customer would otherwise receive two confirmations.
		// `class_exists` alone is unreliable — the module.php require_once loads
		// the class file even when the addon is disabled, so check the addon
		// registry directly.
		$email_automation_active = class_exists( 'G2AB_Addon_Manager' )
			&& G2AB_Addon_Manager::instance()->is_active( 'email_automation' );
		if ( ! $email_automation_active && 1 === (int) get_option( 'g2ab_send_confirmation_email', 1 ) ) {
			$this->send_confirmation_email( $customer_email, $customer_name, $resource->name, $start_sql, $uuid );
		}

		// Cache the response under the caller's idempotency key so a
		// retry within 5 minutes returns the same response and does
		// NOT create another booking row.
		if ( ! empty( $idem_key ) && strlen( $idem_key ) <= 128 ) {
			set_transient(
				'g2ab_idem_' . md5( $idem_key ),
				array(
					'booking_id' => (int) $booking_id,
					'response'   => array( 'success' => true, 'data' => $response ),
				),
				5 * MINUTE_IN_SECONDS
			);
		}

		return rest_ensure_response( array( 'success' => true, 'data' => $response ) );
	}

	public function get_booking_status( WP_REST_Request $request ) {
		global $wpdb;
		$uuid = sanitize_text_field( $request['uuid'] ?? '' );
		$confirm_token = sanitize_text_field( (string) $request->get_param( 'confirm_token' ) );
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $uuid ) ) {
			return new WP_Error( 'g2ab_bad_uuid', 'Bad UUID.', array( 'status' => 400 ) );
		}
		$bt  = $wpdb->prefix . 'g2ab_bookings';
		$rt  = $wpdb->prefix . 'g2ab_resources';
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT b.uuid, b.status, b.start_at, b.total_amount, b.paid_amount, b.currency, b.user_id, b.metadata, r.name AS resource_name FROM {$bt} b LEFT JOIN {$rt} r ON r.id = b.resource_id WHERE b.uuid = %s LIMIT 1",
			$uuid
		) );
		if ( ! $row ) {
			return new WP_Error( 'g2ab_not_found', 'Booking not found.', array( 'status' => 404 ) );
		}
		if ( ! $this->can_access_booking( $row, $confirm_token ) ) {
			return new WP_Error( 'g2ab_forbidden_booking', __( 'Invalid confirmation token.', 'g2a-booking' ), array( 'status' => 403 ) );
		}
		$data = array(
			'uuid'          => (string) $row->uuid,
			'status'        => (string) $row->status,
			'start_at'      => (string) $row->start_at,
			'total_amount'  => (float) $row->total_amount,
			'paid_amount'   => (float) $row->paid_amount,
			'currency'      => (string) $row->currency,
			'resource_name' => (string) $row->resource_name,
		);
		return rest_ensure_response( array( 'success' => true, 'data' => $data ) );
	}

	public function confirm_payment( WP_REST_Request $request ) {
		global $wpdb;
		$uuid          = sanitize_text_field( $request['uuid'] ?? '' );
		$session_id    = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
		$confirm_token = sanitize_text_field( (string) $request->get_param( 'confirm_token' ) );
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $uuid ) ) {
			return new WP_Error( 'g2ab_bad_uuid', 'Bad UUID.', array( 'status' => 400 ) );
		}

		$bt      = $wpdb->prefix . 'g2ab_bookings';
		$booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$bt} WHERE uuid = %s", $uuid ) );
		if ( ! $booking ) {
			return new WP_Error( 'g2ab_not_found', 'Booking not found.', array( 'status' => 404 ) );
		}

		// Detect legacy (pre-1.2.4) bookings — they have no stored
		// confirm_token in metadata. The original public endpoint would
		// fall through to the gateway round-trip for these, which meant
		// anyone with the UUID + a guessable session id could flip the
		// booking to "paid". Staff with manage_g2ab_bookings can still
		// confirm legacy rows manually; public callers must be told to
		// contact the range. We surface this BEFORE can_access_booking()
		// so the customer gets an actionable message instead of a
		// generic "Invalid confirmation token" 403.
		$meta_pre      = json_decode( (string) $booking->metadata, true );
		$has_stored    = is_array( $meta_pre ) && ! empty( $meta_pre['confirm_token'] );
		if ( ! $has_stored && ! current_user_can( 'manage_g2ab_bookings' ) ) {
			return new WP_Error(
				'g2ab_legacy_booking_requires_staff',
				__( 'This booking predates token-based confirmation. Please contact the range to confirm payment.', 'g2a-booking' ),
				array( 'status' => 403 )
			);
		}

		if ( ! $this->can_access_booking( $booking, $confirm_token ) ) {
			return new WP_Error(
				'g2ab_invalid_confirm_token',
				__( 'Invalid confirmation token.', 'g2a-booking' ),
				array( 'status' => 403 )
			);
		}

		// At this point: caller is staff, the booking owner, or has a
		// matching token. Re-parse metadata for downstream use.
		$meta          = is_array( $meta_pre ) ? $meta_pre : array();
		$stored_token  = isset( $meta['confirm_token'] ) ? (string) $meta['confirm_token'] : '';
		$token_matches = '' !== $stored_token && hash_equals( $stored_token, $confirm_token );

		if ( in_array( $booking->status, array( 'paid', 'confirmed', 'completed' ), true ) ) {
			return rest_ensure_response( array( 'success' => true, 'data' => array( 'status' => $booking->status, 'verified' => true ) ) );
		}

		// At this point auth is already established by the two gates above
		// (legacy-requires-staff + can_access_booking). $token_matches is
		// computed for downstream gateway calls that may need it.
		unset( $token_matches );

		$gateway_id = $meta['gateway'] ?? '';
		if ( $gateway_id && class_exists( 'G2AB_Gateway_Manager' ) ) {
			$gw = G2AB_Gateway_Manager::instance()->get( $gateway_id );
			if ( $gw && method_exists( $gw, 'confirm_payment' ) && $session_id ) {
				$result = $gw->confirm_payment( $session_id );
				if ( is_array( $result ) && ! empty( $result['handled'] ) ) {
					return rest_ensure_response( array( 'success' => true, 'data' => array( 'status' => 'paid', 'verified' => true ) ) );
				}
			}
		}
		return rest_ensure_response( array( 'success' => true, 'data' => array( 'status' => $booking->status, 'verified' => false ) ) );
	}

	/**
	 * Booking object access policy for public endpoints.
	 *
	 * Allows access when one of these is true:
	 * - caller can manage bookings (staff/admin)
	 * - caller is the booking's linked WP user
	 * - supplied confirmation token matches booking metadata token
	 */
	private function can_access_booking( $booking, $confirm_token ) {
		if ( current_user_can( 'manage_g2ab_bookings' ) ) {
			return true;
		}

		$current_user_id = get_current_user_id();
		$booking_user_id = isset( $booking->user_id ) ? (int) $booking->user_id : 0;
		if ( $current_user_id > 0 && $booking_user_id > 0 && $current_user_id === $booking_user_id ) {
			return true;
		}

		$meta = json_decode( (string) ( $booking->metadata ?? '' ), true );
		if ( ! is_array( $meta ) ) {
			return false;
		}
		$stored_token = isset( $meta['confirm_token'] ) ? (string) $meta['confirm_token'] : '';
		if ( '' === $stored_token || '' === $confirm_token ) {
			return false;
		}

		return hash_equals( $stored_token, (string) $confirm_token );
	}

	private function send_confirmation_email( $to, $name, $resource, $start_at, $uuid ) {
		$site    = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		$subject = sprintf( '[%s] Booking received — %s', $site, $resource );
		$body    = sprintf(
			"Hi %s,\n\nYour booking is in.\n\nLane: %s\nWhen: %s\nConfirmation: %s\n\nWe'll see you on the range.\n\n— %s",
			$name, $resource, $start_at, $uuid, $site
		);
		wp_mail( $to, $subject, $body );
		$admin_email = get_option( 'g2ab_admin_notification_email', get_option( 'admin_email' ) );
		if ( $admin_email && is_email( $admin_email ) ) {
			wp_mail( $admin_email, '[G2A] New booking — ' . $resource, $body );
		}
	}
}
