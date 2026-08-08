<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\System\Site_Health_Summary;

class SiteHealthSummaryTest extends TestCase {
	public function test_tally_counts_each_bucket() {
		$results = array(
			array( 'status' => 'good' ),
			array( 'status' => 'recommended' ),
			array( 'status' => 'critical' ),
			array( 'status' => 'critical' ),
		);
		$this->assertSame(
			array( 'criticalIssues' => 2, 'recommendedImprovements' => 1, 'good' => 1 ),
			Site_Health_Summary::tally( $results )
		);
	}

	public function test_tally_treats_missing_or_unknown_status_as_good() {
		$results = array( array(), array( 'status' => 'weird' ), array( 'status' => 'GOOD' ) );
		$out = Site_Health_Summary::tally( $results );
		$this->assertSame( 0, $out['criticalIssues'] );
		$this->assertSame( 0, $out['recommendedImprovements'] );
		$this->assertSame( 3, $out['good'] );
	}

	public function test_tally_is_case_insensitive() {
		$out = Site_Health_Summary::tally( array( array( 'status' => 'CRITICAL' ), array( 'status' => 'Recommended' ) ) );
		$this->assertSame( 1, $out['criticalIssues'] );
		$this->assertSame( 1, $out['recommendedImprovements'] );
	}

	public function test_tally_empty_results() {
		$this->assertSame(
			array( 'criticalIssues' => 0, 'recommendedImprovements' => 0, 'good' => 0 ),
			Site_Health_Summary::tally( array() )
		);
	}

	public function test_fallback_tally_flags_missing_auth_key_as_critical() {
		$out = Site_Health_Summary::fallback_tally( array( 'debugDisplay' => false, 'hasAuthKey' => false ) );
		$this->assertSame( 1, $out['criticalIssues'] );
		$this->assertSame( 0, $out['recommendedImprovements'] );
	}

	public function test_fallback_tally_flags_debug_display_as_recommended() {
		$out = Site_Health_Summary::fallback_tally( array( 'debugDisplay' => true, 'hasAuthKey' => true ) );
		$this->assertSame( 0, $out['criticalIssues'] );
		$this->assertSame( 1, $out['recommendedImprovements'] );
	}

	public function test_fallback_tally_clean_environment() {
		$out = Site_Health_Summary::fallback_tally( array( 'debugDisplay' => false, 'hasAuthKey' => true ) );
		$this->assertSame( 0, $out['criticalIssues'] );
		$this->assertSame( 0, $out['recommendedImprovements'] );
	}

	public function test_fallback_tally_defaults_missing_keys_to_falsy() {
		$out = Site_Health_Summary::fallback_tally( array() );
		$this->assertSame( 1, $out['criticalIssues'] ); // hasAuthKey missing => treated as false => critical.
		$this->assertSame( 0, $out['recommendedImprovements'] );
	}
}
