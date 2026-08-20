<?php
/**
 * PHPUnit bootstrap for g2a-referrals unit tests.
 *
 * No live WordPress. WP functions are stubbed with small, deterministic,
 * resettable implementations, and the repository classes the units under
 * test depend on are replaced with configurable static fixtures BEFORE the
 * real files could be loaded.
 *
 * The point of these tests is the rules that cost money if they are wrong:
 * the "$0 total never consumes a Guest Pass" rule above all.
 */

declare( strict_types=1 );

/* Bracketed namespaces are used below, so every statement in this file —
   these constants included — has to live inside a namespace block. */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'G2AR_VERSION', '1.0.0-test' );
	define( 'G2AR_REST_NAMESPACE', 'g2ar/v1' );
	define( 'G2AB_REST_NAMESPACE', 'g2a-booking/v1' );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'ARRAY_A', 'ARRAY_A' );
}

/* ── Repository fixtures, defined before the real classes could load ── */

namespace WordPressistic\G2AReferrals\Database {

	final class Rewards_Repository {

		public const TYPE_GUEST_PASS      = 'guest_pass';
		public const TYPE_MEMBERSHIP_DAYS = 'membership_days';

		/** @var array<string,float> "userId|type" => balance */
		public static array $balances = array();

		/** @var array<int,array> Rows appended by add(). */
		public static array $written = array();

		/** @var array<int,array> Rows returned by booking lookups. */
		public static array $booking_rows = array();

		public static function table(): string {
			return 'wp_g2ar_rewards';
		}

		public static function balance( $user_id, $type ): float {
			return (float) ( self::$balances[ (int) $user_id . '|' . $type ] ?? 0.0 );
		}

		public static function add( array $data ): int {
			self::$written[] = $data;

			return count( self::$written );
		}

		/**
		 * Mirrors the production query's scoping: source + id + direction,
		 * optionally narrowed by reward type and user.
		 */
		public static function exists_for_source( $source, $source_id, $direction, $type = '', $user_id = 0 ): bool {
			foreach ( self::$written as $row ) {
				if ( ( $row['source'] ?? '' ) !== $source ) {
					continue;
				}
				if ( (int) ( $row['source_id'] ?? 0 ) !== (int) $source_id ) {
					continue;
				}
				if ( ( $row['direction'] ?? '' ) !== $direction ) {
					continue;
				}
				if ( '' !== $type && ( $row['reward_type'] ?? '' ) !== $type ) {
					continue;
				}
				if ( (int) $user_id > 0 && (int) ( $row['user_id'] ?? 0 ) !== (int) $user_id ) {
					continue;
				}

				return true;
			}

			return false;
		}

		public static function history( $user_id, $limit = 50, $type = '' ): array {
			return array_values(
				array_filter(
					self::$written,
					static fn( $row ) => (int) ( $row['user_id'] ?? 0 ) === (int) $user_id
						&& ( '' === $type || ( $row['reward_type'] ?? '' ) === $type )
				)
			);
		}

		public static function reset(): void {
			self::$balances     = array();
			self::$written      = array();
			self::$booking_rows = array();
		}
	}

	final class Events_Repository {

		/** @var array<int,array> */
		public static array $logged = array();

		public static function log( $event_type, array $args = array() ): int {
			self::$logged[] = array( 'type' => $event_type ) + $args;

			return count( self::$logged );
		}

		public static function reset(): void {
			self::$logged = array();
		}
	}

	final class Referrers_Repository {

		/** @var array<int,array> */
		public static array $rows = array();

		public static function get( $id ) {
			return self::$rows[ (int) $id ] ?? null;
		}

		public static function refresh_counters( $id ): void {}

		public static function reset(): void {
			self::$rows = array();
		}
	}

	final class Conversions_Repository {

		public static int $rewarded_this_month = 0;
		public static int $since_count         = 0;

		public static function count_rewarded_in_month( $referrer_id, $month = '' ): int {
			return self::$rewarded_this_month;
		}

		public static function count_since( $referrer_id, $since ): int {
			return self::$since_count;
		}

		/** @var array<int,array> */
		public static array $rows = array();

		public static function get( $id ) {
			return self::$rows[ (int) $id ] ?? null;
		}

