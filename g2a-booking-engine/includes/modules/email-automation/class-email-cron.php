<?php
/**
 * Email reminder cron — fires 24h-before and 2h-before booking start.
 *
 * Uses WP-Cron with a 5-minute interval. On each tick we query bookings
 * whose start_at falls within the relevant window AND haven't been notified
 * (tracked via meta-style entry in g2ab_logs).
 *
 * @package G2AB\Modules\EmailAutomation
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class G2AB_Email_Cron {

	const HOOK_TICK    = 'g2ab_email_reminder_tick';
	const LOG_24H      = 'reminder_24h_sent';
	const LOG_2H       = 'reminder_2h_sent';

	private $engine;

	public function __construct( $engine ) {
		$this->engine = $engine;
		add_action( self::HOOK_TICK, array( $this, 'tick' ) );

		// Schedule on plugin init if not already scheduled.
		if ( ! wp_next_scheduled( self::HOOK_TICK ) ) {
			wp_schedule_event( time() + 60, 'every_five_minutes', self::HOOK_TICK );
		}

		// Clean up on module deactivate.
		add_action( 'g2ab_module_deactivated_email_automation', array( $this, 'unschedule' ) );
	}

	public function unschedule() {
		$ts = wp_next_scheduled( self::HOOK_TICK );
		if ( $ts ) wp_unschedule_event( $ts, self::HOOK_TICK );
	}

	/**
	 * Cron tick — find upcoming bookings + send reminders.
	 */
	public function tick() {
		global $wpdb;
		$bk = $wpdb->prefix . 'g2ab_bookings';
		$lg = $wpdb->prefix . 'g2ab_logs';

		// Only care about confirmed/paid bookings — reservations not yet paid don't get reminders.
		$active_statuses = "'reserved','confirmed','paid'";

		// bookings.start_at is stored as site-local wall-clock time (see
		// class-bookings-controller.php), so window bounds must be computed
		// as site-local wall-clock too. Using a wp_timezone()-aware
		// DateTimeImmutable (rather than strtotime()+gmdate(), which only
		// produced the right wall-clock string because parsing "as UTC" and
		// formatting "as UTC" happened to cancel out — fragile, and wrong
		// the moment the site timezone observes DST) keeps this correct
		// across DST transitions.
		$tz  = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( date_default_timezone_get() );
		$now = new DateTimeImmutable( current_time( 'mysql' ), $tz );

		// 24h window: start_at between (now+23h) and (now+25h)
		$start_24 = $now->modify( '+23 hours' )->format( 'Y-m-d H:i:s' );
		$end_24   = $now->modify( '+25 hours' )->format( 'Y-m-d H:i:s' );

		$rows_24 = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$bk} WHERE start_at BETWEEN %s AND %s AND status IN ({$active_statuses}) AND id NOT IN ( SELECT booking_id FROM {$lg} WHERE booking_id IS NOT NULL AND event_type = %s ) ORDER BY start_at ASC LIMIT 200",
			$start_24, $end_24, self::LOG_24H
		) ); // phpcs:ignore

		if ( $rows_24 ) {
			foreach ( $rows_24 as $b ) {
				$res = $this->engine->send_event( 'booking_reminder_24h', $b );
				// Only mark as sent when at least one recipient send succeeded,
				// so a failed send is retried on the next tick.
				if ( is_array( $res ) && in_array( true, $res, true ) ) {
					$this->log( $b->id, self::LOG_24H );
				}
			}
		}

		// 2h window: start_at between (now+1h45m) and (now+2h15m)
		$start_2 = $now->modify( '+105 minutes' )->format( 'Y-m-d H:i:s' );
		$end_2   = $now->modify( '+135 minutes' )->format( 'Y-m-d H:i:s' );

		$rows_2 = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$bk} WHERE start_at BETWEEN %s AND %s AND status IN ({$active_statuses}) AND id NOT IN ( SELECT booking_id FROM {$lg} WHERE booking_id IS NOT NULL AND event_type = %s ) ORDER BY start_at ASC LIMIT 200",
			$start_2, $end_2, self::LOG_2H
		) ); // phpcs:ignore

		if ( $rows_2 ) {
			foreach ( $rows_2 as $b ) {
				$res = $this->engine->send_event( 'booking_reminder_2h', $b );
				if ( is_array( $res ) && in_array( true, $res, true ) ) {
					$this->log( $b->id, self::LOG_2H );
				}
			}
		}
	}

	private function log( $booking_id, $event ) {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'g2ab_logs',
			array(
				'booking_id' => (int) $booking_id,
				'event_type' => sanitize_key( $event ),
				'severity'   => 'info',
				'message'    => '',
				'context'    => '{}',
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}
}
