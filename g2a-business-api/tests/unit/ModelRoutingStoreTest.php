<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Routing\Model_Routing_Store;

class ModelRoutingStoreTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['g2aba_test_options'] = array(
			'g2aba_models' => array(
				'm1' => array( 'id' => 'm1' ),
				'm2' => array( 'id' => 'm2' ),
			),
		);
	}

	public function test_all_returns_every_purpose_null_by_default() {
		$out = ( new Model_Routing_Store() )->all();
		foreach ( Model_Routing_Store::PURPOSES as $p ) {
			$this->assertArrayHasKey( $p, $out );
			$this->assertNull( $out[ $p ] );
		}
	}

	public function test_update_persists_valid_ids() {
		$out = ( new Model_Routing_Store() )->update( array(
			'business_analysis' => 'm1',
			'email_drafts'      => 'm2',
		) );
		$this->assertTrue( $out['ok'] );
		$this->assertSame( 'm1', $out['routing']['business_analysis'] );
		$this->assertSame( 'm2', $out['routing']['email_drafts'] );
	}

	public function test_update_rejects_unknown_model_id() {
		$out = ( new Model_Routing_Store() )->update( array( 'business_analysis' => 'not-a-model' ) );
		$this->assertFalse( $out['ok'] );
		$this->assertStringContainsString( 'Unknown model', $out['error'] );
	}

	public function test_update_ignores_unknown_purposes() {
		$out = ( new Model_Routing_Store() )->update( array(
			'business_analysis' => 'm1',
			'blockchain_alchemy' => 'm1',
		) );
		$this->assertTrue( $out['ok'] );
		$this->assertArrayNotHasKey( 'blockchain_alchemy', $out['routing'] );
	}

	public function test_null_or_empty_clears_route() {
		$store = new Model_Routing_Store();
		$store->update( array( 'business_analysis' => 'm1' ) );
		$out = $store->update( array( 'business_analysis' => null ) );
		$this->assertNull( $out['routing']['business_analysis'] );

		$out2 = $store->update( array( 'seo_analysis' => '' ) );
		$this->assertNull( $out2['routing']['seo_analysis'] );
	}

	public function test_get_for_returns_current_route() {
		$store = new Model_Routing_Store();
		$store->update( array( 'business_analysis' => 'm1' ) );
		$this->assertSame( 'm1', $store->get_for( 'business_analysis' ) );
		$this->assertNull( $store->get_for( 'nonexistent' ) );
	}

	public function test_labels_covers_every_purpose() {
		$labels = Model_Routing_Store::labels();
		foreach ( Model_Routing_Store::PURPOSES as $p ) {
			$this->assertArrayHasKey( $p, $labels );
			$this->assertNotEmpty( $labels[ $p ] );
		}
	}
}
