<?php

namespace WpisticFFL\Tests\Unit;

use Brain\Monkey\Functions;
use WpisticFFL\G2A_Distributor_Registry;

require_once dirname( __DIR__, 2 ) . '/includes/class-wpistic-ffl-g2a-lipseys.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-wpistic-ffl-g2a-sports-south.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-wpistic-ffl-g2a-rsr.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-wpistic-ffl-g2a-bill-hicks.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-wpistic-ffl-g2a-chattanooga.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-wpistic-ffl-g2a-distributor-registry.php';

/**
 * v1.17.0 added three more distributor clients (and v1.19.0 a fifth,
 * Chattanooga) behind the same filterable registry pattern
 * G2A_Carrier_Providers already established -- these tests cover that the
 * default set is complete and that a store can still add/replace entries
 * via wpistic_ffl_distributor_providers, same as every other provider
 * registry in this plugin. v1.21.0 added an independent enable/disable
 * toggle per distributor (🧩 Add-ons) -- covered separately below.
 */
final class DistributorRegistryTest extends TestCase {

	public function test_default_registry_includes_all_five_distributors(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$all = G2A_Distributor_Registry::known_distributors();

		$this->assertSame( [ 'lipseys', 'sports_south', 'rsr', 'bill_hicks', 'chattanooga' ], array_keys( $all ) );
		foreach ( $all as $slug => $meta ) {
			$this->assertTrue( $meta['has_catalog_sync'], "$slug should support catalog sync" );
			$this->assertTrue( $meta['has_order_submission'], "$slug should support order submission" );
			$this->assertNotSame( '', $meta['label'] );
			$this->assertTrue( class_exists( $meta['class'] ), "$slug's registered class must exist" );
		}
	}

	public function test_get_returns_null_for_an_unknown_slug(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$this->assertNull( G2A_Distributor_Registry::get( 'not-a-real-distributor' ) );
	}

	public function test_a_store_supplied_filter_can_add_a_sixth_distributor(): void {
		Functions\when( 'apply_filters' )->alias( function ( $tag, $value ) {
			if ( 'wpistic_ffl_distributor_providers' === $tag ) {
				$value['davidsons'] = [
					'label'                => "Davidson's",
					'class'                => 'Some\\Custom\\Class',
					'has_catalog_sync'     => false,
					'has_order_submission' => false,
					'protocol'             => 'unconfirmed',
				];
				return $value;
			}
			return $value;
		} );

		$all = G2A_Distributor_Registry::known_distributors();

		$this->assertArrayHasKey( 'davidsons', $all );
		$this->assertCount( 6, $all );
	}

	/**
	 * v1.21.0 -- each distributor is independently toggleable (🧩 Add-ons).
	 * Enabled-by-default (absent from stored settings means "on") so an
	 * already-running site sees no functional change until it explicitly
	 * disables one.
	 */
	public function test_is_enabled_defaults_to_true_when_nothing_is_stored(): void {
		Functions\when( 'get_option' )->justReturn( [] );

		$this->assertTrue( G2A_Distributor_Registry::is_enabled( 'lipseys' ) );
		$this->assertTrue( G2A_Distributor_Registry::is_enabled( 'anything-unregistered' ) );
	}

	public function test_is_enabled_reflects_a_stored_false(): void {
		Functions\when( 'get_option' )->justReturn( [ 'rsr' => false ] );

		$this->assertFalse( G2A_Distributor_Registry::is_enabled( 'rsr' ) );
		$this->assertTrue( G2A_Distributor_Registry::is_enabled( 'lipseys' ), 'An unrelated slug not present in the stored settings must still default to enabled.' );
	}

	public function test_set_enabled_writes_the_option_preserving_other_entries(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( null );
		Functions\when( 'get_option' )->justReturn( [ 'rsr' => false ] );
		$captured = null;
		Functions\when( 'update_option' )->alias( function ( $key, $value ) use ( &$captured ) {
			$captured = $value;
			return true;
		} );

		G2A_Distributor_Registry::set_enabled( 'lipseys', false );

		$this->assertSame( [ 'rsr' => false, 'lipseys' => false ], $captured );
	}

	/**
	 * RSR is the only distributor that self-schedules a recurring wp-cron
	 * event from inside its own constructor -- disabling it must clear
	 * that event too, or it stays scheduled forever with nothing left
	 * hooked to it (the class is never instantiated again once disabled).
	 */
	public function test_set_enabled_false_clears_rsrs_orphaned_cron_hook(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'update_option' )->justReturn( true );
		$cleared = [];
		Functions\when( 'wp_clear_scheduled_hook' )->alias( function ( $hook ) use ( &$cleared ) {
			$cleared[] = $hook;
		} );

		G2A_Distributor_Registry::set_enabled( 'rsr', false );

		$this->assertSame( [ 'wpistic_ffl_rsr_check_responses' ], $cleared );
	}

	public function test_set_enabled_false_does_not_touch_cron_for_a_distributor_with_none(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'update_option' )->justReturn( true );
		$cleared = [];
		Functions\when( 'wp_clear_scheduled_hook' )->alias( function ( $hook ) use ( &$cleared ) {
			$cleared[] = $hook;
		} );

		G2A_Distributor_Registry::set_enabled( 'lipseys', false );

		$this->assertSame( [], $cleared );
	}

	public function test_set_enabled_true_never_touches_cron(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_option' )->justReturn( [ 'rsr' => false ] );
		Functions\expect( 'wp_clear_scheduled_hook' )->never();
		$captured = null;
		Functions\when( 'update_option' )->alias( function ( $key, $value ) use ( &$captured ) {
			$captured = $value;
			return true;
		} );

		G2A_Distributor_Registry::set_enabled( 'rsr', true );

		$this->assertSame( [ 'rsr' => true ], $captured );
	}

	public function test_enabled_distributors_excludes_a_disabled_slug(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_option' )->justReturn( [ 'rsr' => false ] );

		$enabled = G2A_Distributor_Registry::enabled_distributors();

		$this->assertArrayNotHasKey( 'rsr', $enabled );
		$this->assertArrayHasKey( 'lipseys', $enabled );
		$this->assertCount( 4, $enabled, 'Five known distributors minus the one disabled.' );
	}
}
