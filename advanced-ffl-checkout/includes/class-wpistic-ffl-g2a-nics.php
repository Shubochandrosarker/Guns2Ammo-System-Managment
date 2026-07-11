<?php
/**
 * G2A — NICS 3-business-day rule helper.
 *
 * When a transfer enters the `delayed` state, federal law allows the dealer
 * to proceed at their discretion 3 business days later. This class fills in
 * the `nics_delay_expires` column when the status flips to delayed and runs
 * a nightly sweep to surface delays that have reached the threshold.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_NICS {

	public function __construct() {
		// Auto-set nics_delay_expires when status changes to a delayed bucket.
		add_action( 'wpistic_ffl_transfer_status_changed', [ __CLASS__, 'maybe_set_expiry' ], 5, 3 );
	}

	/**
	 * @param int    $transfer_id
	 * @param string $old_status
	 * @param string $new_status
	 */
	public static function maybe_set_expiry( int $transfer_id, string $old_status, string $new_status ): void {
		if ( ! in_array( $new_status, [ 'delayed', 'nics_delayed' ], true ) ) {
			return;
		}
		global $wpdb;
		$expires = self::three_business_days_from_now();
		$wpdb->update( // phpcs:ignore WordPress.DB
			DB::table( 'transfers' ),
			[
				'nics_delay_expires' => $expires,
				'nics_check_date'    => current_time( 'Y-m-d' ),
				'updated_at'         => current_time( 'mysql' ),
			],
			[ 'id' => $transfer_id ]
		);
	}

	/**
	 * Daily sweep — for every delayed transfer whose 3-day window has passed,
	 * email the admin once (using transfer staff_notes to suppress duplicates).
	 * Wired from the bootstrap via wpistic_ffl_daily_portal_runner.
	 */
	public static function maybe_flag_delays(): void {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT t.id, t.transfer_ref, t.customer_name, t.customer_email, t.nics_delay_expires
			 FROM ' . DB::table( 'transfers' ) . ' t
			 WHERE t.status IN (%s, %s)
			   AND t.nics_delay_expires IS NOT NULL
			   AND t.nics_delay_expires <= %s
			   AND (t.staff_notes IS NULL OR t.staff_notes NOT LIKE %s)
			 LIMIT 50',
			'delayed',
			'nics_delayed',
			current_time( 'Y-m-d' ),
			'%[nics-3day-flagged]%'
		) );

		if ( ! $rows ) {
			return;
		}

		$admin_email = get_option( 'wpistic_ffl_settings', [] )['notification_email'] ?? get_option( 'admin_email' );

		foreach ( (array) $rows as $row ) {
			$subject = sprintf( '[G2A] ⏰ NICS 3-Day Rule Reached — Transfer #%s', $row->transfer_ref );
			$body    = '<p>The federal NICS 3-day delay window has elapsed for the following transfer.</p>' .
				'<p><strong>Transfer:</strong> #' . esc_html( $row->transfer_ref ) . '<br>' .
				'<strong>Customer:</strong> ' . esc_html( $row->customer_name ) . '<br>' .
				'<strong>Delay expired:</strong> ' . esc_html( $row->nics_delay_expires ) . '</p>' .
				'<p>The dealer may, at their discretion, proceed with the transfer per 18 U.S.C. § 922(t)(1)(B)(ii).</p>' .
				'<p><a href="' . esc_url( admin_url( 'admin.php?page=wpistic-ffl-transfers&id=' . (int) $row->id ) ) . '">View transfer</a></p>';

			wp_mail( $admin_email, $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ] );

			// Append a flag marker so we don't re-email on the next run.
			$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
				'UPDATE ' . DB::table( 'transfers' ) . '
				 SET staff_notes = CONCAT( COALESCE(staff_notes,""), %s )
				 WHERE id = %d',
				"\n[nics-3day-flagged " . current_time( 'mysql' ) . ']',
				(int) $row->id
			) );

			$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB
				'transfer_id' => (int) $row->id,
				'event_type'  => 'nics_3day_reached',
				'notes'       => 'Federal 3-day delay window elapsed.',
				'actor'       => 'system',
				'actor_ip'    => '',
			] );
		}
	}

	/**
	 * Add 3 business days (Mon-Fri, excluding federal holidays) to today.
	 * ATF 4473 instructions count "business days" as days the FFL is open —
	 * a dealer is normally closed on federal holidays same as weekends, so a
	 * holiday inside the window must not count toward the 3 days or the
	 * computed expiry lands before the true statutory window under
	 * 18 U.S.C. § 922(t)(1)(B)(ii).
	 */
	private static function three_business_days_from_now(): string {
		$ts    = current_time( 'timestamp' );
		$added = 0;
		while ( $added < 3 ) {
			$ts  = strtotime( '+1 day', $ts );
			$dow = (int) date( 'N', $ts ); // 1=Mon..7=Sun
			if ( $dow < 6 && ! self::is_federal_holiday( date( 'Y-m-d', $ts ) ) ) {
				$added++;
			}
		}
		return date( 'Y-m-d', $ts );
	}

	/**
	 * Whether $ymd (Y-m-d) is an observed federal holiday. Fixed-date holidays
	 * that fall on a Saturday/Sunday are shifted to the adjacent Friday/Monday
	 * per the standard federal-observance rule (5 U.S.C. § 6103), since that's
	 * the day the FFL/dealer is actually closed.
	 */
	private static function is_federal_holiday( string $ymd ): bool {
		$year = (int) substr( $ymd, 0, 4 );
		return in_array( $ymd, self::federal_holidays_for_year( $year ), true );
	}

	/**
	 * The 11 federal holidays (OPM list) for a given year, as observed
	 * ('Y-m-d' strings). Filterable so a site owner can add/remove dates
	 * (e.g. a state-specific closure) without touching code.
	 */
	private static function federal_holidays_for_year( int $year ): array {
		static $cache = [];
		if ( isset( $cache[ $year ] ) ) {
			return $cache[ $year ];
		}

		$dates = [
			self::observed( "$year-01-01" ),                 // New Year's Day
			self::nth_weekday_of_month( $year, 1, 1, 3 ),    // MLK Day — 3rd Monday of Jan
			self::nth_weekday_of_month( $year, 2, 1, 3 ),    // Washington's Birthday — 3rd Monday of Feb
			self::last_weekday_of_month( $year, 5, 1 ),      // Memorial Day — last Monday of May
			self::observed( "$year-06-19" ),                 // Juneteenth
			self::observed( "$year-07-04" ),                 // Independence Day
			self::nth_weekday_of_month( $year, 9, 1, 1 ),    // Labor Day — 1st Monday of Sept
			self::nth_weekday_of_month( $year, 10, 1, 2 ),   // Columbus Day — 2nd Monday of Oct
			self::observed( "$year-11-11" ),                 // Veterans Day
			self::nth_weekday_of_month( $year, 11, 4, 4 ),   // Thanksgiving — 4th Thursday of Nov
			self::observed( "$year-12-25" ),                 // Christmas Day
		];

		/**
		 * Filter the federal holiday list (as 'Y-m-d' strings) used to compute
		 * the NICS 3-business-day window for a given year.
		 */
		$dates = (array) apply_filters( 'wpistic_ffl_federal_holidays', $dates, $year );
		$cache[ $year ] = array_values( array_unique( $dates ) );
		return $cache[ $year ];
	}

	/** Shift a fixed-date holiday off a weekend: Sat -> Fri, Sun -> Mon. */
	private static function observed( string $ymd ): string {
		$ts  = strtotime( $ymd );
		$dow = (int) date( 'N', $ts ); // 1=Mon..7=Sun
		if ( 6 === $dow ) {
			$ts = strtotime( '-1 day', $ts );
		} elseif ( 7 === $dow ) {
			$ts = strtotime( '+1 day', $ts );
		}
		return date( 'Y-m-d', $ts );
	}

	/**
	 * The date of the Nth occurrence of a weekday in a month.
	 *
	 * @param int $year
	 * @param int $month
	 * @param int $iso_weekday 1=Monday..7=Sunday.
	 * @param int $nth         1-based occurrence (e.g. 3 = third Monday).
	 */
	private static function nth_weekday_of_month( int $year, int $month, int $iso_weekday, int $nth ): string {
		$ts          = mktime( 0, 0, 0, $month, 1, $year );
		$first_dow   = (int) date( 'N', $ts );
		$offset_days = ( $iso_weekday - $first_dow + 7 ) % 7;
		$day         = 1 + $offset_days + ( ( $nth - 1 ) * 7 );
		return date( 'Y-m-d', mktime( 0, 0, 0, $month, $day, $year ) );
	}

	/** The date of the last occurrence of a weekday in a month. */
	private static function last_weekday_of_month( int $year, int $month, int $iso_weekday ): string {
		$last_day  = (int) date( 't', mktime( 0, 0, 0, $month, 1, $year ) );
		$ts        = mktime( 0, 0, 0, $month, $last_day, $year );
		$last_dow  = (int) date( 'N', $ts );
		$back_days = ( $last_dow - $iso_weekday + 7 ) % 7;
		return date( 'Y-m-d', mktime( 0, 0, 0, $month, $last_day - $back_days, $year ) );
	}
}
