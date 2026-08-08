<?php

namespace G2A\POS\Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/**
 * The property under test is a privacy one, and it is the reason this class
 * exists in the shape it does: g2a-rag-worker does not filter on the `scope`
 * it stores, so anything the collector ingests is retrievable by any caller
 * holding the chat token. A future edit that starts folding customer names or
 * firearm serials into a collector body would be invisible in review and
 * catastrophic in production.
 *
 * current_time() is NOT stubbed here: tests/wp-stubs.php already defines it,
 * and Brain Monkey cannot redefine a function that a bootstrap-time file has
 * already declared — Patchwork raises DefinedTooEarly. Same trap that broke
 * LipseysApiClientTest when is_wp_error moved into the shared stubs.
 */
/**
 * Minimal $wpdb double. The collectors read through KpiService, which is raw
 * SQL — the point of these tests is the *shape and safety of the prose*, not
 * the aggregates, so every query returns a benign zero and the assertions are
 * about what the document says.
 */
final class CollectorWpdb {
	public string $prefix = 'wp_';

	/** @return string */
	public function prepare( $sql, ...$args ) {
		return $sql;
	}

	public function get_var( $sql ) {
		return 0;
	}

	/**
	 * Models the case the caveat exists for: sales happened, but no line
	 * matched the wholesaler catalog, so cost of goods comes back zero and the
	 * margin computes to a triumphant and entirely fictional 100%.
	 */
	public function get_row( $sql, $output = null ) {
		return array(
			'rev'             => 1000.0,
			'cogs'            => 0.0,
			'firearm_orders'  => 0,
			'attached_orders' => 0,
		);
	}

	public function get_results( $sql, $output = null ) {
		return array();
	}
}

final class BusinessKnowledgeCollectorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$GLOBALS['wpdb'] = new CollectorWpdb();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Invoke a private static collector without loosening its visibility. */
	private function collector( string $method ): array {
		$ref = new \ReflectionMethod( \G2A\POS\Ai\BusinessKnowledgeCollector::class, $method );
		$ref->setAccessible( true );
		return $ref->invoke( null );
	}

	public function test_compliance_body_states_it_cannot_authorise_a_transfer(): void {
		$doc = $this->collector( 'collect_compliance' );

		$this->assertStringContainsString( 'licensed person', $doc['body'] );
		$this->assertMatchesRegularExpression(
			'/No figure here authorises a transfer/i',
			$doc['body'],
			'The compliance document must never read as clearance to sell.'
		);
	}

	public function test_compliance_body_carries_no_serial_or_identity_fields(): void {
		$body = $this->collector( 'collect_compliance' )['body'];

		// The document should say these are excluded, and contain no value
		// shaped like one. A serial is the highest-risk field here: it ties
		// directly to a bound-book entry.
		$this->assertStringContainsString( 'deliberately not in this knowledge base', $body );
		$this->assertDoesNotMatchRegularExpression( '/@[a-z0-9.-]+\.[a-z]{2,}/i', $body, 'no email addresses' );
		$this->assertDoesNotMatchRegularExpression( '/\b\d{3}-\d{2}-\d{4}\b/', $body, 'no SSN-shaped values' );
	}

	public function test_range_body_keeps_the_id_and_waiver_rule(): void {
		$body = $this->collector( 'collect_range' )['body'];

		$this->assertStringContainsString( 'verified photo ID', $body );
		$this->assertStringContainsString( 'signed', $body );
		$this->assertStringContainsString( 'never issued twice', $body );
	}

	public function test_membership_body_reports_counts_not_members(): void {
		$body = $this->collector( 'collect_membership' )['body'];

		$this->assertStringContainsString( 'Active memberships:', $body );
		$this->assertStringContainsString( 'not in this knowledge base', $body );
		$this->assertDoesNotMatchRegularExpression( '/@[a-z0-9.-]+\.[a-z]{2,}/i', $body, 'no email addresses' );
	}

	/**
	 * A zero cost of goods makes the margin read as 100%, which looks like a
	 * spectacular month and is actually an unlinked vendor catalog. The
	 * document has to carry the caveat or the assistant will quote the fiction.
	 */
	public function test_trading_body_flags_an_untrustworthy_margin(): void {
		$body = $this->collector( 'collect_trading' )['body'];

		$this->assertStringContainsString( 'CAVEAT', $body );
		$this->assertStringContainsString( 'not trustworthy', str_replace( "\n", ' ', $body ) );
	}

	public function test_every_collector_produces_a_label_and_a_body(): void {
		foreach ( array( 'collect_trading', 'collect_compliance', 'collect_range', 'collect_inventory', 'collect_membership', 'collect_suppliers' ) as $method ) {
			$doc = $this->collector( $method );
			$this->assertArrayHasKey( 'label', $doc, $method );
			$this->assertArrayHasKey( 'body', $doc, $method );
			$this->assertNotSame( '', trim( $doc['body'] ), $method . ' produced an empty body' );
			$this->assertStringStartsWith( 'Business state — ', $doc['label'], $method );
		}
	}
}
