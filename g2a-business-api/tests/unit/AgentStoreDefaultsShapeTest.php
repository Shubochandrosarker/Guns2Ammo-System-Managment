<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Agents\Agent_Store;

/**
 * Phase B reshaped Agent_Store::defaults() from 6 agents (one universal
 * `{{snapshot}}`-only prompt each) to 8, with `ag-inventory` renamed to
 * `ag-store` and two new agents (`ag-email`, `ag-sales`) added. This is a
 * pre-launch, no-migration reshape — see Agent_Store::seed_defaults()'s own
 * docblock — so the old `ag-inventory` id/department is gone entirely
 * rather than aliased.
 */
class AgentStoreDefaultsShapeTest extends TestCase {
	private const EXPECTED = array(
		'ag-seo'     => 'seo',
		'ag-analyst' => 'analyst',
		'ag-booking' => 'booking',
		'ag-member'  => 'membership',
		'ag-store'   => 'store',
		'ag-reports' => 'reports',
		'ag-email'   => 'email',
		'ag-sales'   => 'sales',
	);

	public function test_defaults_returns_exactly_eight_agents() {
		$this->assertCount( 8, Agent_Store::defaults() );
	}

	public function test_defaults_has_the_expected_ids_and_departments() {
		$defaults = Agent_Store::defaults();

		foreach ( self::EXPECTED as $id => $department ) {
			$this->assertArrayHasKey( $id, $defaults, "Missing expected agent id: {$id}" );
			$this->assertSame( $id, $defaults[ $id ]['id'] );
			$this->assertSame( $department, $defaults[ $id ]['department'] );
		}
	}

	public function test_old_inventory_agent_id_and_department_are_gone() {
		$defaults = Agent_Store::defaults();
		$this->assertArrayNotHasKey( 'ag-inventory', $defaults );

		$departments = array_column( $defaults, 'department' );
		$this->assertNotContains( 'inventory', $departments );
	}

	public function test_every_prompt_template_contains_the_snapshot_placeholder() {
		foreach ( Agent_Store::defaults() as $id => $agent ) {
			$this->assertStringContainsString( '{{snapshot}}', $agent['promptTemplate'], "{$id} must keep the {{snapshot}} placeholder for backward compatibility." );
		}
	}

	public function test_booking_and_membership_prompts_gained_leads_and_knowledge_placeholders() {
		$defaults = Agent_Store::defaults();
		foreach ( array( 'ag-booking', 'ag-member' ) as $id ) {
			$this->assertStringContainsString( '{{leads}}', $defaults[ $id ]['promptTemplate'] );
			$this->assertStringContainsString( '{{knowledge}}', $defaults[ $id ]['promptTemplate'] );
		}
	}

	public function test_store_prompt_gained_knowledge_but_not_leads() {
		$store = Agent_Store::defaults()['ag-store'];
		$this->assertStringContainsString( '{{knowledge}}', $store['promptTemplate'] );
		$this->assertStringNotContainsString( '{{leads}}', $store['promptTemplate'] );
	}

	public function test_email_prompt_references_the_singular_lead_placeholder() {
		$email = Agent_Store::defaults()['ag-email'];
		$this->assertStringContainsString( '{{lead}}', $email['promptTemplate'] );
		$this->assertStringContainsString( '{{knowledge}}', $email['promptTemplate'] );
	}

	public function test_sales_prompt_references_leads_and_knowledge() {
		$sales = Agent_Store::defaults()['ag-sales'];
		$this->assertStringContainsString( '{{leads}}', $sales['promptTemplate'] );
		$this->assertStringContainsString( '{{knowledge}}', $sales['promptTemplate'] );
	}

	public function test_review_required_flags_match_spec() {
		$defaults = Agent_Store::defaults();
		$this->assertTrue( $defaults['ag-booking']['reviewRequired'] );
		$this->assertTrue( $defaults['ag-member']['reviewRequired'] );
		$this->assertTrue( $defaults['ag-email']['reviewRequired'] );
		$this->assertFalse( $defaults['ag-store']['reviewRequired'] );
		$this->assertFalse( $defaults['ag-sales']['reviewRequired'] );
		$this->assertFalse( $defaults['ag-seo']['reviewRequired'] );
		$this->assertFalse( $defaults['ag-analyst']['reviewRequired'] );
		$this->assertFalse( $defaults['ag-reports']['reviewRequired'] );
	}

	public function test_new_agents_default_to_anthropic_primary_and_zero_assigned_tasks() {
		$defaults = Agent_Store::defaults();
		foreach ( array( 'ag-email', 'ag-sales' ) as $id ) {
			$this->assertSame( 'anthropic-primary', $defaults[ $id ]['model'] );
			$this->assertSame( 0, $defaults[ $id ]['assignedTasks'] );
			$this->assertSame( 'No runs yet.', $defaults[ $id ]['lastOutput'] );
			$this->assertSame( 0.0, $defaults[ $id ]['confidence'] );
			$this->assertNull( $defaults[ $id ]['lastRun'] );
		}
	}

	public function test_seed_defaults_populates_all_eight_agents() {
		$GLOBALS['g2aba_test_options'] = array();
		Agent_Store::seed_defaults();
		$ids = array_column( ( new Agent_Store() )->all(), 'id' );

		foreach ( array_keys( self::EXPECTED ) as $id ) {
			$this->assertContains( $id, $ids );
		}
	}
}
