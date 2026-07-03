<?php
/**
 * Persistent report catalog + latest-delivery tracking.
 *
 * A "report" is a named artefact the plugin can compose on demand or on
 * cron. Report_Generator does the composition; this store answers three
 * questions: which reports exist, when did each one last deliver, and
 * what's the body of the most recent delivery for each?
 *
 * Storage:
 *   g2aba_reports          => { id => { id,label,description,schedule,cronHook,handlerSlug } }
 *   g2aba_report_deliveries => { id => { id, generatedAt, body, format, range } }
 *
 * The definition table is seeded from the built-in catalog on first read
 * so `all()` never returns an empty list on a fresh install.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Reports;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Reports_Store {
	private const OPTION_DEFS       = 'g2aba_reports';
	private const OPTION_DELIVERIES = 'g2aba_report_deliveries';
	private const MAX_DELIVERIES    = 200;

	/**
	 * Built-in report catalog. Handler slugs point at Report_Generator's
	 * dispatch table. Reports without a handler slug are informational only
	 * — they'll show up in the list but "Run now" will surface a graceful
	 * "not yet implemented" message rather than throwing.
	 *
	 * @return array<int, array>
	 */
	public static function catalog(): array {
		return array(
			array(
				'id'          => 'daily-summary',
				'label'       => 'Daily summary',
				'description' => 'Yesterday\'s revenue, bookings, membership churn, top SEO movers.',
				'schedule'    => 'Every day 07:00',
				'cronHook'    => 'g2aba_report_daily_summary',
				'handlerSlug' => 'daily-summary',
			),
			array(
				'id'          => 'weekly-business',
				'label'       => 'Weekly business report',
				'description' => 'Last-7-day roll-up across revenue, bookings, memberships, store, SEO.',
				'schedule'    => 'Monday 07:00',
				'cronHook'    => 'g2aba_automation_weekly-business-report',
				'handlerSlug' => 'weekly-business',
			),
			array(
				'id'          => 'monthly-growth',
				'label'       => 'Monthly growth report',
				'description' => 'Trailing 30 days vs the previous 30 — revenue, MRR, active shooters.',
				'schedule'    => '1st of month 08:00',
				'cronHook'    => 'g2aba_report_monthly_growth',
				'handlerSlug' => 'monthly-growth',
			),
			array(
				'id'          => 'seo-report',
				'label'       => 'SEO report',
				'description' => 'GSC clicks, impressions, position deltas + Business Profile deltas.',
				'schedule'    => 'Monday 08:00',
				'cronHook'    => 'g2aba_report_seo',
				'handlerSlug' => 'seo',
			),
			array(
				'id'          => 'booking-report',
				'label'       => 'Booking report',
				'description' => 'Bookings by type, conversion, cancellations, top movers.',
				'schedule'    => 'Monday 08:15',
				'cronHook'    => 'g2aba_report_booking',
				'handlerSlug' => 'booking',
			),
			array(
				'id'          => 'member-report',
				'label'       => 'Membership report',
				'description' => 'Active / renewed / expired shooters + MRR + churn cohort.',
				'schedule'    => 'Monday 08:30',
				'cronHook'    => 'g2aba_report_member',
				'handlerSlug' => 'member',
			),
			array(
				'id'          => 'woo-report',
				'label'       => 'WooCommerce report',
				'description' => 'Store revenue, orders, AOV, top products.',
				'schedule'    => 'Monday 08:45',
				'cronHook'    => 'g2aba_report_woo',
				'handlerSlug' => 'woo',
			),
			array(
				'id'          => 'ai-recs',
				'label'       => 'AI recommendations report',
				'description' => 'Open AI insights + business gaps + suggested tasks.',
				'schedule'    => 'Wednesday 09:00',
				'cronHook'    => 'g2aba_report_ai_recs',
				'handlerSlug' => 'ai-recs',
			),
		);
	}

	/**
	 * @return array<int, array>
	 */
	public function all(): array {
		$defs = $this->definitions();
		$deliv = $this->deliveries();
		$out   = array();
		foreach ( $defs as $def ) {
			$id            = (string) ( $def['id'] ?? '' );
			$last          = $deliv[ $id ] ?? null;
			$def['lastDeliveredAt'] = $last ? (string) ( $last['generatedAt'] ?? '' ) : null;
			$def['hasLatest']       = null !== $last;
			$out[]                  = $def;
		}
		return $out;
	}

	public function find( string $id ): ?array {
		foreach ( $this->all() as $def ) {
			if ( ( $def['id'] ?? '' ) === $id ) {
				return $def;
			}
		}
		return null;
	}

	public function latest( string $id ): ?array {
		$all = $this->deliveries();
		return $all[ $id ] ?? null;
	}

	/**
	 * Record a delivery. Only the most recent delivery per report is kept —
	 * this is a "latest" store, not a history log. Audit_Log covers "who
	 * ran what when."
	 *
	 * @param array{body:string,format?:string,range?:array} $payload
	 */
	public function record_delivery( string $id, array $payload ): array {
		$all           = $this->deliveries();
		$entry         = array(
			'id'          => $id,
			'generatedAt' => gmdate( 'c' ),
			'body'        => (string) ( $payload['body'] ?? '' ),
			'format'      => (string) ( $payload['format'] ?? 'text' ),
			'range'       => (array) ( $payload['range'] ?? array() ),
		);
		$all[ $id ]    = $entry;
		if ( count( $all ) > self::MAX_DELIVERIES ) {
			$all = array_slice( $all, -self::MAX_DELIVERIES, null, true );
		}
		update_option( self::OPTION_DELIVERIES, $all, false );
		return $entry;
	}

	/**
	 * @return array<int, array>
	 */
	private function definitions(): array {
		$raw = get_option( self::OPTION_DEFS, null );
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			$raw = self::catalog();
			update_option( self::OPTION_DEFS, $raw, false );
		}
		return $raw;
	}

	/**
	 * @return array<string, array>
	 */
	private function deliveries(): array {
		$raw = get_option( self::OPTION_DELIVERIES, array() );
		return is_array( $raw ) ? $raw : array();
	}
}
