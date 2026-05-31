<?php
/**
 * Verifyistic ↔ G2A Booking integration.
 *
 * Reads the `verifyistic_verified` cookie that the Verifyistic plugin sets on
 * successful age verification. On booking creation we look up the matching
 * verification log row (by token, same site, recent) and:
 *
 *   - Stamp the booking's metadata with the verify_token + age + dob.
 *   - Optionally treat the waiver checkbox as satisfied automatically
 *     ("Age verified at {time}" rather than re-asking on every booking).
 *   - Optionally REFUSE to create bookings when the visitor isn't verified.
 *
 * Also exposes a small REST endpoint that the frontend script can poll to
 * pre-fill the customer name from the verification record.
 *
 * @package G2AB\Modules\Verifyistic
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class G2AB_Module_Verifyistic {

	const OPT_ENABLED              = 'g2ab_verifyistic_enabled';
	const OPT_REQUIRE_VERIFICATION = 'g2ab_verifyistic_require_before_booking';
	const OPT_AUTO_WAIVER          = 'g2ab_verifyistic_auto_waiver';
	const OPT_AUTOFILL_NAME        = 'g2ab_verifyistic_autofill_name';
	const OPT_MAX_AGE              = 'g2ab_verifyistic_link_max_age_minutes';

	const COOKIE_NAME = 'verifyistic_verified';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_option( self::OPT_ENABLED, 1 );
		add_option( self::OPT_REQUIRE_VERIFICATION, 0 );
		add_option( self::OPT_AUTO_WAIVER, 1 );
		add_option( self::OPT_AUTOFILL_NAME, 1 );
		add_option( self::OPT_MAX_AGE, 60 ); // minutes — token must be linked to a verification this recent.

		if ( is_admin() ) {
			new G2AB_Verifyistic_Settings();
		}

		// Block booking creation on the REST side if "require verification" is on.
		add_filter( 'rest_pre_dispatch', array( $this, 'maybe_block_unverified_booking' ), 10, 3 );

		// Enrich the booking row immediately after creation.
		add_action( 'g2ab_booking_created', array( $this, 'enrich_booking_with_verification' ), 10, 2 );

		// Tiny REST endpoint to pre-fill the frontend form.
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function is_enabled() {
		return 1 === (int) get_option( self::OPT_ENABLED, 1 );
	}

	public function verifyistic_active() {
		return class_exists( 'Verifyistic_DB' ) || class_exists( 'Verifyistic_Frontend' );
	}

	/**
	 * Read the verification record for the current visitor.
	 *
	 * Strategy:
	 *   1. Pull token from the `verifyistic_verified` cookie.
	 *   2. Look up `wp_verifyistic_logs` by token, status=passed.
	 *   3. Drop the record if it's older than g2ab_verifyistic_link_max_age_minutes.
	 *
	 * @return object|null Row from wp_verifyistic_logs, or null.
	 */
	public function get_current_verification() {
		if ( ! $this->is_enabled() ) return null;
		if ( ! $this->verifyistic_active() ) return null;

		$cookie_token = isset( $_COOKIE[ self::COOKIE_NAME ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) ) : '';
		// SECURITY (compliance): reject empty cookies and the legacy literal "1"
		// sentinel. Any real verification token has length >= 16 and is alnum-ish;
		// a single digit is not a verification — accepting it allowed trivial
		// DevTools bypass of the age gate for an FFL/firearms range.
		if ( '' === $cookie_token || strlen( $cookie_token ) < 16 || ! preg_match( '/^[A-Za-z0-9_\-]+$/', $cookie_token ) ) {
			return null;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'verifyistic_logs';
		// Verifyistic plugin must be installed for this table to exist.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) return null;

		$max_age_minutes = max( 1, (int) get_option( self::OPT_MAX_AGE, 60 ) );
		// Verifyistic cookie outlives the session (configurable cookie_days), so
		// the lookup is the source of truth — not the cookie expiry. We accept
		// any "passed" row matching the token regardless of age unless an
		// explicit max-age window is set in our own settings.
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE verify_token = %s AND status = 'passed' ORDER BY id DESC LIMIT 1",
			$cookie_token
		) );

		if ( ! $row ) return null;

		// Optional freshness window — when set, ignore stale tokens.
		if ( $max_age_minutes < 60 * 24 * 365 ) {
			$verified_ts = strtotime( (string) $row->verified_at );
			if ( $verified_ts && ( time() - $verified_ts ) > $max_age_minutes * 60 ) {
				return null;
			}
		}

		return $row;
	}

	/**
	 * Block the /bookings POST when "require verification" is on AND the
	 * visitor doesn't have a valid verification.
	 *
	 * Filters early so the controller never even gets called.
	 */
	public function maybe_block_unverified_booking( $result, $server, $request ) {
		if ( ! is_null( $result ) ) {
			return $result; // someone earlier already short-circuited
		}
		if ( ! $this->is_enabled() ) return $result;
		if ( 1 !== (int) get_option( self::OPT_REQUIRE_VERIFICATION, 0 ) ) return $result;

		$route  = (string) $request->get_route();
		$method = strtoupper( (string) $request->get_method() );
		// Only guard the booking-creation endpoint.
		if ( 'POST' !== $method || false === strpos( $route, '/bookings' ) ) {
			return $result;
		}
		// Skip if the request looks like /bookings/{uuid}/confirm-payment (not actual creation).
		if ( preg_match( '#/bookings/[a-f0-9-]{36}/#', $route ) ) {
			return $result;
		}

		// Allow logged-in staff to skip the check (they may be making admin bookings).
		if ( is_user_logged_in() && current_user_can( 'manage_g2ab_bookings' ) ) {
			return $result;
		}

		$verification = $this->get_current_verification();
		if ( $verification ) {
			return $result;
		}

		return new WP_Error(
			'g2ab_age_verification_required',
			__( 'Please complete the age verification popup before booking.', 'g2a-booking' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Stamp the booking row with the verification details after it's created.
	 */
	public function enrich_booking_with_verification( $booking_id, $context ) {
		if ( ! $this->is_enabled() ) return;

		$verification = $this->get_current_verification();
		if ( ! $verification ) return;

		global $wpdb;
		$table  = $wpdb->prefix . 'g2ab_bookings';
		$row    = $wpdb->get_row( $wpdb->prepare( "SELECT metadata, waiver_signed FROM {$table} WHERE id = %d", (int) $booking_id ) );
		if ( ! $row ) return;

		$meta = json_decode( (string) $row->metadata, true );
		if ( ! is_array( $meta ) ) $meta = array();

		$meta['verifyistic'] = array(
			'token'       => sanitize_text_field( (string) $verification->verify_token ),
			'type'        => sanitize_key( (string) ( $verification->verify_type ?? 'dob' ) ),
			'verified_at' => sanitize_text_field( (string) ( $verification->verified_at ?? current_time( 'mysql' ) ) ),
			'age'         => (int) ( $verification->age_at_verify ?? 0 ),
			'dob'         => ! empty( $verification->dob ) ? sanitize_text_field( (string) $verification->dob ) : '',
			'first_name'  => sanitize_text_field( (string) ( $verification->first_name ?? '' ) ),
			'last_name'   => sanitize_text_field( (string) ( $verification->last_name ?? '' ) ),
		);

		$update = array(
			'metadata'   => wp_json_encode( $meta ),
			'updated_at' => current_time( 'mysql' ),
		);
		$format = array( '%s', '%s' );

		if ( 1 === (int) get_option( self::OPT_AUTO_WAIVER, 1 ) && empty( $row->waiver_signed ) ) {
			$update['waiver_signed'] = 1;
			$format[]                = '%d';
		}

		$wpdb->update( $table, $update, array( 'id' => (int) $booking_id ), $format, array( '%d' ) );

		// Audit log entry.
		$wpdb->insert( $wpdb->prefix . 'g2ab_logs', array(
			'booking_id' => (int) $booking_id,
			'event_type' => 'age_verified',
			'severity'   => 'info',
			'message'    => sprintf(
				/* translators: 1: verify type, 2: token tail */
				__( 'Booking linked to Verifyistic verification (type=%1$s, token=…%2$s).', 'g2a-booking' ),
				$verification->verify_type ?? 'unknown',
				substr( (string) $verification->verify_token, -8 )
			),
			'context'    => wp_json_encode( $meta['verifyistic'] ),
			'created_at' => current_time( 'mysql' ),
		) );
	}

	public function register_routes() {
		register_rest_route( G2AB_REST_NAMESPACE, '/verifyistic/me', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'rest_current_verification' ),
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * Returns the current visitor's verification record (sanitised) so the
	 * frontend can pre-fill the booking form.
	 */
	public function rest_current_verification( $request ) {
		$verification = $this->get_current_verification();
		if ( ! $verification ) {
			return rest_ensure_response( array( 'success' => true, 'data' => array( 'verified' => false ) ) );
		}

		$autofill = 1 === (int) get_option( self::OPT_AUTOFILL_NAME, 1 );

		return rest_ensure_response( array(
			'success' => true,
			'data'    => array(
				'verified'    => true,
				'verified_at' => $verification->verified_at ?? '',
				'first_name'  => $autofill ? sanitize_text_field( (string) ( $verification->first_name ?? '' ) ) : '',
				'last_name'   => $autofill ? sanitize_text_field( (string) ( $verification->last_name ?? '' ) ) : '',
				'age'         => (int) ( $verification->age_at_verify ?? 0 ),
				'type'        => sanitize_key( (string) ( $verification->verify_type ?? 'dob' ) ),
			),
		) );
	}
}
