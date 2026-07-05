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
		$out      = Agent_Runner::render_prompt( $template, array( 'snapshot' => array( 'k' => 42 ) ) );

		$this->assertStringContainsString( '"k":42', $out );
		$this->assertStringNotContainsString( '{{snapshot}}', $out );
	}

	public function test_render_prompt_leaves_template_alone_when_no_placeholder() {
		$out = Agent_Runner::render_prompt( 'just literal text', array( 'snapshot' => array( 'x' => 1 ) ) );
		$this->assertSame( 'just literal text', $out );
	}

	public function test_render_prompt_substitutes_all_three_additive_placeholders() {
		$template = "SNAPSHOT:\n{{snapshot}}\nLEADS:\n{{leads}}\nKNOWLEDGE:\n{{knowledge}}\nENQUIRY:\n{{lead}}";
		$out      = Agent_Runner::render_prompt(
			$template,
			array(
				'snapshot'  => array( 'a' => 1 ),
				'leads'     => array( array( 'id' => 7 ) ),
				'knowledge' => array( array( 'text_content' => 'Range hours 10-6' ) ),
				'lead'      => array( 'id' => 3, 'subject' => 'Lane question' ),
			)
		);

		$this->assertStringContainsString( '"a":1', $out );
		$this->assertStringContainsString( '"id":7', $out );
		$this->assertStringContainsString( 'Range hours 10-6', $out );
		$this->assertStringContainsString( 'Lane question', $out );
		$this->assertStringNotContainsString( '{{snapshot}}', $out );
		$this->assertStringNotContainsString( '{{leads}}', $out );
		$this->assertStringNotContainsString( '{{knowledge}}', $out );
		$this->assertStringNotContainsString( '{{lead}}', $out );
	}

	public function test_render_prompt_leaves_absent_placeholders_literal() {
		$template = "SNAPSHOT:\n{{snapshot}}\nLEADS:\n{{leads}}\nKNOWLEDGE:\n{{knowledge}}";
		$out      = Agent_Runner::render_prompt( $template, array( 'snapshot' => array( 'a' => 1 ) ) );

		$this->assertStringContainsString( '"a":1', $out );
		// `leads` and `knowledge` were never in the context, so their
		// placeholders must survive untouched rather than erroring.
		$this->assertStringContainsString( '{{leads}}', $out );
		$this->assertStringContainsString( '{{knowledge}}', $out );
	}

	public function test_render_prompt_defaults_context_to_empty_array() {
		$out = Agent_Runner::render_prompt( 'no data needed here' );
		$this->assertSame( 'no data needed here', $out );
	}

	public function test_purpose_for_department_maps_all_eight_departments() {
		$this->assertSame( 'seo_analysis',      Agent_Runner::purpose_for_department( 'seo' ) );
		$this->assertSame( 'business_analysis', Agent_Runner::purpose_for_department( 'analyst' ) );
		$this->assertSame( 'booking_suggest',   Agent_Runner::purpose_for_department( 'booking' ) );
		$this->assertSame( 'business_analysis', Agent_Runner::purpose_for_department( 'membership' ), 'membership has never had a dedicated purpose; it falls through to the business_analysis default.' );
		$this->assertSame( 'private_inventory', Agent_Runner::purpose_for_department( 'store' ), 'renamed from the old "inventory" department key.' );
		$this->assertSame( 'daily_summaries',   Agent_Runner::purpose_for_department( 'reports' ) );
		$this->assertSame( 'email_drafts',      Agent_Runner::purpose_for_department( 'email' ) );
		$this->assertSame( 'sales_pipeline',    Agent_Runner::purpose_for_department( 'sales' ) );
	}

	public function test_purpose_for_department_still_maps_support_even_though_unused_by_any_agent() {
		$this->assertSame( 'support_classify', Agent_Runner::purpose_for_department( 'support' ) );
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

	public function test_runner_records_helpful_message_when_anthropic_absent_for_email_department() {
		Agent_Store::seed_defaults();
		Agent_Runner::run( 'ag-email' );
		$rec = ( new Agent_Store() )->find( 'ag-email' );

		// The is_configured() short-circuit happens before the email
		// department's leads lookup, so this must not fall back to the
		// "No new enquiries to draft." message.
		$this->assertStringContainsString( 'not configured', $rec['lastOutput'] );
		$this->assertSame( 0.0, $rec['confidence'] );
	}

	public function test_per_value_variants_fans_out_one_filter_per_value() {
		$out = Agent_Runner::per_value_variants( 'category', array( 'a', 'b', 'c' ), array( 'status' => 'new', 'per_page' => 10 ) );

		$this->assertCount( 3, $out );
		$this->assertSame( array( 'status' => 'new', 'per_page' => 10, 'category' => 'a' ), $out[0] );
		$this->assertSame( array( 'status' => 'new', 'per_page' => 10, 'category' => 'b' ), $out[1] );
		$this->assertSame( array( 'status' => 'new', 'per_page' => 10, 'category' => 'c' ), $out[2] );
	}

	public function test_sales_snapshot_computes_conversion_rate_and_stale_count() {
		$stats = array(
			'byCategory' => array( 'nfa' => 2 ),
			'byStatus'   => array(
				'new'         => 3,
				'in_progress' => 2,
				'contacted'   => 1,
				'won'         => 4,
				'lost'        => 10,
			),
		);
		$out = Agent_Runner::sales_snapshot( $stats );

		$this->assertSame( array( 'nfa' => 2 ), $out['byCategory'] );
		$this->assertSame( 6, $out['staleLeadCount'] ); // new + in_progress + contacted.
		// won (4) / total (20) = 20.0%.
		$this->assertSame( 20.0, $out['conversionRate'] );
	}

	public function test_sales_snapshot_handles_zero_leads_without_division_by_zero() {
		$out = Agent_Runner::sales_snapshot( array( 'byCategory' => array(), 'byStatus' => array() ) );
		$this->assertSame( 0.0, $out['conversionRate'] );
		$this->assertSame( 0, $out['staleLeadCount'] );
	}

	public function test_email_knowledge_query_combines_category_and_subject() {
		$lead = array( 'category' => 'lane_booking', 'subject' => 'Can I book lane 3 Saturday?' );
		$this->assertSame( 'lane_booking Can I book lane 3 Saturday?', Agent_Runner::email_knowledge_query( $lead ) );
	}

	public function test_email_knowledge_query_tolerates_missing_fields() {
		$this->assertSame( '', Agent_Runner::email_knowledge_query( array() ) );
	}

	public function test_email_draft_fields_uses_contact_email_and_re_prefixed_subject() {
		$lead = array( 'contactEmail' => 'priya@example.com', 'subject' => 'CCW class question' );
		$out  = Agent_Runner::email_draft_fields( $lead );

		$this->assertSame( 'priya@example.com', $out['to'] );
		$this->assertSame( 'Re: CCW class question', $out['subject'] );
	}

	public function test_email_draft_fields_falls_back_when_subject_is_blank() {
		$out = Agent_Runner::email_draft_fields( array() );

		$this->assertSame( '', $out['to'] );
		$this->assertSame( 'Re: Your enquiry to Guns 2 Ammo', $out['subject'] );
	}
}
