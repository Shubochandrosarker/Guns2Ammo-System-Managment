<?php
/**
 * Weekly business report handler.
 *
 * The real weekly report is the Report Agent's AI narration — it runs
 * `ag-reports` in-process (this handler already executes inside a WP-Cron
 * context, same reasoning as the Agent_*_Refresh_Handlers) and reads back
 * its `lastOutput`. The deterministic Report_Generator body is kept ONLY as
 * a graceful fallback for when Anthropic isn't configured or the run fails
 * — see `is_agent_failure_output()` — so a misconfigured/unavailable AI
 * connection never results in an empty or missing weekly report.
 *
 * Either way, the draft lands in Email_Draft_Store — an owner reviews +
 * sends from either WP-admin or the dashboard's Email Management page —
 * and Reports_Store gets a copy so the dashboard can render the last
 * delivery.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Automation\Handlers;

use WordPressistic\G2ABA\Agents\Agent_Runner;
use WordPressistic\G2ABA\Agents\Agent_Store;
use WordPressistic\G2ABA\Ops\Email_Draft_Store;
use WordPressistic\G2ABA\Ops\Opt_Out_Store;
use WordPressistic\G2ABA\Providers\Booking_Provider;
use WordPressistic\G2ABA\Providers\Membership_Provider;
use WordPressistic\G2ABA\Providers\Revenue_Provider;
use WordPressistic\G2ABA\Providers\SEO_Provider;
use WordPressistic\G2ABA\Providers\Store_Provider;
use WordPressistic\G2ABA\Range;
use WordPressistic\G2ABA\Reports\Report_Generator;
use WordPressistic\G2ABA\Reports\Reports_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Weekly_Report_Handler extends Handler_Base {
	public const REPORT_ID = 'weekly-business';
	public const AGENT_ID  = 'ag-reports';

	/**
	 * Prefixes Agent_Runner::run() writes as `lastOutput` when the run did
	 * NOT produce a usable AI narrative. Quoted verbatim (leading substring)
	 * from Agent_Runner::run():
	 *   - "Anthropic connection not configured. Set an API key under Settings → G2A Business API."
	 *   - "Run failed: " . $e->getMessage()
	 */
	private const FAILURE_PREFIXES = array(
		'Anthropic connection not configured.',
		'Run failed:',
	);

	public static function slug(): string {
		return 'weekly-business-report';
	}

	public static function run(): string {
		$range = new Range( gmdate( 'Y-m-d', strtotime( '-6 days' ) ), gmdate( 'Y-m-d' ) );
		$from  = $range->from;
		$to    = $range->to;

		Agent_Runner::run( self::AGENT_ID );
		$agent     = ( new Agent_Store() )->find( self::AGENT_ID );
		$ai_output = null !== $agent ? trim( (string) ( $agent['lastOutput'] ?? '' ) ) : '';
		$used_ai   = ! self::is_agent_failure_output( $ai_output );

		if ( $used_ai ) {
			$body    = $ai_output;
			$payload = array(
				'body'   => $body,
				'format' => 'text',
				'range'  => $range->to_array(),
			);
		} else {
			$payload = ( new Report_Generator() )->generate( 'weekly-business' );
			$body    = (string) ( $payload['body'] ?? '' );
		}

		( new Reports_Store() )->record_delivery( self::REPORT_ID, $payload );

		( new Email_Draft_Store() )->enqueue(
			'Weekly business report',
			'',
			sprintf( 'Weekly business report — %s to %s', $from, $to ),
			$body,
			Opt_Out_Store::CATEGORY_INTERNAL
		);

		return sprintf(
			'%s weekly report drafted (%s → %s).',
			$used_ai ? 'AI-narrated' : 'Fallback',
			$from,
			$to
		);
	}

	/**
	 * Whether $text looks like one of Agent_Runner's failure messages rather
	 * than a genuine AI narrative — also true for an empty string, since an
	 * agent that has never run (or whose record vanished) has nothing usable
	 * to narrate either.
	 *
	 * @internal Exposed for tests.
	 */
	public static function is_agent_failure_output( string $text ): bool {
		$text = trim( $text );
		if ( '' === $text ) {
			return true;
		}
		foreach ( self::FAILURE_PREFIXES as $prefix ) {
			if ( 0 === strpos( $text, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Kept for backward compatibility with existing tests + any external
	 * caller that constructed the body directly. Delegates to the shared
	 * generator so there's one source of truth.
	 *
	 * @internal
	 */
	public static function compose_body(
		Range $range,
		array $revenue,
		array $bookings,
		array $members,
		array $store,
		array $seo
	): string {
		return Report_Generator::compose_weekly( $range, $revenue, $bookings, $members, $store, $seo );
	}
}
