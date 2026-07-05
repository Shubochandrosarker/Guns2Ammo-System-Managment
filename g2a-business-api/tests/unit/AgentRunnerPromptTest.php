<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Agents\Agent_Runner;
use WordPressistic\G2ABA\Agents\Agent_Store;

class AgentRunnerPromptTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['g2aba_test_options'] = array();
	}

	public function test_render_prompt_substitutes_snapshot_placeholder() {
		$template = "You are the analyst.\nSNAPSHOT:\n{{snapshot}}";
		$out      = Agent_Runner::render_prompt( $template, array( 'k' => 42 ) );

		$this->assertStringContainsString( '"k":42', $out );
		$this->assertStringNotContainsString( '{{snapshot}}', $out );
	}

	public function test_render_prompt_leaves_template_alone_when_no_placeholder() {
		$out = Agent_Runner::render_prompt( 'just literal text', array( 'x' => 1 ) );
		$this->assertSame( 'just literal text', $out );
	}

	public function test_purpose_for_department_maps_known_departments() {
		$this->assertSame( 'seo_analysis',      Agent_Runner::purpose_for_department( 'seo' ) );
		$this->assertSame( 'business_analysis', Agent_Runner::purpose_for_department( 'analyst' ) );
		$this->assertSame( 'booking_suggest',   Agent_Runner::purpose_for_department( 'booking' ) );
		$this->assertSame( 'support_classify',  Agent_Runner::purpose_for_department( 'support' ) );
		$this->assertSame( 'email_drafts',      Agent_Runner::purpose_for_department( 'email' ) );
		$this->assertSame( 'daily_summaries',   Agent_Runner::purpose_for_department( 'reports' ) );
		$this->assertSame( 'private_inventory', Agent_Runner::purpose_for_department( 'inventory' ) );
	}

	public function test_purpose_for_department_defaults_to_business_analysis() {
		$this->assertSame( 'business_analysis', Agent_Runner::purpose_for_department( 'compliance' ) );
		$this->assertSame( 'business_analysis', Agent_Runner::purpose_for_department( '' ) );
	}

	public function test_missing_agent_is_a_noop() {
		Agent_Store::seed_defaults();
		Agent_Runner::run( 'ag-does-not-exist' ); // Must not throw.
		$this->assertTrue( true );
	}

	public function test_runner_records_helpful_message_when_anthropic_absent() {
		Agent_Store::seed_defaults();
		Agent_Runner::run( 'ag-seo' );
		$rec = ( new Agent_Store() )->find( 'ag-seo' );

		$this->assertStringContainsString( 'not configured', $rec['lastOutput'] );
		$this->assertSame( 0.0, $rec['confidence'] );
	}
}
