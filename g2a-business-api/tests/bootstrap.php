<?php
/**
 * PHPUnit bootstrap. Provides just enough WordPress-shaped stubs for the
 * unit tests to exercise plugin classes without a full WP test suite.
 *
 * Anything more integration-oriented (activator side effects, REST
 * registration, etc.) should live in the WP-native `wordpress-develop`
 * test harness — deliberately out of scope for these smoke tests.
 *
 * @package G2ABA
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'AUTH_KEY' ) ) {
	define( 'AUTH_KEY', 'test-auth-key-for-phpunit-must-be-at-least-32-chars-long' );
}
if ( ! defined( 'G2ABA_VERSION' ) ) {
	define( 'G2ABA_VERSION', '0.1.0-test' );
}
if ( ! defined( 'G2ABA_PATH' ) ) {
	define( 'G2ABA_PATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'G2ABA_REST_NAMESPACE' ) ) {
	define( 'G2ABA_REST_NAMESPACE', 'g2a/v1' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

// Minimal WP function stubs.
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) { return $url; }
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $s ) { return rtrim( (string) $s, '/' ) . '/'; }
}
if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $format ) { return gmdate( $format ); }
}
if ( ! function_exists( '__' ) ) {
	function __( $text ) { return $text; }
}

$GLOBALS['g2aba_test_options']    = array();
$GLOBALS['g2aba_test_transients'] = array();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return $GLOBALS['g2aba_test_options'][ $key ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value ) {
		$GLOBALS['g2aba_test_options'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $key ) {
		unset( $GLOBALS['g2aba_test_options'][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return $GLOBALS['g2aba_test_transients'][ $key ] ?? false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value ) {
		$GLOBALS['g2aba_test_transients'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		unset( $GLOBALS['g2aba_test_transients'][ $key ] );
		return true;
	}
}

// Minimal shims for the WP REST classes our controllers extend/use.
if ( ! class_exists( 'WP_REST_Controller' ) ) {
	abstract class WP_REST_Controller {
		protected $namespace = '';
		public function register_routes() {}
	}
}
if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server {
		public const READABLE  = 'GET';
		public const CREATABLE = 'POST';
	}
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public $status;
		public $headers = array();
		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}
		public function header( $k, $v ) { $this->headers[ $k ] = $v; }
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public $data;
		public function __construct( $code = '', $message = '', $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_message() { return $this->message; }
	}
}

require_once dirname( __DIR__ ) . '/includes/class-autoloader.php';
\WordPressistic\G2ABA\Autoloader::register();
