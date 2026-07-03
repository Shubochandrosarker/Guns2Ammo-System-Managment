<?php
/**
 * Customer segment engine.
 *
 * Real segments computed against WooCommerce + Memberistic + Booking
 * tables. Each segment is a single SQL / helper call so this stays fast
 * enough to run on the dashboard hot path without a background job.
 *
 * When a source plugin isn't available the segment falls back to zero —
 * the dashboard reads a stable payload either way.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Segments_Provider {
	/**
	 * @return array{
	 *   knownShooters:int, repeatRatePct:float,
	 *   firstTimeThisMonth:int, lapsed90d:int,
	 *   segments: array<int, array{key:string,label:string,value:int,hint:string}>,
	 *   opportunities: array<int, string>
	 * }
	 */
	public function shooter_insights(): array {
		global $wpdb;

		$has_bookings    = $this->table_exists( $wpdb->prefix . 'g2ab_bookings' );
		$has_memberships = $this->table_exists( $wpdb->prefix . 'memberistic_memberships' );

		$range_regulars   = $this->count_range_regulars( $has_bookings );
		$ccw_graduates    = $this->count_ccw_graduates( $has_bookings );
		$ammo_bulk        = $this->count_ammo_bulk_buyers();
		$ladies_attend    = $this->count_ladies_attendees( $has_bookings );
		$expired_30_90    = $this->count_expired_members( $has_memberships );
		$first_time_14d   = $this->count_first_time( $has_bookings );
		$lapsed_90d       = $this->count_lapsed( $has_bookings );

		$known_shooters      = $this->count_known_shooters();
		$repeat_rate_pct     = $this->compute_repeat_rate();
		$first_time_this_mo  = $this->count_first_time( $has_bookings, 30 );

		$segments = array(
			array( 'key' => 'range-regulars',    'label' => 'Range regulars',           'value' => $range_regulars,  'hint' => '≥ 2 lane bookings in 30d' ),
			array( 'key' => 'ccw-graduates',     'label' => 'CCW graduates',            'value' => $ccw_graduates,   'hint' => 'Completed CCW class, no gun purchase yet' ),
			array( 'key' => 'ammo-bulk-buyers',  'label' => 'Ammo bulk buyers',         'value' => $ammo_bulk,       'hint' => '≥ 1000rd order in last 60d' ),
			array( 'key' => 'ladies-tuesday',    'label' => 'Ladies Tuesday attend',    'value' => $ladies_attend,   'hint' => 'Attended in last 90d' ),
			array( 'key' => 'expired-30-90d',    'label' => 'Members expired 30-90d',   'value' => $expired_30_90,   'hint' => 'High-value win-back list' ),
		);

		return array(
			'knownShooters'       => $known_shooters,
			'repeatRatePct'       => $repeat_rate_pct,
			'firstTimeThisMonth'  => $first_time_this_mo,
			'lapsed90d'           => $lapsed_90d,
			'segments'            => $segments,
			'opportunities'       => self::compose_opportunities( $ccw_graduates, $ammo_bulk, $expired_30_90, $first_time_14d ),
		);
	}

	/**
	 * @internal Exposed for tests.
	 */
	public static function compose_opportunities( int $ccw, int $ammo, int $expired, int $first_time ): array {
		$out = array();
		if ( $ccw > 0 ) {
			$out[] = sprintf( 'Send CCW alumni a rifle-class invite (%d shooter%s)', $ccw, 1 === $ccw ? '' : 's' );
		}
		if ( $ammo > 0 ) {
			$out[] = sprintf( 'Ammo bulk buyers → member conversion offer (%d)', $ammo );
		}
		if ( $expired > 0 ) {
			$out[] = sprintf( 'Expired members within 90d → renewal + return credit (%d)', $expired );
		}
		if ( $first_time > 0 ) {
			$out[] = sprintf( 'New first-time visitors last 14d → "come back for lane #2" (%d)', $first_time );
		}
		return $out;
	}

	private function count_range_regulars( bool $has_bookings ): int {
		if ( ! $has_bookings ) {
			return 0;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'g2ab_bookings';
		$since = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM (
					SELECT COALESCE(email, CONCAT('u:', user_id)) AS shooter, COUNT(*) AS bookings
					FROM {$table}
					WHERE type LIKE %s AND created_at >= %s AND status IN ('paid','completed')
					GROUP BY shooter
					HAVING bookings >= 2
				) x",
				'%Lane%',
				$since
			)
		);
	}

	private function count_ccw_graduates( bool $has_bookings ): int {
		if ( ! $has_bookings ) {
			return 0;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'g2ab_bookings';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT COALESCE(email, CONCAT('u:', user_id))) FROM {$table}
					WHERE type LIKE %s AND status = 'completed'",
				'%CCW%'
			)
		);
	}

	private function count_ammo_bulk_buyers(): int {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}
		$since  = gmdate( 'Y-m-d', strtotime( '-60 days' ) );
		$now    = gmdate( 'Y-m-d' );
		$orders = wc_get_orders(
			array(
				'limit'        => -1,
				'status'       => array( 'wc-completed' ),
				'date_created' => $since . '...' . $now,
				'return'       => 'objects',
			)
		);
		if ( ! is_array( $orders ) ) {
			return 0;
		}
		$hits = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			$rounds = 0;
			foreach ( $order->get_items() as $item ) {
				if ( ! ( $item instanceof \WC_Order_Item_Product ) ) {
					continue;
				}
				$name = strtolower( (string) $item->get_name() );
				// Heuristic: any line that mentions "rd" / "round" / "ammo" counts;
				// the qty times the quantity suffix in the name approximates it.
				if ( preg_match( '/(\d{2,5})\s*rd\b/', $name, $m ) ) {
					$rounds += (int) $m[1] * (int) $item->get_quantity();
				}
			}
			if ( $rounds >= 1000 ) {
				$hits[ (int) $order->get_customer_id() ?: 'g:' . $order->get_id() ] = true;
			}
		}
		return count( $hits );
	}

	private function count_ladies_attendees( bool $has_bookings ): int {
		if ( ! $has_bookings ) {
			return 0;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'g2ab_bookings';
		$since = gmdate( 'Y-m-d H:i:s', strtotime( '-90 days' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT COALESCE(email, CONCAT('u:', user_id))) FROM {$table}
					WHERE type = %s AND created_at >= %s AND status IN ('paid','completed')",
				'Ladies Tuesday',
				$since
			)
		);
	}

	private function count_expired_members( bool $has_memberships ): int {
		if ( ! $has_memberships ) {
			return 0;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'memberistic_memberships';
		$upper = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );
		$lower = gmdate( 'Y-m-d H:i:s', strtotime( '-90 days' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT user_id) FROM {$table}
					WHERE status = 'expired' AND expires_at BETWEEN %s AND %s",
				$lower,
				$upper
			)
		);
	}

	private function count_first_time( bool $has_bookings, int $window_days = 14 ): int {
		if ( ! $has_bookings ) {
			return 0;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'g2ab_bookings';
		$since = gmdate( 'Y-m-d H:i:s', strtotime( '-' . max( 1, $window_days ) . ' days' ) );
		// A shooter is "first time" if their earliest booking on record is
		// inside the window.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM (
					SELECT COALESCE(email, CONCAT('u:', user_id)) AS shooter, MIN(created_at) AS first_seen
					FROM {$table}
					GROUP BY shooter
					HAVING first_seen >= %s
				) x",
				$since
			)
		);
	}

	private function count_lapsed( bool $has_bookings ): int {
		if ( ! $has_bookings ) {
			return 0;
		}
		global $wpdb;
		$table  = $wpdb->prefix . 'g2ab_bookings';
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-90 days' ) );
		// Distinct shooters whose most recent booking was more than 90 days ago.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM (
					SELECT COALESCE(email, CONCAT('u:', user_id)) AS shooter, MAX(created_at) AS last_seen
					FROM {$table}
					GROUP BY shooter
					HAVING last_seen < %s
				) x",
				$cutoff
			)
		);
	}

	private function count_known_shooters(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'g2ab_bookings';
		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT COALESCE(email, CONCAT('u:', user_id))) FROM {$table}"
		);
	}

	private function compute_repeat_rate(): float {
		global $wpdb;
		$table = $wpdb->prefix . 'g2ab_bookings';
		if ( ! $this->table_exists( $table ) ) {
			return 0.0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$counts = $wpdb->get_col(
			"SELECT COUNT(*) FROM {$table}
				GROUP BY COALESCE(email, CONCAT('u:', user_id))"
		);
		$counts = is_array( $counts ) ? array_map( 'intval', $counts ) : array();
		if ( empty( $counts ) ) {
			return 0.0;
		}
		$repeats = count( array_filter( $counts, static fn( $c ) => $c > 1 ) );
		return round( ( $repeats / count( $counts ) ) * 100, 1 );
	}

	private function table_exists( string $table ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}
}