		public static function set_status( $id, $status, array $extra = array() ): bool {
			if ( isset( self::$rows[ (int) $id ] ) ) {
				self::$rows[ (int) $id ]['status'] = $status;
			}

			return true;
		}

		public static function reset(): void {
			self::$rewarded_this_month = 0;
			self::$since_count         = 0;
			self::$rows                = array();
		}
	}

	final class Visits_Repository {

		public static int $conversions_for_visitor = 0;

		public static function conversions_for_visitor( $visitor_hash ): int {
			return self::$conversions_for_visitor;
		}

		public static function reset(): void {
			self::$conversions_for_visitor = 0;
		}
	}
}

/* ── Plugin-namespace stubs the units under test reach for ─────────── */

namespace WordPressistic\G2AReferrals {

	final class Settings {

		/** @var array<string,mixed> */
		public static array $values = array();

		public static function defaults(): array {
			return array(
				'redemption_enabled'          => 'yes',
				'redemption_booking_type_ids' => array( 1 ),
				'guest_pass_expiry_days'      => 90,
				'referral_cap_per_month'      => 5,
				'max_conversions_per_hash'    => 3,
				'max_conversions_per_day'     => 10,
				'rate_limit_attempts'         => 20,
				'rate_limit_window_minutes'   => 15,
				'stacking_mode'               => 'best_single',
				'membership_price_floor'      => 0.0,
				'referrer_reward_type'        => 'guest_pass',
				'friend_reward_type'          => 'membership_days',
				'friend_reward_periods'       => 1,
				'referrer_reward_amount'      => 1,
				'non_member_plan_ids'         => array( 5 ),
				'non_member_plans_may_refer'  => 'yes',
				'non_member_plan_reward'      => 'membership_days',
			);
		}

		public static function get( $key, $default = null ) {
			$all = array_merge( self::defaults(), self::$values );

			return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
		}

		public static function get_int( $key ): int {
			return (int) self::get( $key, 0 );
		}

		public static function get_float( $key ): float {
			return (float) self::get( $key, 0 );
		}

		public static function is_on( $key ): bool {
			return 'yes' === self::get( $key, 'no' );
		}

		public static function non_member_plan_ids(): array {
			return array_map( 'intval', (array) self::get( 'non_member_plan_ids', array( 5 ) ) );
		}

		public static function reset(): void {
			self::$values = array();
		}
	}

	final class Fingerprint {
		public static function ip_hash(): string {
			return str_repeat( 'a', 64 );
		}
	}
}

/* ── WordPress function stubs ──────────────────────────────────────── */

namespace {

	$GLOBALS['g2ar_test_filters'] = array();
	$GLOBALS['g2ar_test_users']   = array();
	$GLOBALS['g2ar_test_usermeta'] = array();

