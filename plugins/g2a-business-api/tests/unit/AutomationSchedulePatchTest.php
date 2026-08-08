<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Automation\Automation_Store;
use WordPressistic\G2ABA\Automation\Cron_Scheduler;

class AutomationSchedulePatchTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['g2aba_test_options']        = array();
		$GLOBALS['g2aba_test_cron_scheduled'] = array();
	}

	public function test_patch_schedule_updates_interval_and_returns_hydrated_record() {
		Automation_Store::seed_defaults();
		$out = ( new Automation_Store() )->patch_schedule( 'booking-reminder-24h', array( 'interval' => 'daily' ) );

		$this->assertNotNull( $out );
		$this->assertSame( 'daily', $out['interval'] );
	}

	public function test_patch_schedule_updates_status() {
		Automation_Store::seed_defaults();
		$out = ( new Automation_Store() )->patch_schedule( 'booking-reminder-24h', array( 'status' => 'paused' ) );
		$this->assertSame( 'paused', $out['status'] );
	}

	public function test_patch_schedule_rejects_unknown_interval() {
		Automation_Store::seed_defaults();
		$this->assertNull( ( new Automation_Store() )->patch_schedule( 'booking-reminder-24h', array( 'interval' => 'yearly' ) ) );
	}

	public function test_patch_schedule_rejects_unknown_status() {
		Automation_Store::seed_defaults();
		$this->assertNull( ( new Automation_Store() )->patch_schedule( 'booking-reminder-24h', array( 'status' => 'exploded' ) ) );
	}

	public function test_patch_schedule_returns_null_for_unknown_slug() {
		Automation_Store::seed_defaults();
		$this->assertNull( ( new Automation_Store() )->patch_schedule( 'nope', array( 'interval' => 'daily' ) ) );
	}

	public function test_patch_schedule_persists_to_option() {
		Automation_Store::seed_defaults();
		$store = new Automation_Store();
		$store->patch_schedule( 'weekly-business-report', array( 'interval' => 'daily' ) );

		$fresh = $store->find( 'weekly-business-report' );
		$this->assertSame( 'daily', $fresh['interval'] );
	}

	public function test_reschedule_swaps_wp_cron_interval() {
		Automation_Store::seed_defaults();
		Cron_Scheduler::sync_all();

		$this->assertSame( 'weekly', $GLOBALS['g2aba_test_cron_scheduled']['g2aba_run_weekly_report']['interval'] );

		$updated = ( new Automation_Store() )->patch_schedule( 'weekly-business-report', array( 'interval' => 'daily' ) );
		Cron_Scheduler::apply( $updated );

		$this->assertSame( 'daily', $GLOBALS['g2aba_test_cron_scheduled']['g2aba_run_weekly_report']['interval'] );
	}

	public function test_empty_patch_is_noop_returning_record() {
		Automation_Store::seed_defaults();
		$before = ( new Automation_Store() )->find( 'booking-reminder-24h' );
		$after  = ( new Automation_Store() )->patch_schedule( 'booking-reminder-24h', array() );
		$this->assertSame( $before['interval'], $after['interval'] );
		$this->assertSame( $before['status'], $after['status'] );
	}
}
