<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\REST\Models_Controller;

class ModelsControllerProbeTest extends TestCase {
	public function test_anthropic_plan_posts_to_messages_with_key_headers() {
		$plan = Models_Controller::plan_probe(
			'anthropic',
			array( 'modelName' => 'claude-opus-4-8' ),
			'sk-ant-fake'
		);
		$this->assertSame( 'POST', $plan['method'] );
		$this->assertStringContainsString( 'api.anthropic.com/v1/messages', $plan['url'] );
		$this->assertSame( 'sk-ant-fake', $plan['headers']['x-api-key'] );
		$this->assertSame( '2023-06-01', $plan['headers']['anthropic-version'] );
		$this->assertStringContainsString( '"max_tokens":1', $plan['body'] );
		$this->assertStringContainsString( 'claude-opus-4-8', $plan['body'] );
	}

	public function test_anthropic_plan_defaults_to_current_model_when_model_name_missing() {
		$plan = Models_Controller::plan_probe( 'anthropic', array(), 'sk-ant-fake' );
		$this->assertStringContainsString( 'claude-opus-4-8', $plan['body'] );
	}

	public function test_openai_plan_lists_models_with_bearer_auth() {
		$plan = Models_Controller::plan_probe( 'openai', array(), 'sk-openai-fake' );
		$this->assertSame( 'GET', $plan['method'] );
		$this->assertSame( 'https://api.openai.com/v1/models', $plan['url'] );
		$this->assertSame( 'Bearer sk-openai-fake', $plan['headers']['authorization'] );
		$this->assertSame( '', $plan['verifyModel'] );
	}

	public function test_openai_plan_carries_model_name_for_verification() {
		$plan = Models_Controller::plan_probe( 'openai', array( 'modelName' => 'gpt-4o-mini' ), 'sk-openai-fake' );
		$this->assertSame( 'gpt-4o-mini', $plan['verifyModel'] );
	}

	public function test_openrouter_plan_uses_openrouter_endpoint() {
		$plan = Models_Controller::plan_probe( 'openrouter', array(), 'sk-or-fake' );
		$this->assertSame( 'GET', $plan['method'] );
		$this->assertStringContainsString( 'openrouter.ai/api/v1/models', $plan['url'] );
		$this->assertSame( '', $plan['verifyModel'] );
	}

	public function test_openrouter_plan_carries_model_name_for_verification() {
		$plan = Models_Controller::plan_probe(
			'openrouter',
			array( 'modelName' => 'qwen/qwen-2.5-72b-instruct' ),
			'sk-or-fake'
		);
		$this->assertSame( 'qwen/qwen-2.5-72b-instruct', $plan['verifyModel'] );
	}

	public function test_gemini_plan_puts_key_in_query_and_uses_model_name() {
		$plan = Models_Controller::plan_probe(
			'gemini',
			array( 'modelName' => 'gemini-2.5-pro' ),
			'AIza-fake-key'
		);
		$this->assertSame( 'GET', $plan['method'] );
		$this->assertStringContainsString( 'gemini-2.5-pro', $plan['url'] );
		$this->assertStringContainsString( 'key=AIza-fake-key', $plan['url'] );
		$this->assertArrayNotHasKey( 'authorization', $plan['headers'] );
	}

