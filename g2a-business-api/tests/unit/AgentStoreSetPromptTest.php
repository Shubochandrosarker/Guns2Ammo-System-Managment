<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Agents\Agent_Store;

class AgentStoreSetPromptTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['g2aba_test_options'] = array();
		Agent_Store::seed_defaults();
	}

	public function test_set_prompt_persists_template() {
		$out = ( new Agent_Store() )->set_prompt( 'ag-seo', 'New prompt {{snapshot}}' );

		$this->assertTrue( $out['ok'] );
		$this->assertTrue( $out['placeholder'] );
		$this->assertSame( 'New prompt {{snapshot}}', $out['record']['promptTemplate'] );

		// Stored, not just returned.
		$stored = ( new Agent_Store() )->find( 'ag-seo' );
		$this->assertSame( 'New prompt {{snapshot}}', $stored['promptTemplate'] );
	}

	public function test_set_prompt_warns_when_placeholder_missing() {
		$out = ( new Agent_Store() )->set_prompt( 'ag-seo', 'A prompt without the placeholder' );
		$this->assertTrue( $out['ok'] );
		$this->assertFalse( $out['placeholder'] );
	}

	public function test_set_prompt_rejects_empty_string() {
		$out = ( new Agent_Store() )->set_prompt( 'ag-seo', '   ' );
		$this->assertFalse( $out['ok'] );
		$this->assertStringContainsString( 'empty', $out['error'] );
	}

	public function test_set_prompt_rejects_oversized_template() {
		$out = ( new Agent_Store() )->set_prompt( 'ag-seo', str_repeat( 'x', 8_500 ) );
		$this->assertFalse( $out['ok'] );
		$this->assertStringContainsString( '8 KiB', $out['error'] );
	}

	public function test_set_prompt_returns_error_for_unknown_agent() {
		$out = ( new Agent_Store() )->set_prompt( 'ag-does-not-exist', 'x {{snapshot}}' );
		$this->assertFalse( $out['ok'] );
		$this->assertSame( 'Unknown agent id.', $out['error'] );
	}
}
