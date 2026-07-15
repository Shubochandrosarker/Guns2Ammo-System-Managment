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

// Stubs added for the cookie-session auth phase (Session_Store /
// Session_Auth / Auth_Session_Controller) — controllable via globals the
// same way the wp_mail/cron stubs above are.
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( preg_replace( '/[\r\n\t ]+/', ' ', (string) $str ) );
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}
if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public $ID           = 0;
		public $user_login   = '';
		public $user_email   = '';
		public $user_pass    = '';
		public $display_name = '';
		public $roles        = array();
		public function __construct( $id = 0, $login = '', $email = '' ) {
			$this->ID         = $id;
			$this->user_login = $login;
			$this->user_email = $email;
		}
	}
}
// Per-test capability map: $GLOBALS['g2aba_test_user_caps'][<user id>] = ['g2a_dashboard', ...].
$GLOBALS['g2aba_test_user_caps'] = array();
if ( ! function_exists( 'user_can' ) ) {
	function user_can( $user, $cap ) {
		$id   = $user instanceof WP_User ? $user->ID : (int) $user;
		$caps = $GLOBALS['g2aba_test_user_caps'][ $id ] ?? array();
		return in_array( $cap, $caps, true );
	}
}
// wp_authenticate outcome per test: a WP_User, a WP_Error, or unset (= error).
if ( ! function_exists( 'wp_authenticate' ) ) {
	function wp_authenticate( $username, $password ) {
		$GLOBALS['g2aba_test_wp_authenticate_calls'][] = array( 'username' => $username );
		return $GLOBALS['g2aba_test_wp_authenticate'] ?? new WP_Error( 'invalid_username', 'Unknown user.' );
	}
}
$GLOBALS['g2aba_test_wp_authenticate_calls'] = array();
if ( ! function_exists( 'wp_authenticate_application_password' ) ) {
	function wp_authenticate_application_password( $input_user, $username, $password ) {
		return $GLOBALS['g2aba_test_app_password_auth'] ?? null;
	}
}
if ( ! function_exists( 'get_user_by' ) ) {
	function get_user_by( $field, $value ) {
		return $GLOBALS['g2aba_test_users'][ $field ][ $value ] ?? false;
	}
}
$GLOBALS['g2aba_test_users'] = array();
if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $length = 12, $special_chars = true ) {
		return substr( bin2hex( random_bytes( 64 ) ), 0, $length );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-autoloader.php';
\WordPressistic\G2ABA\Autoloader::register();
