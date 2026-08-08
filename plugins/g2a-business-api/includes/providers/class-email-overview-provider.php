<?php
/**
 * Email traffic overview — Formistic inbox, newsletter subscribers, and
 * Messageistic SMS stats in a single dashboard payload.
 *
 * Formistic and its newsletter tables are read directly via $wpdb with
 * table-exists guards (we never modify that plugin). Messageistic stats are
 * pulled from its own Dashboard_API class in-process — no HTTP loopback.
 * Every section degrades to `active: false` with null metrics when the
 * source plugin is absent, so the dashboard always gets a stable shape.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Email_Overview_Provider {
	private const RECENT_LIMIT   = 20;
	private const EXCERPT_LENGTH = 140;

	/**
	 * @return array{formistic:array, subscribers:array, messageistic:array}
	 */
	public function overview(): array {
		return array(
			'formistic'    => $this->formistic(),
			'subscribers'  => $this->subscribers(),
			'messageistic' => $this->messageistic(),
		);
	}

	/**
	 * Formistic contact-form inbox: totals + recent enquiries.
	 */
	private function formistic(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wpistic_formistic_submissions';
		if ( ! $this->table_exists( $table ) ) {
			return self::empty_formistic();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$new_today = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE DATE(created_at) = CURDATE()"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$last_7d = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
		);

		$by_status = array(
			'new'     => 0,
			'read'    => 0,
			'replied' => 0,
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$status_rows = $wpdb->get_results(
			"SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status",
			ARRAY_A
		);
		foreach ( (array) $status_rows as $row ) {
			$status = (string) ( $row['status'] ?? '' );
			if ( isset( $by_status[ $status ] ) ) {
				$by_status[ $status ] = (int) ( $row['n'] ?? 0 );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$recent_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, form_name, sender_name, sender_email, subject, message, created_at, status
				 FROM {$table}
				 ORDER BY created_at DESC
				 LIMIT %d",
				self::RECENT_LIMIT
			),
			ARRAY_A
		);

		$recent = array();
		foreach ( (array) $recent_rows as $row ) {
			$recent[] = self::shape_submission( (array) $row );
		}

		return array(
			'active'            => true,
			'totalSubmissions'  => $total,
			'newToday'          => $new_today,
			'last7d'            => $last_7d,
			'byStatus'          => $by_status,
			'recentSubmissions' => $recent,
		);
	}

	/**
	 * Newsletter subscribers (Formistic's separate subscribers table — the
	 * owner wants newsletter counts clearly split from the enquiry inbox).
	 */
	private function subscribers(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wpistic_formistic_subscribers';
		if ( ! $this->table_exists( $table ) ) {
			return self::empty_subscribers();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE status = 'active'"
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$last_30d = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table}
				WHERE status = 'active' AND consent_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
		);

		return array(
			'active'  => true,
			'total'   => $total,
			'last30d' => $last_30d,
		);
	}

	/**
	 * Messageistic SMS stats via its own Dashboard_API — called in-process,
	 * never over HTTP.
	 */
	private function messageistic(): array {
		if ( ! class_exists( '\Messageistic\REST\Dashboard_API' ) ) {
			return self::empty_messageistic();
		}

		try {
			$response = ( new \Messageistic\REST\Dashboard_API() )->dashboard_stats();
			$raw      = $response instanceof \WP_REST_Response
				? (array) $response->get_data()
				: (array) $response;
		} catch ( \Throwable $e ) {
			return array(
				'active' => true,
				'stats'  => null,
			);
		}

		return array(
			'active' => true,
			'stats'  => self::shape_messageistic_stats( $raw ),
		);
	}

	/**
	 * Map Messageistic's snake_case stats onto the dashboard's camelCase.
	 *
	 * @internal Exposed for tests.
	 *
	 * @param array<string, mixed> $raw
	 * @return array<string, int>
	 */
	public static function shape_messageistic_stats( array $raw ): array {
		return array(
			'totalSent'       => (int) ( $raw['total_sent'] ?? 0 ),
			'delivered'       => (int) ( $raw['delivered'] ?? 0 ),
			'failed'          => (int) ( $raw['failed'] ?? 0 ),
			'replies'         => (int) ( $raw['replies'] ?? 0 ),
			'contacts'        => (int) ( $raw['contacts'] ?? 0 ),
			'optedOut'        => (int) ( $raw['opted_out'] ?? 0 ),
			'activeCampaigns' => (int) ( $raw['active_camps'] ?? 0 ),
		);
	}

	/**
	 * Project one submissions row into the REST shape. Whitespace-collapsed
	 * message excerpt, capped length — the dashboard never needs the body.
	 *
	 * @internal Exposed for tests.
	 *
	 * @param array<string, mixed> $row
	 */
	public static function shape_submission( array $row ): array {
		$message = (string) ( $row['message'] ?? '' );
		$excerpt = trim( (string) preg_replace( '/\s+/', ' ', $message ) );
		if ( strlen( $excerpt ) > self::EXCERPT_LENGTH ) {
			$excerpt = rtrim( substr( $excerpt, 0, self::EXCERPT_LENGTH ) ) . '…';
		}

		$status = (string) ( $row['status'] ?? 'new' );
		if ( ! in_array( $status, array( 'new', 'read', 'replied' ), true ) ) {
			$status = 'new';
		}

		return array(
			'id'             => (int) ( $row['id'] ?? 0 ),
			'formName'       => (string) ( $row['form_name'] ?? '' ),
			'name'           => (string) ( $row['sender_name'] ?? '' ),
			'email'          => (string) ( $row['sender_email'] ?? '' ),
			'subject'        => (string) ( $row['subject'] ?? '' ),
			'messageExcerpt' => $excerpt,
			'createdAt'      => (string) ( $row['created_at'] ?? '' ),
			'status'         => $status,
		);
	}

	public static function empty_formistic(): array {
		return array(
			'active'            => false,
			'totalSubmissions'  => null,
			'newToday'          => null,
			'last7d'            => null,
			'byStatus'          => null,
			'recentSubmissions' => array(),
		);
	}

	public static function empty_subscribers(): array {
		return array(
			'active'  => false,
			'total'   => null,
			'last30d' => null,
		);
	}

	public static function empty_messageistic(): array {
		return array(
			'active' => false,
			'stats'  => null,
		);
	}

	private function table_exists( string $table ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}
}
