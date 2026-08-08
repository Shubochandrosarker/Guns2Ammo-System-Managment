<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Providers\Email_Overview_Provider;

/**
 * Minimal $wpdb double: every table-exists probe answers "missing", so the
 * provider must take its degrade-to-null paths without touching SQL.
 */
class Email_Overview_Fake_WPDB {
	public $prefix = 'wp_';
	public $queries = array();

	public function prepare( $query, ...$args ) {
		return $query;
	}

	public function get_var( $query ) {
		$this->queries[] = $query;
		return null;
	}

	public function get_results( $query, $output = null ) {
		$this->queries[] = $query;
		return array();
	}
}

class EmailOverviewProviderTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wpdb'] = new Email_Overview_Fake_WPDB();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	public function test_overview_degrades_to_inactive_sections_when_tables_absent() {
		$out = ( new Email_Overview_Provider() )->overview();

		$this->assertSame(
			array(
				'active'            => false,
				'totalSubmissions'  => null,
				'newToday'          => null,
				'last7d'            => null,
				'byStatus'          => null,
				'recentSubmissions' => array(),
			),
			$out['formistic']
		);
		$this->assertSame(
			array( 'active' => false, 'total' => null, 'last30d' => null ),
			$out['subscribers']
		);
		// Messageistic's classes are not loaded in the unit suite.
		$this->assertSame(
			array( 'active' => false, 'stats' => null ),
			$out['messageistic']
		);
	}

	public function test_table_absent_guard_never_runs_data_queries() {
		( new Email_Overview_Provider() )->overview();

		foreach ( $GLOBALS['wpdb']->queries as $query ) {
			$this->assertStringContainsString( 'SHOW TABLES LIKE', $query );
		}
	}

	public function test_shape_submission_projects_and_truncates() {
		$row = array(
			'id'           => '42',
			'form_name'    => 'Contact',
			'sender_name'  => 'Priya',
			'sender_email' => 'priya@example.com',
			'subject'      => 'CCW question',
			'message'      => "Line one\n\n   line   two " . str_repeat( 'x', 200 ),
			'created_at'   => '2026-07-04 10:00:00',
			'status'       => 'new',
		);
		$out = Email_Overview_Provider::shape_submission( $row );

		$this->assertSame( 42, $out['id'] );
		$this->assertSame( 'Contact', $out['formName'] );
		$this->assertSame( 'Priya', $out['name'] );
		$this->assertSame( 'priya@example.com', $out['email'] );
		$this->assertSame( 'CCW question', $out['subject'] );
		$this->assertSame( 'new', $out['status'] );
		// Whitespace collapsed + hard cap at 140 chars + ellipsis.
		$this->assertStringStartsWith( 'Line one line two', $out['messageExcerpt'] );
		$this->assertLessThanOrEqual( 140 + strlen( '…' ), strlen( $out['messageExcerpt'] ) );
		$this->assertStringEndsWith( '…', $out['messageExcerpt'] );
	}

	public function test_shape_submission_coerces_unknown_status_to_new() {
		$out = Email_Overview_Provider::shape_submission( array( 'status' => 'spam' ) );
		$this->assertSame( 'new', $out['status'] );
	}

	public function test_messageistic_stats_map_to_camel_case() {
		$out = Email_Overview_Provider::shape_messageistic_stats(
			array(
				'total_sent'   => 120,
				'delivered'    => 114,
				'failed'       => 6,
				'replies'      => 18,
				'contacts'     => 800,
				'opted_out'    => 12,
				'active_camps' => 2,
			)
		);
		$this->assertSame(
			array(
				'totalSent'       => 120,
				'delivered'       => 114,
				'failed'          => 6,
				'replies'         => 18,
				'contacts'        => 800,
				'optedOut'        => 12,
				'activeCampaigns' => 2,
			),
			$out
		);
	}
}
