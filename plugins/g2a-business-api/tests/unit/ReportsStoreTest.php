<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Reports\Reports_Store;

class ReportsStoreTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['g2aba_test_options'] = array();
	}

	public function test_all_seeds_the_catalog_on_first_read() {
		$store = new Reports_Store();
		$all   = $store->all();

		$this->assertNotEmpty( $all );
		$ids = array_column( $all, 'id' );
		// Every catalog id must appear on first read.
		foreach ( Reports_Store::catalog() as $def ) {
			$this->assertContains( $def['id'], $ids );
		}
	}

	public function test_all_annotates_last_delivered_at_and_has_latest() {
		$store = new Reports_Store();
		$store->all(); // seed
		$store->record_delivery(
			'daily-summary',
			array( 'body' => 'sample body', 'format' => 'text', 'range' => array( 'from' => '2026-07-02', 'to' => '2026-07-02' ) )
		);

		$daily = null;
		foreach ( $store->all() as $def ) {
			if ( 'daily-summary' === $def['id'] ) {
				$daily = $def;
				break;
			}
		}
		$this->assertNotNull( $daily );
		$this->assertTrue( $daily['hasLatest'] );
		$this->assertNotEmpty( $daily['lastDeliveredAt'] );
	}

	public function test_find_returns_null_for_unknown() {
		$this->assertNull( ( new Reports_Store() )->find( 'nope' ) );
	}

	public function test_latest_returns_null_before_first_delivery() {
		$store = new Reports_Store();
		$store->all(); // seed
		$this->assertNull( $store->latest( 'daily-summary' ) );
	}

	public function test_record_delivery_overwrites_previous_delivery() {
		$store = new Reports_Store();
		$store->record_delivery( 'daily-summary', array( 'body' => 'v1', 'range' => array() ) );
		$store->record_delivery( 'daily-summary', array( 'body' => 'v2', 'range' => array() ) );

		$latest = $store->latest( 'daily-summary' );
		$this->assertNotNull( $latest );
		$this->assertSame( 'v2', $latest['body'] );
	}
}
