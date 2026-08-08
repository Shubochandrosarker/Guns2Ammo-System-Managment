<?php

namespace WpisticFFL\Tests\Unit;

use Brain\Monkey\Functions;
use WpisticFFL\G2A_Fraud_Score;
use WpisticFFL\Tests\Unit\Support\FakeWpdb;

require_once dirname( __DIR__, 2 ) . '/includes/class-wpistic-ffl-db.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-wpistic-ffl-g2a-fraud-score.php';
require_once __DIR__ . '/Support/FakeWpdb.php';

/**
 * G2A_Fraud_Score::score_transfer() had zero test coverage despite being
 * the one place the plugin's fraud weights/thresholds (flagged in
 * STATUS.md §6 as "starting defaults, not tuned") actually get exercised.
 * A FakeWpdb stands in for the real DB so the signal-detection and
 * scoring math can be verified without a live MySQL connection.
 */
final class FraudScoreTest extends TestCase {

	private FakeWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;

		// Default: no filters registered anywhere -- apply_filters() just
		// passes its second argument through, same as a real site with no
		// `add_filter()` calls on these hooks.
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	/** Wires get_option()/admin_url()/esc_html()/esc_url() for the notify_admin() email path. */
	private function stub_notify_admin_path(): void {
		Functions\when( 'get_option' )->alias( function ( string $name, $default = false ) {
			return 'admin_email' === $name ? 'owner@example.test' : ( $default ?: [] );
		} );
		Functions\when( 'admin_url' )->justReturn( 'http://example.test/wp-admin/admin.php' );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private function seed_transfer( array $overrides = [] ): object {
		$t = (object) array_merge( [
			'id'             => 5,
			'transfer_ref'   => 'G2A-TEST0001',
			'created_at'     => '2026-06-01 12:00:00',
			'customer_email' => '',
			'customer_ip'    => '',
			'customer_id'    => 0,
			'customer_name'  => 'Jane Buyer',
			'dealer_id'      => 0,
		], $overrides );
		$this->wpdb->when( 'SELECT *', $t );
		return $t;
	}

	public function test_no_signals_yields_zero_score_and_no_admin_email(): void {
		$this->seed_transfer();
		Functions\when( 'wc_get_order' )->justReturn( null );
		Functions\expect( 'wp_mail' )->never();

		G2A_Fraud_Score::score_transfer( 5, 900 );

		$this->assertCount( 1, $this->wpdb->inserts, 'Only the fraud_scores row, no events row for an empty signal set.' );
		$row = $this->wpdb->inserts[0]['data'];
		$this->assertSame( 0, $row['score'] );
		$this->assertSame( 'low', $row['risk_level'] );
	}

	public function test_disposable_email_signal_scores_medium_risk(): void {
		$this->seed_transfer( [ 'customer_email' => 'buyer@mailinator.com' ] );
		// customer_email is set, so three more queries fire; none of them
		// should contribute a signal in this scenario.
		$this->wpdb->when( 'customer_email = %s AND id != %d AND created_at >= %s', 0 );
		$this->wpdb->when( 'multi_sale_flags', null );
		$this->wpdb->when( 'COUNT(DISTINCT dealer_id)', 0 );
		Functions\when( 'wc_get_order' )->justReturn( null );
		Functions\expect( 'wp_mail' )->never();

		G2A_Fraud_Score::score_transfer( 5, 900 );

		$scoreRow = $this->wpdb->inserts[0]['data'];
		$this->assertSame( 25, $scoreRow['score'], 'Default disposable_email weight is 25.' );
		$this->assertSame( 'medium', $scoreRow['risk_level'], '25 clears the default medium threshold (25) but not review (50).' );
		$this->assertCount( 2, $this->wpdb->inserts, 'A non-empty signal set also logs an events row.' );
		$this->assertStringEndsWith( 'events', $this->wpdb->inserts[1]['table'] );
	}

	public function test_combined_signals_crossing_review_threshold_notifies_admin(): void {
		$this->seed_transfer( [ 'customer_email' => 'buyer@mailinator.com' ] );
		$this->wpdb->when( 'customer_email = %s AND id != %d AND created_at >= %s', 0 );
		$this->wpdb->when( 'multi_sale_flags', 42 ); // truthy -- an open 3310.4 flag exists
		$this->wpdb->when( 'COUNT(DISTINCT dealer_id)', 0 );
		Functions\when( 'wc_get_order' )->justReturn( null );
		$this->stub_notify_admin_path();
		Functions\expect( 'wp_mail' )->once()->andReturn( true );

		G2A_Fraud_Score::score_transfer( 5, 900 );

		$scoreRow = $this->wpdb->inserts[0]['data'];
		$this->assertSame( 55, $scoreRow['score'], 'disposable_email (25) + multi_sale_flag (30) = 55.' );
		$this->assertSame( 'high', $scoreRow['risk_level'], '55 clears the default review threshold (50).' );
	}

	public function test_dealer_buyer_state_mismatch_signal_in_isolation(): void {
		$order = new class {
			public function get_total(): float {
				return 200.0; // below the $1500 high-value threshold, so that signal stays silent
			}
			public function get_billing_state(): string {
				return 'CA';
			}
		};
		$this->seed_transfer( [ 'dealer_id' => 7, 'customer_id' => 3 ] );
		$this->wpdb->when( 'customer_id = %d AND id != %d', 1 ); // has a prior order -> not first-time buyer
		$this->wpdb->when( 'SELECT state FROM', 'TX' );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wp_mail' )->never();

		G2A_Fraud_Score::score_transfer( 5, 900 );

		$scoreRow = $this->wpdb->inserts[0]['data'];
		$this->assertSame( 15, $scoreRow['score'], 'Only dealer_state_mismatch (15) fires -- dealer TX vs buyer CA.' );
		$signals = json_decode( $scoreRow['signals_json'], true );
		$this->assertArrayHasKey( 'dealer_state_mismatch', $signals );
	}

	public function test_custom_weight_filter_changes_the_final_score(): void {
		$this->seed_transfer( [ 'customer_email' => 'buyer@mailinator.com' ] );
		$this->wpdb->when( 'customer_email = %s AND id != %d AND created_at >= %s', 0 );
		$this->wpdb->when( 'multi_sale_flags', null );
		$this->wpdb->when( 'COUNT(DISTINCT dealer_id)', 0 );
		Functions\when( 'wc_get_order' )->justReturn( null );
		Functions\when( 'apply_filters' )->alias( function ( $tag, $value, ...$args ) {
			if ( 'wpistic_ffl_fraud_score_weights' === $tag ) {
				return [ 'disposable_email' => 100 ];
			}
			return $value;
		} );
		$this->stub_notify_admin_path();
		Functions\expect( 'wp_mail' )->once()->andReturn( true );

		G2A_Fraud_Score::score_transfer( 5, 900 );

		$this->assertSame( 100, $this->wpdb->inserts[0]['data']['score'], 'A store-supplied weight filter must actually change the score.' );
	}
}
