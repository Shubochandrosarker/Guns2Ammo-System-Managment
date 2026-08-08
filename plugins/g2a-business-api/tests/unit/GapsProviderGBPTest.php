<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Providers\Gaps_Provider;

class GapsProviderGBPTest extends TestCase {
	private array $zero_bookings    = array();
	private array $zero_memberships = array();
	private array $zero_store       = array();
	private array $zero_seo         = array();

	public function test_flags_gbp_actions_gap_when_views_high_actions_low() {
		$gbp = array(
			'viewsSearch'         => 1200,
			'viewsMaps'           => 300,
			'callsClicked'        => 2,
			'directionsRequested' => 3,
			'websiteClicked'      => 1,
			'reviewCount'         => 30,
		);
		$gaps = ( new Gaps_Provider() )->detect( $this->zero_bookings, $this->zero_memberships, $this->zero_store, $this->zero_seo, $gbp );

		$ids = array_column( $gaps, 'id' );
		$this->assertContains( 'gap-gbp-actions', $ids );
	}

	public function test_flags_review_velocity_gap() {
		$gbp = array(
			'viewsSearch'         => 100,
			'viewsMaps'           => 100,
			'callsClicked'        => 10,
			'directionsRequested' => 40,
			'websiteClicked'      => 10,
			'reviewCount'         => 1,
		);
		$gaps = ( new Gaps_Provider() )->detect( array(), array(), array(), array(), $gbp );

		$ids = array_column( $gaps, 'id' );
		$this->assertContains( 'gap-gbp-reviews', $ids );
	}

	public function test_no_gbp_gaps_when_signal_healthy() {
		$gbp = array(
			'viewsSearch'         => 1000,
			'viewsMaps'           => 500,
			'callsClicked'        => 40,
			'directionsRequested' => 60,
			'websiteClicked'      => 90,
			'reviewCount'         => 200,
		);
		$gaps = ( new Gaps_Provider() )->detect( array(), array(), array(), array(), $gbp );

		$ids = array_column( $gaps, 'id' );
		$this->assertNotContains( 'gap-gbp-actions',  $ids );
		$this->assertNotContains( 'gap-gbp-reviews', $ids );
	}

	public function test_gbp_argument_defaults_and_does_not_break_existing_callers() {
		// Calling the old 4-argument signature still works.
		$gaps = ( new Gaps_Provider() )->detect( array(), array(), array(), array() );
		$this->assertIsArray( $gaps );
	}
}
