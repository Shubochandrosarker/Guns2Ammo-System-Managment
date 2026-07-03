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
