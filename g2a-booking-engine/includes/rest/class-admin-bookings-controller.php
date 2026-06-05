<?php
/**
 * REST: staff-created bookings.
 *
 * Endpoint:
 *   POST /admin/bookings  — create a booking on behalf of a customer
 *
 * Foundation only — wired into the new Manual Booking admin page in 1.2.4 and
 * later reused by the v1.3.0 calendar's "quick add" popup. Intentionally
 * lighter than the public POST /bookings: no rate-limit, no waiver gate, and
 * staff can choose `in_store`, `cash`, `card_terminal`, or `admin_comp`.
 *
 * Permission: requires manage_g2ab_bookings + a verified nonce.
 *
 * @package G2AB
 * @since   1.2.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_REST_Admin_Bookings_Controller {

	const ALLOWED_PAYMENT_MODES = array( 'in_store', 'cash', 'card_terminal', 'admin_comp', 'free' );
	const ALLOWED_SOURCES       = array( 'admin', 'phone', 'walk_in', 'staff' );

	public function register_routes() {
		register_rest_route( G2AB_REST_NAMESPACE, '/admin/bookings', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'create_booking' ),
			'permission_callback' => array( $this, 'permission_create' ),
		) );
	}

	public function permission_create( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'g2ab_invalid_nonce', __( 'Invalid or missing nonce.', 'g2a-booking' ), array( 'status' => 403 ) );
		}
		if ( ! current_user_can( 'manage_g2ab_bookings' ) ) {
			return new WP_Error( 'g2ab_forbidden', __( 'You are not allowed to create bookings on behalf of customers.', 'g2a-booking' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public function create_booking( WP_REST_Request $request ) {
		global $wpdb;

		$booking_type_id = (int) $request->get_param( 'booking_type_id' );
		$resource_id     = (int) $request->get_param( 'resource_id' );
		$start_at        = sanitize_text_field( (string) $request->get_param( 'start_at' ) );
		// The Manual Booking form uses an <input type="datetime-local">, whose
		// browser-submitted .value carries a literal "T" separator
		// (YYYY-MM-DDTHH:MM) rather than the space-separated form this method
		// validates and stores. Without this normalisation every manual booking
		// failed the regex below with "Invalid start time." Trim and collapse
		// the separator so both "T" and space inputs are accepted.
		$start_at = str_replace( 'T', ' ', trim( $start_at ) );
		$party_size      = max( 1, (int) ( $request->get_param( 'party_size' ) ?: 1 ) );
		$payment_mode    = sanitize_key( (string) ( $request->get_param( 'payment_mode' ) ?: 'in_store' ) );
		$source          = sanitize_key( (string) ( $request->get_param( 'source' ) ?: 'admin' ) );
		$notes           = sanitize_textarea_field( (string) $request->get_param( 'notes' ) );
		$customer_name   = sanitize_text_field( (string) $request->get_param( 'customer_name' ) );
		$customer_email  = sanitize_email( (string) $request->get_param( 'customer_email' ) );
		$customer_phone  = sanitize_text_field( (string) $request->get_param( 'customer_phone' ) );
		$send_email      = (bool) $request->get_param( 'send_email' );
		$skip_waiver     = (bool) $request->get_param( 'skip_waiver' );

		if ( ! in_array( $payment_mode, self::ALLOWED_PAYMENT_MODES, true ) ) {
			$payment_mode = 'in_store';
		}
		if ( ! in_array( $source, self::ALLOWED_SOURCES, true ) ) {
			$source = 'admin';
		}

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

		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( date_default_timezone_get() );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $start_at ) ) {
			return new WP_Error( 'g2ab_invalid_time', __( 'Invalid start time.', 'g2a-booking' ), array( 'status' => 400 ) );
		}
		if ( strlen( $start_at ) === 16 ) {
			$start_at .= ':00';
		}
		$start_dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $start_at, $tz );
		if ( ! $start_dt || $start_dt->format( 'Y-m-d H:i:s' ) !== $start_at ) {
			return new WP_Error( 'g2ab_invalid_time', __( 'Invalid start time.', 'g2a-booking' ), array( 'status' => 400 ) );
		}

		$duration_min     = (int) $booking_type->duration_min;
		$end_dt           = $start_dt->modify( '+' . $duration_min . ' minutes' );
		$buffer_before    = (int) $booking_type->buffer_before;
		$buffer_after     = (int) $booking_type->buffer_after;
		$capacity_mode    = ( 'party_size' === sanitize_key( $booking_type->capacity_mode ?? '' ) || 'seat_based' === sanitize_key( $booking_type->capacity_mode ?? '' ) ) ? 'party_size' : 'booking_count';
		$start_eff_sql    = $start_dt->modify( '-' . max( 0, $buffer_before ) . ' minutes' )->format( 'Y-m-d H:i:s' );
		$end_eff_sql      = $end_dt->modify( '+' . max( 0, $buffer_after ) . ' minutes' )->format( 'Y-m-d H:i:s' );
		$start_sql        = $start_dt->format( 'Y-m-d H:i:s' );
		$end_sql          = $end_dt->format( 'Y-m-d H:i:s' );

		if ( '' === $customer_name ) {
			return new WP_Error( 'g2ab_missing_name', __( 'Customer name is required.', 'g2a-booking' ), array( 'status' => 400 ) );
		}
		if ( '' !== $customer_email && ! is_email( $customer_email ) ) {
			return new WP_Error( 'g2ab_invalid_email', __( 'Invalid customer email.', 'g2a-booking' ), array( 'status' => 400 ) );
		}

		$capacity        = max( 1, (int) $resource->capacity );
		$bookings_table  = $wpdb->prefix . 'g2ab_bookings';
		$logs_table      = $wpdb->prefix . 'g2ab_logs';

		$wpdb->query( 'START TRANSACTION' );

		$load_select = ( 'party_size' === $capacity_mode ) ? 'COALESCE(SUM(b.party_size), 0)' : 'COUNT(*)';
		$current_load = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT {$load_select} FROM {$bookings_table} b
			   LEFT JOIN {$bt_table} bt ON bt.id = b.booking_type_id
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
			return new WP_Error( 'g2ab_slot_full', __( 'This time slot is full.', 'g2a-booking' ), array( 'status' => 409 ) );
		}

		$status = ( 'admin_comp' === $payment_mode || 'free' === $payment_mode ) ? 'confirmed' : 'reserved';
		$total  = (float) $booking_type->base_price * $party_size;
		if ( 'admin_comp' === $payment_mode ) {
			$total = 0.0;
		}

		$now_mysql = current_time( 'mysql' );
		$uuid      = wp_generate_uuid4();
		$inserted  = $wpdb->insert( $bookings_table, array(
			'uuid'             => $uuid,
			'booking_type_id'  => $booking_type_id,
			'resource_id'      => $resource_id,
			'user_id'          => null,
			'customer_name'    => $customer_name,
			'customer_email'   => $customer_email,
			'customer_phone'   => $customer_phone,
			'start_at'         => $start_sql,
			'end_at'           => $end_sql,
			'duration_min'     => $duration_min,
			'party_size'       => $party_size,
			'status'           => $status,
			'payment_mode'     => $payment_mode,
			'total_amount'     => $total,
			'paid_amount'      => 0,
			'currency'         => get_option( 'g2ab_currency', 'USD' ),
			'form_data'        => wp_json_encode( array() ),
			'metadata'         => wp_json_encode( array(
				'admin_created_by' => get_current_user_id(),
				'payment_mode'     => $payment_mode,
				'capacity_mode'    => $capacity_mode,
			) ),
			'notes'            => $notes,
			'waiver_signed'    => $skip_waiver ? 1 : 0,
			'source'           => $source,
			'created_by'       => get_current_user_id(),
			'created_at'       => $now_mysql,
			'updated_at'       => $now_mysql,
		), array(
			'%s','%d','%d','%d','%s','%s','%s','%s','%s','%d','%d','%s','%s','%f','%f','%s','%s','%s','%s','%d','%s','%d','%s','%s',
		) );

		if ( false === $inserted ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'g2ab_insert_failed', __( 'Could not save booking.', 'g2a-booking' ), array( 'status' => 500 ) );
		}
		$booking_id = (int) $wpdb->insert_id;
		$wpdb->query( 'COMMIT' );

		$wpdb->insert( $logs_table, array(
			'booking_id' => $booking_id,
			'user_id'    => get_current_user_id(),
			'event_type' => 'admin_created',
			'severity'   => 'info',
			'message'    => sprintf( 'Manual booking created by staff (source=%s, payment=%s).', $source, $payment_mode ),
			'context'    => wp_json_encode( array(
				'resource_id'     => $resource_id,
				'booking_type_id' => $booking_type_id,
				'send_email'      => $send_email,
			) ),
			'created_at' => $now_mysql,
		) );

		// Lifecycle hooks. Email automation listens to these; the controller
		// honors the `send_email` flag by suppressing the hook when staff
		// chose not to email the customer.
		if ( $send_email ) {
			do_action( 'g2ab_booking_created', $booking_id, array(
				'uuid'        => $uuid,
				'resource_id' => $resource_id,
				'start_at'    => $start_sql,
				'source'      => $source,
			) );
			if ( 'confirmed' === $status ) {
				do_action( 'g2ab_booking_confirmed', $booking_id );
				do_action( 'g2ab_booking_status_changed', $booking_id, 'confirmed', 'created' );
			}
		}

		return rest_ensure_response( array(
			'success' => true,
			'data'    => array(
				'id'           => $booking_id,
				'uuid'         => $uuid,
				'status'       => $status,
				'start_at'     => $start_sql,
				'end_at'       => $end_sql,
				'resource'     => $resource->name,
				'payment_mode' => $payment_mode,
				'total'        => number_format( $total, 2 ),
			),
		) );
	}
}
