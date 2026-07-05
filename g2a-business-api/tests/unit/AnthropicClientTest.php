<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Integrations\Anthropic_Client;

class AnthropicClientTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['g2aba_test_options'] = array();
	}

	public function test_resolve_model_uses_the_connections_configured_model_name() {
		$GLOBALS['g2aba_test_options']['g2aba_models'] = array(
			'anthropic-primary' => array(
				'id'        => 'anthropic-primary',
				'provider'  => 'anthropic',
				'modelName' => 'claude-sonnet-4-6',
			),
		);
		$client = new Anthropic_Client();
		$this->assertSame( 'claude-sonnet-4-6', $client->resolve_model() );
	}

	public function test_resolve_model_reads_the_record_for_the_given_connection_id() {
		$GLOBALS['g2aba_test_options']['g2aba_models'] = array(
			'anthropic-primary' => array( 'modelName' => 'claude-sonnet-4-6' ),
			'anthropic-2'       => array( 'modelName' => 'claude-haiku-4-5' ),
		);
		$client = new Anthropic_Client( 'anthropic-2' );
		$this->assertSame( 'claude-haiku-4-5', $client->resolve_model() );
	}

	public function test_resolve_model_falls_back_when_connection_is_unknown() {
		$client = new Anthropic_Client( 'missing-connection' );
		$this->assertSame( 'claude-opus-4-8', $client->resolve_model() );
	}

	public function test_resolve_model_falls_back_when_model_name_is_blank() {
		$GLOBALS['g2aba_test_options']['g2aba_models'] = array(
			'anthropic-primary' => array( 'modelName' => '   ' ),
		);
		$client = new Anthropic_Client();
		$this->assertSame( 'claude-opus-4-8', $client->resolve_model() );
	}
}
