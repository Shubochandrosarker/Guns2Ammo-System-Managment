<?php
/**
 * PHPUnit bootstrap for g2a-booking-engine unit tests.
 *
 * No live WordPress: the WP functions the tested service classes touch are
 * stubbed here with small, deterministic, resettable implementations.
 * Keep this file minimal — add stubs on demand as tests need them.
 */

error_reporting( E_ALL );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/wordpress/' );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

/* ── Test-state registry (reset between tests via g2ab_tests_reset_state) ── */

$GLOBALS['g2ab_test_filters']   = array();
$GLOBALS['g2ab_test_actions']   = array(); // do_action() collector.
$GLOBALS['g2ab_test_options']   = array();
$GLOBALS['g2ab_test_user_meta'] = array();
$GLOBALS['g2ab_test_user_id']   = 0;
$GLOBALS['g2ab_test_user_can']  = false;

function g2ab_tests_reset_state(): void {
	$GLOBALS['g2ab_test_filters']   = array();
	$GLOBALS['g2ab_test_actions']   = array();
	$GLOBALS['g2ab_test_options']   = array();
	$GLOBALS['g2ab_test_user_meta'] = array();
	$GLOBALS['g2ab_test_user_id']   = 0;
	$GLOBALS['g2ab_test_user_can']  = false;
}

/* ── Hooks: apply_filters returns the filtered value via a simple registry ── */

function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['g2ab_test_filters'][ $tag ][ (int) $priority ][] = array( $callback, (int) $accepted_args );
	return true;
}

function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	return add_filter( $tag, $callback, $priority, $accepted_args );
}

function apply_filters( $tag, $value, ...$args ) {
	if ( empty( $GLOBALS['g2ab_test_filters'][ $tag ] ) ) {
		return $value;
	}
	$priorities = $GLOBALS['g2ab_test_filters'][ $tag ];
	ksort( $priorities );
	foreach ( $priorities as $callbacks ) {
		foreach ( $callbacks as list( $callback, $accepted_args ) ) {
			$all   = array_merge( array( $value ), $args );
			$value = call_user_func_array( $callback, array_slice( $all, 0, max( 1, $accepted_args ) ) );
		}
	}
	return $value;
}

/** No-op collector: records every fired action for assertions. */
function do_action( $tag, ...$args ) {
	$GLOBALS['g2ab_test_actions'][] = array( 'tag' => $tag, 'args' => $args );
}

/* ── Options (array store) ── */

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['g2ab_test_options'] ) ? $GLOBALS['g2ab_test_options'][ $name ] : $default;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['g2ab_test_options'][ $name ] = $value;
	return true;
}

function add_option( $name, $value, $deprecated = '', $autoload = 'yes' ) {
	if ( array_key_exists( $name, $GLOBALS['g2ab_test_options'] ) ) {
		return false;
	}
	$GLOBALS['g2ab_test_options'][ $name ] = $value;
	return true;
}

function delete_option( $name ) {
	unset( $GLOBALS['g2ab_test_options'][ $name ] );
	return true;
}

/* ── Sanitizers / escapers ── */

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function sanitize_text_field( $str ) {
	$str = (string) $str;
	$str = preg_replace( '/<[^>]*>/', '', $str );
	$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
	return trim( $str );
}

function sanitize_email( $email ) {
	return (string) filter_var( trim( (string) $email ), FILTER_SANITIZE_EMAIL );
}

function is_email( $email ) {
	return false !== filter_var( (string) $email, FILTER_VALIDATE_EMAIL ) ? (string) $email : false;
}

function esc_sql( $data ) {
	return addslashes( (string) $data );
}

function absint( $maybeint ) {
	return abs( (int) $maybeint );
}

function esc_url_raw( $url, $protocols = null ) {
	return (string) $url;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_html__( $text, $domain = 'default' ) {
	return esc_html( $text );
}

function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

/* ── i18n ── */

function __( $text, $domain = 'default' ) { // phpcs:ignore
	return $text;
}

/* ── Time / formatting ── */

function wp_timezone() {
	return new DateTimeZone( 'UTC' );
}

function current_time( $type, $gmt = 0 ) {
	if ( 'timestamp' === $type ) {
		return time();
	}
	if ( 'mysql' === $type ) {
		return gmdate( 'Y-m-d H:i:s' );
	}
	return gmdate( (string) $type );
}

function wp_date( $format, $timestamp = null, $timezone = null ) {
	$timestamp = null === $timestamp ? time() : (int) $timestamp;
	$timezone  = $timezone instanceof DateTimeZone ? $timezone : wp_timezone();
	$dt        = ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $timezone );
	return $dt->format( (string) $format );
}

