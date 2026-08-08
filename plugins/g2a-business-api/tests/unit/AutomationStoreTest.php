<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Automation\Automation_Store;
use WordPressistic\G2ABA\Automation\Cron_Scheduler;

class AutomationStoreTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['g2aba_test_options']        = array();
		$GLOBALS['g2aba_test_cron_scheduled'] = array();
	}

	public function test_seed_defaults_populates_option_with_slugs() {
		Automation_Store::seed_defaults();
		$all = ( new Automation_Store() )->all();

		$slugs = array_column( $all, 'slug' );
		$this->assertContains( 'booking-reminder-24h', $slugs );
		$this->assertContains( 'weekly-business-report', $slugs );
	}

	public function test_seed_defaults_preserves_operator_status() {
		Automation_Store::seed_defaults();
		( new Automation_Store() )->set_status( 'booking-reminder-24h', Automation_Store::STATUS_PAUSED );

		// Re-seeding must not overwrite the operator's pause.
		Automation_Store::seed_defaults();
		$rec = ( new Automation_Store() )->find( 'booking-reminder-24h' );

		$this->assertSame( Automation_Store::STATUS_PAUSED, $rec['status'] );
	}

	public function test_set_status_rejects_unknown_status() {
		Automation_Store::seed_defaults();
		$this->assertNull( ( new Automation_Store() )->set_status( 'booking-reminder-24h', 'exploded' ) );
	}

	public function test_set_status_returns_null_for_unknown_slug() {
		Automation_Store::seed_defaults();
		$this->assertNull( ( new Automation_Store() )->set_status( 'nope', Automation_Store::STATUS_PAUSED ) );
	}

	public function test_record_run_bumps_counters_and_stamps() {
		Automation_Store::seed_defaults();
		$store = new Automation_Store();
		$store->record_run( 'weekly-business-report', 'ok' );
		$rec = $store->find( 'weekly-business-report' );

		$this->assertSame( 1, $rec['runsLast7d'] );
		$this->assertNotEmpty( $rec['lastRun'] );
		$this->assertSame( 'ok', $rec['lastResult'] );
	}

	public function test_cron_scheduler_schedules_active_and_unschedules_paused() {
		Automation_Store::seed_defaults();
		Cron_Scheduler::sync_all();

		// Active default → hook is scheduled.
		$this->assertNotFalse( wp_next_scheduled( 'g2aba_run_weekly_report' ) );

		// Toggle off and re-apply.
		$store  = new Automation_Store();
		$paused = $store->set_status( 'weekly-business-report', Automation_Store::STATUS_PAUSED );
		Cron_Scheduler::apply( $paused );

		$this->assertFalse( wp_next_scheduled( 'g2aba_run_weekly_report' ) );
	}

	public function test_cron_scheduler_refuses_unknown_interval() {
		$rec = array(
			'handler'  => 'g2aba_run_weird',
			'status'   => Automation_Store::STATUS_ACTIVE,
			'interval' => 'yearly', // Not one of hourly/twicedaily/daily/weekly.
		);
		Cron_Scheduler::apply( $rec );
		$this->assertFalse( wp_next_scheduled( 'g2aba_run_weird' ) );
	}
}
