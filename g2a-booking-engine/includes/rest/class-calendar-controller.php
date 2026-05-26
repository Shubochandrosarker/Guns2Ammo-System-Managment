<?php
/**
 * REST: calendar + lifecycle endpoints for v1.3.0.
 *
 * Endpoints:
 *   GET  /calendar/events                                  Admin (FullCalendar feed).
 *   GET  /calendar/filters                                 Admin — resources / categories / statuses.
 *   POST /calendar/reschedule                              Admin — drag-and-drop or modal reschedule.
 *   POST /calendar/cancel                                  Admin — cancel + cancellation reason + refund flag.
 *   POST /calendar/no-show                                 Admin — flip to no_show.
 *   POST /calendar/complete                                Admin — flip to completed.
 *   POST /bookings/{uuid}/customer-reschedule              Customer — token-gated.
 *   POST /bookings/{uuid}/customer-cancel                  Customer — token-gated.
 *
 * Lifecycle hooks fired:
 *   g2ab_booking_rescheduled, g2ab_booking_cancelled,
 *   g2ab_booking_no_show, g2ab_booking_completed,
 *   g2ab_booking_status_changed
 *
 * @package G2AB
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_REST_Calendar_Controller {

	public function register_routes() {
		register_rest_route( G2AB_REST_NAMESPACE, '/calendar/events', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'list_events' ),
			'permission_callback' => array( $this, 'permission_admin_read' ),
			'args'                => array(
				'start'       => array( 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ),
				'end'         => array( 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ),
				'resource_id' => array( 'required' => false, 'sanitize_callback' => 'absint', 'default' => 0 ),
				'category'    => array( 'required' => false, 'sanitize_callback' => 'sanitize_key',  'default' => '' ),
				'status'      => array( 'required' => false, 'sanitize_callback' => 'sanitize_key',  'default' => '' ),
			),
		) );

		register_rest_route( G2AB_REST_NAMESPACE, '/calendar/filters', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'list_filters' ),
			'permission_callback' => array( $this, 'permission_admin_read' ),
		) );

		register_rest_route( G2AB_REST_NAMESPACE, '/calendar/reschedule', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'admin_reschedule' ),
			'permission_callback' => array( $this, 'permission_admin_write' ),
		) );

		register_rest_route( G2AB_REST_NAMESPACE, '/calendar/cancel', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'admin_cancel' ),
			'permission_callback' => array( $this, 'permission_admin_write' ),
		) );

		register_rest_route( G2AB_REST_NAMESPACE, '/calendar/no-show', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'admin_no_show' ),
			'permission_callback' => array( $this, 'permission_admin_write' ),
		) );

		register_rest_route( G2AB_REST_NAMESPACE, '/calendar/complete', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'admin_complete' ),
			'permission_callback' => array( $this, 'permission_admin_write' ),
		) );

		register_rest_route( G2AB_REST_NAMESPACE, '/bookings/(?P<uuid>[a-f0-9-]{36})/customer-reschedule', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'customer_reschedule' ),
			'permission_callback' => '__return_true', // gated by confirm_token
		) );

		register_rest_route( G2AB_REST_NAMESPACE, '/bookings/(?P<uuid>[a-f0-9-]{36})/customer-cancel', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'customer_cancel' ),
			'permission_callback' => '__return_true', // gated by confirm_token
		) );
	}

	/* ---------------------------------------------------------------------
	 * Permission helpers
	 * ------------------------------------------------------------------- */

	public function permission_admin_read( WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_g2ab_bookings' ) ) {
			return new WP_Error( 'g2ab_forbidden', __( 'Forbidden.', 'g2a-booking' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public function permission_admin_write( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'g2ab_invalid_nonce', __( 'Invalid or missing nonce.', 'g2a-booking' ), array( 'status' => 403 ) );
		}
		if ( ! current_user_can( 'manage_g2ab_bookings' ) ) {
			return new WP_Error( 'g2ab_forbidden', __( 'Forbidden.', 'g2a-booking' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/* ---------------------------------------------------------------------
	 * Read endpoints
	 * ------------------------------------------------------------------- */

	public function list_filters() {
		global $wpdb;
		$resources = $wpdb->get_results( "SELECT id, name, type FROM {$wpdb->prefix}g2ab_resources WHERE is_active = 1 ORDER BY sort_order ASC, name ASC" );
		$bt        = $wpdb->get_results( "SELECT DISTINCT category FROM {$wpdb->prefix}g2ab_booking_types WHERE is_active = 1 ORDER BY category ASC" );
		$statuses  = array();
		foreach ( G2AB_Booking_Statuses::all() as $key => $label ) {
			$statuses[] = array(
				'key'   => $key,
				'label' => $label,
				'color' => G2AB_Booking_Statuses::color( $key ),
			);
		}
		return rest_ensure_response( array(
			'success' => true,
			'data'    => array(
				'resources'  => $resources,
				'categories' => array_values( array_filter( wp_list_pluck( $bt, 'category' ) ) ),
				'statuses'   => $statuses,
			),
		) );
	}

	public function list_events( WP_REST_Request $request ) {
		global $wpdb;
		$start_str   = (string) $request->get_param( 'start' );
		$end_str     = (string) $request->get_param( 'end' );
		$resource_id = (int) $request->get_param( 'resource_id' );
		$category    = (string) $request->get_param( 'category' );
		$status      = (string) $request->get_param( 'status' );

		$start = $this->parse_iso_window( $start_str );
		$end   = $this->parse_iso_window( $end_str );
		if ( ! $start || ! $end ) {
			return new WP_Error( 'g2ab_bad_window', __( 'Invalid start / end window.', 'g2a-booking' ), array( 'status' => 400 ) );
		}

		$bookings = $wpdb->prefix . 'g2ab_bookings';
		$bt       = $wpdb->prefix . 'g2ab_booking_types';
		$rt       = $wpdb->prefix . 'g2ab_resources';

		$sql = "SELECT b.id, b.uuid, b.customer_name, b.customer_email, b.party_size,
		               b.start_at, b.end_at, b.status, b.payment_mode, b.total_amount,
		               b.paid_amount, b.notes, b.cancel_reason, b.original_start_at,
		               b.resource_id, b.booking_type_id, b.source,
		               bt.name AS booking_type_name, bt.category AS booking_type_category,
		               r.name AS resource_name, r.type AS resource_type
		          FROM {$bookings} b
		          LEFT JOIN {$bt} bt ON bt.id = b.booking_type_id
		          LEFT JOIN {$rt} r ON r.id  = b.resource_id
		         WHERE b.start_at < %s AND b.end_at > %s";

		$params = array( $end, $start );
		if ( $resource_id > 0 ) {
			$sql      .= ' AND b.resource_id = %d';
			$params[]  = $resource_id;
		}
		if ( '' !== $category ) {
			$sql      .= ' AND bt.category = %s';
			$params[]  = $category;
		}
		if ( '' !== $status && G2AB_Booking_Statuses::is_valid( $status ) ) {
			$sql      .= ' AND b.status = %s';
			$params[]  = $status;
		}
		$sql .= ' ORDER BY b.start_at ASC LIMIT 1000';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );

		$events = array();
		foreach ( $rows as $row ) {
			$events[] = $this->row_to_event( $row );
		}

		return rest_ensure_response( array( 'success' => true, 'data' => $events ) );
	}

	/* ---------------------------------------------------------------------
	 * Admin write endpoints
	 * ------------------------------------------------------------------- */

	public function admin_reschedule( WP_REST_Request $request ) {
		$booking_id      = (int) $request->get_param( 'booking_id' );
		$new_start       = sanitize_text_field( (string) $request->get_param( 'start_at' ) );
		$new_resource_id = (int) $request->get_param( 'resource_id' );

		$booking = $this->get_booking( $booking_id );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		if ( G2AB_Booking_Statuses::is_terminal( $booking->status ) ) {
			return new WP_Error( 'g2ab_terminal', __( 'Booking is already in a final state and cannot be rescheduled.', 'g2a-booking' ), array( 'status' => 409 ) );
		}

		return $this->reschedule_booking( $booking, $new_start, $new_resource_id, get_current_user_id(), 'admin' );
	}

	public function admin_cancel( WP_REST_Request $request ) {
		$booking_id     = (int) $request->get_param( 'booking_id' );
		$reason         = sanitize_text_field( (string) ( $request->get_param( 'reason' ) ?: 'other' ) );
		$reason_text    = sanitize_text_field( (string) $request->get_param( 'reason_text' ) );
		$mark_refunded  = (bool) $request->get_param( 'mark_refunded' );
		$send_email     = null !== $request->get_param( 'send_email' ) ? (bool) $request->get_param( 'send_email' ) : true;

		$booking = $this->get_booking( $booking_id );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		return $this->cancel_booking( $booking, $reason, $reason_text, get_current_user_id(), 'admin', $mark_refunded, $send_email );
	}

	public function admin_no_show( WP_REST_Request $request ) {
		$booking_id = (int) $request->get_param( 'booking_id' );
		$booking    = $this->get_booking( $booking_id );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		return $this->set_status( $booking, G2AB_Booking_Statuses::NO_SHOW, 'no_show', get_current_user_id() );
	}

	public function admin_complete( WP_REST_Request $request ) {
		$booking_id = (int) $request->get_param( 'booking_id' );
		$booking    = $this->get_booking( $booking_id );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		return $this->set_status( $booking, G2AB_Booking_Statuses::COMPLETED, 'completed', get_current_user_id() );
	}

	/* ---------------------------------------------------------------------
	 * Customer (token-gated) endpoints
	 * ------------------------------------------------------------------- */

	public function customer_reschedule( WP_REST_Request $request ) {
		$booking = $this->resolve_customer_booking( $request );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		$new_start = sanitize_text_field( (string) $request->get_param( 'start_at' ) );
		return $this->reschedule_booking( $booking, $new_start, (int) $booking->resource_id, 0, 'customer' );
	}

	public function customer_cancel( WP_REST_Request $request ) {
		$booking = $this->resolve_customer_booking( $request );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		$reason      = sanitize_text_field( (string) ( $request->get_param( 'reason' ) ?: 'customer_request' ) );
		$reason_text = sanitize_text_field( (string) $request->get_param( 'reason_text' ) );
		return $this->cancel_booking( $booking, $reason, $reason_text, 0, 'customer', false, true );
	}

	private function resolve_customer_booking( WP_REST_Request $request ) {
		global $wpdb;
		$uuid  = sanitize_text_field( $request['uuid'] ?? '' );
		$token = sanitize_text_field( (string) $request->get_param( 'confirm_token' ) );
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $uuid ) ) {
			return new WP_Error( 'g2ab_bad_uuid', __( 'Bad UUID.', 'g2a-booking' ), array( 'status' => 400 ) );
		}
		$booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}g2ab_bookings WHERE uuid = %s", $uuid ) );
		if ( ! $booking ) {
			return new WP_Error( 'g2ab_not_found', __( 'Booking not found.', 'g2a-booking' ), array( 'status' => 404 ) );
		}
		$meta         = json_decode( (string) $booking->metadata, true );
		$stored       = isset( $meta['confirm_token'] ) ? (string) $meta['confirm_token'] : '';
		if ( '' === $stored || '' === $token || ! hash_equals( $stored, $token ) ) {
			return new WP_Error( 'g2ab_invalid_confirm_token', __( 'Invalid confirmation token.', 'g2a-booking' ), array( 'status' => 403 ) );
		}
		if ( G2AB_Booking_Statuses::is_terminal( $booking->status ) ) {
			return new WP_Error( 'g2ab_terminal', __( 'Booking is already in a final state.', 'g2a-booking' ), array( 'status' => 409 ) );
		}
		// Customer-side: refuse changes inside the booking lead window so a
		// customer can't reschedule out of a no-show 5 minutes before start.
		$lead = (int) get_option( 'g2ab_min_booking_lead_minutes', 30 );
		$start_ts = strtotime( (string) $booking->start_at );
		if ( $start_ts && ( $start_ts - time() ) < ( $lead * 60 ) ) {
			return new WP_Error( 'g2ab_too_close', __( 'It is too close to your start time. Please call us to make a change.', 'g2a-booking' ), array( 'status' => 409 ) );
		}
		return $booking;
	}

	/* ---------------------------------------------------------------------
	 * Internals — shared booking mutators
	 * ------------------------------------------------------------------- */

	private function get_booking( $booking_id ) {
		global $wpdb;
		if ( $booking_id < 1 ) {
			return new WP_Error( 'g2ab_bad_id', __( 'Invalid booking id.', 'g2a-booking' ), array( 'status' => 400 ) );
		}
		$booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}g2ab_bookings WHERE id = %d", (int) $booking_id ) );
		if ( ! $booking ) {
			return new WP_Error( 'g2ab_not_found', __( 'Booking not found.', 'g2a-booking' ), array( 'status' => 404 ) );
		}
		return $booking;
	}

	/**
	 * Move a booking to a new start_at and (optionally) new resource. Honors
	 * buffers + capacity_mode in the same way as create_booking does.
	 */
	private function reschedule_booking( $booking, $new_start_at, $new_resource_id, $user_id, $source ) {
		global $wpdb;

		$tz       = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( date_default_timezone_get() );
		if ( strlen( $new_start_at ) === 16 ) {
			$new_start_at .= ':00';
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $new_start_at ) ) {
			return new WP_Error( 'g2ab_invalid_time', __( 'Invalid new start time.', 'g2a-booking' ), array( 'status' => 400 ) );
		}
		$start_dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $new_start_at, $tz );
		if ( ! $start_dt || $start_dt->format( 'Y-m-d H:i:s' ) !== $new_start_at ) {
			return new WP_Error( 'g2ab_invalid_time', __( 'Invalid new start time.', 'g2a-booking' ), array( 'status' => 400 ) );
		}

		$bt_table     = $wpdb->prefix . 'g2ab_booking_types';
		$booking_type = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$bt_table} WHERE id = %d", (int) $booking->booking_type_id ) );
		if ( ! $booking_type ) {
			return new WP_Error( 'g2ab_invalid_type', __( 'Booking type missing.', 'g2a-booking' ), array( 'status' => 400 ) );
		}

		$res_table     = $wpdb->prefix . 'g2ab_resources';
		$resource_id   = $new_resource_id > 0 ? $new_resource_id : (int) $booking->resource_id;
		$resource      = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$res_table} WHERE id = %d AND is_active = 1", $resource_id ) );
		if ( ! $resource ) {
			return new WP_Error( 'g2ab_invalid_resource', __( 'Invalid resource.', 'g2a-booking' ), array( 'status' => 400 ) );
		}

		$duration       = (int) $booking_type->duration_min;
		$end_dt         = $start_dt->modify( '+' . $duration . ' minutes' );
		$buffer_before  = (int) $booking_type->buffer_before;
		$buffer_after   = (int) $booking_type->buffer_after;
		$capacity_mode  = $this->normalize_capacity_mode( $booking_type->capacity_mode ?? '' );
		$start_eff_sql  = $start_dt->modify( '-' . max( 0, $buffer_before ) . ' minutes' )->format( 'Y-m-d H:i:s' );
		$end_eff_sql    = $end_dt->modify( '+' . max( 0, $buffer_after ) . ' minutes' )->format( 'Y-m-d H:i:s' );
		$start_sql      = $start_dt->format( 'Y-m-d H:i:s' );
		$end_sql        = $end_dt->format( 'Y-m-d H:i:s' );

		$capacity      = max( 1, (int) $resource->capacity );
		$party_size    = max( 1, (int) $booking->party_size );
		$bookings_table = $wpdb->prefix . 'g2ab_bookings';

		$wpdb->query( 'START TRANSACTION' );

		$load_select = ( 'party_size' === $capacity_mode ) ? 'COALESCE(SUM(b.party_size), 0)' : 'COUNT(*)';
		$current_load = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT {$load_select} FROM {$bookings_table} b
			   LEFT JOIN {$bt_table} bt ON bt.id = b.booking_type_id
			  WHERE b.resource_id = %d
			    AND b.id <> %d
			    AND b.status IN ('pending','reserved','confirmed','paid')
			    AND DATE_SUB(b.start_at, INTERVAL COALESCE(bt.buffer_before, 0) MINUTE) < %s
			    AND DATE_ADD(b.end_at,   INTERVAL COALESCE(bt.buffer_after,  0) MINUTE) > %s
			  FOR UPDATE",
			$resource_id,
			(int) $booking->id,
			$end_eff_sql,
			$start_eff_sql
		) );
		$projected = $current_load + ( 'party_size' === $capacity_mode ? $party_size : 1 );
		if ( $projected > $capacity ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'g2ab_slot_full', __( 'That new time is full. Please pick another.', 'g2a-booking' ), array( 'status' => 409 ) );
		}

		$now = current_time( 'mysql' );
		$old_start    = (string) $booking->start_at;
		$old_end      = (string) $booking->end_at;
		$old_resource = (int) $booking->resource_id;

		$update = array(
			'start_at'        => $start_sql,
			'end_at'          => $end_sql,
			'resource_id'     => $resource_id,
			'duration_min'    => $duration,
			'rescheduled_at'  => $now,
			'updated_at'      => $now,
		);
		// Backfill original_start_at the first time we touch this booking.
		if ( empty( $booking->original_start_at ) ) {
			$update['original_start_at'] = $old_start;
		}

		$ok = $wpdb->update(
			$bookings_table,
			$update,
			array( 'id' => (int) $booking->id ),
			array( '%s', '%s', '%d', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $ok ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'g2ab_update_failed', __( 'Reschedule failed.', 'g2a-booking' ), array( 'status' => 500 ) );
		}
		$wpdb->query( 'COMMIT' );

		G2AB_Booking_Activity::log(
			(int) $booking->id,
			'rescheduled',
			array( 'start_at' => $old_start, 'end_at' => $old_end, 'resource_id' => $old_resource ),
			array( 'start_at' => $start_sql, 'end_at' => $end_sql, 'resource_id' => $resource_id ),
			sprintf( '%s reschedule', $source ),
			$user_id ?: null
		);

		do_action(
			'g2ab_booking_rescheduled',
			(int) $booking->id,
			array( 'start_at' => $old_start, 'end_at' => $old_end, 'resource_id' => $old_resource ),
			array( 'start_at' => $start_sql, 'end_at' => $end_sql, 'resource_id' => $resource_id, 'source' => $source )
		);

		return rest_ensure_response( array(
			'success' => true,
			'data'    => array(
				'id'          => (int) $booking->id,
				'uuid'        => $booking->uuid,
				'start_at'    => $start_sql,
				'end_at'      => $end_sql,
				'resource_id' => $resource_id,
			),
		) );
	}

	private function cancel_booking( $booking, $reason, $reason_text, $user_id, $source, $mark_refunded, $send_email ) {
		global $wpdb;
		if ( G2AB_Booking_Statuses::is_terminal( $booking->status ) ) {
			return new WP_Error( 'g2ab_terminal', __( 'Booking already finalised.', 'g2a-booking' ), array( 'status' => 409 ) );
		}

		$now = current_time( 'mysql' );
		$reason_clean = sanitize_text_field( $reason );
		$reason_label = '' !== $reason_text ? sanitize_text_field( $reason_text ) : $reason_clean;
		$new_status   = $mark_refunded ? G2AB_Booking_Statuses::REFUNDED : G2AB_Booking_Statuses::CANCELLED;

		$ok = $wpdb->update(
			$wpdb->prefix . 'g2ab_bookings',
			array(
				'status'               => $new_status,
				'cancelled_at'         => $now,
				'cancel_reason'        => mb_substr( $reason_label, 0, 255 ),
				'cancelled_by_user_id' => $user_id ?: null,
				'updated_at'           => $now,
			),
			array( 'id' => (int) $booking->id ),
			array( '%s', '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);
		if ( false === $ok ) {
			return new WP_Error( 'g2ab_update_failed', __( 'Cancel failed.', 'g2a-booking' ), array( 'status' => 500 ) );
		}

		G2AB_Booking_Activity::log(
			(int) $booking->id,
			'cancelled',
			array( 'status' => $booking->status ),
			array( 'status' => $new_status, 'reason' => $reason_clean, 'reason_text' => $reason_text ),
			sprintf( '%s cancel — %s', $source, $reason_label ),
			$user_id ?: null
		);

		if ( $send_email ) {
			do_action( 'g2ab_booking_cancelled', (int) $booking->id, array(
				'reason'      => $reason_clean,
				'reason_text' => $reason_text,
				'source'      => $source,
				'refunded'    => $mark_refunded,
			) );
		}
		do_action( 'g2ab_booking_status_changed', (int) $booking->id, $new_status, $booking->status );

		return rest_ensure_response( array(
			'success' => true,
			'data'    => array(
				'id'     => (int) $booking->id,
				'status' => $new_status,
			),
		) );
	}

	private function set_status( $booking, $new_status, $action, $user_id ) {
		global $wpdb;
		if ( ! G2AB_Booking_Statuses::is_valid( $new_status ) ) {
			return new WP_Error( 'g2ab_bad_status', __( 'Invalid status.', 'g2a-booking' ), array( 'status' => 400 ) );
		}
		$now = current_time( 'mysql' );
		$wpdb->update(
			$wpdb->prefix . 'g2ab_bookings',
			array( 'status' => $new_status, 'updated_at' => $now ),
			array( 'id' => (int) $booking->id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		G2AB_Booking_Activity::log(
			(int) $booking->id,
			$action,
			array( 'status' => $booking->status ),
			array( 'status' => $new_status ),
			'',
			$user_id ?: null
		);
		do_action( 'g2ab_booking_status_changed', (int) $booking->id, $new_status, $booking->status );
		switch ( $new_status ) {
			case G2AB_Booking_Statuses::NO_SHOW:
				do_action( 'g2ab_booking_no_show', (int) $booking->id );
				break;
			case G2AB_Booking_Statuses::COMPLETED:
				do_action( 'g2ab_booking_completed', (int) $booking->id );
				break;
		}
		return rest_ensure_response( array(
			'success' => true,
			'data'    => array( 'id' => (int) $booking->id, 'status' => $new_status ),
		) );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	private function row_to_event( $row ) {
		$status = (string) $row->status;
		$badge  = '';
		if ( (float) $row->total_amount > 0 ) {
			$paid   = (float) $row->paid_amount >= (float) $row->total_amount;
			$badge  = $paid ? 'paid' : ( (float) $row->paid_amount > 0 ? 'partial' : 'unpaid' );
		} else {
			$badge = 'no_charge';
		}

		return array(
			'id'         => (int) $row->id,
			'uuid'       => $row->uuid,
			'title'      => $this->event_title( $row ),
			'start'      => $this->iso_local( $row->start_at ),
			'end'        => $this->iso_local( $row->end_at ),
			'color'      => G2AB_Booking_Statuses::color( $status ),
			'textColor'  => '#ffffff',
			'editable'   => ! G2AB_Booking_Statuses::is_terminal( $status ),
			'extendedProps' => array(
				'status'         => $status,
				'status_label'   => G2AB_Booking_Statuses::all()[ $status ] ?? $status,
				'payment_badge'  => $badge,
				'payment_mode'   => $row->payment_mode,
				'paid_amount'    => (float) $row->paid_amount,
				'total_amount'   => (float) $row->total_amount,
				'customer_name'  => $row->customer_name,
				'customer_email' => $row->customer_email,
				'party_size'     => (int) $row->party_size,
				'resource_id'    => (int) $row->resource_id,
				'resource_name'  => $row->resource_name,
				'resource_type'  => $row->resource_type,
				'booking_type'   => $row->booking_type_name,
				'category'       => $row->booking_type_category,
				'source'         => $row->source,
				'notes'          => $row->notes,
				'cancel_reason'  => $row->cancel_reason,
			),
		);
	}

	private function event_title( $row ) {
		$name = $row->customer_name ?: __( 'Guest', 'g2a-booking' );
		$res  = $row->resource_name ?: '';
		return $res ? sprintf( '%s — %s', $name, $res ) : $name;
	}

	private function iso_local( $sql_dt ) {
		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( date_default_timezone_get() );
		try {
			$dt = new DateTimeImmutable( (string) $sql_dt, $tz );
			return $dt->format( DateTimeInterface::ATOM );
		} catch ( \Throwable $e ) {
			return (string) $sql_dt;
		}
	}

	private function parse_iso_window( $iso ) {
		if ( '' === $iso ) {
			return null;
		}
		try {
			$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( date_default_timezone_get() );
			$dt = new DateTimeImmutable( $iso, $tz );
			return $dt->format( 'Y-m-d H:i:s' );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	private function normalize_capacity_mode( $mode ) {
		$mode = sanitize_key( (string) $mode );
		if ( in_array( $mode, array( 'party_size', 'seat_based' ), true ) ) {
			return 'party_size';
		}
		return 'booking_count';
	}
}