	public function test_gemini_plan_defaults_to_current_model_when_model_name_missing() {
		$plan = Models_Controller::plan_probe( 'gemini', array(), 'AIza-fake-key' );
		$this->assertStringContainsString( 'gemini-2.5-flash', $plan['url'] );
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

	public function test_verify_model_in_list_passes_when_model_present() {
		$body = '{"data":[{"id":"gpt-4o-mini"},{"id":"gpt-4o"}]}';
		$this->assertNull( Models_Controller::verify_model_in_list( 'gpt-4o-mini', $body ) );
	}

	public function test_verify_model_in_list_fails_with_examples_when_model_missing() {
		$body = '{"data":[{"id":"a/one"},{"id":"b/two"},{"id":"c/three"},{"id":"d/four"}]}';
		$err  = Models_Controller::verify_model_in_list( 'qwen/qwen2.5-72b', $body );
		$this->assertNotNull( $err );
		$this->assertStringContainsString( "model 'qwen/qwen2.5-72b' not found", $err );
		$this->assertStringContainsString( 'a/one, b/two, c/three', $err );
		$this->assertStringNotContainsString( 'd/four', $err );
	}

	public function test_verify_model_in_list_tolerates_unrecognisable_body() {
		$this->assertNull( Models_Controller::verify_model_in_list( 'gpt-4o-mini', 'not json' ) );
		$this->assertNull( Models_Controller::verify_model_in_list( 'gpt-4o-mini', '{"data":[]}' ) );
	}

	public function test_plan_catalog_builds_provider_list_calls() {
		$anthropic = Models_Controller::plan_catalog( 'anthropic', 'sk-ant-fake' );
		$this->assertSame( 'https://api.anthropic.com/v1/models', $anthropic['url'] );
		$this->assertSame( 'sk-ant-fake', $anthropic['headers']['x-api-key'] );
		$this->assertSame( '2023-06-01', $anthropic['headers']['anthropic-version'] );

		$openai = Models_Controller::plan_catalog( 'openai', 'sk-openai-fake' );
		$this->assertSame( 'https://api.openai.com/v1/models', $openai['url'] );
		$this->assertSame( 'Bearer sk-openai-fake', $openai['headers']['authorization'] );

		$openrouter = Models_Controller::plan_catalog( 'openrouter', 'sk-or-fake' );
		$this->assertSame( 'https://openrouter.ai/api/v1/models', $openrouter['url'] );
		$this->assertSame( 'Bearer sk-or-fake', $openrouter['headers']['authorization'] );
	}

	public function test_plan_catalog_requires_key_for_hosted_providers() {
		$plan = Models_Controller::plan_catalog( 'openai', '' );
		$this->assertArrayHasKey( 'error', $plan );
	}

	public function test_plan_catalog_rejects_providers_without_a_catalog() {
		$this->assertArrayHasKey( 'error', Models_Controller::plan_catalog( 'ollama', '' ) );
		$this->assertArrayHasKey( 'error', Models_Controller::plan_catalog( 'custom', 'token' ) );
	}

	public function test_parse_catalog_body_normalises_provider_shapes() {
		$openai = Models_Controller::parse_catalog_body( 'openai', '{"data":[{"id":"gpt-4o-mini"}]}' );
		$this->assertSame( array( array( 'id' => 'gpt-4o-mini', 'name' => 'gpt-4o-mini' ) ), $openai );

		$openrouter = Models_Controller::parse_catalog_body(
			'openrouter',
			'{"data":[{"id":"qwen/qwen-2.5-72b-instruct","name":"Qwen 2.5 72B Instruct"}]}'
		);
		$this->assertSame( 'Qwen 2.5 72B Instruct', $openrouter[0]['name'] );

		$anthropic = Models_Controller::parse_catalog_body(
			'anthropic',
			'{"data":[{"id":"claude-opus-4-8","display_name":"Claude Opus 4.8"}]}'
		);
		$this->assertSame( 'Claude Opus 4.8', $anthropic[0]['name'] );
	}

	public function test_parse_catalog_body_caps_at_300_and_survives_garbage() {
		$entries = array();
		for ( $i = 0; $i < 350; $i++ ) {
			$entries[] = array( 'id' => 'model-' . $i );
		}
		$out = Models_Controller::parse_catalog_body( 'openai', (string) json_encode( array( 'data' => $entries ) ) );
		$this->assertCount( 300, $out );

		$this->assertSame( array(), Models_Controller::parse_catalog_body( 'openai', 'not json' ) );
		$this->assertSame( array(), Models_Controller::parse_catalog_body( 'openai', '{"data":[{"noid":true}]}' ) );
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
