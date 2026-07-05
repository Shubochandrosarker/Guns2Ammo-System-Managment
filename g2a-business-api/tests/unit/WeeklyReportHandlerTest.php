<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Agents\Agent_Store;
use WordPressistic\G2ABA\Automation\Handlers\Weekly_Report_Handler;
use WordPressistic\G2ABA\Range;

class WeeklyReportHandlerTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	public function test_compose_body_covers_all_channels() {
		$range = new Range( '2026-06-27', '2026-07-03' );
		$body  = Weekly_Report_Handler::compose_body(
			$range,
			array( 'totalRevenue' => 4_812_400, 'revenueGrowthPct' => 12.4 ),
			array( 'bookingsByType' => array(
				array( 'type' => 'Range Lane', 'count' => 412 ),
				array( 'type' => 'CCW Class',  'count' =>  74 ),
			), 'topBookingType' => 'Range Lane' ),
			array( 'active' => 612, 'renewals' => 39, 'expired' => 27 ),
			array( 'revenue' => 2_106_800, 'orders' => 236 ),
			array( 'clicks' => 5_412 )
		);

		$this->assertStringContainsString( '2026-06-27 to 2026-07-03', $body );
		$this->assertStringContainsString( '$48,124', $body );
		$this->assertStringContainsString( '+12.4%', $body );
		$this->assertStringContainsString( 'Range Lane leading', $body );
		$this->assertStringContainsString( '486 (Range Lane leading)', $body );
		$this->assertStringContainsString( '612 active', $body );
		$this->assertStringContainsString( '39 renewed', $body );
		$this->assertStringContainsString( '27 expired', $body );
		$this->assertStringContainsString( '5412', $body );
	}

	public function test_slug_is_stable() {
		$this->assertSame( 'weekly-business-report', Weekly_Report_Handler::slug() );
	}

	// -----------------------------------------------------------------
	// is_agent_failure_output() — exhaustive cases.
	// -----------------------------------------------------------------

	public function test_is_agent_failure_output_true_for_empty_string() {
		$this->assertTrue( Weekly_Report_Handler::is_agent_failure_output( '' ) );
		$this->assertTrue( Weekly_Report_Handler::is_agent_failure_output( '   ' ) );
	}

	public function test_is_agent_failure_output_true_for_not_configured_prefix() {
		$this->assertTrue(
			Weekly_Report_Handler::is_agent_failure_output(
				'Anthropic connection not configured. Set an API key under Settings → G2A Business API.'
			)
		);
	}

	public function test_is_agent_failure_output_true_for_run_failed_prefix() {
		$this->assertTrue(
			Weekly_Report_Handler::is_agent_failure_output( 'Run failed: Anthropic HTTP 529: overloaded' )
		);
	}

	public function test_is_agent_failure_output_false_for_a_normal_ai_narrative() {
		$this->assertFalse(
			Weekly_Report_Handler::is_agent_failure_output(
				"- Revenue: up 8% week over week.\n- Top mover: CCW class bookings.\n- Miss: store restock lagging.\n- Biggest risk: churn queue growing.\n- Top action: launch ladies-night upsell."
			)
		);
	}

	public function test_is_agent_failure_output_false_for_text_that_merely_mentions_failure_mid_sentence() {
		// Only a leading match counts as a failure marker — text that
		// happens to discuss "run failed" attempts elsewhere in the body
		// must not be misclassified.
		$this->assertFalse(
			Weekly_Report_Handler::is_agent_failure_output(
				'Store report: last week a promo run failed to convert as expected.'
			)
		);
	}

	// -----------------------------------------------------------------
	// run() — exercised live. Anthropic is unconfigured in this suite, so
	// Agent_Runner::run('ag-reports') always takes its "not configured"
	// fast path, which means run() must always fall back to the
	// deterministic Report_Generator body here.
	// -----------------------------------------------------------------

	public function test_run_falls_back_to_the_deterministic_report_when_ai_is_unavailable() {
		$GLOBALS['g2aba_test_options'] = array();
		$GLOBALS['wpdb']               = new Leads_Fake_WPDB();
		Agent_Store::seed_defaults();

		$result = Weekly_Report_Handler::run();

		$this->assertStringStartsWith( 'Fallback weekly report drafted', $result );

		$draft = ( new \WordPressistic\G2ABA\Ops\Email_Draft_Store() )->pending()[0] ?? null;
		$this->assertNotNull( $draft );
		$this->assertStringContainsString( 'Guns2Ammo — week of', $draft['body'] );
	}

	// NOTE: run()'s "used the AI narrative" branch has no seam to exercise
	// live in this suite — Agent_Runner::run() always takes its "Anthropic
	// not configured" fast path here (same constraint AgentRunnerPromptTest
	// already documents for the other departments), so there is no way to
	// make ag-reports' lastOutput a genuine narrative from inside run()
	// without a real Anthropic key. That branch is covered structurally by
	// the is_agent_failure_output() tests above (which is exactly the
	// predicate run() uses to decide) plus the fallback test, which proves
	// the OTHER branch behaves correctly — together they pin both sides of
	// the `if ($used_ai)` decision.
}
