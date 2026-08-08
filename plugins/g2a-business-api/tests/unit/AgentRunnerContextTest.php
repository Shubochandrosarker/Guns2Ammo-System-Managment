<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Agents\Agent_Runner;
use WordPressistic\G2ABA\Agents\Agent_Store;
use WordPressistic\G2ABA\Leads\Leads_Repository;

/**
 * Exercises Agent_Runner's per-department context builder against the same
 * Leads_Fake_WPDB double LeadsRepositoryTest.php defines (PHPUnit loads
 * every test file up front, so that class is already declared by the time
 * any test method runs).
 *
 * None of the analytics Providers (Booking/Membership/Store/SEO/Revenue)
 * have their tables/integrations available in this suite, so they all take
 * their own "not installed" fast path and return a zeroed skeleton —
 * Leads_Fake_WPDB's get_var() falls through to `null` for a `SHOW TABLES
 * LIKE` query it doesn't recognise, which every provider reads as "table
 * absent". That's exactly the degrade-gracefully behaviour Agent_Runner's
 * safe_call() wrapper exists for.
 */
class AgentRunnerContextTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wpdb']               = new Leads_Fake_WPDB();
		$GLOBALS['g2aba_test_options'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	public function test_merge_lead_queries_dedupes_across_variants() {
		$repo = new Leads_Repository();
		$repo->upsert_by_source( 'formistic', '1', array( 'category' => 'lane_booking', 'status' => 'new' ) );
		$repo->upsert_by_source( 'formistic', '2', array( 'category' => 'ccw_class', 'status' => 'new' ) );
		$repo->upsert_by_source( 'formistic', '3', array( 'category' => 'range_enquiry', 'status' => 'new' ) );

		$variants = Agent_Runner::per_value_variants(
			'category',
			array( 'lane_booking', 'ccw_class', 'range_enquiry', 'event_booking' ),
			array( 'status' => 'new', 'per_page' => 10 )
		);
		$out = Agent_Runner::merge_lead_queries( $variants, 10 );

		$this->assertCount( 3, $out, 'One lead per matching category, event_booking has none.' );
	}

	public function test_merge_lead_queries_caps_at_limit() {
		$repo = new Leads_Repository();
		for ( $i = 1; $i <= 5; $i++ ) {
			$repo->upsert_by_source( 'formistic', (string) $i, array( 'category' => 'nfa', 'status' => 'new' ) );
		}

		$out = Agent_Runner::merge_lead_queries(
			array( array( 'category' => 'nfa', 'status' => 'new', 'per_page' => 10 ) ),
			3
		);
		$this->assertCount( 3, $out );
	}

	public function test_context_for_booking_includes_snapshot_leads_and_knowledge() {
		$context = Agent_Runner::context_for( 'booking' );

		$this->assertArrayHasKey( 'snapshot', $context );
		$this->assertArrayHasKey( 'bookings', $context['snapshot'] );
		$this->assertArrayHasKey( 'leads', $context );
		$this->assertIsArray( $context['leads'] );
		$this->assertArrayHasKey( 'knowledge', $context );
		$this->assertSame( array(), $context['knowledge'], 'Brain unavailable in this suite -> degrades to [].' );
	}

	public function test_context_for_membership_includes_snapshot_leads_and_knowledge() {
		$context = Agent_Runner::context_for( 'membership' );

		$this->assertArrayHasKey( 'memberships', $context['snapshot'] );
		$this->assertArrayHasKey( 'leads', $context );
		$this->assertArrayHasKey( 'knowledge', $context );
	}

	public function test_context_for_store_has_snapshot_and_knowledge_but_no_leads() {
		$context = Agent_Runner::context_for( 'store' );

		$this->assertArrayHasKey( 'store', $context['snapshot'] );
		$this->assertArrayHasKey( 'knowledge', $context );
		$this->assertArrayNotHasKey( 'leads', $context );
	}

	public function test_context_for_seo_is_scoped_to_seo_data_only() {
		$context = Agent_Runner::context_for( 'seo' );

		$this->assertArrayHasKey( 'seo', $context['snapshot'] );
		$this->assertArrayNotHasKey( 'overview', $context['snapshot'] );
		$this->assertArrayNotHasKey( 'bookings', $context['snapshot'] );
		$this->assertArrayNotHasKey( 'leads', $context );
		$this->assertArrayNotHasKey( 'knowledge', $context );
	}

	public function test_context_for_analyst_keeps_the_full_cross_cutting_snapshot() {
		$context = Agent_Runner::context_for( 'analyst' );

		foreach ( array( 'overview', 'bookings', 'memberships', 'store', 'seo' ) as $key ) {
			$this->assertArrayHasKey( $key, $context['snapshot'] );
		}
	}

	public function test_context_for_reports_keeps_the_full_snapshot_too() {
		$context = Agent_Runner::context_for( 'reports' );

		foreach ( array( 'overview', 'bookings', 'memberships', 'store', 'seo' ) as $key ) {
			$this->assertArrayHasKey( $key, $context['snapshot'] );
		}
	}

	public function test_context_for_reports_also_includes_agent_findings() {
		Agent_Store::seed_defaults();
		( new Agent_Store() )->record_run( 'ag-seo', 'CCW class page is bleeding traffic', 0.86 );

		$context = Agent_Runner::context_for( 'reports' );

		$this->assertArrayHasKey( 'agents', $context );
		$this->assertIsArray( $context['agents'] );

		$ids = array_column( $context['agents'], 'id' );
		$this->assertContains( 'ag-seo', $ids );
		$this->assertNotContains( 'ag-reports', $ids, 'The reporter must not cite its own prior output.' );

		$seo = $context['agents'][ array_search( 'ag-seo', $ids, true ) ];
		$this->assertSame( 'CCW class page is bleeding traffic', $seo['lastOutput'] );
		$this->assertSame( 0.86, $seo['confidence'] );
		$this->assertArrayHasKey( 'name', $seo );
		$this->assertArrayHasKey( 'department', $seo );
		$this->assertArrayHasKey( 'lastRun', $seo );
		$this->assertArrayNotHasKey( 'promptTemplate', $seo, 'Prompt templates must never leak into the narration context.' );
	}

	public function test_context_for_analyst_has_no_agents_key() {
		$context = Agent_Runner::context_for( 'analyst' );
		$this->assertArrayNotHasKey( 'agents', $context );
	}

	public function test_agent_findings_excludes_ag_reports_and_strips_internal_fields() {
		Agent_Store::seed_defaults();

		$findings = Agent_Runner::agent_findings();
		$ids      = array_column( $findings, 'id' );

		$this->assertNotContains( 'ag-reports', $ids );
		$this->assertContains( 'ag-seo', $ids );
		$this->assertCount( 7, $findings, '8 default agents minus ag-reports itself.' );

		foreach ( $findings as $entry ) {
			$this->assertSame(
				array( 'id', 'name', 'department', 'lastOutput', 'confidence', 'lastRun' ),
				array_keys( $entry )
			);
		}
	}

	public function test_context_for_sales_has_funnel_snapshot_and_leads_but_no_knowledge() {
		$repo = new Leads_Repository();
		$repo->upsert_by_source( 'formistic', '1', array( 'status' => 'new' ) );
		$repo->upsert_by_source( 'formistic', '2', array( 'status' => 'won' ) );

		$context = Agent_Runner::context_for( 'sales' );

		$this->assertArrayHasKey( 'staleLeadCount', $context['snapshot'] );
		$this->assertArrayHasKey( 'conversionRate', $context['snapshot'] );
		$this->assertArrayHasKey( 'leads', $context );
		$this->assertArrayNotHasKey( 'knowledge', $context, 'Knowledge is intentionally skipped for the sales department.' );
	}

	public function test_context_for_sales_sorts_leads_oldest_first() {
		global $wpdb;
		$repo = new Leads_Repository();
		$repo->upsert_by_source( 'formistic', 'older', array( 'status' => 'new' ) );
		$repo->upsert_by_source( 'formistic', 'newer', array( 'status' => 'new' ) );

		// Backdate the "older" row so ordering is unambiguous — Leads_Fake_WPDB
		// stores whatever `update()`/`insert()` are given verbatim.
		$older_id = $repo->find_id_by_source( 'formistic', 'older' );
		$wpdb->update( 'ignored', array( 'created_at' => '2020-01-01 00:00:00' ), array( 'id' => $older_id ) );

		$context = Agent_Runner::context_for( 'sales' );
		$ids     = array_column( $context['leads'], 'id' );

		$this->assertSame( $older_id, $ids[0], 'Oldest (most stale) lead must sort first.' );
	}
}
