<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Ai\Gateway;
use PHPUnit\Framework\TestCase;

final class GatewayProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__wp_options'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__wp_options']);
        parent::tearDown();
    }

    public function test_legacy_config_defaults_to_ollama_provider_and_models(): void
    {
        // A pre-provider option row (or none at all) keeps the old local-Ollama
        // model defaults so existing installs keep working unchanged.
        $cfg = Gateway::config();
        $this->assertSame('ollama', $cfg['provider']);
        $this->assertSame('qwen2.5:7b-instruct', $cfg['chat_model']);
        $this->assertSame('nomic-embed-text', $cfg['embed_model']);
        $this->assertSame('stub', $cfg['mode']);
        $this->assertSame('local', $cfg['brain_backend']);
    }

    public function test_openrouter_preset_fills_endpoint_and_hosted_model(): void
    {
        $GLOBALS['__wp_options']['g2a_pos_ai_gateway'] = ['provider' => 'openrouter'];
        $cfg = Gateway::config();
        $this->assertSame('https://openrouter.ai/api/v1/chat/completions', $cfg['chat_endpoint']);
        $this->assertSame('anthropic/claude-sonnet-4.5', $cfg['chat_model']);
        // The Ollama tags must never leak onto a hosted provider (they 404).
        $this->assertStringNotContainsString('qwen', $cfg['chat_model']);
        $this->assertSame('', $cfg['embed_model'], 'OpenRouter has no /embeddings — no embed model preset');
    }

    public function test_operator_overrides_win_over_presets(): void
    {
        $GLOBALS['__wp_options']['g2a_pos_ai_gateway'] = [
            'provider'      => 'openrouter',
            'chat_endpoint' => 'https://proxy.example.com/v1/chat/completions',
            'chat_model'    => 'meta-llama/llama-3.3-70b-instruct',
        ];
        $cfg = Gateway::config();
        $this->assertSame('https://proxy.example.com/v1/chat/completions', $cfg['chat_endpoint']);
        $this->assertSame('meta-llama/llama-3.3-70b-instruct', $cfg['chat_model']);
    }

    public function test_openai_embed_provider_preset(): void
    {
        $GLOBALS['__wp_options']['g2a_pos_ai_gateway'] = [
            'provider'       => 'openrouter',
            'embed_provider' => 'openai',
        ];
        $cfg = Gateway::config();
        $this->assertSame('https://api.openai.com/v1/embeddings', $cfg['embed_endpoint']);
        $this->assertSame('text-embedding-3-small', $cfg['embed_model']);
    }

    public function test_none_and_cloudflare_embed_providers_disable_wp_side_embeddings(): void
    {
        foreach (['none', 'cloudflare'] as $embed) {
            $GLOBALS['__wp_options']['g2a_pos_ai_gateway'] = [
                'mode'           => 'live',
                'provider'       => 'openrouter',
                'embed_provider' => $embed,
                'embed_endpoint' => 'https://api.openai.com/v1/embeddings',
            ];
            $cfg = Gateway::config();
            $this->assertSame('', $cfg['embed_endpoint'], "embed_provider={$embed} must clear the endpoint");
            $this->assertFalse(Gateway::embeddings_enabled($cfg), "embed_provider={$embed} must disable embeddings");
        }
    }

    public function test_unknown_provider_falls_back_to_ollama(): void
    {
        $GLOBALS['__wp_options']['g2a_pos_ai_gateway'] = ['provider' => 'skynet'];
        $this->assertSame('ollama', Gateway::config()['provider']);
    }

    public function test_openrouter_requests_carry_attribution_headers(): void
    {
        $headers = Gateway::chat_headers([
            'provider' => 'openrouter',
            'api_key'  => 'sk-or-test',
        ]);
        $this->assertSame('Bearer sk-or-test', $headers['Authorization']);
        $this->assertSame('Guns2Ammo POS', $headers['X-Title']);
        $this->assertArrayHasKey('HTTP-Referer', $headers);
        $this->assertSame('application/json', $headers['Content-Type']);
    }

    public function test_non_openrouter_requests_have_no_attribution_headers(): void
    {
        foreach (['ollama', 'openai_compatible'] as $provider) {
            $headers = Gateway::chat_headers([
                'provider' => $provider,
                'api_key'  => 'key',
            ]);
            $this->assertArrayNotHasKey('HTTP-Referer', $headers, $provider);
            $this->assertArrayNotHasKey('X-Title', $headers, $provider);
        }
    }

    public function test_stub_mode_test_connection_reports_configuration_needed(): void
    {
        $res = Gateway::test_connection();
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('stub mode', (string) $res['error']);
    }
}
