<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Agents\Agent_Store;

class AgentStorePromptVersionTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['g2aba_test_options'] = array();
		Agent_Store::seed_defaults();
	}

	public function test_set_prompt_captures_previous_version() {
		$store = new Agent_Store();
		$store->set_prompt( 'ag-seo', 'V1 {{snapshot}}' );
		$store->set_prompt( 'ag-seo', 'V2 {{snapshot}}' );

		$history = Agent_Store::prompt_versions_for( 'ag-seo' );
		$this->assertCount( 2, $history, 'Seed → V1 pushes the seed template; V1 → V2 pushes V1.' );
		$this->assertSame( 'V1 {{snapshot}}', $history[0]['template'] );
	}

	public function test_set_prompt_does_not_record_when_template_unchanged() {
		$store = new Agent_Store();
		$store->set_prompt( 'ag-seo', 'Same {{snapshot}}' );
		$store->set_prompt( 'ag-seo', 'Same {{snapshot}}' );

		// The first save pushed the seed (1 entry). A second save with the
		// SAME template must not push another.
		$this->assertCount( 1, Agent_Store::prompt_versions_for( 'ag-seo' ) );
	}

	public function test_get_prompt_returns_current_plus_history() {
		$store = new Agent_Store();
		$store->set_prompt( 'ag-seo', 'V1 {{snapshot}}' );
		$store->set_prompt( 'ag-seo', 'V2 {{snapshot}}' );

		$out = $store->get_prompt( 'ag-seo' );
		$this->assertSame( 'V2 {{snapshot}}', $out['template'] );
		$this->assertCount( 2, $out['history'] );
	}

	public function test_get_prompt_returns_null_for_unknown_agent() {
		$this->assertNull( ( new Agent_Store() )->get_prompt( 'ag-missing' ) );
	}

	public function test_prompt_version_history_capped_at_10() {
		$store = new Agent_Store();
		for ( $i = 0; $i < 15; $i++ ) {
			$store->set_prompt( 'ag-seo', 'V' . $i . ' {{snapshot}}' );
		}
		$this->assertCount( 10, Agent_Store::prompt_versions_for( 'ag-seo' ) );
	}
}
