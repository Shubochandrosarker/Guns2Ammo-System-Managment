<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Settings\Settings_Store;

class SettingsStoreTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['g2aba_test_options'] = array();
	}

	public function test_all_returns_defaults_when_option_is_missing() {
		$out = ( new Settings_Store() )->all();
		$this->assertSame( 'last-30', $out['defaultRange'] );
		$this->assertSame( 'USD', $out['currency'] );
		$this->assertSame( 'monday', $out['weeklyReportDay'] );
		$this->assertSame( 7, $out['weeklyReportHour'] );
		$this->assertSame( 'America/Phoenix', $out['timezone'] );
	}

	public function test_update_persists_and_returns_hydrated_state() {
		$store = new Settings_Store();
		$out   = $store->update( array( 'defaultRange' => 'last-90', 'weeklyReportDay' => 'friday' ) );

		$this->assertTrue( $out['ok'] );
		$this->assertSame( 'last-90', $out['settings']['defaultRange'] );
		$this->assertSame( 'friday', $out['settings']['weeklyReportDay'] );

		// A second read should reflect the persisted change.
		$again = $store->all();
		$this->assertSame( 'last-90', $again['defaultRange'] );
	}

	public function test_unknown_range_falls_back_to_last_30() {
		$out = ( new Settings_Store() )->update( array( 'defaultRange' => '<script>evil</script>' ) );
		$this->assertSame( 'last-30', $out['settings']['defaultRange'] );
	}

	public function test_hour_clamps_to_valid_range() {
		$out = ( new Settings_Store() )->update( array(
			'weeklyReportHour' => 42,
			'dailySummaryHour' => -5,
		) );
		$this->assertSame( 23, $out['settings']['weeklyReportHour'] );
		$this->assertSame( 0,  $out['settings']['dailySummaryHour'] );
	}

	public function test_email_must_be_valid_or_stripped() {
		$out = ( new Settings_Store() )->update( array( 'ownerEmail' => 'not-an-email' ) );
		$this->assertSame( '', $out['settings']['ownerEmail'] );

		$out2 = ( new Settings_Store() )->update( array( 'ownerEmail' => 'owner@guns2ammo.com' ) );
		$this->assertSame( 'owner@guns2ammo.com', $out2['settings']['ownerEmail'] );
	}

	public function test_timezone_only_accepts_area_city_shape() {
		$out = ( new Settings_Store() )->update( array( 'timezone' => 'Europe/London' ) );
		$this->assertSame( 'Europe/London', $out['settings']['timezone'] );

		$out2 = ( new Settings_Store() )->update( array( 'timezone' => "'; DROP TABLE users; --" ) );
		$this->assertSame( 'America/Phoenix', $out2['settings']['timezone'] );
	}

	public function test_unknown_keys_are_dropped() {
		$out = ( new Settings_Store() )->update( array(
			'defaultRange'      => 'last-30',
			'someFutureFeature' => 'yes please',
		) );
		$this->assertArrayNotHasKey( 'someFutureFeature', $out['settings'] );
	}

	public function test_weekly_day_falls_back_when_bogus() {
		$out = ( new Settings_Store() )->update( array( 'weeklyReportDay' => 'someday' ) );
		$this->assertSame( 'monday', $out['settings']['weeklyReportDay'] );
	}

	public function test_allowed_origins_default_to_hosted_dashboard() {
		$out = ( new Settings_Store() )->all();
		$this->assertSame( array( 'https://app.guns2ammo.com' ), $out['allowedOrigins'] );
	}

	public function test_allowed_origins_are_normalised_and_deduped() {
		$out = ( new Settings_Store() )->update( array(
			'allowedOrigins' => array(
				'HTTPS://App.Guns2Ammo.com/',
				'https://app.guns2ammo.com',
				'http://localhost:5173',
			),
		) );
		$this->assertSame(
			array( 'https://app.guns2ammo.com', 'http://localhost:5173' ),
			$out['settings']['allowedOrigins']
		);
	}

	public function test_invalid_origins_are_dropped() {
		$out = ( new Settings_Store() )->update( array(
			'allowedOrigins' => array(
				'https://ok.example.com',
				'https://bad.example.com/path',
				'*',
				'javascript:alert(1)',
				42,
			),
		) );
		$this->assertSame( array( 'https://ok.example.com' ), $out['settings']['allowedOrigins'] );
	}

	public function test_empty_origin_list_falls_back_to_default() {
		$out = ( new Settings_Store() )->update( array( 'allowedOrigins' => array( 'not-an-origin' ) ) );
		$this->assertSame( array( 'https://app.guns2ammo.com' ), $out['settings']['allowedOrigins'] );

		$out2 = ( new Settings_Store() )->update( array( 'allowedOrigins' => 'not-even-an-array' ) );
		$this->assertSame( array( 'https://app.guns2ammo.com' ), $out2['settings']['allowedOrigins'] );
	}
}
