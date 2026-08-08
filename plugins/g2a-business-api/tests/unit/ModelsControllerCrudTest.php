<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\REST\Models_Controller;

class ModelsControllerCrudTest extends TestCase {
	public function test_create_requires_provider_display_and_model() {
		$out = Models_Controller::sanitise_patch( array(), true );
		$this->assertArrayHasKey( 'error', $out );
	}

	public function test_create_accepts_minimal_valid_patch_and_fills_defaults() {
		$out = Models_Controller::sanitise_patch( array(
			'provider'    => 'openai',
			'displayName' => 'Fast GPT',
			'modelName'   => 'gpt-4o-mini',
		), true );
		$this->assertArrayNotHasKey( 'error', $out );
		$this->assertSame( 'openai', $out['data']['provider'] );
		$this->assertSame( '',        $out['data']['apiBaseUrl'] );
		$this->assertSame( 'medium',  $out['data']['costLevel'] );
	}

	public function test_create_rejects_unknown_provider() {
		$out = Models_Controller::sanitise_patch( array(
			'provider'    => 'skynet',
			'displayName' => 'x',
			'modelName'   => 'x',
		), true );
		$this->assertArrayHasKey( 'error', $out );
	}

	public function test_create_rejects_invalid_url() {
		$out = Models_Controller::sanitise_patch( array(
			'provider'    => 'custom',
			'displayName' => 'x',
			'modelName'   => 'x',
			'apiBaseUrl'  => 'not a url',
		), true );
		$this->assertArrayHasKey( 'error', $out );
	}

	public function test_update_allows_partial_patch_without_errors() {
		$out = Models_Controller::sanitise_patch( array( 'useCase' => 'edge case testing' ), false );
		$this->assertArrayNotHasKey( 'error', $out );
		$this->assertSame( 'edge case testing', $out['data']['useCase'] );
	}

	public function test_update_rejects_empty_display_name() {
		$out = Models_Controller::sanitise_patch( array( 'displayName' => '   ' ), false );
		$this->assertArrayHasKey( 'error', $out );
	}

	public function test_update_rejects_bad_cost_level() {
		$out = Models_Controller::sanitise_patch( array( 'costLevel' => 'ludicrous' ), false );
		$this->assertArrayHasKey( 'error', $out );
	}

	public function test_update_clamps_negative_context_limit_to_zero() {
		$out = Models_Controller::sanitise_patch( array( 'contextLimit' => -10 ), false );
		$this->assertSame( 0, $out['data']['contextLimit'] );
	}

	public function test_update_allows_null_fallback_to_clear() {
		$out = Models_Controller::sanitise_patch( array( 'fallbackId' => null ), false );
		$this->assertNull( $out['data']['fallbackId'] );
	}

	public function test_mint_id_uses_provider_stem_and_deduplicates() {
		$this->assertSame( 'anthropic',   Models_Controller::mint_id( array( 'provider' => 'anthropic' ), array() ) );
		$this->assertSame( 'anthropic-2', Models_Controller::mint_id( array( 'provider' => 'anthropic' ), array( 'anthropic' ) ) );
		$this->assertSame( 'anthropic-3', Models_Controller::mint_id(
			array( 'provider' => 'anthropic' ),
			array( 'anthropic', 'anthropic-2' )
		) );
	}

	public function test_mint_id_falls_back_when_provider_missing() {
		$this->assertSame( 'model', Models_Controller::mint_id( array(), array() ) );
	}
}
