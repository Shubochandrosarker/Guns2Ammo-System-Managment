<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Agents\Agent_Store;
use WordPressistic\G2ABA\Automation\Automation_Store;

/**
 * Automation_Store::maybe_reseed() is the version-gated self-heal that lets
 * Plugin::run() pick up new/changed automations + agents on an
 * already-activated install (Activator::activate() only fires on
 * activation, never on a plugin update) — mirrors
 * Leads_Installer::maybe_install()'s idiom.
 */
class AutomationStoreMaybeReseedTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['g2aba_test_options']        = array();
		$GLOBALS['g2aba_test_cron_scheduled'] = array();
	}

	public function test_reseed_runs_when_version_option_is_absent() {
		Automation_Store::maybe_reseed();

		$this->assertSame(
			Automation_Store::DEFS_VERSION,
			get_option( 'g2aba_automation_defs_version' )
		);

		$slugs = array_column( ( new Automation_Store() )->all(), 'slug' );
		$this->assertContains( 'agent-refresh-hourly', $slugs );
		$this->assertContains( 'agent-refresh-daily', $slugs );

		$agent_ids = array_column( ( new Agent_Store() )->all(), 'id' );
		$this->assertContains( 'ag-seo', $agent_ids );

		// Cron_Scheduler::sync_all() must have run too — the new active
		// hourly automation is scheduled.
		$this->assertNotFalse( wp_next_scheduled( 'g2aba_run_agent_refresh_hourly' ) );
	}

	public function test_reseed_runs_when_version_option_is_stale() {
		$GLOBALS['g2aba_test_options']['g2aba_automation_defs_version'] = '0.0.1-stale';

		Automation_Store::maybe_reseed();

		$this->assertSame( Automation_Store::DEFS_VERSION, get_option( 'g2aba_automation_defs_version' ) );
		$slugs = array_column( ( new Automation_Store() )->all(), 'slug' );
		$this->assertContains( 'agent-refresh-daily', $slugs );
	}

	public function test_reseed_is_a_noop_when_version_matches() {
		$GLOBALS['g2aba_test_options']['g2aba_automation_defs_version'] = Automation_Store::DEFS_VERSION;

		// Sentinel cron state that seed_defaults()+sync_all() would
		// overwrite if they actually ran — a matching version must leave it
		// completely untouched.
		$GLOBALS['g2aba_test_cron_scheduled']['g2aba_run_weekly_report'] = array(
			'ts'       => 424242,
			'interval' => 'weekly',
			'args'     => array(),
		);

		Automation_Store::maybe_reseed();

		$this->assertSame( 424242, $GLOBALS['g2aba_test_cron_scheduled']['g2aba_run_weekly_report']['ts'] );
		$this->assertSame( array(), ( new Automation_Store() )->all(), 'seed_defaults() must not have run.' );
		$this->assertSame( array(), ( new Agent_Store() )->all(), 'Agent_Store::seed_defaults() must not have run.' );
	}
}
