<?php
/**
 * Self-contained analytics engine for dealer confirmation portal.
 *
 * Does not depend on Insightistic, Google Analytics, or any external plugin.
 * All queries run against the plugin's own wp_wpistic_ffl_analytics_events table.
 *
 * Event types tracked:
 *   - token_issued            (admin created a token)
 *   - email_sent              (confirmation email dispatched)
 *   - email_opened            (1x1 pixel loaded — off by default, GDPR-sensitive)
 *   - portal_viewed           (dealer loaded the landing page)
 *   - two_factor_failed       (dealer entered wrong last-4 FFL)
 *   - confirmed               (dealer marked item received)
 *   - issue_reported          (dealer reported an issue)
 *   - not_my_shipment         (dealer rejected shipment)
 *   - reminder_sent           (auto-reminder email went out)
 *   - token_expired           (cleanup marked a token expired)
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class Analytics {

	/**
	 * Log an analytics event.
	 * Swallows all errors (analytics never breaks user flow).
	 */
	public static function log( string $event_type, array $context = [] ): void {
		try {
			global $wpdb;

			$data = [
				'event_type'  => sanitize_key( $event_type ),
				'transfer_id' => isset( $context['transfer_id'] ) ? (int) $context['transfer_id'] : null,
				'dealer_id'   => isset( $context['dealer_id'] )   ? (int) $context['dealer_id']   : null,
				'token_id'    => isset( $context['token_id'] )    ? (int) $context['token_id']    : null,
				'metadata'    => ! empty( $context['metadata'] ) ? wp_json_encode( $context['metadata'] ) : null,
				'created_ip'  => self::ip_to_binary( Token::client_ip() ),
			];

			$wpdb->insert( DB::table( 'analytics_events' ), $data ); // phpcs:ignore WordPress.DB
		} catch ( \Throwable $e ) {
			// Log silently, never break.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[wpistic-ffl-analytics] ' . $e->getMessage() ); // phpcs:ignore
			}
		}
	}

	/**
	 * Dashboard summary for a date range.
	 *
	 * @param string $period  7d|30d|90d|365d|all
	 */
	public static function get_summary( string $period = '30d' ): array {
		global $wpdb;

		$days  = self::period_days( $period );
		$since = $days > 0 ? gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) ) : '1970-01-01 00:00:00';
		$ae    = DB::table( 'analytics_events' );
		$tk    = DB::table( 'dealer_tokens' );

		$counts = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT event_type, COUNT(*) AS cnt
			 FROM {$ae}
			 WHERE created_at >= %s
			 GROUP BY event_type",
			$since
		) );

		$map = [
			'token_issued'      => 0,
			'email_sent'        => 0,
			'email_opened'      => 0,
			'portal_viewed'     => 0,
			'confirmed'         => 0,
			'issue_reported'    => 0,
			'not_my_shipment'   => 0,
			'reminder_sent'     => 0,
			'token_expired'     => 0,
			'two_factor_failed' => 0,
		];
		foreach ( (array) $counts as $row ) {
			$map[ $row->event_type ] = (int) $row->cnt;
		}

		// Funnel rates
		$issued    = max( 1, $map['token_issued'] );
		$confirmed = $map['confirmed'];
		$rate      = round( ( $confirmed / $issued ) * 100, 1 );

		// Average confirmation time (from email_sent → confirmed, per transfer)
		$avg_seconds = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT AVG( TIMESTAMPDIFF( SECOND, s.created_at, c.created_at ) )
			 FROM {$ae} s
			 JOIN {$ae} c ON c.transfer_id = s.transfer_id
			 WHERE s.event_type = %s
			   AND c.event_type = %s
			   AND s.created_at >= %s",
			'email_sent',
			'confirmed',
			$since
		) );

		return [
			'period'            => $period,
			'since'             => $since,
			'totals'            => $map,
			'confirmation_rate' => $rate,
			'avg_confirm_hours' => $avg_seconds ? round( $avg_seconds / 3600, 1 ) : 0,
			'pending_tokens'    => (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
				"SELECT COUNT(*) FROM {$tk} WHERE status = %s AND expires_at > NOW()",
				'active'
			) ),
		];
	}

	/**
	 * Per-dealer performance ranking.
	 *
	 * @return array[]  [ { dealer_id, dealer_name, sent, confirmed, rate, avg_hours } ]
	 */
	public static function dealer_performance( string $period = '90d', int $limit = 25, string $order = 'slowest' ): array {
		global $wpdb;

		$days  = self::period_days( $period );
		$since = $days > 0 ? gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) ) : '1970-01-01 00:00:00';
		$ae    = DB::table( 'analytics_events' );
		$d     = DB::table( 'dealers' );

		$order_sql = match ( $order ) {
			'fastest' => 'avg_hours ASC',
			'slowest' => 'avg_hours DESC',
			'best'    => 'rate DESC',
			default   => 'avg_hours DESC',
		};

		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT
				d.id AS dealer_id,
				d.business_name AS dealer_name,
				d.premise_city AS city,
				d.premise_state AS state,
				SUM( CASE WHEN e.event_type = 'email_sent' THEN 1 ELSE 0 END ) AS sent,
				SUM( CASE WHEN e.event_type = 'confirmed'  THEN 1 ELSE 0 END ) AS confirmed,
				SUM( CASE WHEN e.event_type = 'issue_reported' THEN 1 ELSE 0 END ) AS issues,
				ROUND(
					CASE WHEN SUM( CASE WHEN e.event_type = 'email_sent' THEN 1 ELSE 0 END ) > 0
					THEN ( SUM( CASE WHEN e.event_type = 'confirmed' THEN 1 ELSE 0 END )
						 / SUM( CASE WHEN e.event_type = 'email_sent' THEN 1 ELSE 0 END ) ) * 100
					ELSE 0 END
				, 1 ) AS rate,
				ROUND(
					( SELECT AVG( TIMESTAMPDIFF( SECOND, s.created_at, c.created_at ) )
					  FROM {$ae} s
					  JOIN {$ae} c ON c.transfer_id = s.transfer_id
					  WHERE s.event_type = 'email_sent'
						AND c.event_type = 'confirmed'
						AND s.dealer_id  = d.id
						AND s.created_at >= %s
					) / 3600
				, 1 ) AS avg_hours
			 FROM {$ae} e
			 JOIN {$d} d ON d.id = e.dealer_id
			 WHERE e.created_at >= %s
			   AND e.dealer_id IS NOT NULL
			 GROUP BY d.id
			 HAVING sent > 0
			 ORDER BY {$order_sql}
			 LIMIT %d",
			$since,
			$since,
			$limit
		) );

		return array_map( static fn( $r ) => [
			'dealer_id'   => (int) $r->dealer_id,
			'dealer_name' => $r->dealer_name,
			'city'        => $r->city,
			'state'       => $r->state,
			'sent'        => (int) $r->sent,
			'confirmed'   => (int) $r->confirmed,
			'issues'      => (int) $r->issues,
			'rate'        => (float) $r->rate,
			'avg_hours'   => (float) $r->avg_hours,
		], (array) $rows );
	}

	/**
	 * Time-series for chart rendering (events per day).
	 */
	public static function time_series( string $period = '30d', string $event = 'confirmed' ): array {
		global $wpdb;

		$days  = self::period_days( $period );
		$since = $days > 0 ? gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) ) : '1970-01-01 00:00:00';
		$ae    = DB::table( 'analytics_events' );

		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT DATE( created_at ) AS d, COUNT(*) AS cnt
			 FROM {$ae}
			 WHERE event_type = %s AND created_at >= %s
			 GROUP BY DATE( created_at )
			 ORDER BY d ASC",
			$event,
			$since
		) );

		return array_map( static fn( $r ) => [
			'date'  => $r->d,
			'count' => (int) $r->cnt,
		], (array) $rows );
	}

	/**
	 * Stream CSV export of all events in a period.
	 * Used by the admin Analytics tab "Export" button.
	 */
	public static function export_csv( string $period = '90d' ): void {
		global $wpdb;

		$days  = self::period_days( $period );
		$since = $days > 0 ? gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) ) : '1970-01-01 00:00:00';
		$ae    = DB::table( 'analytics_events' );

		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT e.event_type, e.transfer_id, e.dealer_id, e.token_id, e.metadata, e.created_at, t.transfer_ref
			 FROM {$ae} e
			 LEFT JOIN " . DB::table( 'transfers' ) . " t ON t.id = e.transfer_id
			 WHERE e.created_at >= %s
			 ORDER BY e.created_at ASC",
			$since
		) );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ffl-portal-analytics-' . date( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $out, [ 'Event', 'Transfer Ref', 'Transfer ID', 'Dealer ID', 'Token ID', 'Metadata', 'Created At' ] );

		foreach ( $rows as $r ) {
			fputcsv( $out, [
				$r->event_type,
				$r->transfer_ref ?? '',
				$r->transfer_id,
				$r->dealer_id,
				$r->token_id,
				$r->metadata,
				$r->created_at,
			] );
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * Cleanup — delete analytics rows older than retention window.
	 * Free tier defaults to 30 days, Pro can configure.
	 */
	public static function cleanup_old_rows(): int {
		global $wpdb;
		$retention_days = (int) ( get_option( 'wpistic_ffl_portal_settings', [] )['analytics_retention_days'] ?? 365 );
		if ( $retention_days < 1 ) {
			return 0;
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $retention_days * DAY_IN_SECONDS ) );
		return (int) $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'DELETE FROM ' . DB::table( 'analytics_events' ) . ' WHERE created_at < %s',
			$cutoff
		) );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private static function period_days( string $period ): int {
		return match ( $period ) {
			'7d'   => 7,
			'30d'  => 30,
			'90d'  => 90,
			'365d' => 365,
			'1y'   => 365,
			'all'  => 0,
			default => 30,
		};
	}

	private static function ip_to_binary( string $ip ): ?string {
		$packed = @inet_pton( $ip );
		return $packed === false ? null : $packed;
	}
}