function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	return json_encode( $data, $options, $depth ); // phpcs:ignore
}

function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
	$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
	if ( $special_chars ) {
		$chars .= '!@#$%^&*()';
	}
	if ( $extra_special_chars ) {
		$chars .= '-_ []{}<>~`+=,.;:/?|';
	}
	$password = '';
	for ( $i = 0; $i < (int) $length; $i++ ) {
		$password .= substr( $chars, random_int( 0, strlen( $chars ) - 1 ), 1 );
	}
	return $password;
}

/* ── URLs ── */

function home_url( $path = '', $scheme = null ) {
	return 'https://example.test' . ( $path ? '/' . ltrim( (string) $path, '/' ) : '' );
}

function add_query_arg( ...$args ) {
	if ( is_array( $args[0] ) ) {
		$params = $args[0];
		$url    = (string) ( $args[1] ?? '' );
	} else {
		$params = array( $args[0] => $args[1] );
		$url    = (string) ( $args[2] ?? '' );
	}
	$sep = str_contains( $url, '?' ) ? '&' : '?';
	return $url . $sep . http_build_query( $params );
}

/* ── Users / caps (configurable) ── */

function get_current_user_id() {
	return (int) $GLOBALS['g2ab_test_user_id'];
}

function current_user_can( $capability, ...$args ) {
	$can = $GLOBALS['g2ab_test_user_can'];
	return is_callable( $can ) ? (bool) $can( $capability, ...$args ) : (bool) $can;
}

function is_user_logged_in() {
	return get_current_user_id() > 0;
}

function get_user_meta( $user_id, $key = '', $single = false ) {
	$store = $GLOBALS['g2ab_test_user_meta'][ (int) $user_id ] ?? array();
	if ( '' === $key ) {
		return $store;
	}
	if ( ! array_key_exists( $key, $store ) ) {
		return $single ? '' : array();
	}
	return $single ? $store[ $key ] : array( $store[ $key ] );
}

function update_user_meta( $user_id, $meta_key, $meta_value, $prev_value = '' ) {
	$GLOBALS['g2ab_test_user_meta'][ (int) $user_id ][ $meta_key ] = $meta_value;
	return true;
}

/* ── WP_Error ── */

class WP_Error {
	public $errors     = array();
	public $error_data = array();

	public function __construct( $code = '', $message = '', $data = '' ) {
		if ( '' !== $code ) {
			$this->add( $code, $message, $data );
		}
	}

	public function add( $code, $message, $data = '' ) {
		$this->errors[ $code ][] = $message;
		if ( ! empty( $data ) ) {
			$this->error_data[ $code ] = $data;
		}
	}

	public function get_error_code() {
		$codes = array_keys( $this->errors );
		return $codes ? $codes[0] : '';
	}

	public function get_error_message( $code = '' ) {
		$code = $code ? $code : $this->get_error_code();
		return isset( $this->errors[ $code ][0] ) ? $this->errors[ $code ][0] : '';
	}

	public function get_error_data( $code = '' ) {
		$code = $code ? $code : $this->get_error_code();
		return $this->error_data[ $code ] ?? null;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/* ── Load the service classes under test ─────────────────────────────────────
 * DB-touching methods (G2AB_Booking_Transitions::transition(), the email
 * endpoint handlers, …) are intentionally NOT exercised by unit tests; only
 * pure policy/validation methods run here.
 */

define( 'G2AB_TESTS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

// Pure helpers (option lists, token hashing, CIDR maths). Nothing here runs
// at load time; the DB-touching helpers are simply never called by a unit test.
require_once G2AB_TESTS_PLUGIN_DIR . 'includes/helpers/functions.php';
require_once G2AB_TESTS_PLUGIN_DIR . 'includes/services/class-booking-statuses.php';
require_once G2AB_TESTS_PLUGIN_DIR . 'includes/services/class-booking-transitions.php';
require_once G2AB_TESTS_PLUGIN_DIR . 'includes/services/class-checkout-policy.php';
require_once G2AB_TESTS_PLUGIN_DIR . 'includes/class-booking-visibility.php';
require_once G2AB_TESTS_PLUGIN_DIR . 'includes/class-payment-validator.php';
require_once G2AB_TESTS_PLUGIN_DIR . 'includes/payments/class-stripe.php';
require_once G2AB_TESTS_PLUGIN_DIR . 'includes/services/class-email-actions.php';
// Parsed only for its class definition: IdempotencyFingerprintTest reflects
// on the private booking_idempotency_key() contract. No route registration
// or WP REST classes are needed for that.
require_once G2AB_TESTS_PLUGIN_DIR . 'includes/rest/class-bookings-controller.php';
