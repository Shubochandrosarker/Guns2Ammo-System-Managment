<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Routing\Model_Routing_Store;

class ModelRoutingStoreTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['g2aba_test_options'] = array(
			'g2aba_models' => array(
				'm1' => array( 'id' => 'm1', 'provider' => 'anthropic' ),
				'm2' => array( 'id' => 'm2', 'provider' => 'openai' ),
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

	public function test_connection_for_returns_routed_anthropic_connection() {
		$store = new Model_Routing_Store();
		$store->update( array( 'business_analysis' => 'm1' ) );
		$this->assertSame( 'm1', $store->connection_for( 'business_analysis', 'anthropic-primary' ) );
	}

	public function test_connection_for_falls_back_when_purpose_is_unrouted() {
		$store = new Model_Routing_Store();
		$this->assertSame( 'anthropic-primary', $store->connection_for( 'business_analysis', 'anthropic-primary' ) );
	}

	public function test_connection_for_falls_back_when_route_targets_non_anthropic_provider() {
		$store = new Model_Routing_Store();
		$store->update( array( 'email_drafts' => 'm2' ) );
		$this->assertSame( 'anthropic-primary', $store->connection_for( 'email_drafts', 'anthropic-primary' ) );
	}

	public function test_connection_for_falls_back_when_routed_connection_was_deleted() {
		$store = new Model_Routing_Store();
		$store->update( array( 'business_analysis' => 'm1' ) );
		unset( $GLOBALS['g2aba_test_options']['g2aba_models']['m1'] );
		$this->assertSame( 'anthropic-primary', $store->connection_for( 'business_analysis', 'anthropic-primary' ) );
	}

	public function test_labels_covers_every_purpose() {
		$labels = Model_Routing_Store::labels();
		foreach ( Model_Routing_Store::PURPOSES as $p ) {
			$this->assertArrayHasKey( $p, $labels );
			$this->assertNotEmpty( $labels[ $p ] );
		}
	}

	public function test_sales_pipeline_is_a_known_purpose_with_a_label() {
		$this->assertContains( 'sales_pipeline', Model_Routing_Store::PURPOSES );
		$this->assertArrayHasKey( 'sales_pipeline', Model_Routing_Store::labels() );
		$this->assertNotEmpty( Model_Routing_Store::labels()['sales_pipeline'] );
	}

	public function test_sales_pipeline_purpose_routes_like_any_other() {
		$store = new Model_Routing_Store();
		$out   = $store->update( array( 'sales_pipeline' => 'm1' ) );

		$this->assertTrue( $out['ok'] );
		$this->assertSame( 'm1', $out['routing']['sales_pipeline'] );
		$this->assertSame( 'm1', $store->get_for( 'sales_pipeline' ) );
		$this->assertSame( 'm1', $store->connection_for( 'sales_pipeline', 'anthropic-primary' ) );
	}
}
