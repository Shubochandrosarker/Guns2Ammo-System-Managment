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
			'permission_callback' => '__return_true',
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
		register_rest_route( G2AB_REST_NAMESPACE, '/resources', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array( $this, 'list_resources' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( G2AB_REST_NAMESPACE, '/payment-methods', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array( $this, 'list_payment_methods' ),
			'permission_callback' => '__return_true',
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
		register_rest_route( G2AB_REST_NAMESPACE, '/bookings/(?P<uuid>[a-f0-9-]{36})/resume-payment', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array( $this, 'resume_payment' ),
			'permission_callback' => '__return_true',
		) );
		// Tiny, explicitly-uncached endpoint so the frontend can fetch a FRESH
		// wp_rest nonce when the one baked into a (possibly cached) page has gone
		// stale. Prevents a stale-nonce 403 from looking like "cookies won't let me
		// book". Public on purpose — a nonce is not a secret.
		register_rest_route( G2AB_REST_NAMESPACE, '/nonce', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array( $this, 'get_fresh_nonce' ),
			'permission_callback' => '__return_true',
		) );

		// ── Events (public reads + seat booking) ──────────────────────────
		register_rest_route( G2AB_REST_NAMESPACE, '/events', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array( $this, 'list_events' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( G2AB_REST_NAMESPACE, '/events/(?P<id>\d+)/occurrences', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array( $this, 'list_event_occurrences' ),
			'permission_callback' => '__return_true',
			'args' => array(
				'id' => array( 'required' => true, 'sanitize_callback' => 'absint', 'validate_callback' => static fn( $v ) => $v > 0 ),
			),
		) );
		register_rest_route( G2AB_REST_NAMESPACE, '/events/book', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'create_event_booking' ),
			'permission_callback' => array( $this, 'permission_create_booking' ),
		) );
	}

	/**
	 * Return a fresh wp_rest nonce, always uncached, so a stale page cache can
	 * self-heal before retrying a booking POST.
	 */
	public function get_fresh_nonce() {
		nocache_headers();
		return rest_ensure_response( array(
			'success' => true,
			'data'    => array( 'nonce' => wp_create_nonce( 'wp_rest' ) ),
		) );
	}

	private function send_no_store_headers() {
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0', false );
			header( 'Pragma: no-cache', false );
			header( 'Expires: 0', false );
		}
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

		$resource_key = (int) $request->get_param( 'resource_id' );
		if ( ! $resource_key && (int) $request->get_param( 'occurrence_id' ) ) {
			$resource_key = 'event-' . (int) $request->get_param( 'occurrence_id' );
		}
		$attempt = function_exists( 'g2ab_rate_limit_check' )
			? g2ab_rate_limit_check( 'booking_attempt', array( $this->rate_limit_identity(), $resource_key ), 30, 10 * MINUTE_IN_SECONDS )
			: array( 'limited' => false, 'retry_after' => 600 );
		if ( ! empty( $attempt['limited'] ) ) {
			return new WP_Error( 'g2ab_rate_limited', __( 'Too many booking attempts. Try again in a few minutes.', 'g2a-booking' ), array( 'status' => 429, 'retry_after' => (int) $attempt['retry_after'] ) );
		}

		$success = function_exists( 'g2ab_rate_limit_check' )
			? g2ab_rate_limit_check( 'booking_success_probe', array( $this->rate_limit_identity() ), 100, HOUR_IN_SECONDS )
			: array( 'limited' => false, 'retry_after' => HOUR_IN_SECONDS );
		if ( ! empty( $success['limited'] ) ) {
			return new WP_Error( 'g2ab_rate_limited', __( 'Too many bookings from this network. Please try again later or call us.', 'g2a-booking' ), array( 'status' => 429, 'retry_after' => (int) $success['retry_after'] ) );
		}
		return true;
	}

	/**
	 * Transient key for the per-IP+resource booking rate limit. Shared by the
	 * permission gate (which increments it) and create_booking() (which clears it
	 * on a successful booking).
	 */
	private function rate_limit_key( $resource_id ) {
		return 'g2ab_rl_' . md5( $this->rate_limit_identity() . '|' . (string) $resource_id );
	}

	/**
	 * Transient key for the per-IP SUCCESSFUL guest booking counter. Unlike the
	 * attempt counter above, this one is incremented on success and never
	 * cleared, capping how many accounts/bookings one IP can create per hour.
	 */
	private function success_rate_limit_key() {
		return 'g2ab_rls_' . md5( $this->rate_limit_identity() );
	}

	/**
	 * Count a successful guest booking toward the per-IP hourly success cap.
	 */
	private function bump_success_rate_limit() {
		if ( function_exists( 'g2ab_rate_limit_check' ) ) {
			g2ab_rate_limit_check( 'booking_success', array( $this->rate_limit_identity() ), 5, HOUR_IN_SECONDS );
		}
	}

	private function rate_limit_identity() {
		$ip = function_exists( 'g2ab_get_client_ip' ) ? g2ab_get_client_ip() : (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		return hash( 'sha256', (string) $ip );
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
		$this->send_no_store_headers();
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
				$buffer_before = (int) $booking_type->buffer_before;
				$buffer_after  = (int) $booking_type->buffer_after;
				$capacity_mode = $this->normalize_capacity_mode( $booking_type->capacity_mode ?? '' );
				$duration      = max( 15, min( 480, (int) $booking_type->duration_min ) );
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
		// Memberistic answers authoritatively via the g2ab_user_is_member filter
		// (it checks live membership status + renewal date). We deliberately do NOT
		// trust the memberistic_active_plan_id user-meta here: it is written on
		// activation but NOT cleared on expiry/cancellation, so trusting it would
		// keep an expired member on member pricing and members-only access. This
		// method only SEEDS the filter; Memberistic (or PMPro above) supplies the
		// real answer. Staff with the manage capability can always book.
		return user_can( $user_id, 'manage_g2ab_bookings' );
	}

	private function user_can_book_member_type( $booking_type, $customer_email = '' ) {
		if ( empty( $booking_type->members_only ) ) {
			return true;
		}
		$user_id = $this->authenticated_user_id();
		$allowed = $this->is_active_member( $user_id );
		return (bool) apply_filters( 'g2ab_user_is_member', $allowed, $user_id, $booking_type, $customer_email );
	}

	private function is_member_for_discount( $booking_type, $customer_email = '' ) {
		$user_id   = $this->authenticated_user_id();
		$is_member = $this->is_active_member( $user_id );
		return (bool) apply_filters( 'g2ab_user_is_member', $is_member, $user_id, $booking_type, $customer_email );
	}

	/**
	 * Advisory-only membership lookup for a typed email.
	 *
	 * Returns a hint that the person booking MAY hold a membership, so the
	 * front desk can apply the member rate after checking ID at check-in. This
	 * deliberately does NOT change the price or grant access — it can't, safely,
	 * because a typed address proves nothing (audit C27). It exists so a
	 * logged-out member is never silently charged the walk-in rate by card.
	 *
	 * @return array{matched:bool,label:string}
	 */
	private function advisory_membership_hint( $customer_email ) {
		$hint = array( 'matched' => false, 'label' => '' );

		if ( ! is_email( $customer_email ) ) {
			return $hint;
		}

		return (array) apply_filters( 'g2ab_advisory_membership_hint', $hint, $customer_email );
	}

	private function payment_modes_for_type( $booking_type ) {
		$modes = array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', (string) $booking_type->payment_modes ) ) ) );
		return $modes ? $modes : array( 'full', 'in_store' );
	}

	private function calculate_pricing( $booking_type, $party_size, $user_id, $customer_email = '' ) {
		$party_size       = max( 1, (int) $party_size );
		$subtotal         = round( (float) $booking_type->base_price * $party_size, 2 );
		$discount_amount  = 0.0;
		$discount_percent = 0.0;

		if ( $subtotal > 0 && (float) $booking_type->member_discount > 0 && $this->is_member_for_discount( $booking_type, $customer_email ) ) {
			$discount_percent = min( 100, (float) $booking_type->member_discount );
			$discount_amount  = round( $subtotal * $discount_percent / 100, 2 );
		}

		$pricing = array(
			'subtotal'                => $subtotal,
			'discount_amount'         => $discount_amount,
			'total'                   => max( 0, round( $subtotal - $discount_amount, 2 ) ),
			'member_discount_percent' => $discount_percent,
			'membership_level_id'     => 0,
			'membership_source'       => $discount_amount > 0 ? 'booking_type' : '',
			'discount_label'          => '',
		);

		$pricing = apply_filters( 'g2ab_booking_pricing', $pricing, $booking_type, $party_size, $user_id, $customer_email );

		$pricing['subtotal']                = max( 0, round( (float) ( $pricing['subtotal'] ?? $subtotal ), 2 ) );
		$pricing['discount_amount']         = max( 0, min( $pricing['subtotal'], round( (float) ( $pricing['discount_amount'] ?? 0 ), 2 ) ) );
		$pricing['total']                   = max( 0, round( (float) ( $pricing['total'] ?? ( $pricing['subtotal'] - $pricing['discount_amount'] ) ), 2 ) );
		$pricing['member_discount_percent'] = max( 0, min( 100, (float) ( $pricing['member_discount_percent'] ?? 0 ) ) );
		$pricing['membership_level_id']     = absint( $pricing['membership_level_id'] ?? 0 );
		$pricing['membership_source']       = sanitize_key( $pricing['membership_source'] ?? '' );
		$pricing['discount_label']          = sanitize_text_field( $pricing['discount_label'] ?? '' );

		return $pricing;
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

	/**
	 * Optionally force a public (walk-in) booking onto Stripe.
	 *
	 * This used to be unconditional, which is why EVERY public lane booking
	 * minted a Stripe Checkout Session up front — including for members and for
	 * customers who never intended to pay online — leaving a long tail of
	 * abandoned `open` attempts. Prepay is now opt-in per site via
	 * `g2ab_require_public_prepay` (default off: reserve now, pay at the range),
	 * and is always skipped when the booking still needs a front-desk rate
	 * decision.
	 */
	/**
	 * Whether a public (walk-in) booking must be paid by card before the lane is
	 * held. Off by default: reserve now, settle at the front desk.
	 */
	private function public_prepay_required( $booking_type ) {
		return (bool) apply_filters(
			'g2ab_require_public_prepay',
			1 === (int) get_option( 'g2ab_require_public_prepay', 0 ),
			$booking_type
		);
	}

	private function prefer_stripe_for_public_payment( $gateway, $gateway_id, $booking_type, $needs_desk_rate = false ) {
		if ( $gateway_id || ! class_exists( 'G2AB_Gateway_Manager' ) || ! empty( $booking_type->members_only ) ) {
			return $gateway;
		}
		if ( $needs_desk_rate || ! $this->public_prepay_required( $booking_type ) ) {
			return $gateway;
		}
		$mgr    = G2AB_Gateway_Manager::instance();
		$stripe = $mgr->get( 'stripe' );
		// Only prefer Stripe when it's actually configured and usable — otherwise we
		// would hand a payable booking to a dead gateway and the customer hits
		// "payment setup failed" with no way to pay. Fall back to the originally
		// chosen gateway in that case.
		if ( $stripe && ( ! method_exists( $stripe, 'is_available' ) || $stripe->is_available() ) ) {
			return $stripe;
		}
		return $gateway;
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
	/**
	 * Resolve the user record a booking should be ATTRIBUTED to.
	 *
	 * Attribution is deliberately separate from entitlement. This may match an
	 * existing account by the typed email so the booking lands on the right
	 * customer record, front-desk history, and CRM — but the returned id must
	 * NEVER be used to grant member pricing or members-only access on its own.
	 * Use authenticated_user_id() for that. See the class docblock on
	 * calculate_pricing() for why the two are kept apart (audit C27).
	 *
	 * No account is created here: since 1.9.9.17 provisioning is deferred until
	 * the reservation is actually committed (free / pay-in-store) or an online
	 * payment is verified, so an abandoned checkout never leaves a stray user.
	 *
	 * @return int User id, or 0 when the customer has no account yet.
	 */
	private function ensure_customer_user( $customer_name, $customer_email, $customer_phone ) {
		$current_user_id = get_current_user_id();
		if ( $current_user_id ) {
			update_user_meta( $current_user_id, 'billing_phone', $customer_phone );
			return (int) $current_user_id;
		}

		$existing = get_user_by( 'email', $customer_email );
		if ( $existing instanceof WP_User ) {
			// Attribution only. Do NOT auto-login, and do NOT treat this as proof
			// the visitor IS this person — they merely typed the address.
			update_user_meta( $existing->ID, 'g2ab_last_booking_contact_phone', $customer_phone );
			return (int) $existing->ID;
		}

		return 0;
	}

	/**
	 * The user id that may be trusted for ENTITLEMENT decisions — member
	 * pricing, members-only access. Only a real authenticated session counts.
	 *
	 * Audit C27: matching on a typed email let anyone who knew a member's
	 * address book at member rates. Logged-out members are handled instead by
	 * the pay-at-range path, where staff verify membership at check-in.
	 */
	private function authenticated_user_id() {
		return (int) get_current_user_id();
	}

	public function create_booking( WP_REST_Request $request ) {
		global $wpdb;

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
		$capacity      = max( 1, (int) $resource->capacity );
		$capacity_mode = $this->normalize_capacity_mode( $booking_type->capacity_mode ?? '' );

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

		// Slot must fall within business hours for that weekday and not on a blackout.
		$rules_table = $wpdb->prefix . 'g2ab_availability_rules';
		$dow         = (int) $start_dt->format( 'w' );
		$hours       = $wpdb->get_row( $wpdb->prepare(
			"SELECT start_time, end_time FROM {$rules_table} WHERE rule_type = 'business_hours' AND day_of_week = %d AND is_active = 1 ORDER BY priority DESC LIMIT 1",
			$dow
		) );
		if ( ! $hours ) {
			return new WP_Error( 'g2ab_closed', __( 'Bookings are not available on this day.', 'g2a-booking' ), array( 'status' => 400 ) );
		}
		$date_str   = $start_dt->format( 'Y-m-d' );
		$open_dt    = $this->parse_site_datetime( $date_str . ' ' . substr( (string) $hours->start_time, 0, 8 ) );
		$close_dt   = $this->parse_site_datetime( $date_str . ' ' . substr( (string) $hours->end_time, 0, 8 ) );
		if ( ! $open_dt || ! $close_dt || $start_dt < $open_dt || $end_dt > $close_dt ) {
			return new WP_Error( 'g2ab_outside_hours', __( 'This time is outside business hours.', 'g2a-booking' ), array( 'status' => 400 ) );
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
		// The form checkbox satisfies the waiver, but let integrations (e.g.
		// Memberistic's waiver-on-file bridge) auto-satisfy it for a customer who
		// already has a signed waiver on record.
		$waiver_ok = ! empty( $fields['waiver_acceptance'] );
		$waiver_ok = (bool) apply_filters( 'g2ab_waiver_satisfied', $waiver_ok, $fields, $booking_type );
		if ( (int) $booking_type->requires_waiver === 1 && ! $waiver_ok ) {
			return new WP_Error( 'g2ab_waiver_required', __( 'Waiver acceptance is required.', 'g2a-booking' ), array( 'status' => 400 ) );
		}
		// Only a party_size-capacity resource (e.g. a classroom counting seats)
		// caps headcount against capacity. For a booking_count lane, party_size is
		// just "how many shooters share this one lane" — the seeded lane form
		// invites 1-4 — so it must NOT be rejected against the lane's capacity of 1.
		// The slot/overlap check below already guarantees the single lane is free.
		if ( 'party_size' === $capacity_mode && $party_size > $capacity ) {
			return new WP_Error( 'g2ab_party_too_large', sprintf(
				/* translators: %d capacity */
				__( 'Party size exceeds the resource capacity of %d.', 'g2a-booking' ),
				$capacity
			), array( 'status' => 400 ) );
		}

		// Members-only check (with public fallback to lane-booking).
		if ( ! $this->user_can_book_member_type( $booking_type, $customer_email ) ) {
			$fallback_booking_type = $this->public_fallback_booking_type( $booking_type );
			if ( $fallback_booking_type ) {
				$booking_type    = $fallback_booking_type;
				$booking_type_id = (int) $booking_type->id;
				// Re-resolve capacity_mode for the fallback type so the capacity/
				// overlap math below matches the type we actually book.
				$capacity_mode   = $this->normalize_capacity_mode( $booking_type->capacity_mode ?? '' );
			} else {
				return new WP_Error( 'g2ab_membership_required', __( 'This booking is available to active members only.', 'g2a-booking' ), array( 'status' => 403 ) );
			}
		}

		$start_sql = $start_dt->format( 'Y-m-d H:i:s' );
		$end_sql   = $end_dt->format( 'Y-m-d H:i:s' );

		// Buffer for the overlap checks below ($capacity_mode already resolved above).
		$buffer_before    = (int) $booking_type->buffer_before;
		$buffer_after     = (int) $booking_type->buffer_after;
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

		// Entitlement (member pricing / members-only) is resolved from the
		// AUTHENTICATED session only — never from $user_id, which may have been
		// matched from a typed email for attribution. See ensure_customer_user().
		$pricing         = $this->calculate_pricing( $booking_type, $party_size, $this->authenticated_user_id(), $customer_email );
		$subtotal        = (float) $pricing['subtotal'];
		$discount_amount = (float) $pricing['discount_amount'];
		$total           = (float) $pricing['total'];

		// A logged-out member typing their own address gets no automatic
		// discount (it can't be trusted), but we must not silently charge them
		// the walk-in rate by card either. Flag it and let the front desk settle
		// the correct rate at check-in.
		$membership_hint = $this->advisory_membership_hint( $customer_email );
		$needs_desk_rate = empty( $pricing['membership_source'] ) && ! empty( $membership_hint['matched'] );

		// ─── Gateway selection ──────────────────────────────────────────────
		$gateway = null;
		if ( class_exists( 'G2AB_Gateway_Manager' ) ) {
			$mgr = G2AB_Gateway_Manager::instance();
			if ( $gateway_id ) {
				$gateway = $mgr->get( $gateway_id );
				if ( ! $gateway || ( method_exists( $gateway, 'is_available' ) && ! $gateway->is_available() ) ) {
					return new WP_Error( 'g2ab_gateway_unavailable', __( 'Selected payment gateway is not available.', 'g2a-booking' ), array( 'status' => 503 ) );
				}
			}
			if ( ! $gateway ) {
				$gateway = $mgr->pick_for_type( $booking_type );
			}
			$gateway = $this->prefer_stripe_for_public_payment( $gateway, $gateway_id, $booking_type, $needs_desk_rate );
		}
		$gateway_used_id = ( $gateway && method_exists( $gateway, 'id' ) ) ? $gateway->id() : 'pay_in_store';

		$modes = $this->payment_modes_for_type( $booking_type );

		// Walk-in lane types are frequently configured card-only, which is what
		// forced every public booking through Stripe: with no `in_store` mode
		// there was no other branch to fall into, so an abandoned form left an
		// `open` checkout attempt behind. Unless the site explicitly requires
		// prepay, a public booking may always settle at the front desk. The same
		// applies when the email matched a membership we couldn't verify — that
		// customer must reach the desk rather than be charged the walk-in rate.
		$allow_desk_settlement = empty( $booking_type->members_only )
			&& empty( $gateway_id )
			&& ( $needs_desk_rate || ! $this->public_prepay_required( $booking_type ) );

		if ( $allow_desk_settlement ) {
			if ( ! in_array( 'in_store', $modes, true ) ) {
				$modes[] = 'in_store';
			}
			$gateway_used_id = 'pay_in_store';
		}

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

		// ─── Idempotency key ────────────────────────────────────────────────
		// A network retry or a double form-submit (slow response, impatient
		// double-click) resubmits the exact same resource/type/time/customer —
		// without this, each attempt was a fresh insert, so on a resource with
		// spare capacity two submissions could both succeed as separate
		// bookings (and, for a paid booking type, trigger two separate payment-
		// gateway intent/charge creations). Deriving the key from the request
		// content plus a coarse time bucket needs no client changes: retries of
		// the SAME attempt land in the same bucket and collide on the unique
		// idempotency_key index below; a deliberate new booking of the same
		// slot made later (a different bucket) is never blocked.
		$idempotency_key = hash( 'sha256', implode( '|', array(
			$resource_id,
			$booking_type_id,
			$start_sql,
			strtolower( $customer_email ),
			(int) floor( time() / 600 ), // 10-minute bucket
		) ) );

		// ─── Race-safe + capacity-aware insert ──────────────────────────────
		// One transaction, one FOR UPDATE load query, one INSERT. Concurrent
		// submitters see a consistent view and are serialised by row/gap locks.
		// Load = COUNT(*) for booking_count, SUM(party_size) for party_size.
		// Buffers are honored on both sides via DATE_SUB / DATE_ADD on the
		// existing bookings' booking_type buffer columns.
		$bt_table_for_lock = $wpdb->prefix . 'g2ab_booking_types';
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'g2ab_transaction_failed', __( 'Could not start booking transaction.', 'g2a-booking' ), array( 'status' => 500 ) );
		}
		$resource = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$res_table} WHERE id = %d AND is_active = 1 FOR UPDATE", $resource_id ) );
		if ( ! $resource ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'g2ab_invalid_resource', __( 'Invalid resource.', 'g2a-booking' ), array( 'status' => 400 ) );
		}
		$booking_type = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$bt_table} WHERE id = %d AND is_active = 1 FOR UPDATE", $booking_type_id ) );
		if ( ! $booking_type ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'g2ab_invalid_type', __( 'Invalid booking type.', 'g2a-booking' ), array( 'status' => 400 ) );
		}
		$capacity      = max( 1, (int) $resource->capacity );
		$capacity_mode = $this->normalize_capacity_mode( $booking_type->capacity_mode ?? '' );

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
				'confirm_token_hash' => function_exists( 'g2ab_hash_public_token' ) ? g2ab_hash_public_token( $confirm_token ) : hash( 'sha256', $confirm_token ),
				'membership'      => array(
					'source'   => $pricing['membership_source'],
					'level_id' => $pricing['membership_level_id'],
					'percent'  => $pricing['member_discount_percent'],
				),
				// Advisory only — a typed email matched an active membership but
				// the visitor wasn't logged in, so no discount was applied. The
				// front desk verifies ID and settles the member rate at check-in.
				'membership_hint' => array(
					'matched'          => ! empty( $membership_hint['matched'] ),
					'label'            => (string) ( $membership_hint['label'] ?? '' ),
					'needs_desk_rate'  => (bool) $needs_desk_rate,
				),
			) ),
			'waiver_signed'    => $waiver_ok ? 1 : 0,
			'source'           => 'web',
			'created_by'       => $user_id ?: null,
			'created_at'       => $now_mysql,
			'updated_at'       => $now_mysql,
			'idempotency_key'  => $idempotency_key,
		), array( '%s','%d','%d','%d','%d','%s','%s','%s','%s','%s','%d','%d','%s','%s','%f','%f','%s','%s','%s','%d','%s','%d','%s','%s','%s' ) );

		if ( false === $inserted ) {
			// A duplicate idempotency_key means this exact request was already
			// processed a moment ago (retry/double-submit) — return the booking
			// that attempt created instead of erroring or creating a second one.
			if ( false !== stripos( (string) $wpdb->last_error, 'idempotency_key' ) ) {
				$wpdb->query( 'ROLLBACK' );
				$existing_id = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$bookings_table} WHERE idempotency_key = %s LIMIT 1",
					$idempotency_key
				) );
				if ( $existing_id > 0 ) {
					return $this->idempotent_replay_response( $existing_id );
				}
			}
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'g2ab_insert_failed', __( 'Could not save booking.', 'g2a-booking' ), array( 'status' => 500 ) );
		}
		$booking_id = (int) $wpdb->insert_id;
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'g2ab_commit_failed', __( 'Could not commit booking.', 'g2a-booking' ), array( 'status' => 500 ) );
		}

		// A completed booking proves this visitor isn't a scripted bot — release
		// their rate-limit budget so a returning legitimate guest (or the next
		// person on the same shared/NAT IP) isn't penalised by earlier attempts.
		if ( ! is_user_logged_in() ) {
			if ( function_exists( 'g2ab_rate_limit_clear' ) ) {
				g2ab_rate_limit_clear( 'booking_attempt', array( $this->rate_limit_identity(), $resource_id ) );
			}
			$this->bump_success_rate_limit();
		}

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

		// The booking row is already committed. Isolate post-commit side effects so
		// a fault in ANY listener (Memberistic enrichment, corporate guest
		// enrollment, email automation, a third-party hook) can't turn a saved
		// booking into a 500 — which would show the customer a failure and tempt a
		// duplicate retry. We log and swallow; the booking stands.
		try {
			do_action( 'g2ab_booking_created', $booking_id, array(
				'uuid' => $uuid, 'resource_id' => $resource_id, 'booking_type_id' => $booking_type_id,
				'start_at' => $start_sql, 'gateway' => $gateway_used_id,
			) );
			if ( in_array( $initial_status, array( 'confirmed', 'paid' ), true ) ) {
				do_action( 'g2ab_booking_status_changed', $booking_id, $initial_status, 'created' );
			}
		} catch ( \Throwable $e ) {
			error_log( '[G2AB] post-commit booking hook failed for #' . $booking_id . ': ' . $e->getMessage() );
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
			$intent      = ( 'stripe' === $gateway_used_id )
				? $gateway->create_intent( $booking_row, $due_now, array( 'confirm_token' => $confirm_token ) )
				: $gateway->create_intent( $booking_row, $due_now );

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
			if ( function_exists( 'g2ab_provision_booking_customer_account' ) ) {
				g2ab_provision_booking_customer_account( $booking_id );
			}
		} else {
			$response['message'] = __( 'Reservation received. Pay at the front desk on arrival.', 'g2a-booking' );
			if ( function_exists( 'g2ab_provision_booking_customer_account' ) ) {
				g2ab_provision_booking_customer_account( $booking_id );
			}
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

		return rest_ensure_response( array( 'success' => true, 'data' => $response ) );
	}

	/**
	 * Build the same success response shape create_booking() returns, from an
	 * already-existing booking row — used when an idempotency-key collision
	 * shows the current request is a retry/double-submit of one that already
	 * succeeded, so the caller gets the original booking back instead of a
	 * confusing error (or a second booking/charge).
	 *
	 * @param int $booking_id Existing g2ab_bookings row id.
	 * @return \WP_REST_Response|WP_Error
	 */
	private function idempotent_replay_response( $booking_id ) {
		global $wpdb;
		$bookings_table = $wpdb->prefix . 'g2ab_bookings';
		$res_table      = $wpdb->prefix . 'g2ab_resources';

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT b.*, r.name AS resource_name FROM {$bookings_table} b
			 LEFT JOIN {$res_table} r ON r.id = b.resource_id
			 WHERE b.id = %d",
			$booking_id
		) );
		if ( ! $row ) {
			return new WP_Error( 'g2ab_insert_failed', __( 'Could not save booking.', 'g2a-booking' ), array( 'status' => 500 ) );
		}

		$metadata = json_decode( (string) $row->metadata, true );
		$metadata = is_array( $metadata ) ? $metadata : array();

		$response = array(
			'id'               => (int) $row->id,
			'uuid'             => (string) $row->uuid,
			'status'           => (string) $row->status,
			'start_at'         => (string) $row->start_at,
			'end_at'           => (string) $row->end_at,
			'resource'         => (string) $row->resource_name,
			'total'            => number_format( (float) $row->total_amount, 2 ),
			'subtotal'         => number_format( (float) ( $metadata['subtotal'] ?? $row->total_amount ), 2 ),
			'discount'         => number_format( (float) ( $metadata['discount_amount'] ?? 0 ), 2 ),
			'due_now'          => number_format( (float) ( $metadata['due_now'] ?? 0 ), 2 ),
			'discount_label'   => (string) ( $metadata['discount_label'] ?? '' ),
			'gateway'          => (string) ( $metadata['gateway'] ?? '' ),
			'payment_required' => (float) ( $metadata['due_now'] ?? 0 ) > 0,
			'redirect_url'     => '',
			'message'          => __( 'This booking was already received — showing your existing reservation.', 'g2a-booking' ),
			'confirm_token'    => '',
			'idempotent_replay'=> true,
		);

		return rest_ensure_response( array( 'success' => true, 'data' => $response ) );
	}

	public function get_booking_status( WP_REST_Request $request ) {
		global $wpdb;
		$uuid = sanitize_text_field( $request['uuid'] ?? '' );
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $uuid ) ) {
			return new WP_Error( 'g2ab_bad_uuid', 'Bad UUID.', array( 'status' => 400 ) );
		}
		$limit = function_exists( 'g2ab_rate_limit_check' )
			? g2ab_rate_limit_check( 'booking_status', array( $this->rate_limit_identity(), $uuid ), 30, 5 * MINUTE_IN_SECONDS )
			: array( 'limited' => false, 'retry_after' => 300 );
		if ( ! empty( $limit['limited'] ) ) {
			return new WP_Error( 'g2ab_rate_limited', __( 'Too many status requests. Please wait and try again.', 'g2a-booking' ), array( 'status' => 429, 'retry_after' => (int) $limit['retry_after'] ) );
		}
		$bt  = $wpdb->prefix . 'g2ab_bookings';
		$rt  = $wpdb->prefix . 'g2ab_resources';
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT b.id, b.uuid, b.status, b.start_at, b.total_amount, b.paid_amount, b.currency, b.user_id, b.metadata, r.name AS resource_name FROM {$bt} b LEFT JOIN {$rt} r ON r.id = b.resource_id WHERE b.uuid = %s LIMIT 1",
			$uuid
		) );
		if ( ! $row ) {
			return new WP_Error( 'g2ab_not_found', 'Booking not found.', array( 'status' => 404 ) );
		}
		nocache_headers();
		$meta          = json_decode( (string) $row->metadata, true );
		$meta          = is_array( $meta ) ? $meta : array();
		$confirm_token = sanitize_text_field( (string) $request->get_param( 'confirm_token' ) );
		$owns_booking  = is_user_logged_in() && (int) $row->user_id > 0 && get_current_user_id() === (int) $row->user_id;
		$staff         = current_user_can( 'manage_g2ab_bookings' );
		$token_ok      = function_exists( 'g2ab_verify_public_token' ) && g2ab_verify_public_token( $confirm_token, $meta );
		if ( ! $staff && ! $owns_booking && ! $token_ok ) {
			return rest_ensure_response( array( 'success' => true, 'data' => array( 'uuid' => $row->uuid, 'status' => $row->status ) ) );
		}
		unset( $row->id, $row->user_id, $row->metadata );
		return rest_ensure_response( array( 'success' => true, 'data' => $row ) );
	}

	public function confirm_payment( WP_REST_Request $request ) {
		global $wpdb;
		nocache_headers();
		$uuid          = sanitize_text_field( $request['uuid'] ?? '' );
		$session_id    = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
		$confirm_token = sanitize_text_field( (string) $request->get_param( 'confirm_token' ) );
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $uuid ) ) {
			return new WP_Error( 'g2ab_bad_uuid', 'Bad UUID.', array( 'status' => 400 ) );
		}
		$limit = function_exists( 'g2ab_rate_limit_check' )
			? g2ab_rate_limit_check( 'confirm_payment', array( $this->rate_limit_identity(), $uuid ), 12, 5 * MINUTE_IN_SECONDS )
			: array( 'limited' => false, 'retry_after' => 300 );
		if ( ! empty( $limit['limited'] ) ) {
			return new WP_Error( 'g2ab_rate_limited', __( 'Too many confirmation attempts. Please wait and try again.', 'g2a-booking' ), array( 'status' => 429, 'retry_after' => (int) $limit['retry_after'] ) );
		}

		$bt      = $wpdb->prefix . 'g2ab_bookings';
		$booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$bt} WHERE uuid = %s", $uuid ) );
		if ( ! $booking ) {
			return new WP_Error( 'g2ab_not_found', 'Booking not found.', array( 'status' => 404 ) );
		}

		// Token gate: returned to the customer in the create response and
		// stored in metadata. Without it (or for a mismatched value), the
		// endpoint refuses to drive any state change. Bookings created
		// before 1.2.4 don't carry a token; for those we fall back to
		// the gateway round-trip which already verifies the session
		// against the upstream provider.
		$meta          = json_decode( (string) $booking->metadata, true );
		$has_stored    = ! empty( $meta['confirm_token_hash'] ) || ! empty( $meta['confirm_token'] );
		$token_matches = function_exists( 'g2ab_verify_public_token' ) && g2ab_verify_public_token( $confirm_token, is_array( $meta ) ? $meta : array() );

		if ( in_array( $booking->status, array( 'paid', 'confirmed', 'completed' ), true ) && ( ! $has_stored || $token_matches ) ) {
			return rest_ensure_response( array( 'success' => true, 'data' => array( 'status' => $booking->status, 'verified' => true ) ) );
		}

		if ( $has_stored && ! $token_matches ) {
			return new WP_Error(
				'g2ab_invalid_confirm_token',
				__( 'Invalid confirmation token.', 'g2a-booking' ),
				array( 'status' => 403 )
			);
		}

		$gateway_id = $meta['gateway'] ?? '';
		if ( $gateway_id && class_exists( 'G2AB_Gateway_Manager' ) ) {
			$gw = G2AB_Gateway_Manager::instance()->get( $gateway_id );
			if ( $gw && method_exists( $gw, 'confirm_payment' ) && $session_id ) {
				$result = $gw->confirm_payment( $session_id, array( 'booking_id' => (int) $booking->id, 'uuid' => (string) $booking->uuid, 'confirm_token_valid' => $token_matches ) );
				if ( is_array( $result ) && ! empty( $result['handled'] ) ) {
					return rest_ensure_response( array( 'success' => true, 'data' => array( 'status' => 'paid', 'verified' => true ) ) );
				}
			}
		}
		return rest_ensure_response( array( 'success' => true, 'data' => array( 'status' => $booking->status, 'verified' => false ) ) );
	}

	public function resume_payment( WP_REST_Request $request ) {
		global $wpdb;
		nocache_headers();
		$uuid          = sanitize_text_field( $request['uuid'] ?? '' );
		$confirm_token = sanitize_text_field( (string) $request->get_param( 'confirm_token' ) );
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $uuid ) ) {
			return new WP_Error( 'g2ab_bad_uuid', 'Bad UUID.', array( 'status' => 400 ) );
		}
		$limit = function_exists( 'g2ab_rate_limit_check' )
			? g2ab_rate_limit_check( 'resume_payment', array( $this->rate_limit_identity(), $uuid ), 12, 5 * MINUTE_IN_SECONDS )
			: array( 'limited' => false, 'retry_after' => 300 );
		if ( ! empty( $limit['limited'] ) ) {
			return new WP_Error( 'g2ab_rate_limited', __( 'Too many payment link requests. Please wait and try again.', 'g2a-booking' ), array( 'status' => 429, 'retry_after' => (int) $limit['retry_after'] ) );
		}

		$bt      = $wpdb->prefix . 'g2ab_bookings';
		$pt      = $wpdb->prefix . 'g2ab_payments';
		$booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$bt} WHERE uuid = %s LIMIT 1", $uuid ) );
		if ( ! $booking ) {
			return new WP_Error( 'g2ab_not_found', 'Booking not found.', array( 'status' => 404 ) );
		}

		$meta          = json_decode( (string) $booking->metadata, true );
		$meta          = is_array( $meta ) ? $meta : array();
		$token_matches = function_exists( 'g2ab_verify_public_token' ) && g2ab_verify_public_token( $confirm_token, $meta );
		if ( ! current_user_can( 'manage_g2ab_bookings' ) && ! $token_matches ) {
			return new WP_Error( 'g2ab_invalid_confirm_token', __( 'Invalid confirmation token.', 'g2a-booking' ), array( 'status' => 403 ) );
		}

		if ( in_array( (string) $booking->status, array( 'paid', 'confirmed', 'completed' ), true ) ) {
			return rest_ensure_response( array( 'success' => true, 'data' => array( 'status' => $booking->status, 'payment_required' => false ) ) );
		}

		$payment = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$pt}
			 WHERE booking_id = %d AND gateway = 'stripe' AND status IN ('creating','pending','open','failed')
			 ORDER BY id DESC LIMIT 1",
			(int) $booking->id
		) );
		if ( ! $payment ) {
			return new WP_Error( 'g2ab_no_checkout_attempt', __( 'No checkout attempt is available for this booking.', 'g2a-booking' ), array( 'status' => 404 ) );
		}
		$response = json_decode( (string) $payment->gateway_response, true );
		$url      = is_array( $response ) ? esc_url_raw( (string) ( $response['url'] ?? '' ) ) : '';
		$expires  = ! empty( $payment->checkout_expires_at ) ? strtotime( (string) $payment->checkout_expires_at ) : 0;
		if ( ! $url || ( $expires && $expires <= time() + 60 ) ) {
			return new WP_Error( 'g2ab_checkout_expired', __( 'The payment link has expired. Please contact staff for a fresh checkout link.', 'g2a-booking' ), array( 'status' => 410 ) );
		}

		return rest_ensure_response( array(
			'success' => true,
			'data'    => array(
				'status'              => (string) $booking->status,
				'payment_required'    => true,
				'redirect_url'        => $url,
				'checkout_session_id' => ! empty( $payment->checkout_session_id ) ? (string) $payment->checkout_session_id : (string) $payment->transaction_id,
				'checkout_expires_at' => ! empty( $payment->checkout_expires_at ) ? (string) $payment->checkout_expires_at : '',
			),
		) );
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

	/* ────────────────────────────────────────────────────────────────
	 * Events — public listing + seat booking. Mirrors create_booking()
	 * for customer/payment/email handling, but the unit of inventory is
	 * an event *occurrence* with a fixed seat count rather than a lane.
	 * ──────────────────────────────────────────────────────────────── */

	public function list_events( WP_REST_Request $request ) {
		$this->send_no_store_headers();
		if ( ! class_exists( 'G2AB_Events' ) ) {
			return rest_ensure_response( array( 'success' => true, 'data' => array() ) );
		}
		$category = sanitize_key( (string) $request->get_param( 'category' ) );
		$events   = G2AB_Events::get_events( array(
			'status'   => 'publish',
			'category' => $category,
			'limit'    => 100,
		) );
		$out = array();
		foreach ( $events as $ev ) {
			$next = G2AB_Events::next_occurrence( $ev->id );
			if ( ! $next ) {
				continue; // hide events with no upcoming dates from the picker.
			}
			$out[] = array(
				'id'           => (int) $ev->id,
				'title'        => $ev->title,
				'slug'         => $ev->slug,
				'category'     => $ev->category,
				'summary'      => $ev->summary,
				'is_free'      => (int) $ev->is_free === 1,
				'price'        => (float) $ev->price,
				'members_only' => (int) $ev->members_only === 1,
				'requires_waiver' => (int) $ev->requires_waiver === 1,
				'next_start'   => $next->start_at,
				'next_seats_left' => (int) $next->seats_left,
			);
		}
		return rest_ensure_response( array( 'success' => true, 'data' => $out ) );
	}

	public function list_event_occurrences( WP_REST_Request $request ) {
		$this->send_no_store_headers();
		if ( ! class_exists( 'G2AB_Events' ) ) {
			return new WP_Error( 'g2ab_no_events', __( 'Events are not available.', 'g2a-booking' ), array( 'status' => 400 ) );
		}
		$event_id = (int) $request->get_param( 'id' );
		$event    = G2AB_Events::get_event( $event_id );
		if ( ! $event || 'publish' !== $event->status ) {
			return new WP_Error( 'g2ab_bad_event', __( 'Event not found.', 'g2a-booking' ), array( 'status' => 404 ) );
		}
		$is_member = (bool) apply_filters( 'g2ab_user_is_member', $this->is_active_member( get_current_user_id() ), get_current_user_id(), $event, '' );
		$occs      = G2AB_Events::get_occurrences( $event_id, array( 'upcoming_only' => true, 'limit' => 60 ) );
		$out       = array();
		foreach ( $occs as $o ) {
			$price = G2AB_Events::price_for( $event, $o, $is_member );
			$diag  = class_exists( 'G2AB_Event_Capacity_Service' ) ? G2AB_Event_Capacity_Service::diagnostics( (int) $o->id ) : null;
			$reserved = is_array( $diag ) ? (int) $diag['reserved'] : (int) $o->seats_booked;
			$capacity = is_array( $diag ) ? (int) $diag['capacity'] : (int) $o->seats_total;
			$left     = is_array( $diag ) ? (int) $diag['seats_left'] : (int) $o->seats_left;
			$out[] = array(
				'id'         => (int) $o->id,
				'start_at'   => $o->start_at,
				'end_at'     => $o->end_at,
				'seats_left' => $left,
				'seats_total'=> $capacity,
				'seats_reserved' => $reserved,
				'sold_out'   => $left <= 0,
				'price'      => (float) $price,
				'is_free'    => (int) $event->is_free === 1 || (float) $price <= 0,
				'price_label'=> ( (int) $event->is_free === 1 || (float) $price <= 0 ) ? __( 'Free', 'g2a-booking' ) : '$' . number_format( (float) $price, 2 ),
				'calculated_at' => is_array( $diag ) ? $diag['calculated_at'] : current_time( 'mysql' ),
			);
		}
		return rest_ensure_response( array(
			'success' => true,
			'data'    => array(
				'event' => array(
					'id'              => (int) $event->id,
					'title'           => $event->title,
					'requires_waiver' => (int) $event->requires_waiver === 1,
					'members_only'    => (int) $event->members_only === 1,
				),
				'occurrences' => $out,
			),
		) );
	}

	/**
	 * Find a usable ONLINE gateway for an event seat (one that can take payment
	 * now via create_intent). Prefers an explicit choice, then Stripe, then
	 * PayPal, then any available non-store gateway. Returns null when only
	 * pay-in-store is configured.
	 */
	private function pick_online_gateway_for_event( $booking_type, $gateway_id = '' ) {
		if ( ! class_exists( 'G2AB_Gateway_Manager' ) ) {
			return null;
		}
		$mgr = G2AB_Gateway_Manager::instance();
		$ok  = static function ( $g ) {
			return $g
				&& method_exists( $g, 'create_intent' )
				&& ( ! method_exists( $g, 'is_available' ) || $g->is_available() )
				&& ( ! method_exists( $g, 'id' ) || 'pay_in_store' !== $g->id() );
		};
		if ( $gateway_id ) {
			$g = $mgr->get( $gateway_id );
			if ( $ok( $g ) ) {
				return $g;
			}
			return new WP_Error( 'g2ab_gateway_unavailable', __( 'Selected payment gateway is not available for online event payment.', 'g2a-booking' ), array( 'status' => 503 ) );
		}
		foreach ( array( 'stripe', 'paypal' ) as $pref ) {
			$g = $mgr->get( $pref );
			if ( $ok( $g ) ) {
				return $g;
			}
		}
		foreach ( $mgr->available() as $g ) {
			if ( $ok( $g ) ) {
				return $g;
			}
		}
		return null;
	}

	public function create_event_booking( WP_REST_Request $request ) {
		global $wpdb;
		$this->send_no_store_headers();
		if ( ! class_exists( 'G2AB_Events' ) ) {
			return new WP_Error( 'g2ab_no_events', __( 'Events are not available.', 'g2a-booking' ), array( 'status' => 400 ) );
		}

		$occurrence_id = (int) $request->get_param( 'occurrence_id' );
		$seats         = max( 1, (int) ( $request->get_param( 'seats' ) ?: 1 ) );
		$gateway_id    = sanitize_key( (string) ( $request->get_param( 'gateway' ) ?: '' ) );
		$fields        = $request->get_param( 'fields' );
		$fields        = is_array( $fields ) ? $fields : array();

		$occ = G2AB_Events::get_occurrence( $occurrence_id );
		if ( ! $occ || 'cancelled' === $occ->status ) {
			return new WP_Error( 'g2ab_bad_occurrence', __( 'That date is no longer available.', 'g2a-booking' ), array( 'status' => 400 ) );
		}
		$event = G2AB_Events::get_event( $occ->event_id );
		if ( ! $event || 'publish' !== $event->status ) {
			return new WP_Error( 'g2ab_bad_event', __( 'This event is not available.', 'g2a-booking' ), array( 'status' => 400 ) );
		}
		if ( G2AB_Events::timestamp( $occ->start_at ) < ( time() - 60 ) ) {
			return new WP_Error( 'g2ab_event_past', __( 'That date has already started.', 'g2a-booking' ), array( 'status' => 400 ) );
		}

		// ─── Customer fields ────────────────────────────────────────────────
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

		// Waiver.
		$waiver_ok = ! empty( $fields['waiver_acceptance'] );
		$waiver_ok = (bool) apply_filters( 'g2ab_waiver_satisfied', $waiver_ok, $fields, $event );
		if ( (int) $event->requires_waiver === 1 && ! $waiver_ok ) {
			return new WP_Error( 'g2ab_waiver_required', __( 'Waiver acceptance is required.', 'g2a-booking' ), array( 'status' => 400 ) );
		}

		// Members-only.
		$user_id_now = get_current_user_id();
		$is_member   = (bool) apply_filters( 'g2ab_user_is_member', $this->is_active_member( $user_id_now ), $user_id_now, $event, $customer_email );
		if ( (int) $event->members_only === 1 && ! $is_member ) {
			return new WP_Error( 'g2ab_members_only', __( 'This event is available to active members only.', 'g2a-booking' ), array( 'status' => 403 ) );
		}

		$unit_price = G2AB_Events::price_for( $event, $occ, $is_member );
		$total      = round( $unit_price * $seats, 2 );

		// ─── Customer record ────────────────────────────────────────────────
		$user_id = $this->ensure_customer_user( $customer_name, $customer_email, $customer_phone );
		if ( is_wp_error( $user_id ) ) {
			return new WP_Error( 'g2ab_customer_account_failed', $user_id->get_error_message(), array( 'status' => 500 ) );
		}
		$user_id = (int) $user_id;

		$bt             = G2AB_Events::get_or_create_event_booking_type();
		$bookings_table = $wpdb->prefix . 'g2ab_bookings';
		$start_sql      = $occ->start_at;
		$end_sql        = $occ->end_at;
		$duration_min   = max( 15, (int) round( ( strtotime( $end_sql ) - strtotime( $start_sql ) ) / 60 ) );
		$capacity_total = (int) $occ->seats_total;

		// ─── Gateway selection — paid events take payment NOW ───────────────
		// Pick a real online gateway (prefers Stripe, then PayPal, then any
		// available card gateway). Only when none is configured do we fall back
		// to reserve-and-pay-at-the-desk.
		$gateway         = ( $total > 0 ) ? $this->pick_online_gateway_for_event( $bt, $gateway_id ) : null;
		if ( is_wp_error( $gateway ) ) {
			return $gateway;
		}
		if ( $total > 0 && ! $gateway && 1 !== (int) get_option( 'g2ab_allow_event_pay_in_store_fallback', 0 ) ) {
			return new WP_Error( 'g2ab_no_online_gateway', __( 'Online payment is currently unavailable for this event.', 'g2a-booking' ), array( 'status' => 503 ) );
		}
		$gateway_used_id = ( $gateway && method_exists( $gateway, 'id' ) ) ? $gateway->id() : 'pay_in_store';

		if ( $total <= 0 ) {
			$payment_mode    = 'free';
			$due_now         = 0.0;
			$gateway_used_id = 'free';
			$initial_status  = 'confirmed';
		} elseif ( $gateway && method_exists( $gateway, 'create_intent' ) ) {
			$payment_mode   = 'full';
			$due_now        = $total;
			$initial_status = 'pending';
		} else {
			$payment_mode    = 'in_store';
			$gateway_used_id = 'pay_in_store';
			$due_now         = 0.0;
			$initial_status  = 'reserved';
		}

		$clean_fields = array();
		foreach ( $fields as $k => $v ) {
			$clean_fields[ sanitize_key( $k ) ] = is_scalar( $v ) ? sanitize_text_field( wp_unslash( (string) $v ) ) : '';
		}

		$now_mysql     = current_time( 'mysql' );
		$uuid          = wp_generate_uuid4();
		$confirm_token = wp_generate_password( 32, false );
		$hold_minutes  = class_exists( 'G2AB_Event_Capacity_Service' ) ? G2AB_Event_Capacity_Service::hold_minutes() : (int) apply_filters( 'g2ab_event_hold_minutes', get_option( 'g2ab_reservation_hold_minutes', 15 ) );
		$hold_expires_at = '';
		if ( in_array( $initial_status, array( 'pending', 'reserved' ), true ) && in_array( $payment_mode, array( 'full', 'deposit' ), true ) ) {
			$hold_expires_at = wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + ( max( 1, $hold_minutes ) * MINUTE_IN_SECONDS ), wp_timezone() );
		}

		// See create_booking() for why this exists: a retry/double-submit of
		// the same seat request must not create a second reservation (and, for
		// a paid event, a second payment-gateway intent).
		$idempotency_key = hash( 'sha256', implode( '|', array(
			'event',
			$occurrence_id,
			strtolower( $customer_email ),
			(int) floor( time() / 600 ),
		) ) );

		// ─── Race-safe seat reservation ─────────────────────────────────────
		$booking_data = array(
			'uuid'                => $uuid,
			'booking_type_id'     => (int) $bt->id,
			'resource_id'         => null,
			'form_id'             => null,
			'user_id'             => $user_id ?: null,
			'customer_name'       => $customer_name,
			'customer_email'      => $customer_email,
			'customer_phone'      => $customer_phone,
			'start_at'            => $start_sql,
			'end_at'              => $end_sql,
			'duration_min'        => $duration_min,
			'party_size'          => $seats,
			'status'              => $initial_status,
			'payment_mode'        => $payment_mode,
			'total_amount'        => $total,
			'paid_amount'         => 0,
			'currency'            => get_option( 'g2ab_currency', 'USD' ),
			'form_data'           => wp_json_encode( $clean_fields ),
			'metadata'            => wp_json_encode( array(
				'gateway'       => $gateway_used_id,
				'due_now'       => $due_now,
				'confirm_token_hash' => function_exists( 'g2ab_hash_public_token' ) ? g2ab_hash_public_token( $confirm_token ) : hash( 'sha256', $confirm_token ),
				'event_title'   => $event->title,
				'seats'         => $seats,
				'unit_price'    => $unit_price,
				'hold_expires_at' => $hold_expires_at,
			) ),
			'waiver_signed'       => $waiver_ok ? 1 : 0,
			'source'              => 'web',
			'event_id'            => (int) $event->id,
			'event_occurrence_id' => (int) $occ->id,
			'created_by'          => $user_id ?: null,
			'created_at'          => $now_mysql,
			'updated_at'          => $now_mysql,
			'idempotency_key'     => $idempotency_key,
		);
		$booking_formats = array( '%s','%d','%s','%s','%d','%s','%s','%s','%s','%s','%d','%d','%s','%s','%f','%f','%s','%s','%s','%d','%s','%d','%d','%d','%s','%s','%s' );

		if ( ! class_exists( 'G2AB_Event_Capacity_Service' ) ) {
			return new WP_Error( 'g2ab_capacity_service_missing', __( 'Event availability service is unavailable.', 'g2a-booking' ), array( 'status' => 500 ) );
		}

		$reservation = G2AB_Event_Capacity_Service::reserve_seats_atomically( $occurrence_id, $seats, $booking_data, $booking_formats );
		if ( is_wp_error( $reservation ) ) {
			return $reservation;
		}
		if ( ! empty( $reservation['idempotent_replay_id'] ) ) {
			return $this->idempotent_replay_response( (int) $reservation['idempotent_replay_id'] );
		}
		$booking_id = (int) $reservation['booking_id'];

		if ( ! is_user_logged_in() ) {
			if ( function_exists( 'g2ab_rate_limit_clear' ) ) {
				g2ab_rate_limit_clear( 'booking_attempt', array( $this->rate_limit_identity(), 'event-' . $occurrence_id ) );
			}
			$this->bump_success_rate_limit();
		}

		$wpdb->insert( $wpdb->prefix . 'g2ab_logs', array(
			'booking_id' => $booking_id,
			'user_id'    => $user_id ?: null,
			'event_type' => 'created',
			'severity'   => 'info',
			'message'    => sprintf( 'Event seat booked: %s x%d (gateway=%s) for %s', $event->title, $seats, $gateway_used_id, $customer_email ),
			'context'    => wp_json_encode( array( 'event_id' => (int) $event->id, 'occurrence_id' => (int) $occ->id, 'gateway' => $gateway_used_id ) ),
			'created_at' => $now_mysql,
		) );

		try {
			do_action( 'g2ab_booking_created', $booking_id, array(
				'uuid' => $uuid, 'event_id' => (int) $event->id, 'event_occurrence_id' => (int) $occ->id,
				'start_at' => $start_sql, 'gateway' => $gateway_used_id,
			) );
			if ( in_array( $initial_status, array( 'confirmed', 'paid' ), true ) ) {
				do_action( 'g2ab_booking_status_changed', $booking_id, $initial_status, 'created' );
			}
		} catch ( \Throwable $e ) {
			error_log( '[G2AB] post-commit event hook failed for #' . $booking_id . ': ' . $e->getMessage() );
		}

		$response = array(
			'id'               => $booking_id,
			'uuid'             => $uuid,
			'status'           => $initial_status,
			'start_at'         => $start_sql,
			'end_at'           => $end_sql,
			'event'            => $event->title,
			'seats'            => $seats,
			'total'            => number_format( $total, 2 ),
			'due_now'          => number_format( $due_now, 2 ),
			'gateway'          => $gateway_used_id,
			'payment_required' => $due_now > 0,
			'redirect_url'     => '',
			'message'          => '',
			'confirm_token'    => $confirm_token,
		);

		if ( $due_now > 0 && $gateway && method_exists( $gateway, 'create_intent' ) ) {
			$booking_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$bookings_table} WHERE id = %d", $booking_id ) );
			$intent      = ( 'stripe' === $gateway_used_id )
				? $gateway->create_intent( $booking_row, $due_now, array( 'confirm_token' => $confirm_token ) )
				: $gateway->create_intent( $booking_row, $due_now );
			if ( is_wp_error( $intent ) ) {
				$response['message'] = sprintf( __( 'Reservation saved, but payment setup failed: %s. Staff will contact you.', 'g2a-booking' ), $intent->get_error_message() );
			} elseif ( is_array( $intent ) ) {
				$response['redirect_url'] = $intent['redirect_url'] ?? '';
				$response['message']      = $intent['message'] ?? '';
			}
		} elseif ( $total <= 0 ) {
			$response['message'] = __( 'Seat reserved — no payment required. See you there!', 'g2a-booking' );
			if ( function_exists( 'g2ab_provision_booking_customer_account' ) ) {
				g2ab_provision_booking_customer_account( $booking_id );
			}
		} else {
			$response['message'] = __( 'Seat reserved. Pay at the front desk on arrival.', 'g2a-booking' );
			if ( function_exists( 'g2ab_provision_booking_customer_account' ) ) {
				g2ab_provision_booking_customer_account( $booking_id );
			}
		}

		$email_automation_active = class_exists( 'G2AB_Addon_Manager' )
			&& G2AB_Addon_Manager::instance()->is_active( 'email_automation' );
		if ( ! $email_automation_active && 1 === (int) get_option( 'g2ab_send_confirmation_email', 1 ) ) {
			$this->send_confirmation_email( $customer_email, $customer_name, $event->title, $start_sql, $uuid );
		}

		return rest_ensure_response( array( 'success' => true, 'data' => $response ) );
	}
}
