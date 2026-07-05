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
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
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
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ) {
		$t = strtolower( (string) $title );
		$t = preg_replace( '/[^a-z0-9-]+/', '-', $t );
		return trim( (string) $t, '-' );
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return $GLOBALS['g2aba_test_current_user_id'] ?? 0; }
}

// wp_mail stub for Email_Sender tests. Tests can override the outcome per-case
// by setting $GLOBALS['g2aba_test_wp_mail_return']. Every call is captured.
$GLOBALS['g2aba_test_wp_mail_return'] = true;
$GLOBALS['g2aba_test_wp_mail_calls']  = array();
if ( ! function_exists( 'wp_mail' ) ) {
	function wp_mail( $to, $subject, $body, $headers = array() ) {
		$GLOBALS['g2aba_test_wp_mail_calls'][] = array(
			'to'      => $to,
			'subject' => $subject,
			'body'    => $body,
			'headers' => $headers,
		);
		return $GLOBALS['g2aba_test_wp_mail_return'];
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( ...$args ) {
		$GLOBALS['g2aba_test_actions'][] = $args;
	}
}
$GLOBALS['g2aba_test_actions'] = array();

// Cron stubs for Automation_Store + Agent tests.
$GLOBALS['g2aba_test_cron_scheduled']   = array();  // hook => [ts, interval]
$GLOBALS['g2aba_test_cron_single']      = array();  // hook => [[ts, args], ...]
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $ts, $interval, $hook, $args = array() ) {
		$GLOBALS['g2aba_test_cron_scheduled'][ $hook ] = array( 'ts' => $ts, 'interval' => $interval, 'args' => $args );
		return true;
	}
}
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $ts, $hook, $args = array() ) {
		$GLOBALS['g2aba_test_cron_single'][] = array( 'ts' => $ts, 'hook' => $hook, 'args' => $args );
		return true;
	}
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook, $args = array() ) {
		return isset( $GLOBALS['g2aba_test_cron_scheduled'][ $hook ] )
			? $GLOBALS['g2aba_test_cron_scheduled'][ $hook ]['ts']
			: false;
	}
}
if ( ! function_exists( 'wp_unschedule_event' ) ) {
	function wp_unschedule_event( $ts, $hook, $args = array() ) {
		unset( $GLOBALS['g2aba_test_cron_scheduled'][ $hook ] );
		return true;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ) { $GLOBALS['g2aba_test_added_actions'][] = $args; }
}
$GLOBALS['g2aba_test_added_actions'] = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( ...$args ) { $GLOBALS['g2aba_test_added_filters'][] = $args; }
}
$GLOBALS['g2aba_test_added_filters'] = array();

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) { return $value; }
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
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private array $params;
		private array $body;
		public function __construct( array $params = array(), array $body = array() ) {
			$this->params = $params;
			$this->body   = $body;
		}
		public function get_param( $key ) {
			return $this->params[ $key ] ?? null;
		}
		public function get_json_params() {
			return $this->body;
		}
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

// Stubs added for the leads pipeline (Leads_Installer / Leads_Repository /
// Lead_Ingestion) — a real $wpdb table needs ARRAY_A, current_time(),
// dbDelta(), and is_email() which nothing in this suite required before.
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type = 'mysql' ) {
		return 'timestamp' === $type ? time() : gmdate( 'Y-m-d H:i:s' );
	}
}
if ( ! function_exists( 'is_email' ) ) {
	function is_email( $email ) {
		return (bool) filter_var( (string) $email, FILTER_VALIDATE_EMAIL );
	}
}
if ( ! function_exists( 'dbDelta' ) ) {
	function dbDelta( $sql ) {
		$GLOBALS['g2aba_test_dbdelta_calls'][] = $sql;
		return array();
	}
}
$GLOBALS['g2aba_test_dbdelta_calls'] = array();

require_once dirname( __DIR__ ) . '/includes/class-autoloader.php';
\WordPressistic\G2ABA\Autoloader::register();
