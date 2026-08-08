<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Agents\Agent_Store;
use WordPressistic\G2ABA\Automation\Handlers\Agent_Hourly_Refresh_Handler;

class AgentHourlyRefreshHandlerTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['g2aba_test_options'] = array();
	}

	public function test_slug_is_stable() {
		$this->assertSame( 'agent-refresh-hourly', Agent_Hourly_Refresh_Handler::slug() );
	}

	public function test_skips_when_agent_record_is_missing() {
		// No Agent_Store::seed_defaults() call at all — find() returns null.
		$result = Agent_Hourly_Refresh_Handler::run();
		$this->assertSame( 'Skipped: paused.', $result );
	}

	public function test_skips_and_does_not_invoke_the_runner_when_paused() {
		Agent_Store::seed_defaults();
		$raw                                  = $GLOBALS['g2aba_test_options']['g2aba_agents'];
		$raw['ag-email']['status']            = Agent_Store::STATUS_PAUSED;
		$GLOBALS['g2aba_test_options']['g2aba_agents'] = $raw;

		$result = Agent_Hourly_Refresh_Handler::run();

		$this->assertSame( 'Skipped: paused.', $result );
		// If Agent_Runner::run() had been invoked it would have stamped
		// lastRun (Anthropic being unconfigured in this suite still records
		// a "not configured" run) — it must stay null, proving the runner
		// was never called for a paused agent.
		$this->assertNull( ( new Agent_Store() )->find( 'ag-email' )['lastRun'] );
	}

	public function test_refreshes_the_email_agent_when_active() {
		Agent_Store::seed_defaults(); // ag-email defaults to active.

		$result = Agent_Hourly_Refresh_Handler::run();

		$this->assertSame( 'Email Manager refreshed.', $result );
		// The runner having actually executed is observable via the
		// now-non-null lastRun stamp (Agent_Runner::run() always calls
		// record_run(), even on its "Anthropic not configured" fast path).
		$this->assertNotNull( ( new Agent_Store() )->find( 'ag-email' )['lastRun'] );
	}
}
