<?php
/**
 * Agent: hourly refresh handler.
 *
 * Refreshes the Email Manager agent every hour so new Formistic enquiries
 * get a drafted reply queued for review without waiting on the daily
 * refresh cadence. This handler already executes inside a WP-Cron context
 * (see Handler_Base::invoke()), so it calls Agent_Runner::run() directly
 * rather than scheduling another single event.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Automation\Handlers;

use WordPressistic\G2ABA\Agents\Agent_Runner;
use WordPressistic\G2ABA\Agents\Agent_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Agent_Hourly_Refresh_Handler extends Handler_Base {
	public const AGENT_ID = 'ag-email';

	public static function slug(): string {
		return 'agent-refresh-hourly';
	}

	public static function run(): string {
		$agent = ( new Agent_Store() )->find( self::AGENT_ID );
		if ( null === $agent || Agent_Store::STATUS_ACTIVE !== ( $agent['status'] ?? '' ) ) {
			return 'Skipped: paused.';
		}

		Agent_Runner::run( self::AGENT_ID );
		return 'Email Manager refreshed.';
	}
}
