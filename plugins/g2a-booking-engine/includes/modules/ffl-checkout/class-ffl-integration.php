<?php
/**
 * Advanced FFL Checkout — module bootstrap + public API surface.
 *
 * advanced-ffl-checkout is a same-site WooCommerce plugin that manages
 * online FFL firearm-transfer orders. Before this module existed, the
 * "arrived at dealer" pickup email attached a fixed-guess .ics invite
 * (next business day, 12:00, 30 minutes — a time nobody confirmed). That
 * plugin now links customers to a real scheduling page that calls this
 * module's public API (dealer→resource sync, booking-type setup) plus
 * this plugin's own already-public `/availability` REST route and
 * transactional (`SELECT ... FOR UPDATE`) booking-creation logic — no
 * changes were needed to those for the integration to work.
 *
 * This class owns exactly the two pieces that DID need a real, stable,
 * callable surface instead of a sibling plugin reaching into our raw
 * tables directly: syncing a dealer to a resource, and ensuring the
 * shared "FFL Firearm Pickup" booking type exists. It also closes the
 * loop the other direction — pushing booking status changes back onto
 * the transfer record — which the sibling plugin's original
 * implementation didn't attempt.
 *
 * @package G2AB\Modules\FflCheckout
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class G2AB_Module_Ffl_Checkout {

	private static $instance = null;

	const BOOKING_TYPE_EXTERNAL_REF = 'ffl_pickup_type';

	public static function instance() {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		if ( ! self::ffl_plugin_active() ) return;

		add_action( 'g2ab_booking_status_changed', array( $this, 'on_booking_status_changed' ), 10, 3 );
		add_action( 'g2ab_booking_cancelled',      array( $this, 'on_booking_cancelled' ), 10, 2 );
	}

	/** Is advanced-ffl-checkout active on this same site? */
	public static function ffl_plugin_active() {
		return class_exists( '\WpisticFFL\DB' );
	}

	// ── Public API — the stable surface advanced-ffl-checkout calls ────────

	/**
	 * Find-or-create a resource for a receiving FFL dealer, keyed by
	 * `external_ref = 'ffl_dealer:{license_number}'` — one resource per
	 * dealer, reused across every transfer to that dealer. Seeds a default
	 * Mon-Fri 9-5 availability window ONLY the first time a resource is
	 * created (never overwrites hours a G2AB admin later customizes by
	 * hand for that resource).
	 *
	 * @param array $dealer { license_number, business_name, street, city, state, zip, phone }
	 * @return int Resource id, or 0 on failure (e.g. no license_number given).
	 */
	public static function sync_dealer_resource( array $dealer ) {
		$license = trim( (string) ( $dealer['license_number'] ?? '' ) );
		if ( '' === $license ) return 0;

		global $wpdb;
		$ext_ref   = 'ffl_dealer:' . $license;
		$res_table = $wpdb->prefix . 'g2ab_resources';

		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$res_table} WHERE external_ref = %s LIMIT 1", $ext_ref ) );
		if ( $id ) return $id;

		$now  = current_time( 'mysql' );
		$name = (string) ( $dealer['business_name'] ?? '' ) ?: 'FFL Dealer';
		$slug = sanitize_title( 'ffl-' . $name . '-' . $license );

		$wpdb->insert( $res_table, array(
			'name'         => $name,
			'slug'         => $slug,
			'type'         => 'ffl_dealer',
			'capacity'     => 1,
			'description'  => trim(
				( $dealer['street'] ?? '' ) . ', ' . ( $dealer['city'] ?? '' ) . ', ' . ( $dealer['state'] ?? '' ) . ' ' . ( $dealer['zip'] ?? '' ),
				', '
			),
			'settings'     => wp_json_encode( array(
				'phone'  => (string) ( $dealer['phone'] ?? '' ),
				'source' => 'advanced-ffl-checkout',
			) ),
			'sort_order'   => 0,
			'is_active'    => 1,
			'external_ref' => $ext_ref,
			'created_at'   => $now,
			'updated_at'   => $now,
		) );
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) return 0;

		self::maybe_seed_default_hours( $id );

		return $id;
	}

	private static function maybe_seed_default_hours( $resource_id ) {
		global $wpdb;
		$rules_table = $wpdb->prefix . 'g2ab_availability_rules';
		$existing    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$rules_table} WHERE resource_id = %d", $resource_id ) );
		if ( $existing > 0 ) return;

		$now = current_time( 'mysql' );
		$default_hours = apply_filters( 'g2ab_ffl_checkout_default_hours', array(
			array( 1, '09:00:00', '17:00:00' ), array( 2, '09:00:00', '17:00:00' ), array( 3, '09:00:00', '17:00:00' ),
			array( 4, '09:00:00', '17:00:00' ), array( 5, '09:00:00', '17:00:00' ),
		) );
		foreach ( $default_hours as $rule ) {
			list( $dow, $start, $end ) = $rule;
			$wpdb->insert( $rules_table, array(
				'rule_type'   => 'business_hours',
				'resource_id' => $resource_id,
				'day_of_week' => $dow,
				'start_time'  => $start,
				'end_time'    => $end,
				'priority'    => 10,
				'is_active'   => 1,
				'created_at'  => $now,
			) );
		}
	}

	/**
	 * Find-or-create the one shared booking type used for every FFL pickup
	 * appointment across every dealer — 30 minutes, no price, no waiver
	 * (the actual legal paperwork is the 4473 + NICS flow the FFL plugin
	 * already owns, not a G2AB waiver).
	 *
	 * @return int Booking type id.
	 */
	public static function ensure_pickup_booking_type() {
		global $wpdb;
		$table = $wpdb->prefix . 'g2ab_booking_types';

		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE external_ref = %s LIMIT 1", self::BOOKING_TYPE_EXTERNAL_REF ) );
		if ( $id ) return $id;

		$now = current_time( 'mysql' );
		$wpdb->insert( $table, array(
			'name'            => 'FFL Firearm Pickup',
			'slug'            => 'ffl-firearm-pickup',
			'category'        => 'ffl_pickup',
			'duration_min'    => 30,
			'buffer_before'   => 0,
			'buffer_after'    => 0,
			'base_price'      => 0.00,
			'deposit_amount'  => 0.00,
			'payment_modes'   => 'admin_comp',
			'requires_waiver' => 0,
			'members_only'    => 0,
			'capacity_mode'   => 'booking_count',
			'settings'        => wp_json_encode( array( 'source' => 'advanced-ffl-checkout' ) ),
			'is_active'       => 1,
			'external_ref'    => self::BOOKING_TYPE_EXTERNAL_REF,
			'created_at'      => $now,
			'updated_at'      => $now,
		) );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Validate a candidate start time against this resource's REAL
	 * availability for that day — business hours, blackout dates, and
	 * existing bookings — the same computation `/availability` uses. This
	 * exists so a caller that books via the lighter admin-bookings path
	 * (no lead-time/advance-window/business-hours checks of its own, by
	 * design — see that controller's docblock) can still refuse to create
	 * an appointment outside a real open slot.
	 *
	 * @param int    $resource_id
	 * @param int    $booking_type_id
	 * @param string $start_at 'Y-m-d H:i:s'
	 * @return bool
	 */
	public static function is_real_open_slot( $resource_id, $booking_type_id, $start_at ) {
		if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})(:\d{2})?$/', (string) $start_at, $m ) ) {
			return false;
		}
		$date = $m[1];

		if ( ! class_exists( 'G2AB_REST_Bookings_Controller' ) ) return false;
		if ( ! class_exists( 'WP_REST_Request' ) ) return false;

		$booking_type = null;
		global $wpdb;
		$bt_table = $wpdb->prefix . 'g2ab_booking_types';
		$duration = (int) $wpdb->get_var( $wpdb->prepare( "SELECT duration_min FROM {$bt_table} WHERE id = %d", (int) $booking_type_id ) );
		$duration = $duration ?: 30;

		$request = new WP_REST_Request( 'GET', '/g2a-booking/v1/availability' );
		$request->set_param( 'resource_id', (int) $resource_id );
		$request->set_param( 'date', $date );
		$request->set_param( 'duration', $duration );
		$request->set_param( 'booking_type_id', (int) $booking_type_id );
		$request->set_param( 'party_size', 1 );

		$controller = new G2AB_REST_Bookings_Controller();
		$response   = $controller->get_availability( $request );
		if ( is_wp_error( $response ) ) return false;

		$data = $response instanceof WP_REST_Response ? $response->get_data() : $response;
		$body = isset( $data['data'] ) ? $data['data'] : $data;
		if ( empty( $body['slots'] ) || ! empty( $body['closed'] ) ) return false;

		$normalized_start = str_replace( 'T', ' ', (string) $start_at );
		if ( 16 === strlen( $normalized_start ) ) $normalized_start .= ':00';

		foreach ( $body['slots'] as $slot ) {
			if ( isset( $slot['start'], $slot['available'] ) && $slot['start'] === $normalized_start && $slot['available'] ) {
				return true;
			}
		}
		return false;
	}

	// ── Push booking outcome back onto the transfer record ─────────────────

	public function on_booking_status_changed( $booking_id, $new_status, $old_status ) {
		$this->maybe_sync_transfer( $booking_id, $new_status );
	}

	public function on_booking_cancelled( $booking_id, $reason ) {
		$this->maybe_sync_transfer( $booking_id, 'cancelled' );
	}

	private function maybe_sync_transfer( $booking_id, $status ) {
		global $wpdb;
		$booking = $wpdb->get_row( $wpdb->prepare(
			"SELECT b.uuid, b.start_at, r.external_ref
			 FROM {$wpdb->prefix}g2ab_bookings b
			 LEFT JOIN {$wpdb->prefix}g2ab_resources r ON r.id = b.resource_id
			 WHERE b.id = %d", (int) $booking_id
		), ARRAY_A );
		if ( ! $booking || empty( $booking['external_ref'] ) || 0 !== strpos( (string) $booking['external_ref'], 'ffl_dealer:' ) ) {
			return; // Not an FFL-checkout resource — not ours to touch.
		}

		$ffl_table = $wpdb->prefix . 'wpistic_ffl_transfers';
		$transfer  = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$ffl_table} WHERE booking_appointment_id = %s LIMIT 1", (string) $booking['uuid']
		), ARRAY_A );
		if ( ! $transfer ) {
			return; // Booking wasn't confirmed through the FFL scheduling flow.
		}

		if ( 'cancelled' === $status ) {
			$wpdb->update( $ffl_table, array(
				'booking_appointment_id' => '',
				'booking_slot_start'     => null,
				'updated_at'             => current_time( 'mysql' ),
			), array( 'id' => (int) $transfer['id'] ) );
		} else {
			$wpdb->update( $ffl_table, array(
				'booking_slot_start' => (string) $booking['start_at'],
				'updated_at'         => current_time( 'mysql' ),
			), array( 'id' => (int) $transfer['id'] ) );
		}

		$events_table = $wpdb->prefix . 'wpistic_ffl_events';
		$wpdb->insert( $events_table, array(
			'transfer_id' => (int) $transfer['id'],
			'event_type'  => 'pickup_appointment_' . sanitize_key( (string) $status ),
			'notes'       => sprintf( 'g2a-booking-engine reported appointment status "%s".', $status ),
			'actor'       => 'g2a-booking-engine',
			'actor_ip'    => '',
		) );
	}
}
