<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Agents\Agent_Store;
use WordPressistic\G2ABA\Automation\Handlers\Agent_Daily_Refresh_Handler;

class AgentDailyRefreshHandlerTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['g2aba_test_options'] = array();
	}

	public function test_slug_is_stable() {
		$this->assertSame( 'agent-refresh-daily', Agent_Daily_Refresh_Handler::slug() );
	}

	public function test_agent_ids_cover_the_six_non_hourly_departments() {
		$this->assertSame(
			array( 'ag-analyst', 'ag-booking', 'ag-member', 'ag-store', 'ag-sales', 'ag-seo' ),
			Agent_Daily_Refresh_Handler::AGENT_IDS
		);
	}

	// -----------------------------------------------------------------
	// summarize() — the pure helper the loop's bookkeeping funnels into.
	// Tested directly per the task brief: Agent_Runner::run() can't be
	// meaningfully stubbed from this suite (no HTTP layer swap-in point),
	// so the skip/failure summary logic is isolated here instead.
	// -----------------------------------------------------------------

	public function test_summarize_with_no_skips_or_failures() {
		$out = Agent_Daily_Refresh_Handler::summarize( 6, 6, array(), array() );
		$this->assertSame( 'Refreshed 6/6 agents.', $out );
	}

	public function test_summarize_names_paused_agents() {
		$out = Agent_Daily_Refresh_Handler::summarize( 5, 6, array( 'ag-seo' ), array() );
		$this->assertSame( 'Refreshed 5/6 agents (ag-seo paused).', $out );
	}

	public function test_summarize_names_failed_agents() {
		$out = Agent_Daily_Refresh_Handler::summarize( 5, 6, array(), array( 'ag-store' ) );
		$this->assertSame( 'Refreshed 5/6 agents (ag-store failed).', $out );
	}

	public function test_summarize_combines_paused_and_failed() {
		$out = Agent_Daily_Refresh_Handler::summarize( 4, 6, array( 'ag-seo', 'ag-sales' ), array( 'ag-store' ) );
		$this->assertSame( 'Refreshed 4/6 agents (ag-seo, ag-sales paused; ag-store failed).', $out );
	}

	// -----------------------------------------------------------------
	// run() — exercised live (Anthropic is unconfigured in this suite, so
	// every active agent's Agent_Runner::run() call takes its harmless
	// "not configured" fast path rather than making an HTTP call).
	// -----------------------------------------------------------------

	public function test_run_skips_a_paused_agent_and_refreshes_the_rest() {
		Agent_Store::seed_defaults();
		$raw                                          = $GLOBALS['g2aba_test_options']['g2aba_agents'];
		$raw['ag-seo']['status']                      = Agent_Store::STATUS_PAUSED;
		$GLOBALS['g2aba_test_options']['g2aba_agents'] = $raw;

		$result = Agent_Daily_Refresh_Handler::run();

		$this->assertSame( 'Refreshed 5/6 agents (ag-seo paused).', $result );
		$this->assertNull( ( new Agent_Store() )->find( 'ag-seo' )['lastRun'], 'Paused agent must never be invoked.' );
		$this->assertNotNull( ( new Agent_Store() )->find( 'ag-analyst' )['lastRun'], 'Active agents must have been invoked.' );
	}

	public function test_run_refreshes_all_six_when_all_active() {
		Agent_Store::seed_defaults();

		$result = Agent_Daily_Refresh_Handler::run();

		$this->assertSame( 'Refreshed 6/6 agents.', $result );
		foreach ( Agent_Daily_Refresh_Handler::AGENT_IDS as $id ) {
			$this->assertNotNull( ( new Agent_Store() )->find( $id )['lastRun'] );
		}
	}
}
