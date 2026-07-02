<?php
/**
 * Analytics backbone — shared aggregation for the Dashboard, the Shooters CRM,
 * and the Bookings roster.
 *
 * Everything here is derived live from the bookings table (and the events table
 * for class/event classification). A "shooter" is a distinct customer email
 * across all bookings — there is no separate customer table, so this collapses
 * the booking history into one row per shooter and tags each with a lifecycle
 * segment (new / active / VIP / at-risk / lapsed).
 *
 * The numbers a range owner actually cares about: who is coming back, who is
 * worth the most, and — crucially — who has stopped showing up so they can be
 * pulled back with an offer.
 *
 * @package G2AB
 * @since   1.9.9.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Analytics {

	/** Bookings counted as "real" revenue / attendance. */
	const PAID_STATUSES = array( 'paid', 'confirmed', 'completed' );

	/** Churn thresholds, in days since last booking activity. */
	const ACTIVE_DAYS  = 60;   // booked within 60 days → active
	const AT_RISK_DAYS = 120;  // 60–120 days → cooling off
	// > 120 days → lapsed (the re-engagement list)

	/** A shooter becomes "new" if their first booking is within this window. */
	const NEW_DAYS = 30;

	/** VIP qualifiers (either threshold trips it). */
	const VIP_SPEND    = 500.0;
	const VIP_BOOKINGS = 10;

	/* ───────────────────────────── shooters ───────────────────────────── */

	/**
	 * Full shooter roster, one row per email, with lifecycle segment attached.
	 * Cached per-request because both the list and the stats derive from it.
	 *
	 * @return array<int,array>
	 */
	public static function shooters_all() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		global $wpdb;
		$tbl = $wpdb->prefix . 'g2ab_bookings';

		$paid_in = "'" . implode( "','", self::PAID_STATUSES ) . "'";
		// One row per email. Aggregate counts, spend, first/last activity.
		$rows = $wpdb->get_results(
			"SELECT
				LOWER(customer_email) AS email,
				MAX(customer_name)    AS name,
				MAX(customer_phone)   AS phone,
				MAX(user_id)          AS user_id,
				COUNT(*)              AS bookings,
				COALESCE(SUM(CASE WHEN status IN ({$paid_in}) THEN paid_amount ELSE 0 END),0) AS spent,
				SUM(CASE WHEN event_id IS NOT NULL THEN 1 ELSE 0 END) AS event_bookings,
				SUM(CASE WHEN status IN ('cancelled','no_show') THEN 1 ELSE 0 END) AS lost_bookings,
				MIN(created_at)       AS first_seen,
				MAX(created_at)       AS last_seen,
				MAX(start_at)         AS last_visit
			FROM {$tbl}
			WHERE customer_email <> ''
			GROUP BY LOWER(customer_email)",
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$now = (int) current_time( 'timestamp' );
		foreach ( $rows as &$r ) {
			$r['bookings']       = (int) $r['bookings'];
			$r['event_bookings'] = (int) $r['event_bookings'];
			$r['lost_bookings']  = (int) $r['lost_bookings'];
			$r['spent']          = (float) $r['spent'];
			$r['user_id']        = $r['user_id'] ? (int) $r['user_id'] : 0;
			$r['name']           = trim( (string) $r['name'] );
			$r['phone']          = trim( (string) $r['phone'] );
			if ( '' === $r['name'] ) {
				$r['name'] = ucwords( trim( current( explode( '@', $r['email'] ) ) ) );
			}
			$last_ts             = strtotime( $r['last_seen'] );
			$first_ts            = strtotime( $r['first_seen'] );
			$r['days_since']     = $last_ts ? max( 0, (int) floor( ( $now - $last_ts ) / DAY_IN_SECONDS ) ) : 9999;
			$r['days_known']     = $first_ts ? max( 0, (int) floor( ( $now - $first_ts ) / DAY_IN_SECONDS ) ) : 0;
			$r['avg_spend']      = $r['bookings'] > 0 ? $r['spent'] / $r['bookings'] : 0.0;
			$r['segment']        = self::segment_for( $r );
		}
		unset( $r );

		$cache = $rows;
		return $cache;
	}

	/**
	 * Lifecycle segment for one aggregated shooter row.
	 *
	 * Order matters: VIP and New win over plain active; lapsed/at-risk are
	 * driven purely by recency so the re-engagement list is honest.
	 *
	 * @return string new|vip|active|at_risk|lapsed
	 */
	public static function segment_for( $r ) {
		$days  = isset( $r['days_since'] ) ? (int) $r['days_since'] : 9999;
		$spent = isset( $r['spent'] ) ? (float) $r['spent'] : 0.0;
		$bk    = isset( $r['bookings'] ) ? (int) $r['bookings'] : 0;

		if ( $days > self::AT_RISK_DAYS ) {
			return 'lapsed';
		}
		if ( $days > self::ACTIVE_DAYS ) {
			return 'at_risk';
		}
		// Recent enough to count as active — refine into VIP / new / active.
		if ( $spent >= self::VIP_SPEND || $bk >= self::VIP_BOOKINGS ) {
			return 'vip';
		}
		if ( $bk <= 1 && isset( $r['days_known'] ) && (int) $r['days_known'] <= self::NEW_DAYS ) {
			return 'new';
		}
		return 'active';
	}

	/** Human label + accent for a segment. */
	public static function segment_meta( $seg ) {
		$map = array(
			'new'     => array( 'label' => 'New',      'color' => '#2E7D32' ),
			'active'  => array( 'label' => 'Active',   'color' => '#1565C0' ),
			'vip'     => array( 'label' => 'VIP',      'color' => '#E8772E' ),
			'at_risk' => array( 'label' => 'At Risk',  'color' => '#B26A00' ),
			'lapsed'  => array( 'label' => 'Lapsed',   'color' => '#C62828' ),
		);
		return isset( $map[ $seg ] ) ? $map[ $seg ] : array( 'label' => ucfirst( $seg ), 'color' => '#5A6577' );
	}

	/**
	 * Filtered + sorted + paginated shooter list for the roster UI / REST.
	 *
	 * @param array $args search, segment, sort, order, page, per_page
	 * @return array { rows, total, page, per_page, pages }
	 */
	public static function shooters_query( $args = array() ) {
		$args = wp_parse_args( $args, array(
			'search'   => '',
			'segment'  => 'all',
			'sort'     => 'last_seen',
			'order'    => 'desc',
			'page'     => 1,
			'per_page' => 12,
		) );

		$rows   = self::shooters_all();
		$search = strtolower( trim( (string) $args['search'] ) );
		$seg    = sanitize_key( $args['segment'] );

		if ( '' !== $search ) {
			$rows = array_values( array_filter( $rows, function ( $r ) use ( $search ) {
				return false !== strpos( strtolower( $r['name'] ), $search )
					|| false !== strpos( strtolower( $r['email'] ), $search )
					|| false !== strpos( strtolower( $r['phone'] ), $search );
			} ) );
		}
		if ( $seg && 'all' !== $seg ) {
			$rows = array_values( array_filter( $rows, function ( $r ) use ( $seg ) {
				return $r['segment'] === $seg;
			} ) );
		}

		// Sort.
		$sort  = in_array( $args['sort'], array( 'name', 'bookings', 'spent', 'last_seen', 'first_seen' ), true ) ? $args['sort'] : 'last_seen';
		$order = 'asc' === strtolower( $args['order'] ) ? 1 : -1;
		usort( $rows, function ( $a, $b ) use ( $sort, $order ) {
			if ( 'name' === $sort ) {
				return strcasecmp( $a['name'], $b['name'] ) * $order;
			}
			if ( 'last_seen' === $sort || 'first_seen' === $sort ) {
				$av = strtotime( $a[ $sort ] );
				$bv = strtotime( $b[ $sort ] );
			} else {
				$av = $a[ $sort ];
				$bv = $b[ $sort ];
			}
			if ( $av === $bv ) {
				return 0;
			}
			return ( $av < $bv ? -1 : 1 ) * $order;
		} );

		$total    = count( $rows );
		$per_page = max( 1, (int) $args['per_page'] );
		$pages    = max( 1, (int) ceil( $total / $per_page ) );
		$page     = min( max( 1, (int) $args['page'] ), $pages );
		$offset   = ( $page - 1 ) * $per_page;
		$slice    = array_slice( $rows, $offset, $per_page );

		return array(
			'rows'     => $slice,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => $pages,
		);
	}

	/**
	 * Headline shooter stats + 12-month growth series + segment mix.
	 *
	 * @return array
	 */
	public static function shooters_stats() {
		$rows = self::shooters_all();
		$now  = (int) current_time( 'timestamp' );

		$total       = count( $rows );
		$new_30      = 0;
		$new_prev_30 = 0;
		$active      = 0;
		$returning   = 0;
		$at_risk     = 0;
		$lapsed      = 0;
		$vip         = 0;
		$revenue     = 0.0;

		$cut_new  = $now - self::NEW_DAYS * DAY_IN_SECONDS;
		$cut_prev = $now - 2 * self::NEW_DAYS * DAY_IN_SECONDS;

		// 12-month buckets of new shooters (by first_seen).
		$months = array();
		for ( $i = 11; $i >= 0; $i-- ) {
			$key            = wp_date( 'Y-m', strtotime( "first day of -{$i} month", $now ) );
			$months[ $key ] = 0;
		}

		$seg_counts = array( 'new' => 0, 'active' => 0, 'vip' => 0, 'at_risk' => 0, 'lapsed' => 0 );

		foreach ( $rows as $r ) {
			$revenue += $r['spent'];
			if ( isset( $seg_counts[ $r['segment'] ] ) ) {
				$seg_counts[ $r['segment'] ]++;
			}
			switch ( $r['segment'] ) {
				case 'at_risk':
					$at_risk++;
					break;
				case 'lapsed':
					$lapsed++;
					break;
				case 'vip':
					$vip++;
					break;
			}
			if ( $r['days_since'] <= self::ACTIVE_DAYS ) {
				$active++;
			}
			if ( $r['bookings'] > 1 ) {
				$returning++;
			}
			$first_ts = strtotime( $r['first_seen'] );
			if ( $first_ts >= $cut_new ) {
				$new_30++;
			} elseif ( $first_ts >= $cut_prev ) {
				$new_prev_30++;
			}
			$mkey = wp_date( 'Y-m', $first_ts );
			if ( isset( $months[ $mkey ] ) ) {
				$months[ $mkey ]++;
			}
		}

		// Cumulative growth line: total shooters known at the end of each month.
		$series = array();
		$running_before = 0;
		// Count shooters whose first_seen predates the 12-month window.
		$month_keys   = array_keys( $months );
		$window_start = strtotime( reset( $month_keys ) . '-01 00:00:00' );
		foreach ( $rows as $r ) {
			if ( strtotime( $r['first_seen'] ) < $window_start ) {
				$running_before++;
			}
		}
		$cumulative = $running_before;
		foreach ( $months as $key => $count ) {
			$cumulative += $count;
			$series[] = array(
				'month'      => $key,
				'label'      => wp_date( 'M', strtotime( $key . '-01 00:00:00' ) ),
				'new'        => $count,
				'cumulative' => $cumulative,
			);
		}

		$returning_rate = $total > 0 ? round( $returning / $total * 100 ) : 0;

		return array(
			'total'          => $total,
			'new_30'         => $new_30,
			'new_delta'      => self::pct_delta( $new_30, $new_prev_30 ),
			'active'         => $active,
			'returning'      => $returning,
			'returning_rate' => $returning_rate,
			'at_risk'        => $at_risk,
			'lapsed'         => $lapsed,
			'vip'            => $vip,
			'revenue'        => $revenue,
			'series'         => $series,
			'segments'       => $seg_counts,
		);
	}

	/**
	 * Deep profile for a single shooter: aggregate + full booking history.
	 *
	 * @param string $email
	 * @return array|null
	 */
	public static function shooter_detail( $email ) {
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return null;
		}
		$rows = self::shooters_all();
		$base = null;
		foreach ( $rows as $r ) {
			if ( $r['email'] === $email ) {
				$base = $r;
				break;
			}
		}
		if ( null === $base ) {
			return null;
		}

		global $wpdb;
		$bt = $wpdb->prefix . 'g2ab_bookings';
		$et = $wpdb->prefix . 'g2ab_events';
		$history = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.id, b.start_at, b.status, b.total_amount, b.paid_amount, b.party_size,
				        b.event_id, e.title AS event_title, e.category AS event_category
				 FROM {$bt} b
				 LEFT JOIN {$et} e ON e.id = b.event_id
				 WHERE LOWER(b.customer_email) = %s
				 ORDER BY b.start_at DESC
				 LIMIT 50",
				$email
			),
			ARRAY_A
		);
		$base['history'] = is_array( $history ) ? $history : array();
		return $base;
	}

	/* ───────────────────────────── bookings ───────────────────────────── */

	/**
	 * Classify a booking row into one of: lane, event, class.
	 * Requires event_id and (optionally) event_category on the row.
	 */
	public static function classify_booking( $row ) {
		$event_id = isset( $row['event_id'] ) ? (int) $row['event_id'] : 0;
		if ( ! $event_id ) {
			return 'lane';
		}
		$cat = isset( $row['event_category'] ) ? (string) $row['event_category'] : '';
		return ( 'ccw-class' === $cat ) ? 'class' : 'event';
	}

	/**
	 * Bookings roster query with tab scoping + search + status filter.
	 *
	 * @param array $args tab(all|lane|event|class), search, status, page, per_page, date_from, date_to
	 * @return array { rows, total, page, per_page, pages, counts }
	 */
	public static function bookings_query( $args = array() ) {
		global $wpdb;
		$args = wp_parse_args( $args, array(
			'tab'      => 'all',
			'search'   => '',
			'status'   => '',
			'page'     => 1,
			'per_page' => 20,
			'date_from'=> '',
			'date_to'  => '',
		) );

		$bt = $wpdb->prefix . 'g2ab_bookings';
		$et = $wpdb->prefix . 'g2ab_events';
		$rt = $wpdb->prefix . 'g2ab_resources';
		$tt = $wpdb->prefix . 'g2ab_booking_types';

		$where  = array( '1=1' );
		$params = array();

		$tab = sanitize_key( $args['tab'] );
		if ( 'lane' === $tab ) {
			$where[] = 'b.event_id IS NULL';
		} elseif ( 'event' === $tab ) {
			$where[] = "b.event_id IS NOT NULL AND (e.category IS NULL OR e.category <> 'ccw-class')";
		} elseif ( 'class' === $tab ) {
			$where[] = "b.event_id IS NOT NULL AND e.category = 'ccw-class'";
		}

		$search = trim( (string) $args['search'] );
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(b.customer_name LIKE %s OR b.customer_email LIKE %s OR b.customer_phone LIKE %s OR e.title LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		$status = sanitize_key( $args['status'] );
		if ( $status ) {
			$where[]  = 'b.status = %s';
			$params[] = $status;
		}
		if ( $args['date_from'] ) {
			$where[]  = 'b.start_at >= %s';
			$params[] = $args['date_from'] . ' 00:00:00';
		}
		if ( $args['date_to'] ) {
			$where[]  = 'b.start_at <= %s';
			$params[] = $args['date_to'] . ' 23:59:59';
		}

		$where_sql = implode( ' AND ', $where );
		$join      = " FROM {$bt} b
			LEFT JOIN {$et} e ON e.id = b.event_id
			LEFT JOIN {$rt} r ON r.id = b.resource_id
			LEFT JOIN {$tt} t ON t.id = b.booking_type_id
			WHERE {$where_sql}";

		$count_sql = "SELECT COUNT(*) {$join}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

		$per_page = max( 1, (int) $args['per_page'] );
		$pages    = max( 1, (int) ceil( $total / $per_page ) );
		$page     = min( max( 1, (int) $args['page'] ), $pages );
		$offset   = ( $page - 1 ) * $per_page;

		$select = "SELECT b.*, e.title AS event_title, e.category AS event_category,
			r.name AS resource_name, t.name AS type_name {$join}
			ORDER BY b.start_at DESC LIMIT %d OFFSET %d";
		$qparams = array_merge( $params, array( $per_page, $offset ) );
		$rows    = $wpdb->get_results( $wpdb->prepare( $select, $qparams ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		return array(
			'rows'     => $rows,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => $pages,
			'counts'   => self::bookings_tab_counts(),
		);
	}

	/** Count of bookings in each tab (for the tab badges). */
	public static function bookings_tab_counts() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		global $wpdb;
		$bt  = $wpdb->prefix . 'g2ab_bookings';
		$et  = $wpdb->prefix . 'g2ab_events';
		$all = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$bt}" );
		$lane = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$bt} WHERE event_id IS NULL" );
		$class = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$bt} b LEFT JOIN {$et} e ON e.id = b.event_id WHERE b.event_id IS NOT NULL AND e.category = 'ccw-class'" );
		$event = max( 0, $all - $lane - $class );
		$cache = array( 'all' => $all, 'lane' => $lane, 'event' => $event, 'class' => $class );
		return $cache;
	}

	/* ───────────────────────────── dashboard ──────────────────────────── */

	/**
	 * Dashboard KPIs + revenue trend for a given scope and date window.
	 * Scope filters which bookings count: all | lane | event | class.
	 *
	 * @return array
	 */
	public static function dashboard_stats( $scope = 'all', $days = 30 ) {
		global $wpdb;
		$bt = $wpdb->prefix . 'g2ab_bookings';
		$et = $wpdb->prefix . 'g2ab_events';

		$days       = max( 7, (int) $days );
		$now        = (int) current_time( 'timestamp' );
		$start      = wp_date( 'Y-m-d 00:00:00', $now - ( $days - 1 ) * DAY_IN_SECONDS );
		$prev_start = wp_date( 'Y-m-d 00:00:00', $now - ( 2 * $days - 1 ) * DAY_IN_SECONDS );
		$prev_end   = wp_date( 'Y-m-d 23:59:59', $now - $days * DAY_IN_SECONDS );
		$end        = wp_date( 'Y-m-d 23:59:59', $now );

		$scope_sql = self::scope_clause( $scope );
		$paid_in   = "'" . implode( "','", self::PAID_STATUSES ) . "'";

		$book = function ( $a, $b ) use ( $wpdb, $bt, $et, $scope_sql ) {
			return (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$bt} b LEFT JOIN {$et} e ON e.id = b.event_id WHERE b.created_at BETWEEN %s AND %s {$scope_sql}",
				$a, $b
			) );
		};
		$rev = function ( $a, $b ) use ( $wpdb, $bt, $et, $scope_sql, $paid_in ) {
			return (float) $wpdb->get_var( $wpdb->prepare(
				"SELECT COALESCE(SUM(b.paid_amount),0) FROM {$bt} b LEFT JOIN {$et} e ON e.id = b.event_id WHERE b.created_at BETWEEN %s AND %s AND b.status IN ({$paid_in}) {$scope_sql}",
				$a, $b
			) );
		};

		$bookings_now  = $book( $start, $end );
		$bookings_prev = $book( $prev_start, $prev_end );
		$revenue_now   = $rev( $start, $end );
		$revenue_prev  = $rev( $prev_start, $prev_end );

		// Distinct shooters active in window.
		$shooters_now = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT LOWER(b.customer_email)) FROM {$bt} b LEFT JOIN {$et} e ON e.id = b.event_id WHERE b.created_at BETWEEN %s AND %s AND b.customer_email <> '' {$scope_sql}",
			$start, $end
		) );
		$shooters_prev = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT LOWER(b.customer_email)) FROM {$bt} b LEFT JOIN {$et} e ON e.id = b.event_id WHERE b.created_at BETWEEN %s AND %s AND b.customer_email <> '' {$scope_sql}",
			$prev_start, $prev_end
		) );

		$avg_now = $bookings_now > 0 ? $revenue_now / $bookings_now : 0.0;

		// Daily revenue series for the window.
		$series = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$series[ wp_date( 'Y-m-d', $now - $i * DAY_IN_SECONDS ) ] = 0.0;
		}
		$daily = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(b.created_at) AS d, COALESCE(SUM(b.paid_amount),0) AS v
			 FROM {$bt} b LEFT JOIN {$et} e ON e.id = b.event_id
			 WHERE b.created_at >= %s AND b.status IN ({$paid_in}) {$scope_sql}
			 GROUP BY DATE(b.created_at)",
			$start
		) );
		if ( is_array( $daily ) ) {
			foreach ( $daily as $d ) {
				if ( isset( $series[ $d->d ] ) ) {
					$series[ $d->d ] = (float) $d->v;
				}
			}
		}

		return array(
			'scope'          => $scope,
			'days'           => $days,
			'bookings'       => $bookings_now,
			'bookings_delta' => self::pct_delta( $bookings_now, $bookings_prev ),
			'revenue'        => $revenue_now,
			'revenue_delta'  => self::pct_delta( $revenue_now, $revenue_prev ),
			'revenue_prev'   => $revenue_prev,
			'shooters'       => $shooters_now,
			'shooters_delta' => self::pct_delta( $shooters_now, $shooters_prev ),
			'avg'            => $avg_now,
			'series'         => $series,
			'status'         => self::status_breakdown( $scope, $start, $end ),
		);
	}

	/** Status counts within a window for the scope. */
	public static function status_breakdown( $scope, $start, $end ) {
		global $wpdb;
		$bt = $wpdb->prefix . 'g2ab_bookings';
		$et = $wpdb->prefix . 'g2ab_events';
		$scope_sql = self::scope_clause( $scope );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT b.status AS s, COUNT(*) AS c FROM {$bt} b LEFT JOIN {$et} e ON e.id = b.event_id
			 WHERE b.created_at BETWEEN %s AND %s {$scope_sql} GROUP BY b.status",
			$start, $end
		) );
		$out = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$out[ $r->s ] = (int) $r->c;
			}
		}
		return $out;
	}

	/**
	 * Top trends rows for the dashboard tables. Scope decides the grouping:
	 *   lane  → top booking types / resources
	 *   event → top events (non-class)
	 *   class → top classes
	 *   all   → top events overall
	 *
	 * @return array<int,array{label:string,bookings:int,revenue:float}>
	 */
	public static function dashboard_top( $scope = 'all', $limit = 8 ) {
		global $wpdb;
		$bt = $wpdb->prefix . 'g2ab_bookings';
		$et = $wpdb->prefix . 'g2ab_events';
		$rt = $wpdb->prefix . 'g2ab_resources';
		$limit = max( 1, (int) $limit );
		$paid_in = "'" . implode( "','", self::PAID_STATUSES ) . "'";

		if ( 'lane' === $scope ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT r.name AS label, COUNT(b.id) AS bookings,
				        COALESCE(SUM(CASE WHEN b.status IN ({$paid_in}) THEN b.paid_amount ELSE 0 END),0) AS revenue
				 FROM {$rt} r
				 LEFT JOIN {$bt} b ON b.resource_id = r.id AND b.event_id IS NULL
				 WHERE r.is_active = 1
				 GROUP BY r.id ORDER BY bookings DESC LIMIT %d",
				$limit
			), ARRAY_A );
		} else {
			$cat = '';
			if ( 'event' === $scope ) {
				$cat = "AND (e.category IS NULL OR e.category <> 'ccw-class')";
			} elseif ( 'class' === $scope ) {
				$cat = "AND e.category = 'ccw-class'";
			}
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT e.title AS label, COUNT(b.id) AS bookings,
				        COALESCE(SUM(CASE WHEN b.status IN ({$paid_in}) THEN b.paid_amount ELSE 0 END),0) AS revenue
				 FROM {$et} e
				 LEFT JOIN {$bt} b ON b.event_id = e.id
				 WHERE e.status = 'publish' {$cat}
				 GROUP BY e.id ORDER BY bookings DESC LIMIT %d",
				$limit
			), ARRAY_A );
		}
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}
		foreach ( $rows as &$r ) {
			$r['bookings'] = (int) $r['bookings'];
			$r['revenue']  = (float) $r['revenue'];
			$r['label']    = $r['label'] ? $r['label'] : '—';
		}
		unset( $r );
		return $rows;
	}

	/** Upcoming events/classes for the dashboard Events/Classes tabs. */
	public static function upcoming_events( $scope = 'event', $limit = 6 ) {
		global $wpdb;
		$et    = $wpdb->prefix . 'g2ab_events';
		$ot    = $wpdb->prefix . 'g2ab_event_occurrences';
		$bt    = $wpdb->prefix . 'g2ab_bookings';
		$limit = max( 1, (int) $limit );
		$now   = current_time( 'mysql' );

		$cat = '';
		if ( 'event' === $scope ) {
			$cat = "AND (e.category IS NULL OR e.category <> 'ccw-class')";
		} elseif ( 'class' === $scope ) {
			$cat = "AND e.category = 'ccw-class'";
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT o.id AS occ_id, o.start_at, o.capacity, o.status AS occ_status,
			        e.id AS event_id, e.title, e.category,
			        (SELECT COUNT(*) FROM {$bt} bb WHERE bb.event_occurrence_id = o.id AND bb.status IN ('pending','reserved','confirmed','paid','completed')) AS booked
			 FROM {$ot} o
			 INNER JOIN {$et} e ON e.id = o.event_id
			 WHERE o.start_at >= %s AND e.status = 'publish' {$cat}
			 ORDER BY o.start_at ASC LIMIT %d",
			$now, $limit
		), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/* ───────────────────────────── helpers ────────────────────────────── */

	/** SQL fragment to scope a `b`/`e` joined query to lane/event/class. */
	private static function scope_clause( $scope ) {
		switch ( $scope ) {
			case 'lane':
				return 'AND b.event_id IS NULL';
			case 'event':
				return "AND b.event_id IS NOT NULL AND (e.category IS NULL OR e.category <> 'ccw-class')";
			case 'class':
				return "AND b.event_id IS NOT NULL AND e.category = 'ccw-class'";
			default:
				return '';
		}
	}

	/** Percentage delta with the usual zero-baseline handling. */
	public static function pct_delta( $now, $prev ) {
		if ( $prev <= 0 ) {
			return $now > 0 ? 100.0 : 0.0;
		}
		return round( ( ( $now - $prev ) / $prev ) * 100, 1 );
	}
}
