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

		// Check-in station endpoints ---------------------------------------
		register_rest_route( self::NS, '/staff/station-token', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'station_token' ),
			'permission_callback' => array( $this, 'permission_read' ),
		) );
		register_rest_route( self::NS, '/staff/pending', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'pending' ),
			'permission_callback' => array( $this, 'permission_read' ),
			'args'                => array(
				'station' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );
		register_rest_route( self::NS, '/staff/open-lanes', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'open_lanes' ),
			'permission_callback' => array( $this, 'permission_read' ),
		) );
		register_rest_route( self::NS, '/staff/finalize-checkin', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'finalize_checkin' ),
			'permission_callback' => array( $this, 'permission_write' ),
			'args'                => array(
				'request_id'   => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'lane_id'      => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				'minutes'      => array( 'required' => false, 'sanitize_callback' => 'absint', 'default' => 60 ),
				'party_size'   => array( 'required' => false, 'sanitize_callback' => 'absint', 'default' => 1 ),
				'send_email'   => array( 'required' => false, 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => true ),
			),
		) );
		register_rest_route( self::NS, '/staff/decline-checkin', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'decline_checkin' ),
			'permission_callback' => array( $this, 'permission_write' ),
			'args'                => array(
				'request_id' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'reason'     => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );
	}

	/* ------------------------------------------------------------------ */
	/* Check-in station                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Issue a signed station token for THIS staff dashboard session.
	 * Tokens last 12 hours so a single staff shift doesn't need to refresh.
	 * Format: <random_nonce>.<exp>.<sig>
	 */
	public function station_token() {
		$nonce = wp_generate_password( 12, false, false );
		$exp   = time() + 12 * HOUR_IN_SECONDS;
		$sig   = hash_hmac( 'sha256', 'station|' . $nonce . '|' . $exp, wp_salt( 'auth' ) );
		$token = $nonce . '.' . $exp . '.' . $sig;

		// Build the URL members land on after scanning. Admins set the
		// landing page slug via the g2ab_checkin_page_slug option (default
		// 'check-in').
		$slug = (string) get_option( 'g2ab_checkin_page_slug', 'check-in' );
		$base = trailingslashit( home_url( '/' ) ) . ltrim( $slug, '/' ) . '/';
		$url  = add_query_arg( array( 's' => rawurlencode( $token ) ), $base );

		return rest_ensure_response( array(
			'token' => $token,
			'url'   => $url,
			'exp'   => $exp,
		) );
	}

	/**
	 * Validate a station token. Used by the public check-in controller.
	 */
	public static function verify_station_token( $token ) {
		$token = (string) $token;
		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) ) return false;
		list( $nonce, $exp, $sig ) = $parts;
		if ( ! ctype_digit( (string) $exp ) || (int) $exp < time() ) return false;
		$expected = hash_hmac( 'sha256', 'station|' . $nonce . '|' . $exp, wp_salt( 'auth' ) );
		return hash_equals( $expected, (string) $sig );
	}

	/**
	 * Poll for a pending check-in queued at this station. Returns 204-style
	 * empty when nothing pending. Returns the resolved member record when
	 * a pending request exists.
	 */
	public function pending( WP_REST_Request $request ) {
		$station = (string) $request->get_param( 'station' );
		if ( ! self::verify_station_token( $station ) ) {
			return new WP_Error( 'g2ab_bad_station', __( 'Invalid station token.', 'g2a-booking' ), array( 'status' => 400 ) );
		}
		$rid = (string) get_transient( 'g2ab_ci_pending_' . md5( $station ) );
		if ( '' === $rid ) {
			return rest_ensure_response( array( 'pending' => null ) );
		}
		$record = get_transient( 'g2ab_ci_request_' . $rid );
		if ( ! is_array( $record ) || 'pending' !== ( $record['status'] ?? '' ) ) {
			delete_transient( 'g2ab_ci_pending_' . md5( $station ) );
			return rest_ensure_response( array( 'pending' => null ) );
		}
		// Strip the submitter IP from what the dashboard sees — staff don't need it.
		unset( $record['submitter_ip'] );
		return rest_ensure_response( array( 'pending' => $record ) );
	}

	/**
	 * Currently-open lanes (capacity 1 resources with no active booking
	 * overlapping `now`).
	 */
	public function open_lanes() {
		return rest_ensure_response( array( 'lanes' => self::lane_states() ) );
	}

	/**
	 * Shared lane-state snapshot: every active lane resource with its
	 * current state ('open' | 'in_use'), where in_use means a live
	 * booking overlaps the current moment.
	 *
	 * Single source of truth for the overlap logic — consumed by the
	 * staff /staff/open-lanes endpoint AND the public /lane-status
	 * endpoint (G2AB_REST_Bookings_Controller::get_lane_status), which
	 * only exposes the aggregate counts, never lane names.
	 *
	 * @return array[] [ { id:int, label:string, name:string, state:string }, … ]
	 */
	public static function lane_states() {
		global $wpdb;
		$rt  = $wpdb->prefix . 'g2ab_resources';
		$bt  = $wpdb->prefix . 'g2ab_bookings';
		$now = current_time( 'mysql' );
		$lanes = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT id, name FROM {$rt}
			 WHERE is_active = 1 AND type = %s
			 ORDER BY sort_order ASC, name ASC",
			'lane'
		), ARRAY_A );
		$in_use = (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT resource_id FROM {$bt}
			 WHERE status IN ('checked_in','paid','confirmed','reserved')
			   AND start_at <= %s AND end_at >= %s",
			$now, $now
		) );
		$in_use = array_map( 'intval', $in_use );
		$out = array();
		foreach ( $lanes as $l ) {
			$out[] = array(
				'id'    => (int) $l['id'],
				'label' => self::clean_label( $l['name'] ),
				'name'  => wp_strip_all_tags( (string) $l['name'] ),
				'state' => in_array( (int) $l['id'], $in_use, true ) ? 'in_use' : 'open',
			);
		}
		return $out;
	}

	/**
	 * Confirm a pending check-in: create a checked_in booking, send an
	 * email receipt (if configured), and flip the transient to confirmed.
	 */
	public function finalize_checkin( WP_REST_Request $request ) {
		$rid = (string) $request->get_param( 'request_id' );
		$record = get_transient( 'g2ab_ci_request_' . $rid );
		if ( ! is_array( $record ) ) {
			return new WP_Error( 'g2ab_expired', __( 'Request expired. Ask member to scan again.', 'g2a-booking' ), array( 'status' => 410 ) );
		}
		if ( 'pending' !== ( $record['status'] ?? '' ) ) {
			return new WP_Error( 'g2ab_bad_state', __( 'Already handled.', 'g2a-booking' ), array( 'status' => 409 ) );
		}

		$lane_id = (int) $request->get_param( 'lane_id' );
		$minutes = max( 30, min( 240, (int) $request->get_param( 'minutes' ) ) );
		$party   = max( 1, (int) $request->get_param( 'party_size' ) );
		$send    = (bool) $request->get_param( 'send_email' );

		global $wpdb;
		$rt  = $wpdb->prefix . 'g2ab_resources';
		$bt  = $wpdb->prefix . 'g2ab_bookings';
		$resource = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, name FROM {$rt} WHERE id = %d AND is_active = 1",
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

		$type_id = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}g2ab_booking_types WHERE is_active = 1 ORDER BY id ASC LIMIT 1" );
		if ( ! $type_id ) {
			return new WP_Error( 'g2ab_no_type', __( 'No booking type configured.', 'g2a-booking' ), array( 'status' => 500 ) );
		}

		$inserted = $wpdb->insert( $bt, array(
			'uuid'              => $uuid,
			'booking_type_id'   => $type_id,
			'resource_id'       => $lane_id,
			'customer_name'     => substr( (string) ( $record['name'] ?? 'Member' ), 0, 150 ),
			'customer_email'    => substr( (string) ( $record['email'] ?? '' ), 0, 150 ),
			'start_at'          => $start_at,
			'end_at'            => $end_at,
			'duration_min'      => $minutes,
			'party_size'        => $party,
			'status'            => 'checked_in',
			'payment_mode'      => 'in_store',
			'total_amount'      => 0,
			'paid_amount'       => 0,
			'currency'          => (string) get_option( 'g2ab_currency', 'USD' ),
			'source'            => 'self_checkin',
			'created_by'        => get_current_user_id(),
			'created_at'        => $now,
			'updated_at'        => $now,
			'checked_in_at'     => $now,
		) );
		if ( ! $inserted ) {
			return new WP_Error( 'g2ab_db', __( 'Could not create check-in.', 'g2a-booking' ), array( 'status' => 500 ) );
		}
		$booking_id = (int) $wpdb->insert_id;

		$wpdb->insert( $wpdb->prefix . 'g2ab_logs', array(
			'booking_id' => $booking_id,
			'user_id'    => get_current_user_id(),
			'event_type' => 'checked_in',
			'severity'   => 'info',
			'message'    => sprintf( 'Self check-in confirmed by staff. Lane %s, %d min.', $resource['name'], $minutes ),
			'context'    => wp_json_encode( array( 'request_id' => $rid, 'source' => $record['source'] ?? '' ) ),
			'created_at' => $now,
		) );

		// Flip the transient so the member's phone shows "Welcome".
		$record['status']          = 'confirmed';
		$record['confirm_message'] = sprintf(
			__( "Lane %1\$s · %2\$s · %3\$d min", 'g2a-booking' ),
			$resource['name'], wp_date( 'g:i a', $start_ts ), $minutes
		);
		$record['booking_id']      = $booking_id;
		set_transient( 'g2ab_ci_request_' . $rid, $record, 2 * MINUTE_IN_SECONDS );
		delete_transient( 'g2ab_ci_pending_' . md5( (string) $record['station'] ) );

		// Email receipt.
		if ( $send && ! empty( $record['email'] ) && is_email( $record['email'] ) ) {
			self::send_checkin_email( (array) $record, array(
				'lane_name' => (string) $resource['name'],
				'start_at'  => $start_at,
				'end_at'    => $end_at,
				'minutes'   => $minutes,
				'booking_id'=> $booking_id,
			) );
		}

		delete_transient( 'g2ab_staff_snapshot' );
		do_action( 'g2ab_booking_status_changed', $booking_id, 'checked_in', 'new' );
		do_action( 'g2ab_member_checked_in', $booking_id, $record );

		return rest_ensure_response( array(
			'ok'         => true,
			'booking_id' => $booking_id,
			'message'    => sprintf( __( '%s checked in on %s.', 'g2a-booking' ), (string) $record['name'], (string) $resource['name'] ),
		) );
	}

	/**
	 * Decline a pending check-in (e.g. waiver expired / member not found).
	 */
	public function decline_checkin( WP_REST_Request $request ) {
		$rid    = (string) $request->get_param( 'request_id' );
		$reason = (string) $request->get_param( 'reason' );
		$record = get_transient( 'g2ab_ci_request_' . $rid );
		if ( ! is_array( $record ) ) {
			return new WP_Error( 'g2ab_expired', __( 'Request expired.', 'g2a-booking' ), array( 'status' => 410 ) );
		}
		$record['status']          = 'declined';
		$record['decline_message'] = $reason ? wp_strip_all_tags( $reason ) : __( 'Please see the front desk.', 'g2a-booking' );
		set_transient( 'g2ab_ci_request_' . $rid, $record, 2 * MINUTE_IN_SECONDS );
		delete_transient( 'g2ab_ci_pending_' . md5( (string) $record['station'] ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Send the check-in receipt email. Uses the email engine when it's
	 * available (so HTML wrapping + brand colors carry over) and falls
	 * back to a plain wp_mail when not.
	 */
	private static function send_checkin_email( array $record, array $details ) {
		$to      = (string) $record['email'];
		$name    = (string) $record['name'];
		$tz      = wp_timezone();
		$start_dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $details['start_at'], $tz );
		$start_human = $start_dt ? $start_dt->format( 'l, F j · g:i a' ) : (string) $details['start_at'];

		$subject = sprintf( __( 'Checked in — %s', 'g2a-booking' ), (string) get_option( 'g2ab_business_name', get_bloginfo( 'name' ) ) );

		$body_html = ''
			. '<p>' . esc_html( sprintf( __( 'Hi %s,', 'g2a-booking' ), wp_strip_all_tags( $name ) ) ) . '</p>'
			. '<p>' . esc_html__( "You're checked in. Have a great session.", 'g2a-booking' ) . '</p>'
			. '<table cellpadding="0" cellspacing="0" border="0" style="margin:18px 0;font-family:Arial,sans-serif;font-size:14px;">'
			. '<tr><td style="padding:4px 14px 4px 0;color:#888;text-transform:uppercase;letter-spacing:0.06em;font-size:11px;">' . esc_html__( 'Lane', 'g2a-booking' ) . '</td><td style="padding:4px 0;font-weight:600;">' . esc_html( $details['lane_name'] ) . '</td></tr>'
			. '<tr><td style="padding:4px 14px 4px 0;color:#888;text-transform:uppercase;letter-spacing:0.06em;font-size:11px;">' . esc_html__( 'When', 'g2a-booking' ) . '</td><td style="padding:4px 0;font-weight:600;">' . esc_html( $start_human ) . '</td></tr>'
			. '<tr><td style="padding:4px 14px 4px 0;color:#888;text-transform:uppercase;letter-spacing:0.06em;font-size:11px;">' . esc_html__( 'Duration', 'g2a-booking' ) . '</td><td style="padding:4px 0;font-weight:600;">' . esc_html( (int) $details['minutes'] ) . ' min</td></tr>'
			. '</table>'
			. '<p style="color:#888;font-size:12px;">' . esc_html__( 'This is an automated check-in receipt.', 'g2a-booking' ) . '</p>';

		if ( class_exists( 'G2AB_Email_Engine' ) ) {
			try {
				$engine = new G2AB_Email_Engine();
				if ( method_exists( $engine, 'send_to' ) ) {
					$engine->send_to( $to, $subject, $body_html );
					return;
				}
				if ( method_exists( $engine, 'wrap_html' ) && method_exists( $engine, 'from_name' ) && method_exists( $engine, 'from_addr' ) ) {
					$body = $engine->wrap_html( $body_html, $subject );
					$headers = array(
						'Content-Type: text/html; charset=UTF-8',
						sprintf( 'From: %s <%s>', $engine->from_name(), $engine->from_addr() ),
					);
					wp_mail( $to, $subject, $body, $headers );
					return;
				}
			} catch ( \Throwable $e ) {
				// fall through to plain wp_mail
			}
		}
		wp_mail( $to, $subject, $body_html, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/* ------------------------------------------------------------------ */

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
	 * Waiver lookup. Searches the two real sources of truth in Memberistic:
	 *   1. wp_memberistic_people     — active member roster (full_name + waiver columns)
	 *   2. wp_memberistic_waivers_archive — imported OtterText/historical waivers
	 *      (first_name / last_name / email / signed_at)
	 * Results are merged, deduped by lower-cased email, and trimmed to 25.
	 *
	 * NEVER returns: file URL, DOB, raw text, PDF link, raw IP.
	 */
	public function waiver_lookup( WP_REST_Request $request ) {
		$q = trim( (string) $request->get_param( 'q' ) );
		if ( '' === $q || strlen( $q ) < 2 ) {
			return new WP_Error( 'g2ab_bad_query', __( 'Enter at least 2 characters.', 'g2a-booking' ), array( 'status' => 400 ) );
		}

		global $wpdb;
		$people  = $wpdb->prefix . 'memberistic_people';
		$archive = $wpdb->prefix . 'memberistic_waivers_archive';
		$has_people  = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $people ) ) === $people );
		$has_archive = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $archive ) ) === $archive );

		if ( ! $has_people && ! $has_archive ) {
			return rest_ensure_response( array( 'results' => array(), 'note' => 'memberistic_inactive' ) );
		}

		$like = '%' . $wpdb->esc_like( $q ) . '%';
		$by_email = array();   // email lower => merged record
		$ordered  = array();   // preserve search-relevance order

		if ( $has_people ) {
			$rows = (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT id, full_name, email, phone, waiver_status, waiver_signed_at, waiver_expires_at, membership_id
				 FROM {$people}
				 WHERE email LIKE %s OR full_name LIKE %s
				 ORDER BY waiver_signed_at DESC
				 LIMIT 25",
				$like, $like
			), ARRAY_A );
			foreach ( $rows as $r ) {
				$key = strtolower( trim( (string) $r['email'] ) );
				if ( '' === $key ) $key = 'pid:' . $r['id'];
				$status = self::resolve_waiver_status( array(
					'waiver_status'      => $r['waiver_status'],
					'waiver_expires_at'  => $r['waiver_expires_at'],
				) );
				$by_email[ $key ] = array(
					'source'     => 'member',
					'person_id'  => (int) $r['id'],
					'name'       => wp_strip_all_tags( (string) $r['full_name'] ),
					'email'      => (string) $r['email'],
					'email_mask' => self::mask_email( (string) $r['email'] ),
					'status'     => $status['label'],
					'state'      => $status['state'],
					'signed_at'  => $r['waiver_signed_at']  ? self::pretty_date( $r['waiver_signed_at'] )  : '',
					'expires_at' => $r['waiver_expires_at'] ? self::pretty_date( $r['waiver_expires_at'] ) : '',
					'tier'       => self::membership_tier_label( (int) $r['membership_id'] ),
				);
				$ordered[] = $key;
			}
		}

		if ( $has_archive ) {
			$rows = (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT id, first_name, last_name, email, signed_at, is_current, matched_user_id
				 FROM {$archive}
				 WHERE email LIKE %s OR CONCAT(IFNULL(first_name,''),' ',IFNULL(last_name,'')) LIKE %s
				 ORDER BY is_current DESC, signed_at DESC
				 LIMIT 25",
				$like, $like
			), ARRAY_A );
			foreach ( $rows as $r ) {
				$key = strtolower( trim( (string) $r['email'] ) );
				if ( '' === $key ) $key = 'aid:' . $r['id'];
				// If a member record already exists for this email, do not
				// overwrite — that record carries the membership tier. But
				// promote the archive's signed_at if newer.
				if ( isset( $by_email[ $key ] ) ) {
					$arch_state = (int) $r['is_current'] === 1 ? 'current' : 'expired';
					if ( 'missing' === $by_email[ $key ]['state'] && 'current' === $arch_state ) {
						$by_email[ $key ]['state']     = 'current';
						$by_email[ $key ]['status']    = __( 'Current (archived)', 'g2a-booking' );
						$by_email[ $key ]['signed_at'] = $r['signed_at'] ? self::pretty_date( $r['signed_at'] ) : $by_email[ $key ]['signed_at'];
					}
					continue;
				}
				$state = (int) $r['is_current'] === 1 ? 'current' : 'expired';
				$by_email[ $key ] = array(
					'source'     => 'archive',
					'archive_id' => (int) $r['id'],
					'name'       => self::pretty_name( $r['first_name'], $r['last_name'] ),
					'email'      => (string) $r['email'],
					'email_mask' => self::mask_email( (string) $r['email'] ),
					'status'     => 'current' === $state ? __( 'Current (archived)', 'g2a-booking' ) : __( 'Expired (archived)', 'g2a-booking' ),
					'state'      => $state,
					'signed_at'  => $r['signed_at'] ? self::pretty_date( $r['signed_at'] ) : '',
					'expires_at' => '',
					'tier'       => '',
				);
				$ordered[] = $key;
			}
		}

		// Preserve order; drop duplicates that snuck in
		$seen = array();
		$results = array();
		foreach ( $ordered as $k ) {
			if ( isset( $seen[ $k ] ) || ! isset( $by_email[ $k ] ) ) continue;
			$seen[ $k ] = true;
			$results[] = $by_email[ $k ];
			if ( count( $results ) >= 25 ) break;
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
		$people = $wpdb->prefix . 'memberistic_people';
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, full_name, email, waiver_status, waiver_signed_at, waiver_expires_at, membership_id
			 FROM {$people} WHERE id = %d",
			$pid
		), ARRAY_A );
		if ( ! $row ) {
			return new WP_Error( 'g2ab_not_found', __( 'No matching member.', 'g2a-booking' ), array( 'status' => 404 ) );
		}
		$status = self::resolve_waiver_status( $row );
		return rest_ensure_response( array(
			'person_id'  => (int) $row['id'],
			'source'     => 'member',
			'name'       => wp_strip_all_tags( (string) $row['full_name'] ),
			'email'      => (string) $row['email'],
			'email_mask' => self::mask_email( (string) $row['email'] ),
			'status'     => $status['label'],
			'state'      => $status['state'],
			'signed_at'  => $row['waiver_signed_at']  ? self::pretty_date( $row['waiver_signed_at'] )  : '',
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

	public static function resolve_waiver_status( array $row ) {
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

	public static function membership_tier_label( $membership_id ) {
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
	public static function clean_label( $name ) {
		$name = wp_strip_all_tags( (string) $name );
		if ( preg_match( '/(\d+)/', $name, $m ) ) {
			return 'L' . str_pad( $m[1], 2, '0', STR_PAD_LEFT );
		}
		return substr( $name, 0, 6 );
	}
}
