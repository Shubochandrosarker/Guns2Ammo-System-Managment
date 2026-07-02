<?php
/**
 * Memberistic booking-discount integration.
 *
 * Mirrors the PMPro module but resolves Memberistic plans + the user's
 * active plan. Falls back through several discovery paths so it works on
 * sites that use Memberistic's class, custom post type, or custom table.
 *
 * @package G2AB\Modules\Memberistic
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class G2AB_Module_Memberistic {

	private static $instance = null;

	const OPT_ENABLED           = 'g2ab_memberistic_enabled';
	const OPT_MEMBERS_ONLY_MODE = 'g2ab_memberistic_members_only_mode';
	const OPT_RULES             = 'g2ab_memberistic_discount_rules';

	public static function instance() {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_option( self::OPT_ENABLED, 1 );
		add_option( self::OPT_MEMBERS_ONLY_MODE, 'any_active' );
		add_option( self::OPT_RULES, array() );

		if ( is_admin() ) {
			new G2AB_Memberistic_Settings();
		}

		add_filter( 'g2ab_user_is_member', array( $this, 'filter_user_is_member' ), 11, 3 );
		add_filter( 'g2ab_booking_pricing', array( $this, 'filter_booking_pricing' ), 11, 4 );
		add_filter( 'g2ab_booking_display_pricing', array( $this, 'filter_display_pricing' ), 11, 4 );
	}

	public function is_enabled() {
		return 1 === (int) get_option( self::OPT_ENABLED, 1 );
	}

	public function memberistic_active() {
		return class_exists( '\\WordPressistic\\Memberistic\\Database\\Plans_Repository' )
			|| post_type_exists( 'memberistic_plan' )
			|| function_exists( 'memberistic_get_plans' );
	}

	public function get_rules() {
		$rules = get_option( self::OPT_RULES, array() );
		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * Discover all Memberistic plans. Returns array of objects with `id` and `name`.
	 */
	public function get_plans() {
		$plans = array();

		// 1. Plans_Repository::all() if Memberistic class is loaded.
		if ( class_exists( '\\WordPressistic\\Memberistic\\Database\\Plans_Repository' ) ) {
			try {
				$rows = \WordPressistic\Memberistic\Database\Plans_Repository::get_all();
				if ( is_array( $rows ) ) {
					foreach ( $rows as $row ) {
						$id   = is_array( $row ) ? (int) ( $row['id'] ?? 0 ) : (int) ( $row->id ?? 0 );
						$name = is_array( $row ) ? (string) ( $row['name'] ?? '' ) : (string) ( $row->name ?? '' );
						if ( $id > 0 ) {
							$plans[ $id ] = (object) array( 'id' => $id, 'name' => $name ?: ( 'Plan #' . $id ) );
						}
					}
				}
			} catch ( \Throwable $e ) {
				// fall through to other discovery paths
			}
		}

		// 2. Custom post type fallback.
		if ( empty( $plans ) && post_type_exists( 'memberistic_plan' ) ) {
			$posts = get_posts( array(
				'post_type'      => 'memberistic_plan',
				'post_status'    => array( 'publish', 'private' ),
				'numberposts'    => 200,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'suppress_filters' => true,
			) );
			foreach ( (array) $posts as $pid ) {
				$plans[ (int) $pid ] = (object) array( 'id' => (int) $pid, 'name' => get_the_title( $pid ) ?: ( 'Plan #' . $pid ) );
			}
		}

		// 3. Custom table fallback.
		if ( empty( $plans ) ) {
			global $wpdb;
			$tables_to_try = array(
				$wpdb->prefix . 'memberistic_plans',
				$wpdb->prefix . 'wpistic_memberistic_plans',
			);
			foreach ( $tables_to_try as $table ) {
				$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
				if ( $exists === $table ) {
					$rows = $wpdb->get_results( "SELECT id, name FROM {$table} ORDER BY id ASC" );
					foreach ( (array) $rows as $row ) {
						$plans[ (int) $row->id ] = (object) array( 'id' => (int) $row->id, 'name' => (string) $row->name ?: ( 'Plan #' . $row->id ) );
					}
					if ( $plans ) break;
				}
			}
		}

		return apply_filters( 'g2ab_memberistic_plans', $plans );
	}

	/**
	 * Plan ids that the given user holds. Memberistic stores one active plan id in user meta;
	 * we also check a "plans" multi-meta for sites that allow multiple plans per user.
	 */
	public function get_user_plan_ids( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) return array();

		$ids = array();

		// Primary: memberistic_active_plan_id (single).
		$single = (int) get_user_meta( $user_id, 'memberistic_active_plan_id', true );
		if ( $single > 0 ) $ids[] = $single;

		// Secondary: memberistic_plans (multi).
		$multi = get_user_meta( $user_id, 'memberistic_plans', true );
		if ( is_array( $multi ) ) {
			foreach ( $multi as $pid ) {
				$pid = (int) $pid;
				if ( $pid > 0 ) $ids[] = $pid;
			}
		}

		$ids = array_values( array_unique( array_filter( $ids ) ) );
		return apply_filters( 'g2ab_memberistic_user_plan_ids', $ids, $user_id );
	}

	/**
	 * True when the user has at least one *paid* plan, matching the
	 * g2ab_user_has_paid_memberistic_plan helper. Only a paid plan grants
	 * member-status by default; filterable.
	 */
	public function user_has_paid_plan( $user_id ) {
		if ( function_exists( 'g2ab_user_has_paid_memberistic_plan' ) ) {
			return g2ab_user_has_paid_memberistic_plan( $user_id );
		}
		return ! empty( $this->get_user_plan_ids( $user_id ) );
	}

	public function filter_user_is_member( $allowed, $user_id, $booking_type ) {
		if ( $allowed ) return $allowed; // earlier integrations already approved
		if ( ! $this->is_enabled() || ! $this->memberistic_active() || ! $user_id ) {
			return $allowed;
		}
		$plan_ids = $this->get_user_plan_ids( $user_id );
		if ( empty( $plan_ids ) ) {
			return false;
		}
		if ( ! $this->user_has_paid_plan( $user_id ) ) {
			return false;
		}
		$mode = get_option( self::OPT_MEMBERS_ONLY_MODE, 'any_active' );
		if ( 'configured_only' !== $mode ) {
			return true;
		}
		return null !== $this->best_rule_for_booking_type( $plan_ids, $booking_type );
	}

	public function filter_display_pricing( $pricing, $booking_type, $party_size, $user_id ) {
		return $this->filter_booking_pricing( $pricing, $booking_type, $party_size, $user_id );
	}

	public function filter_booking_pricing( $pricing, $booking_type, $party_size, $user_id ) {
		if ( ! $this->is_enabled() || ! $this->memberistic_active() || ! $user_id ) {
			return $pricing;
		}
		$plan_ids = $this->get_user_plan_ids( $user_id );
		if ( empty( $plan_ids ) ) {
			return $pricing;
		}
		$rule = $this->best_rule_for_booking_type( $plan_ids, $booking_type );
		if ( ! $rule ) {
			return $pricing;
		}

		$subtotal = isset( $pricing['subtotal'] ) ? (float) $pricing['subtotal'] : ( (float) $booking_type->base_price * max( 1, (int) $party_size ) );
		$percent  = min( 100, max( 0, (float) $rule['discount'] ) );
		$discount = round( $subtotal * $percent / 100, 2 );

		// Only overwrite if our discount is bigger than whatever earlier filters set.
		$existing_discount = (float) ( $pricing['discount_amount'] ?? 0 );
		if ( $discount <= $existing_discount ) {
			return $pricing;
		}

		$pricing['discount_amount']         = $discount;
		$pricing['total']                   = max( 0, round( $subtotal - $discount, 2 ) );
		$pricing['member_discount_percent'] = $percent;
		$pricing['membership_level_id']     = (int) $rule['plan_id'];
		$pricing['membership_source']       = 'memberistic';
		$pricing['discount_label']          = sprintf(
			/* translators: %s discount percent */
			__( 'Memberistic member discount: %s%%', 'g2a-booking' ),
			rtrim( rtrim( number_format( $percent, 2 ), '0' ), '.' )
		);
		return $pricing;
	}

	private function best_rule_for_booking_type( $plan_ids, $booking_type ) {
		$rules           = $this->get_rules();
		$booking_type_id = isset( $booking_type->id ) ? (int) $booking_type->id : 0;
		$best            = null;

		foreach ( $plan_ids as $plan_id ) {
			if ( empty( $rules[ $plan_id ] ) || ! is_array( $rules[ $plan_id ] ) ) {
				continue;
			}
			$rule     = $rules[ $plan_id ];
			$discount = isset( $rule['discount'] ) ? (float) $rule['discount'] : 0;
			if ( $discount <= 0 ) {
				continue;
			}
			$all      = ! empty( $rule['all'] );
			$type_ids = isset( $rule['booking_types'] ) && is_array( $rule['booking_types'] ) ? array_map( 'absint', $rule['booking_types'] ) : array();
			if ( ! $all && ( ! $booking_type_id || ! in_array( $booking_type_id, $type_ids, true ) ) ) {
				continue;
			}
			$rule['plan_id'] = (int) $plan_id;
			if ( null === $best || $discount > (float) $best['discount'] ) {
				$best = $rule;
			}
		}
		return $best;
	}
}
