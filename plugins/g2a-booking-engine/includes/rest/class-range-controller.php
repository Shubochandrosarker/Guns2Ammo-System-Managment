<?php
/**
 * REST: Range Status console + QR self check-in.
 *
 * Endpoints
 *   GET  /range/status        Admin — live lane map, KPIs, reservations, payment feed.
 *   POST /range/walkin        Admin — book + check in a walk-in on an open lane.
 *   POST /range/lookup        Public — find a customer's booking for self check-in.
 *   POST /range/self-checkin  Public — customer checks themselves in (validates waiver).
 *
 * Lane status is derived purely from today's bookings + check-ins:
 *   IN USE   — a checked-in booking is active on the lane right now.
 *   RESERVED — a booking holds the lane right now but hasn't checked in.
 *   OPEN     — nothing on the lane right now (a later booking may exist).
 *
 * @package G2AB
 * @since   1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_REST_Range_Controller {

	const ACTIVE = array( 'pending', 'reserved', 'confirmed', 'paid', 'completed' );

	public function register_routes() {
		register_rest_route( G2AB_REST_NAMESPACE, '/range/status', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'status' ),
			'permission_callback' => array( $this, 'perm_staff' ),
		) );
		register_rest_route( G2AB_REST_NAMESPACE, '/range/walkin', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'walkin' ),
			'permission_callback' => array( $this, 'perm_staff' ),
		) );
		register_rest_route( G2AB_REST_NAMESPACE, '/range/lookup', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'lookup' ),
			'permission_callback' => array( $this, 'perm_public' ),
		) );
		register_rest_route( G2AB_REST_NAMESPACE, '/range/self-checkin', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'self_checkin' ),
			'permission_callback' => array( $this, 'perm_public' ),
		) );
	}

	public function perm_staff( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'g2ab_invalid_nonce', __( 'Invalid or missing nonce.', 'g2a-booking' ), array( 'status' => 403 ) );
		}
		if ( ! current_user_can( 'manage_g2ab_bookings' ) ) {
			return new WP_Error( 'g2ab_forbidden', __( 'Staff only.', 'g2a-booking' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public function perm_public( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'g2ab_invalid_nonce', __( 'Please refresh the page and try again.', 'g2a-booking' ), array( 'status' => 403 ) );
		}
		// Per-IP throttle — /range/lookup and /range/self-checkin are public and
		// match bookings by code or name+phone, so cap attempts to slow enumeration.
		$ip   = function_exists( 'g2ab_get_client_ip' ) ? g2ab_get_client_ip() : (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		$key  = 'g2ab_rl_range_' . md5( $ip );
		$hits = (int) get_transient( $key );
		if ( $hits >= 10 ) {
			return new WP_Error( 'g2ab_rate_limited', __( 'Too many attempts. Please wait a few minutes or see the front desk.', 'g2a-booking' ), array( 'status' => 429 ) );
		}
		set_transient( $key, $hits + 1, 10 * MINUTE_IN_SECONDS );
		return true;
	}

	/* ────────────────────────────── helpers ────────────────────────────── */

	private function now() {
		return current_time( 'mysql' );
	}

	private function bookings_table() {
		global $wpdb;
		return $wpdb->prefix . 'g2ab_bookings';
	}

	/** A booking is "checked in" when bookings.checked_in_at is set. */
	private function active_status_sql() {
		return "'" . implode( "','", array_map( 'esc_sql', self::ACTIVE ) ) . "'";
	}

	/* ────────────────────────────── /range/status ────────────────────────────── */

	public function status( WP_REST_Request $request ) {
		global $wpdb;
		$b   = $this->bookings_table();
		$rt  = $wpdb->prefix . 'g2ab_resources';
		$ci  = $wpdb->prefix . 'g2ab_checkins';
		$now = $this->now();
		$today = current_time( 'Y-m-d' );
		$in    = $this->active_status_sql();

		$lanes = $wpdb->get_results( "SELECT id, name, slug, capacity FROM {$rt} WHERE type = 'lane' AND is_active = 1 ORDER BY sort_order ASC, name ASC" );

		// All of today's active lane bookings (for current + next computation).
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT bk.id, bk.resource_id, bk.customer_name, bk.start_at, bk.end_at, bk.party_size,
			        bk.status, bk.checked_in_at, bk.total_amount, bk.paid_amount,
			        c.waiver_verified
			   FROM {$b} bk
			   LEFT JOIN {$ci} c ON c.booking_id = bk.id
			  WHERE bk.resource_id IS NOT NULL
			    AND bk.status IN ({$in})
			    AND DATE(bk.start_at) = %s
			  ORDER BY bk.start_at ASC",
			$today
		) );

		$by_lane = array();
		foreach ( $rows as $r ) {
			$by_lane[ (int) $r->resource_id ][] = $r;
		}

		$lane_out   = array();
		$in_use     = 0;
		$reserved_n = 0;
		foreach ( $lanes as $lane ) {
			$list    = $by_lane[ (int) $lane->id ] ?? array();
			$current = null;
			$next    = null;
			foreach ( $list as $r ) {
				if ( $r->start_at <= $now && $r->end_at > $now ) {
					$current = $r;
				} elseif ( $r->start_at > $now && ( ! $next || $r->start_at < $next->start_at ) ) {
					$next = $r;
				}
			}
			$state = 'open';
			$occ   = null;
			if ( $current ) {
				$state = $current->checked_in_at ? 'in_use' : 'reserved';
				$occ   = $current;
			}
			if ( 'in_use' === $state ) {
				$in_use++;
			} elseif ( 'reserved' === $state ) {
				$reserved_n++;
			}
			$lane_out[] = array(
				'id'         => (int) $lane->id,
				'name'       => $lane->name,
				'state'      => $state,
				'occupant'   => $occ ? $occ->customer_name : '',
				'booking_id' => $occ ? (int) $occ->id : 0,
				'party'      => $occ ? (int) $occ->party_size : 0,
				'ends'       => $occ ? G2AB_Events::format_local( $occ->end_at, 'g:i A' ) : '',
				'waiver_ok'  => $occ ? ( (int) $occ->waiver_verified === 1 ) : false,
				'next_name'  => $next ? $next->customer_name : '',
				'next_at'    => $next ? G2AB_Events::format_local( $next->start_at, 'g:i A' ) : '',
			);
		}

		// Reservations still to arrive today (not checked in), lane + event.
		$reservations = $wpdb->get_results( $wpdb->prepare(
			"SELECT bk.id, bk.customer_name, bk.customer_phone, bk.start_at, bk.party_size, bk.status,
			        bk.event_id, bk.checked_in_at, r.name AS lane_name, e.title AS event_title
			   FROM {$b} bk
			   LEFT JOIN {$rt} r ON r.id = bk.resource_id
			   LEFT JOIN {$wpdb->prefix}g2ab_events e ON e.id = bk.event_id
			  WHERE bk.status IN ({$in})
			    AND DATE(bk.start_at) = %s
			    AND bk.checked_in_at IS NULL
			  ORDER BY bk.start_at ASC
			  LIMIT 40",
			$today
		) );
		$res_out = array();
		foreach ( $reservations as $r ) {
			$res_out[] = array(
				'id'    => (int) $r->id,
				'name'  => $r->customer_name ?: __( 'Guest', 'g2a-booking' ),
				'phone' => $r->customer_phone,
				'time'  => G2AB_Events::format_local( $r->start_at, 'g:i A' ),
				'where' => $r->event_title ? $r->event_title : ( $r->lane_name ?: '' ),
				'party' => (int) $r->party_size,
			);
		}

		// Live payment feed — most recent payments.
		$pay_table = $wpdb->prefix . 'g2ab_payments';
		$feed      = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.amount, p.payment_method, p.gateway, p.status, p.created_at, bk.customer_name
			   FROM {$pay_table} p
			   LEFT JOIN {$b} bk ON bk.id = p.booking_id
			  WHERE p.created_at >= %s
			  ORDER BY p.created_at DESC
			  LIMIT 12",
			$today . ' 00:00:00'
		) );
		$feed_out = array();
		foreach ( $feed as $f ) {
			$feed_out[] = array(
				'name'   => $f->customer_name ?: __( 'Customer', 'g2a-booking' ),
				'amount' => number_format( (float) $f->amount, 2 ),
				'method' => $f->payment_method ?: $f->gateway,
				'status' => $f->status,
				'time'   => G2AB_Events::format_local( $f->created_at, 'g:i A' ),
			);
		}

		// KPIs.
		$revenue = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(paid_amount),0) FROM {$b} WHERE DATE(start_at) = %s AND status IN ({$in})",
			$today
		) );
		$checked_in = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$b} WHERE DATE(start_at) = %s AND checked_in_at IS NOT NULL AND status IN ({$in})",
			$today
		) );

		return rest_ensure_response( array(
			'success' => true,
			'data'    => array(
				'updated'   => G2AB_Events::format_local( $now, 'g:i:s A' ),
				'kpis'      => array(
					'in_use'     => $in_use,
					'open'       => max( 0, count( $lanes ) - $in_use - $reserved_n ),
					'checked_in' => $checked_in,
					'revenue'    => number_format( $revenue, 2 ),
				),
				'lanes'        => $lane_out,
				'reservations' => $res_out,
				'feed'         => $feed_out,
			),
		) );
	}

	/* ────────────────────────────── /range/walkin ────────────────────────────── */

	public function walkin( WP_REST_Request $request ) {
		global $wpdb;
		$lane_id  = (int) $request->get_param( 'resource_id' );
		$name     = sanitize_text_field( (string) $request->get_param( 'customer_name' ) );
		$phone    = sanitize_text_field( (string) $request->get_param( 'customer_phone' ) );
		$party    = max( 1, (int) ( $request->get_param( 'party_size' ) ?: 1 ) );
		$minutes  = (int) ( $request->get_param( 'duration' ) ?: 0 );
		$waiver   = (bool) $request->get_param( 'waiver_ok' );

		if ( '' === $name ) {
			return new WP_Error( 'g2ab_missing_name', __( 'Customer name is required.', 'g2a-booking' ), array( 'status' => 400 ) );
		}
		$rt   = $wpdb->prefix . 'g2ab_resources';
		$lane = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$rt} WHERE id = %d AND type = 'lane' AND is_active = 1", $lane_id ) );
		if ( ! $lane ) {
			return new WP_Error( 'g2ab_bad_lane', __( 'That lane is not available.', 'g2a-booking' ), array( 'status' => 400 ) );
		}

		// Default lane booking type for duration/price.
		$bt_table = $wpdb->prefix . 'g2ab_booking_types';
		$bt = $wpdb->get_row( "SELECT * FROM {$bt_table} WHERE category = 'lane' AND is_active = 1 ORDER BY id ASC LIMIT 1" );
		if ( ! $bt ) {
			$bt = $wpdb->get_row( "SELECT * FROM {$bt_table} WHERE is_active = 1 ORDER BY id ASC LIMIT 1" );
		}
		if ( ! $bt ) {
			return new WP_Error( 'g2ab_no_type', __( 'No lane booking type is configured.', 'g2a-booking' ), array( 'status' => 400 ) );
		}
		$duration = $minutes > 0 ? $minutes : max( 15, (int) $bt->duration_min );

		$tz       = function_exists( 'wp_timezone' ) ? wp_timezone() : null;
		$start_dt = $tz ? new DateTimeImmutable( 'now', $tz ) : new DateTimeImmutable( 'now' );
		$start_sql = $start_dt->format( 'Y-m-d H:i:s' );
		$end_sql   = $start_dt->modify( '+' . $duration . ' minutes' )->format( 'Y-m-d H:i:s' );

		// Guard: lane must be free right now.
		$in   = $this->active_status_sql();
		$busy = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM " . $this->bookings_table() . "
			  WHERE resource_id = %d AND status IN ({$in}) AND start_at <= %s AND end_at > %s",
			$lane_id, $start_sql, $start_sql
		) );
		if ( $busy > 0 ) {
			return new WP_Error( 'g2ab_lane_busy', __( 'That lane is already occupied right now.', 'g2a-booking' ), array( 'status' => 409 ) );
		}

		$now_mysql = current_time( 'mysql' );
		$uuid      = wp_generate_uuid4();
		$total     = (float) $bt->base_price * $party;
		$ok = $wpdb->insert( $this->bookings_table(), array(
			'uuid'            => $uuid,
			'booking_type_id' => (int) $bt->id,
			'resource_id'     => $lane_id,
			'customer_name'   => $name,
			'customer_phone'  => $phone,
			'start_at'        => $start_sql,
			'end_at'          => $end_sql,
			'duration_min'    => $duration,
			'party_size'      => $party,
			'status'          => 'confirmed',
			'payment_mode'    => 'in_store',
			'total_amount'    => $total,
			'paid_amount'     => 0,
			'currency'        => get_option( 'g2ab_currency', 'USD' ),
			'form_data'       => wp_json_encode( array() ),
			'metadata'        => wp_json_encode( array( 'walk_in' => true ) ),
			'waiver_signed'   => $waiver ? 1 : 0,
			'source'          => 'walk_in',
			'created_by'      => get_current_user_id(),
			'created_at'      => $now_mysql,
			'updated_at'      => $now_mysql,
		) );
		if ( false === $ok ) {
			return new WP_Error( 'g2ab_insert_failed', __( 'Could not create the walk-in.', 'g2a-booking' ), array( 'status' => 500 ) );
		}
		$booking_id = (int) $wpdb->insert_id;

		// Check them in immediately.
		if ( class_exists( 'G2AB_Checkin_Service' ) ) {
			G2AB_Checkin_Service::check_in( $booking_id, array( 'waiver_verified' => $waiver ? 1 : 0, 'note' => 'Walk-in via Range Status' ), get_current_user_id() );
		}

		do_action( 'g2ab_booking_created', $booking_id, array( 'uuid' => $uuid, 'resource_id' => $lane_id, 'start_at' => $start_sql, 'source' => 'walk_in' ) );

		return rest_ensure_response( array( 'success' => true, 'data' => array(
			'id'    => $booking_id,
			'uuid'  => $uuid,
			'lane'  => $lane->name,
			'ends'  => G2AB_Events::format_local( $end_sql, 'g:i A' ),
		) ) );
	}

	/* ────────────────────────── self check-in (public) ────────────────────────── */

	public function lookup( WP_REST_Request $request ) {
		$matches = $this->find_today_bookings( $request );
		if ( is_wp_error( $matches ) ) {
			return $matches;
		}
		return rest_ensure_response( array( 'success' => true, 'data' => array( 'bookings' => $matches ) ) );
	}

	public function self_checkin( WP_REST_Request $request ) {
		global $wpdb;
		$booking_id = (int) $request->get_param( 'booking_id' );
		$matches    = $this->find_today_bookings( $request );
		if ( is_wp_error( $matches ) ) {
			return $matches;
		}
		$allowed = wp_list_pluck( $matches, 'id' );
		if ( ! $booking_id || ! in_array( $booking_id, array_map( 'intval', $allowed ), true ) ) {
			return new WP_Error( 'g2ab_not_found', __( 'We could not match that booking. Please see the front desk.', 'g2a-booking' ), array( 'status' => 404 ) );
		}

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . $this->bookings_table() . " WHERE id = %d", $booking_id ) );
		if ( ! $row ) {
			return new WP_Error( 'g2ab_not_found', __( 'Booking not found.', 'g2a-booking' ), array( 'status' => 404 ) );
		}

		// Waiver validation — accept the on-file flag or an integration's
		// answer. Pass the booking's contact fields so integrations (e.g.
		// Memberistic's waiver-on-file bridge, which matches by email) can
		// actually look the customer up; an empty array made the filter a
		// no-op and sent on-file customers to the front desk.
		$waiver_ok = ( (int) $row->waiver_signed === 1 );
		$waiver_ok = (bool) apply_filters(
			'g2ab_waiver_satisfied',
			$waiver_ok,
			array(
				'customer_email' => (string) $row->customer_email,
				'customer_name'  => (string) $row->customer_name,
			),
			$row
		);

		if ( class_exists( 'G2AB_Checkin_Service' ) ) {
			$res = G2AB_Checkin_Service::check_in( $booking_id, array(
				'waiver_verified' => $waiver_ok ? 1 : 0,
				'note'            => 'Self check-in (QR)',
			), 0 );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}

		// Feed the kiosk/waiver-completion signal to any listening automation
		// (e.g. Messageistic's SMS bridge) — this endpoint IS the actual
		// self-serve QR kiosk, unlike the general check-in service which also
		// covers staff-initiated check-ins.
		$name_parts = preg_split( '/\s+/', trim( (string) $row->customer_name ) );
		$contact    = array(
			'first_name' => $name_parts[0] ?? '',
			'last_name'  => count( $name_parts ) > 1 ? implode( ' ', array_slice( $name_parts, 1 ) ) : '',
			'email'      => (string) $row->customer_email,
			'phone'      => (string) $row->customer_phone,
		);
		do_action( 'g2a_kiosk_checkin_completed', $contact );
		if ( ! $waiver_ok ) {
			do_action( 'g2a_waiver_incomplete', array_merge( $contact, array(
				'waiver_link' => class_exists( '\WordPressistic\Memberistic\Waivers\Waiver_Public' )
					? \WordPressistic\Memberistic\Waivers\Waiver_Public::guest_url()
					: add_query_arg( 'memberistic_waiver', 'guest', home_url( '/' ) ),
			) ) );
		}

		return rest_ensure_response( array( 'success' => true, 'data' => array(
			'checked_in' => true,
			'waiver_ok'  => $waiver_ok,
			'name'       => $row->customer_name,
			'time'       => G2AB_Events::format_local( $row->start_at, 'g:i A' ),
			'message'    => $waiver_ok
				? __( 'You are checked in. Head to the range — have fun and shoot safe!', 'g2a-booking' )
				: __( 'You are checked in, but we still need your signed waiver. Please see the front desk before shooting.', 'g2a-booking' ),
		) ) );
	}

	/**
	 * Match today's active bookings by confirmation code OR name + phone.
	 */
	private function find_today_bookings( WP_REST_Request $request ) {
		global $wpdb;
		$code  = sanitize_text_field( (string) $request->get_param( 'code' ) );
		$name  = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$phone = preg_replace( '/[^0-9]/', '', (string) $request->get_param( 'phone' ) );
		$today = current_time( 'Y-m-d' );
		$in    = $this->active_status_sql();
		$b     = $this->bookings_table();

		if ( '' !== $code ) {
			$code = strtolower( $code );
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, customer_name, start_at, uuid FROM {$b}
				  WHERE LOWER(uuid) LIKE %s AND DATE(start_at) = %s AND status IN ({$in})
				  ORDER BY start_at ASC LIMIT 10",
				$wpdb->esc_like( $code ) . '%', $today
			) );
		} elseif ( '' !== $name && strlen( $phone ) >= 4 ) {
			$digits = substr( $phone, -4 );
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, customer_name, start_at, uuid FROM {$b}
				  WHERE customer_name LIKE %s
				    AND REPLACE(REPLACE(REPLACE(REPLACE(customer_phone,'-',''),' ',''),'(',''),')','') LIKE %s
				    AND DATE(start_at) = %s AND status IN ({$in})
				  ORDER BY start_at ASC LIMIT 10",
				'%' . $wpdb->esc_like( $name ) . '%', '%' . $wpdb->esc_like( $digits ), $today
			) );
		} else {
			return new WP_Error( 'g2ab_need_info', __( 'Enter your confirmation code, or your name and phone.', 'g2a-booking' ), array( 'status' => 400 ) );
		}

		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'id'   => (int) $r->id,
				'name' => $r->customer_name,
				'time' => G2AB_Events::format_local( $r->start_at, 'g:i A' ),
				'code' => strtoupper( substr( (string) $r->uuid, 0, 8 ) ),
			);
		}
		return $out;
	}
}
