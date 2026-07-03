<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\REST\Models_Controller;

class ModelsControllerProbeTest extends TestCase {
	public function test_anthropic_plan_posts_to_messages_with_key_headers() {
		$plan = Models_Controller::plan_probe(
			'anthropic',
			array( 'modelName' => 'claude-3-haiku-20240307' ),
			'sk-ant-fake'
		);
		$this->assertSame( 'POST', $plan['method'] );
		$this->assertStringContainsString( 'api.anthropic.com/v1/messages', $plan['url'] );
		$this->assertSame( 'sk-ant-fake', $plan['headers']['x-api-key'] );
		$this->assertSame( '2023-06-01', $plan['headers']['anthropic-version'] );
		$this->assertStringContainsString( '"max_tokens":1', $plan['body'] );
		$this->assertStringContainsString( 'claude-3-haiku-20240307', $plan['body'] );
	}

	public function test_openai_plan_lists_models_with_bearer_auth() {
		$plan = Models_Controller::plan_probe( 'openai', array(), 'sk-openai-fake' );
		$this->assertSame( 'GET', $plan['method'] );
		$this->assertSame( 'https://api.openai.com/v1/models', $plan['url'] );
		$this->assertSame( 'Bearer sk-openai-fake', $plan['headers']['authorization'] );
	}

	public function test_openrouter_plan_uses_openrouter_endpoint() {
		$plan = Models_Controller::plan_probe( 'openrouter', array(), 'sk-or-fake' );
		$this->assertSame( 'GET', $plan['method'] );
		$this->assertStringContainsString( 'openrouter.ai/api/v1/models', $plan['url'] );
	}

	public function test_gemini_plan_puts_key_in_query_and_uses_model_name() {
		$plan = Models_Controller::plan_probe(
			'gemini',
			array( 'modelName' => 'gemini-1.5-pro' ),
			'AIza-fake-key'
		);
		$this->assertSame( 'GET', $plan['method'] );
		$this->assertStringContainsString( 'gemini-1.5-pro', $plan['url'] );
		$this->assertStringContainsString( 'key=AIza-fake-key', $plan['url'] );
		$this->assertArrayNotHasKey( 'authorization', $plan['headers'] );
	}

	public function test_ollama_plan_requires_api_base_url() {
		$missing = Models_Controller::plan_probe( 'ollama', array(), '' );
		$this->assertArrayHasKey( 'error', $missing );

		$ok = Models_Controller::plan_probe(
			'ollama',
			array( 'apiBaseUrl' => 'http://127.0.0.1:11434' ),
			''
		);
		$this->assertSame( 'GET', $ok['method'] );
		$this->assertStringContainsString( '/api/tags', $ok['url'] );
	}

	public function test_custom_plan_falls_back_to_root_get_and_bearer_when_key_present() {
		$plan = Models_Controller::plan_probe(
			'custom',
			array( 'apiBaseUrl' => 'https://api.example.com' ),
			'some-token'
		);
		$this->assertSame( 'GET', $plan['method'] );
		$this->assertSame( 'Bearer some-token', $plan['headers']['authorization'] );
	}

	public function test_custom_plan_returns_error_when_apiBaseUrl_missing() {
		$plan = Models_Controller::plan_probe( 'custom', array(), 'token' );
		$this->assertArrayHasKey( 'error', $plan );
	}

	public function test_provider_requires_key_matches_hosted_providers() {
		$this->assertTrue( Models_Controller::provider_requires_key( 'anthropic' ) );
		$this->assertTrue( Models_Controller::provider_requires_key( 'openai' ) );
		$this->assertTrue( Models_Controller::provider_requires_key( 'gemini' ) );
		$this->assertTrue( Models_Controller::provider_requires_key( 'openrouter' ) );
		$this->assertFalse( Models_Controller::provider_requires_key( 'ollama' ) );
		$this->assertFalse( Models_Controller::provider_requires_key( 'custom' ) );
	}

	public function test_extract_error_summary_pulls_anthropic_message() {
		$msg = Models_Controller::extract_error_summary( '{"error":{"type":"authentication_error","message":"invalid x-api-key"}}' );
		$this->assertSame( 'invalid x-api-key', $msg );
	}

	public function test_extract_error_summary_handles_gemini_style_string_error() {
		$msg = Models_Controller::extract_error_summary( '{"error":"API key not valid."}' );
		$this->assertSame( 'API key not valid.', $msg );
	}

	public function test_extract_error_summary_truncates_unstructured_body() {
		$body = str_repeat( 'a', 500 );
		$out  = Models_Controller::extract_error_summary( $body );
		$this->assertSame( 240, strlen( $out ) );
	}

	public function test_extract_error_summary_reports_empty_body() {
		$this->assertSame( 'HTTP error with empty body.', Models_Controller::extract_error_summary( '' ) );
	}
}
