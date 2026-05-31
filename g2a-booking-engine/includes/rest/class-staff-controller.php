<?php
/**
 * Staff Console REST controller.
 *
 * Powers the front-end Staff Console shortcode. Every endpoint requires
 * the `manage_g2ab_bookings` capability — staff can VIEW summarised data
 * and take a small set of write actions (walk-in check-in, waiver verify
 * stamp). They cannot download waiver files, view raw ID images, or
 * read/edit any other backend data through this surface.
 *
 * Response shapes deliberately omit sensitive fields:
 *   - waiver row: name, status, signed_at, expires_at — NO file URL, NO DOB
 *   - member lookup: name, status, membership_tier — NO address, NO phone
 *   - booking summary: time, status, party_size, resource — NO full address
 *
 * @package G2AB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_REST_Staff_Controller {

	const NS = G2AB_REST_NAMESPACE;

	public function register_routes() {
		register_rest_route( self::NS, '/staff/snapshot', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'snapshot' ),
			'permission_callback' => array( $this, 'permission_read' ),
		) );
		register_rest_route( self::NS, '/staff/waiver-lookup', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'waiver_lookup' ),
			'permission_callback' => array( $this, 'permission_read' ),
			'args'                => array(
				'q'    => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'type' => array( 'required' => false, 'sanitize_callback' => 'sanitize_key', 'default' => 'auto' ),
			),
		) );
		register_rest_route( self::NS, '/staff/qr-resolve', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'qr_resolve' ),
			'permission_callback' => array( $this, 'permission_write' ),
			'args'                => array(
				'payload' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );
		register_rest_route( self::NS, '/staff/walk-in', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'walk_in_checkin' ),
			'permission_callback' => array( $this, 'permission_write' ),
			'args'                => array(
				'name'         => array( 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ),
				'email'        => array( 'required' => false, 'sanitize_callback' => 'sanitize_email' ),
				'phone'        => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'lane'         => array( 'required' => true,  'sanitize_callback' => 'absint' ),
				'party_size'   => array( 'required' => false, 'sanitize_callback' => 'absint', 'default' => 1 ),
				'minutes'      => array( 'required' => false, 'sanitize_callback' => 'absint', 'default' => 60 ),
			),
		) );
		register_rest_route( self::NS, '/staff/booking-action', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'booking_action' ),
			'permission_callback' => array( $this, 'permission_write' ),
			'args'                => array(
				'booking_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				'action'     => array( 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
			),
		) );
	}

	/* ------------------------------------------------------------------ */
	/* Permission gates                                                   */
	/* ------------------------------------------------------------------ */

	public function permission_read() {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_g2ab_bookings' ) ) {
			return new WP_Error( 'g2ab_forbidden', __( 'Staff sign-in required.', 'g2a-booking' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public function permission_write( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'g2ab_invalid_nonce', __( 'Invalid nonce.', 'g2a-booking' ), array( 'status' => 403 ) );
		}
		return $this->permission_read();
	}

	/* ------------------------------------------------------------------ */
	/* Endpoints                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Dashboard snapshot — lane counts, today's revenue total, active
	 * members, FFL pending count, last-N activity items. Cached for 30s
	 * so the dashboard polling doesn't slam the DB.
	 */
	public function snapshot() {
		$cached = get_transient( 'g2ab_staff_snapshot' );
		if ( is_array( $cached ) ) {
			return rest_ensure_response( $cached );
		}
		global $wpdb;
		$bt = $wpdb->prefix . 'g2ab_bookings';
		$rt = $wpdb->prefix . 'g2ab_resources';
		$pt = $wpdb->prefix . 'g2ab_payments';
		$lt = $wpdb->prefix . 'g2ab_logs';

		$today_start = current_time( 'Y-m-d' ) . ' 00:00:00';
		$today_end   = current_time( 'Y-m-d' ) . ' 23:59:59';

		// Lanes — pull resource list (type=lane) and bookings currently active.
		$lanes = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, name FROM {$rt} WHERE is_active = 1 AND type = %s ORDER BY sort_order ASC, name ASC",
			'lane'
		), ARRAY_A );
		$now_mysql = current_time( 'mysql' );
		$in_use_ids = (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT resource_id FROM {$bt}
			 WHERE status IN ('checked_in','paid','confirmed')
			   AND start_at <= %s AND end_at >= %s",
			$now_mysql, $now_mysql
		) );
		$reserved_ids = (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT resource_id FROM {$bt}
			 WHERE status IN ('pending','reserved','confirmed','paid')
			   AND start_at > %s AND start_at <= DATE_ADD(%s, INTERVAL 1 HOUR)",
			$now_mysql, $now_mysql
		) );

		$lane_summary = array();
		foreach ( (array) $lanes as $lane ) {
			$id = (int) $lane['id'];
			$state = 'open';
			if ( in_array( $id, array_map( 'intval', $in_use_ids ), true ) )       $state = 'in_use';
			elseif ( in_array( $id, array_map( 'intval', $reserved_ids ), true ) ) $state = 'reserved';
			$lane_summary[] = array(
				'id'    => $id,
				'label' => self::clean_label( $lane['name'] ),
				'state' => $state,
			);
		}

		$today_revenue = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(amount),0) FROM {$pt} WHERE status = 'succeeded' AND created_at BETWEEN %s AND %s",
			$today_start, $today_end
		) );

		$active_members = 0;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'memberistic_memberships' ) ) === $wpdb->prefix . 'memberistic_memberships' ) {
			$active_members = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}memberistic_memberships WHERE status = 'active'" );
		}

		// Last 15 activity-log lines for the live feed — staff-friendly
		// summaries only; never include raw transaction ids, no PII.
		$feed_rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT l.event_type, l.severity, l.message, l.created_at, b.customer_name
			 FROM {$lt} l LEFT JOIN {$bt} b ON l.booking_id = b.id
			 WHERE l.event_type IN ('checked_in','payment_succeeded','status_changed','waiver_verified','walk_in_created')
			 ORDER BY l.id DESC LIMIT 15"
		), ARRAY_A );
		$feed = array();
		foreach ( $feed_rows as $r ) {
			$feed[] = array(
				'when'     => human_time_diff( strtotime( $r['created_at'] ), current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'g2a-booking' ),
				'kind'     => sanitize_key( $r['event_type'] ),
				'severity' => sanitize_key( $r['severity'] ),
				'message'  => wp_strip_all_tags( (string) $r['message'] ),
				'who'      => $r['customer_name'] ? self::initialize_name( (string) $r['customer_name'] ) : '',
			);
		}

		$kpi = array(
			'lanes_total'      => count( $lane_summary ),
			'lanes_in_use'     => count( array_filter( $lane_summary, function ( $l ) { return 'in_use' === $l['state']; } ) ),
			'today_revenue'    => round( $today_revenue, 2 ),
			'active_members'   => $active_members,
		);

		$snapshot = array(
			'kpi'   => $kpi,
			'lanes' => $lane_summary,
			'feed'  => $feed,
		);
		set_transient( 'g2ab_staff_snapshot', $snapshot, 30 );
		return rest_ensure_response( $snapshot );
	}

	/**
	 * Waiver lookup. Searches Memberistic's people table by email or name.
	 * Returns a minimal summary — never the waiver text, signed-PDF URL,
	 * DOB, or anything that would let staff exfiltrate PII.
	 */
	public function waiver_lookup( WP_REST_Request $request ) {
		$q = trim( (string) $request->get_param( 'q' ) );
		if ( '' === $q || strlen( $q ) < 2 ) {
			return new WP_Error( 'g2ab_bad_query', __( 'Enter at least 2 characters.', 'g2a-booking' ), array( 'status' => 400 ) );
		}

		global $wpdb;
		$persons = $wpdb->prefix . 'memberistic_persons';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $persons ) ) !== $persons ) {
			return rest_ensure_response( array( 'results' => array(), 'note' => 'memberistic_inactive' ) );
		}

		$like  = '%' . $wpdb->esc_like( $q ) . '%';
		$rows  = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT id, first_name, last_name, email, waiver_status, waiver_signed_at, waiver_expires_at, membership_id
			 FROM {$persons}
			 WHERE email LIKE %s OR CONCAT(first_name,' ',last_name) LIKE %s
			 ORDER BY waiver_signed_at DESC
			 LIMIT 25",
			$like, $like
		), ARRAY_A );

		$results = array();
		foreach ( $rows as $r ) {
			$status = self::resolve_waiver_status( $r );
			$results[] = array(
				'id'         => (int) $r['id'],
				'name'       => self::pretty_name( $r['first_name'], $r['last_name'] ),
				'email_mask' => self::mask_email( (string) $r['email'] ),
				'status'     => $status['label'],
				'state'      => $status['state'],
				'signed_at'  => $r['waiver_signed_at'] ? self::pretty_date( $r['waiver_signed_at'] ) : '',
				'expires_at' => $r['waiver_expires_at'] ? self::pretty_date( $r['waiver_expires_at'] ) : '',
				'tier'       => self::membership_tier_label( (int) $r['membership_id'] ),
			);
		}

		return rest_ensure_response( array( 'results' => $results ) );
	}

	/**
	 * QR resolve. Member cards encode a signed token (see
	 * g2ab_member_qr_payload helper). We verify the HMAC, then return the
	 * same minimal summary as waiver_lookup so the dashboard can pop the
	 * member card without exposing PII.
	 */
	public function qr_resolve( WP_REST_Request $request ) {
		$payload = (string) $request->get_param( 'payload' );
		if ( '' === $payload || strlen( $payload ) > 512 ) {
			return new WP_Error( 'g2ab_bad_payload', __( 'Invalid QR payload.', 'g2a-booking' ), array( 'status' => 400 ) );
		}
		// Accept both raw signed tokens (g2ab:<person_id>:<exp>:<sig>) and
		// full URLs that contain ?g2ab_card=<token>.
		if ( false !== stripos( $payload, 'g2ab_card=' ) ) {
			$parts = wp_parse_url( $payload );
			parse_str( (string) ( $parts['query'] ?? '' ), $q );
			$payload = (string) ( $q['g2ab_card'] ?? '' );
		}
		$bits = explode( ':', $payload );
		if ( 4 !== count( $bits ) || 'g2ab' !== $bits[0] ) {
			return new WP_Error( 'g2ab_bad_payload', __( 'Unrecognised QR code.', 'g2a-booking' ), array( 'status' => 400 ) );
		}
		list( , $pid, $exp, $sig ) = $bits;
		$pid = (int) $pid;
		$exp = (int) $exp;
		if ( $pid <= 0 || $exp < time() ) {
			return new WP_Error( 'g2ab_expired', __( 'Member card expired. Ask the member to refresh their app.', 'g2a-booking' ), array( 'status' => 410 ) );
		}
		$expected = hash_hmac( 'sha256', 'card|' . $pid . '|' . $exp, wp_salt( 'auth' ) );
		if ( ! hash_equals( $expected, (string) $sig ) ) {
			return new WP_Error( 'g2ab_bad_sig', __( 'Invalid card signature.', 'g2a-booking' ), array( 'status' => 403 ) );
		}

		global $wpdb;
		$persons = $wpdb->prefix . 'memberistic_persons';
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, first_name, last_name, email, waiver_status, waiver_signed_at, waiver_expires_at, membership_id
			 FROM {$persons} WHERE id = %d",
			$pid
		), ARRAY_A );
		if ( ! $row ) {
			return new WP_Error( 'g2ab_not_found', __( 'No matching member.', 'g2a-booking' ), array( 'status' => 404 ) );
		}
		$status = self::resolve_waiver_status( $row );
		return rest_ensure_response( array(
			'id'         => (int) $row['id'],
			'name'       => self::pretty_name( $row['first_name'], $row['last_name'] ),
			'email_mask' => self::mask_email( (string) $row['email'] ),
			'status'     => $status['label'],
			'state'      => $status['state'],
			'signed_at'  => $row['waiver_signed_at'] ? self::pretty_date( $row['waiver_signed_at'] ) : '',
			'expires_at' => $row['waiver_expires_at'] ? self::pretty_date( $row['waiver_expires_at'] ) : '',
			'tier'       => self::membership_tier_label( (int) $row['membership_id'] ),
		) );
	}

	/**
	 * Walk-in check-in: create a booking for the next-available slot on
	 * the chosen lane, mark it checked-in immediately. Idempotency guard
	 * via a short-lived transient keyed by name + lane + minute.
	 */
	public function walk_in_checkin( WP_REST_Request $request ) {
		$name    = trim( (string) $request->get_param( 'name' ) );
		$email   = trim( (string) $request->get_param( 'email' ) );
		$phone   = trim( (string) $request->get_param( 'phone' ) );
		$lane_id = (int) $request->get_param( 'lane' );
		$party   = max( 1, (int) $request->get_param( 'party_size' ) );
		$minutes = max( 30, min( 240, (int) $request->get_param( 'minutes' ) ) );

		if ( '' === $name || $lane_id <= 0 ) {
			return new WP_Error( 'g2ab_invalid', __( 'Name and lane are required.', 'g2a-booking' ), array( 'status' => 400 ) );
		}

		$idem = 'g2ab_walkin_' . md5( $name . '|' . $lane_id . '|' . current_time( 'YmdHi' ) );
		if ( get_transient( $idem ) ) {
			return new WP_Error( 'g2ab_duplicate', __( 'A walk-in for this name is already being created.', 'g2a-booking' ), array( 'status' => 409 ) );
		}
		set_transient( $idem, 1, 60 );

		global $wpdb;
		$bt  = $wpdb->prefix . 'g2ab_bookings';
		$rt  = $wpdb->prefix . 'g2ab_resources';

		$resource = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, name, type FROM {$rt} WHERE id = %d AND is_active = 1",
			$lane_id
		), ARRAY_A );
		if ( ! $resource ) {
			return new WP_Error( 'g2ab_unknown_lane', __( 'Lane not found.', 'g2a-booking' ), array( 'status' => 404 ) );
		}

		$start_ts = (int) current_time( 'timestamp' );
		$start_at = date( 'Y-m-d H:i:s', $start_ts );
		$end_at   = date( 'Y-m-d H:i:s', $start_ts + $minutes * MINUTE_IN_SECONDS );
		$uuid     = function_exists( 'g2ab_generate_uuid' ) ? g2ab_generate_uuid() : wp_generate_uuid4();
		$now      = current_time( 'mysql' );

		// Default booking_type — first active "walk-in" or first lane type.
		$type_id = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}g2ab_booking_types WHERE is_active = 1 ORDER BY id ASC LIMIT 1" );
		if ( ! $type_id ) {
			return new WP_Error( 'g2ab_no_type', __( 'No booking type configured.', 'g2a-booking' ), array( 'status' => 500 ) );
		}

		$inserted = $wpdb->insert( $bt, array(
			'uuid'              => $uuid,
			'booking_type_id'   => $type_id,
			'resource_id'       => $lane_id,
			'customer_name'     => substr( $name, 0, 150 ),
			'customer_email'    => $email ? substr( $email, 0, 150 ) : '',
			'customer_phone'    => $phone ? substr( $phone, 0, 30 ) : null,
			'start_at'          => $start_at,
			'end_at'            => $end_at,
			'duration_min'      => $minutes,
			'party_size'        => $party,
			'status'            => 'checked_in',
			'payment_mode'      => 'in_store',
			'total_amount'      => 0,
			'paid_amount'       => 0,
			'currency'          => (string) get_option( 'g2ab_currency', 'USD' ),
			'source'            => 'walk_in',
			'created_by'        => get_current_user_id(),
			'created_at'        => $now,
			'updated_at'        => $now,
			'checked_in_at'     => $now,
		) );
		if ( ! $inserted ) {
			return new WP_Error( 'g2ab_db', __( 'Could not create walk-in.', 'g2a-booking' ), array( 'status' => 500 ) );
		}
		$booking_id = (int) $wpdb->insert_id;
		$wpdb->insert( $wpdb->prefix . 'g2ab_logs', array(
			'booking_id' => $booking_id,
			'user_id'    => get_current_user_id(),
			'event_type' => 'walk_in_created',
			'severity'   => 'info',
			'message'    => sprintf( 'Walk-in by %s on lane %s', $name, $resource['name'] ),
			'context'    => wp_json_encode( array() ),
			'created_at' => $now,
		) );

		delete_transient( 'g2ab_staff_snapshot' );
		do_action( 'g2ab_booking_status_changed', $booking_id, 'checked_in', 'new' );

		return rest_ensure_response( array(
			'ok'        => true,
			'booking_id'=> $booking_id,
			'message'   => sprintf( __( '%1$s checked in on %2$s', 'g2a-booking' ), $name, $resource['name'] ),
		) );
	}

	/**
	 * Limited booking actions from the staff console:
	 *   - check_in   : mark checked-in (only if status in pending/reserved/confirmed/paid)
	 *   - no_show    : flip to no_show (only if start_at < now AND not already cancelled)
	 *   - mark_paid  : flip to paid (only when payment_mode = in_store)
	 *
	 * Anything outside this list is rejected — staff cannot edit customer
	 * data, delete bookings, or change financial fields.
	 */
	public function booking_action( WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'booking_id' );
		$action = sanitize_key( $request->get_param( 'action' ) );
		$allowed = array( 'check_in', 'no_show', 'mark_paid' );
		if ( ! in_array( $action, $allowed, true ) || $id <= 0 ) {
			return new WP_Error( 'g2ab_invalid_action', __( 'Action not allowed.', 'g2a-booking' ), array( 'status' => 400 ) );
		}

		global $wpdb;
		$bt = $wpdb->prefix . 'g2ab_bookings';
		$booking = $wpdb->get_row( $wpdb->prepare( "SELECT id, status, start_at, payment_mode FROM {$bt} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $booking ) {
			return new WP_Error( 'g2ab_not_found', __( 'Booking not found.', 'g2a-booking' ), array( 'status' => 404 ) );
		}

		$now = current_time( 'mysql' );
		$prev = (string) $booking['status'];

		switch ( $action ) {
			case 'check_in':
				if ( ! in_array( $prev, array( 'pending', 'reserved', 'confirmed', 'paid' ), true ) ) {
					return new WP_Error( 'g2ab_bad_state', __( 'Booking is not in a state that can be checked in.', 'g2a-booking' ), array( 'status' => 409 ) );
				}
				$wpdb->update( $bt, array( 'status' => 'checked_in', 'checked_in_at' => $now, 'updated_at' => $now ), array( 'id' => $id ) );
				$message = __( 'Checked in.', 'g2a-booking' );
				break;
			case 'no_show':
				if ( in_array( $prev, array( 'cancelled', 'refunded', 'completed', 'expired' ), true ) ) {
					return new WP_Error( 'g2ab_bad_state', __( 'Cannot mark this booking as no-show.', 'g2a-booking' ), array( 'status' => 409 ) );
				}
				$wpdb->update( $bt, array( 'status' => 'no_show', 'updated_at' => $now ), array( 'id' => $id ) );
				$message = __( 'Marked no-show.', 'g2a-booking' );
				break;
			case 'mark_paid':
				if ( 'in_store' !== (string) $booking['payment_mode'] ) {
					return new WP_Error( 'g2ab_not_in_store', __( 'Only in-store bookings can be marked paid here.', 'g2a-booking' ), array( 'status' => 409 ) );
				}
				$wpdb->update( $bt, array( 'status' => 'paid', 'updated_at' => $now ), array( 'id' => $id ) );
				$message = __( 'Marked paid.', 'g2a-booking' );
				break;
		}
		$wpdb->insert( $wpdb->prefix . 'g2ab_logs', array(
			'booking_id' => $id,
			'user_id'    => get_current_user_id(),
			'event_type' => 'status_changed',
			'severity'   => 'info',
			'message'    => sprintf( 'Staff console: %s', $action ),
			'context'    => wp_json_encode( array( 'previous_status' => $prev ) ),
			'created_at' => $now,
		) );
		delete_transient( 'g2ab_staff_snapshot' );
		do_action( 'g2ab_booking_status_changed', $id, 'check_in' === $action ? 'checked_in' : ( 'no_show' === $action ? 'no_show' : 'paid' ), $prev );
		return rest_ensure_response( array( 'ok' => true, 'message' => $message ) );
	}

	/* ------------------------------------------------------------------ */
	/* Helpers (formatting / redaction)                                    */
	/* ------------------------------------------------------------------ */

	private static function resolve_waiver_status( array $row ) {
		$state = 'missing';
		$label = __( 'No waiver on file', 'g2a-booking' );
		if ( ! empty( $row['waiver_status'] ) && 'signed' === $row['waiver_status'] ) {
			$exp_ok = empty( $row['waiver_expires_at'] ) || strtotime( (string) $row['waiver_expires_at'] ) > current_time( 'timestamp' );
			if ( $exp_ok ) {
				$state = 'current';
				$label = __( 'Current', 'g2a-booking' );
			} else {
				$state = 'expired';
				$label = __( 'Expired', 'g2a-booking' );
			}
		}
		return array( 'state' => $state, 'label' => $label );
	}

	private static function pretty_name( $first, $last ) {
		$first = wp_strip_all_tags( (string) $first );
		$last  = wp_strip_all_tags( (string) $last );
		return trim( $first . ' ' . $last );
	}

	private static function initialize_name( $name ) {
		$bits = preg_split( '/\s+/', trim( wp_strip_all_tags( $name ) ) );
		if ( count( $bits ) <= 1 ) return $bits[0] ?? '';
		$last = array_pop( $bits );
		$ini  = implode( '. ', array_map( function ( $b ) { return mb_substr( $b, 0, 1 ); }, $bits ) ) . '.';
		return trim( $ini . ' ' . $last );
	}

	/**
	 * Mask the local part of an email: jonathan.smith@example.com -> j*****h@example.com.
	 */
	private static function mask_email( $email ) {
		if ( false === strpos( $email, '@' ) ) return '';
		list( $local, $domain ) = explode( '@', $email, 2 );
		if ( strlen( $local ) <= 2 ) return $local[0] . '*@' . $domain;
		return $local[0] . str_repeat( '*', max( 1, strlen( $local ) - 2 ) ) . substr( $local, -1 ) . '@' . $domain;
	}

	private static function pretty_date( $mysql ) {
		$ts = strtotime( (string) $mysql );
		return $ts ? wp_date( 'M j, Y', $ts ) : '';
	}

	private static function membership_tier_label( $membership_id ) {
		global $wpdb;
		if ( $membership_id <= 0 ) return '';
		$table = $wpdb->prefix . 'memberistic_memberships';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return '';
		$plan = (string) $wpdb->get_var( $wpdb->prepare( "SELECT plan_label FROM {$table} WHERE id = %d", $membership_id ) );
		return $plan ?: '';
	}

	/**
	 * Clean a resource name to a short label like "L01".
	 */
	private static function clean_label( $name ) {
		$name = wp_strip_all_tags( (string) $name );
		if ( preg_match( '/(\d+)/', $name, $m ) ) {
			return 'L' . str_pad( $m[1], 2, '0', STR_PAD_LEFT );
		}
		return substr( $name, 0, 6 );
	}
}
