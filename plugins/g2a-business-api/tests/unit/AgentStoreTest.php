<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Agents\Agent_History;
use WordPressistic\G2ABA\Agents\Agent_Store;

class AgentStoreTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['g2aba_test_options'] = array();
	}

	public function test_seed_defaults_populates_agents_and_prompt_templates() {
		Agent_Store::seed_defaults();
		$store = new Agent_Store();
		$all   = $store->all();

		$ids = array_column( $all, 'id' );
		$this->assertContains( 'ag-seo', $ids );
		$this->assertContains( 'ag-analyst', $ids );

		$seo = $store->find( 'ag-seo' );
		$this->assertStringContainsString( '{{snapshot}}', $seo['promptTemplate'] );
	}

	public function test_seed_defaults_preserves_operator_prompt_edits() {
		Agent_Store::seed_defaults();

		// Simulate an operator saving a customised prompt.
		$raw = $GLOBALS['g2aba_test_options']['g2aba_agents'];
		$raw['ag-seo']['promptTemplate'] = 'Custom prompt with {{snapshot}}';
		$GLOBALS['g2aba_test_options']['g2aba_agents'] = $raw;

		Agent_Store::seed_defaults();
		$rec = ( new Agent_Store() )->find( 'ag-seo' );
		$this->assertSame( 'Custom prompt with {{snapshot}}', $rec['promptTemplate'] );
	}

	public function test_record_run_updates_last_output_and_history() {
		Agent_Store::seed_defaults();
		$store = new Agent_Store();
		$store->record_run( 'ag-seo', 'CCW class page is bleeding traffic', 0.86 );
		$rec = $store->find( 'ag-seo' );

		$this->assertSame( 'CCW class page is bleeding traffic', $rec['lastOutput'] );
		$this->assertSame( 0.86, $rec['confidence'] );
		$this->assertNotEmpty( $rec['lastRun'] );

		$history = Agent_History::for_agent( 'ag-seo' );
		$this->assertCount( 1, $history );
		$this->assertSame( 'CCW class page is bleeding traffic', $history[0]['output'] );
	}

	public function test_record_run_clamps_confidence_to_valid_range() {
		Agent_Store::seed_defaults();
		$store = new Agent_Store();

		$store->record_run( 'ag-seo', 'x', 2.5 );
		$this->assertSame( 1.0, $store->find( 'ag-seo' )['confidence'] );

		$store->record_run( 'ag-seo', 'x', -0.5 );
		$this->assertSame( 0.0, $store->find( 'ag-seo' )['confidence'] );
	}

	public function test_record_run_null_for_unknown_agent() {
		Agent_Store::seed_defaults();
		$this->assertNull( ( new Agent_Store() )->record_run( 'ag-missing', 'x' ) );
	}

	public function test_history_ring_caps_at_50() {
		Agent_Store::seed_defaults();
		$store = new Agent_Store();
		for ( $i = 0; $i < 60; $i++ ) {
			$store->record_run( 'ag-seo', 'run ' . $i, 0.5 );
		}
		$this->assertCount( 50, Agent_History::for_agent( 'ag-seo' ) );
	}
}
