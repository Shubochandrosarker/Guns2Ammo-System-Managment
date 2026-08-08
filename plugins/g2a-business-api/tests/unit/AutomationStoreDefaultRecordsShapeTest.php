<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Automation\Automation_Store;

/**
 * Phase C added two recurring agent-refresh automations to
 * Automation_Store::default_records(). This pins their shape the same way
 * AgentStoreDefaultsShapeTest pins Agent_Store::defaults().
 */
class AutomationStoreDefaultRecordsShapeTest extends TestCase {
	public function test_agent_refresh_hourly_record_shape() {
		$rec = Automation_Store::default_records()['agent-refresh-hourly'];

		$this->assertSame( 'agent-refresh-hourly', $rec['slug'] );
		$this->assertSame( 'ai', $rec['category'] );
		$this->assertContains( $rec['interval'], Automation_Store::ALLOWED_INTERVALS );
		$this->assertSame( 'hourly', $rec['interval'] );
		$this->assertSame( 'g2aba_run_agent_refresh_hourly', $rec['handler'] );
		$this->assertSame( Automation_Store::STATUS_ACTIVE, $rec['status'] );
		$this->assertNotEmpty( $rec['trigger'] );
		$this->assertNotEmpty( $rec['action'] );
	}

	public function test_agent_refresh_daily_record_shape() {
		$rec = Automation_Store::default_records()['agent-refresh-daily'];

		$this->assertSame( 'agent-refresh-daily', $rec['slug'] );
		$this->assertSame( 'ai', $rec['category'] );
		$this->assertContains( $rec['interval'], Automation_Store::ALLOWED_INTERVALS );
		$this->assertSame( 'daily', $rec['interval'] );
		$this->assertSame( 'g2aba_run_agent_refresh_daily', $rec['handler'] );
		$this->assertSame( Automation_Store::STATUS_ACTIVE, $rec['status'] );
		$this->assertNotEmpty( $rec['trigger'] );
		$this->assertNotEmpty( $rec['action'] );
	}

	public function test_both_new_automations_are_present_in_default_records() {
		$slugs = array_keys( Automation_Store::default_records() );
		$this->assertContains( 'agent-refresh-hourly', $slugs );
		$this->assertContains( 'agent-refresh-daily', $slugs );
	}
}
