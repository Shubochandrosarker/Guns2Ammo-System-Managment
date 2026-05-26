<?php
/**
 * Paid Memberships Pro booking rules.
 *
 * @package G2AB\Modules\PMPro
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class G2AB_Module_PMPro_Memberships {

	private static $instance = null;

	const OPT_ENABLED           = 'g2ab_pmpro_enabled';
	const OPT_MEMBERS_ONLY_MODE = 'g2ab_pmpro_members_only_mode';
	const OPT_RULES             = 'g2ab_pmpro_discount_rules';

	public static function instance() {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_option( self::OPT_ENABLED, 1 );
		add_option( self::OPT_MEMBERS_ONLY_MODE, 'any_active' );
		add_option( self::OPT_RULES, array() );

		if ( is_admin() ) {
			new G2AB_PMPro_Settings();
		}

		add_filter( 'g2ab_user_is_member', array( $this, 'filter_user_is_member' ), 10, 3 );
		add_filter( 'g2ab_booking_pricing', array( $this, 'filter_booking_pricing' ), 10, 4 );
		add_filter( 'g2ab_booking_display_pricing', array( $this, 'filter_display_pricing' ), 10, 4 );
	}

	public function is_enabled() {
		return 1 === (int) get_option( self::OPT_ENABLED, 1 );
	}

	public function pmpro_active() {
		return function_exists( 'pmpro_hasMembershipLevel' ) || function_exists( 'pmpro_getMembershipLevelsForUser' ) || function_exists( 'pmpro_getMembershipLevelForUser' );
	}

	public function get_rules() {
		$rules = get_option( self::OPT_RULES, array() );
		return is_array( $rules ) ? $rules : array();
	}

	public function get_levels() {
		global $wpdb;
		$levels = array();

		if ( function_exists( 'pmpro_getAllLevels' ) ) {
			try {
				$raw = pmpro_getAllLevels( true, true );
				if ( is_array( $raw ) ) {
					foreach ( $raw as $level ) {
						if ( is_object( $level ) && isset( $level->id ) ) {
							$levels[ (int) $level->id ] = $level;
						}
					}
				}
			} catch ( \Throwable $e ) {
				$levels = array();
			}
		}

		if ( empty( $levels ) && isset( $wpdb ) ) {
			$table = $wpdb->prefix . 'pmpro_membership_levels';
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists === $table ) {
				$rows = $wpdb->get_results( "SELECT id, name FROM {$table} ORDER BY id ASC" );
				foreach ( (array) $rows as $row ) {
					$levels[ (int) $row->id ] = $row;
				}
			}
		}

		return $levels;
	}

	public function get_user_level_ids( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id || ! $this->pmpro_active() ) return array();

		$ids = array();
		if ( function_exists( 'pmpro_getMembershipLevelsForUser' ) ) {
			$levels = pmpro_getMembershipLevelsForUser( $user_id );
			if ( is_array( $levels ) ) {
				foreach ( $levels as $level ) {
					if ( is_object( $level ) && isset( $level->id ) ) {
						$ids[] = (int) $level->id;
					} elseif ( is_array( $level ) && isset( $level['id'] ) ) {
						$ids[] = (int) $level['id'];
					}
				}
			}
		}

		if ( empty( $ids ) && function_exists( 'pmpro_getMembershipLevelForUser' ) ) {
			$level = pmpro_getMembershipLevelForUser( $user_id );
			if ( is_object( $level ) && isset( $level->id ) ) {
				$ids[] = (int) $level->id;
			} elseif ( is_array( $level ) && isset( $level['id'] ) ) {
				$ids[] = (int) $level['id'];
			}
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	public function filter_user_is_member( $allowed, $user_id, $booking_type ) {
		if ( ! $this->is_enabled() || ! $this->pmpro_active() || ! $user_id ) {
			return $allowed;
		}

		$level_ids = $this->get_user_level_ids( $user_id );
		if ( empty( $level_ids ) ) {
			return false;
		}

		$mode = get_option( self::OPT_MEMBERS_ONLY_MODE, 'any_active' );
		if ( 'configured_only' !== $mode ) {
			return true;
		}

		return null !== $this->best_rule_for_booking_type( $level_ids, $booking_type );
	}

	public function filter_display_pricing( $pricing, $booking_type, $party_size, $user_id ) {
		return $this->filter_booking_pricing( $pricing, $booking_type, $party_size, $user_id );
	}

	public function filter_booking_pricing( $pricing, $booking_type, $party_size, $user_id ) {
		if ( ! $this->is_enabled() || ! $this->pmpro_active() || ! $user_id ) {
			return $pricing;
		}

		$level_ids = $this->get_user_level_ids( $user_id );
		if ( empty( $level_ids ) ) {
			return $pricing;
		}

		$rule = $this->best_rule_for_booking_type( $level_ids, $booking_type );
		if ( ! $rule ) {
			return $pricing;
		}

		$subtotal = isset( $pricing['subtotal'] ) ? (float) $pricing['subtotal'] : ( (float) $booking_type->base_price * max( 1, (int) $party_size ) );
		$percent = min( 100, max( 0, (float) $rule['discount'] ) );
		$discount = round( $subtotal * $percent / 100, 2 );

		$pricing['discount_amount'] = max( (float) ( $pricing['discount_amount'] ?? 0 ), $discount );
		$pricing['total'] = max( 0, round( $subtotal - (float) $pricing['discount_amount'], 2 ) );
		$pricing['member_discount_percent'] = $percent;
		$pricing['membership_level_id'] = (int) $rule['level_id'];
		$pricing['membership_source'] = 'pmpro';
		$pricing['discount_label'] = sprintf( __( 'PMPro member discount: %s%%', 'g2a-booking' ), rtrim( rtrim( number_format( $percent, 2 ), '0' ), '.' ) );

		return $pricing;
	}

	private function best_rule_for_booking_type( $level_ids, $booking_type ) {
		$rules = $this->get_rules();
		$booking_type_id = isset( $booking_type->id ) ? (int) $booking_type->id : 0;
		$best = null;

		foreach ( $level_ids as $level_id ) {
			if ( empty( $rules[ $level_id ] ) || ! is_array( $rules[ $level_id ] ) ) {
				continue;
			}
			$rule = $rules[ $level_id ];
			$discount = isset( $rule['discount'] ) ? (float) $rule['discount'] : 0;
			if ( $discount <= 0 ) {
				continue;
			}

			$all = ! empty( $rule['all'] );
			$type_ids = isset( $rule['booking_types'] ) && is_array( $rule['booking_types'] ) ? array_map( 'absint', $rule['booking_types'] ) : array();
			if ( ! $all && ( ! $booking_type_id || ! in_array( $booking_type_id, $type_ids, true ) ) ) {
				continue;
			}

			$rule['level_id'] = (int) $level_id;
			if ( null === $best || $discount > (float) $best['discount'] ) {
				$best = $rule;
			}
		}

		return $best;
	}
}