	function __( $text, $domain = null ) { return $text; }
	function _n( $single, $plural, $number, $domain = null ) { return 1 === (int) $number ? $single : $plural; }
	function esc_html( $text ) { return $text; }
	function esc_attr( $text ) { return $text; }
	function esc_url_raw( $url ) { return $url; }
	function sanitize_text_field( $text ) { return is_scalar( $text ) ? trim( (string) $text ) : ''; }
	function sanitize_textarea_field( $text ) { return is_scalar( $text ) ? trim( (string) $text ) : ''; }
	function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) ); }
	function wp_unslash( $value ) { return $value; }
	function absint( $value ) { return abs( (int) $value ); }
	function wp_json_encode( $value ) { return json_encode( $value ); }
	function current_time( $type = 'mysql', $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
	function wp_rand( $min = 0, $max = 0 ) { return random_int( $min, $max ); }
	function wp_generate_password( $length = 12, $special = true, $extra = false ) { return bin2hex( random_bytes( (int) ceil( $length / 2 ) ) ); }
	function wp_salt( $scheme = 'auth' ) { return 'test-salt'; }
	function home_url( $path = '/' ) { return 'https://guns2ammo.test' . $path; }
	function get_current_user_id() { return $GLOBALS['g2ar_test_current_user'] ?? 0; }
	function is_email( $value ) { return (bool) filter_var( $value, FILTER_VALIDATE_EMAIL ); }
	function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }
	function get_option( $name, $default = false ) { return $GLOBALS['g2ar_test_options'][ $name ] ?? $default; }
	function update_option( $name, $value, $autoload = null ) { $GLOBALS['g2ar_test_options'][ $name ] = $value; return true; }

	function add_query_arg( $key, $value = null, $url = '' ) {
		if ( is_array( $key ) ) {
			$url = (string) $value;
			return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $key );
		}
		return $url . ( str_contains( (string) $url, '?' ) ? '&' : '?' ) . rawurlencode( (string) $key ) . '=' . $value;
	}

	function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
		$GLOBALS['g2ar_test_filters'][ $hook ][] = array(
			'callback' => $callback,
			'priority' => $priority,
			'args'     => $args,
		);
		return true;
	}

	function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
		return add_filter( $hook, $callback, $priority, $args );
	}

	function apply_filters( $hook, $value ) {
		return $value;
	}

	function do_action( $hook, ...$args ) {
		return null;
	}

	/**
	 * Memberistic's public status lookup, driven by $GLOBALS['g2ar_test_plan'].
	 */
	function memberistic_get_membership_status( $user_id ) {
		$plan = $GLOBALS['g2ar_test_plan'][ (int) $user_id ] ?? 0;

		return array(
			'user_id'        => (int) $user_id,
			'has_membership' => $plan > 0,
			'is_live'        => $plan > 0,
			'plan_id'        => (int) $plan,
			'status'         => $plan > 0 ? 'active' : '',
		);
	}

	function get_userdata( $user_id ) {
		return $GLOBALS['g2ar_test_users'][ (int) $user_id ] ?? false;
	}

	function get_user_meta( $user_id, $key, $single = false ) {
		return $GLOBALS['g2ar_test_usermeta'][ (int) $user_id ][ $key ] ?? ( $single ? '' : array() );
	}

	function update_user_meta( $user_id, $key, $value ) {
		$GLOBALS['g2ar_test_usermeta'][ (int) $user_id ][ $key ] = $value;
		return true;
	}

	function wp_list_pluck( $list, $field ) {
		return array_map( static fn( $item ) => is_array( $item ) ? ( $item[ $field ] ?? null ) : null, $list );
	}

	/** Minimal $wpdb so classes that touch it can be loaded without fataling. */
	class G2AR_Test_WPDB {
		public string $prefix = 'wp_';
		public function prepare( $query, ...$args ) { return $query; }
		public function get_var( $query ) { return null; }
		public function get_row( $query, $output = null ) { return null; }
		public function get_results( $query, $output = null ) { return array(); }
		public function query( $query ) { return 0; }
		public function insert( $table, $data, $format = null ) { return 1; }
		public function update( $table, $data, $where, $f = null, $wf = null ) { return 1; }
		public function esc_like( $text ) { return $text; }
	}

	$GLOBALS['wpdb']              = new G2AR_Test_WPDB();
	$GLOBALS['g2ar_test_options'] = array();

	require_once __DIR__ . '/../vendor/autoload.php';

	require_once __DIR__ . '/../includes/class-codes.php';
	require_once __DIR__ . '/../includes/class-qr.php';
	require_once __DIR__ . '/../includes/class-redemption.php';
	require_once __DIR__ . '/../includes/class-stacking.php';
	require_once __DIR__ . '/../includes/class-fraud.php';
	require_once __DIR__ . '/../includes/class-rewards-service.php';

	/**
	 * Reset every fixture between tests.
	 */
	function g2ar_test_reset(): void {
		\WordPressistic\G2AReferrals\Database\Rewards_Repository::reset();
		\WordPressistic\G2AReferrals\Database\Events_Repository::reset();
		\WordPressistic\G2AReferrals\Database\Conversions_Repository::reset();
		\WordPressistic\G2AReferrals\Database\Referrers_Repository::reset();
		\WordPressistic\G2AReferrals\Database\Visits_Repository::reset();
		\WordPressistic\G2AReferrals\Settings::reset();
		\WordPressistic\G2AReferrals\Redemption::reset();

		$GLOBALS['g2ar_test_filters']     = array();
		$GLOBALS['g2ar_test_users']       = array();
		$GLOBALS['g2ar_test_usermeta']    = array();
		$GLOBALS['g2ar_test_options']     = array();
		$GLOBALS['g2ar_test_current_user'] = 0;
		$GLOBALS['g2ar_test_plan']         = array();
	}
}
