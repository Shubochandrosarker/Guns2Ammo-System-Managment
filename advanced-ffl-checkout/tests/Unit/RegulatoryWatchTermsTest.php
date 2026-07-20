<?php

namespace WpisticFFL\Tests\Unit;

use Brain\Monkey\Functions;
use WpisticFFL\G2A_Regulatory_Watch;

require_once dirname( __DIR__, 2 ) . '/includes/class-wpistic-ffl-g2a-regulatory-watch.php';

/**
 * The regulatory-watch search terms were a hardcoded class constant with
 * no extension point, unlike every other tunable list in this plugin
 * (fraud-score weights, state-rules seed, etc.) -- a store could not
 * broaden or narrow them without editing plugin code. These tests cover
 * the new `wpistic_ffl_regulatory_watch_terms` filter: that it's honored
 * by both the public accessor and the actual per-term query loop in
 * run_check(), and that the unfiltered default still applies.
 */
final class RegulatoryWatchTermsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-07-16 00:00:00' );

		// Every query in these tests returns zero results, so run_check()
		// never reaches a $wpdb call -- these tests are purely about which
		// terms get queried, not what happens with a match.
		Functions\when( 'wp_remote_get' )->justReturn( [ 'body' => '{"results":[]}' ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"results":[]}' );
	}

	/** Wires add_query_arg() to record each term run_check() actually queries, in order, into &$queried. */
	private function wire_term_capture( array &$queried ): void {
		Functions\when( 'add_query_arg' )->alias( function ( array $args, string $url ) use ( &$queried ) {
			$queried[] = $args['conditions']['term'];
			return $url;
		} );
	}

	public function test_active_terms_returns_the_curated_default_when_unfiltered(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$this->assertSame( G2A_Regulatory_Watch::TERMS, G2A_Regulatory_Watch::active_terms() );
		$this->assertGreaterThanOrEqual( 5, count( G2A_Regulatory_Watch::TERMS ), 'The curated list should cover more than the original 3 narrow phrasings.' );
	}

	public function test_active_terms_honors_a_store_supplied_filter(): void {
		$custom = [ 'a completely different phrasing', 'another one' ];
		Functions\when( 'apply_filters' )->alias( function ( $tag, $value ) use ( $custom ) {
			return 'wpistic_ffl_regulatory_watch_terms' === $tag ? $custom : $value;
		} );

		$this->assertSame( $custom, G2A_Regulatory_Watch::active_terms() );
	}

	public function test_run_check_queries_every_default_term_when_unfiltered(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$queried = [];
		$this->wire_term_capture( $queried );

		G2A_Regulatory_Watch::run_check();

		$this->assertSame( G2A_Regulatory_Watch::TERMS, $queried, 'Every default term must actually be queried, not just enumerable via active_terms().' );
	}

	public function test_run_check_queries_only_the_filtered_terms_when_a_store_narrows_the_list(): void {
		$custom = [ 'narrow-store-specific-term' ];
		Functions\when( 'apply_filters' )->alias( function ( $tag, $value ) use ( $custom ) {
			return 'wpistic_ffl_regulatory_watch_terms' === $tag ? $custom : $value;
		} );
		$queried = [];
		$this->wire_term_capture( $queried );

		G2A_Regulatory_Watch::run_check();

		$this->assertSame( $custom, $queried, 'A store that narrows the list to cut noise must not still have the defaults queried underneath it.' );
	}
}
