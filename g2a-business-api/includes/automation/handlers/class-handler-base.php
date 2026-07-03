<?php
/**
 * Shared harness for automation handlers.
 *
 * A handler's contract:
 *
 *   - `slug()`   returns the Automation_Store slug (also the WP-Cron hook name).
 *   - `run()`    does the work, returns a short human-readable result.
 *
 * The base class subscribes on register(), catches exceptions, and records
 * the result back on the Automation_Store so the dashboard reflects the
 * latest outcome. Handlers themselves stay small and focused.
 *
 * Safety note: every handler in this plugin writes to Email_Draft_Store —
 * it does NOT auto-send. A human still reviews the draft before it goes
 * out. Actual sending happens through the existing Email_Sender path,
 * which is owner-approved and audit-logged.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Automation\Handlers;

use WordPressistic\G2ABA\Automation\Automation_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Handler_Base {
	abstract public static function slug(): string;
	abstract public static function run(): string;

	public static function register(): void {
		$slug = static::slug();
		if ( '' === $slug ) {
			return;
		}
		$record = ( new Automation_Store() )->find( $slug );
		if ( null === $record ) {
			return;
		}
		add_action( (string) ( $record['handler'] ?? '' ), array( static::class, 'invoke' ) );
	}

	public static function invoke(): void {
		$slug = static::slug();
		try {
			$result = static::run();
		} catch ( \Throwable $e ) {
			$result = 'Handler failed: ' . $e->getMessage();
		}
		( new Automation_Store() )->record_run( $slug, $result );
	}
}
