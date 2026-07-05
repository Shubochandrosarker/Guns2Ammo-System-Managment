<?php

namespace G2A\POS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use G2A\POS\Wholesalers\Lipseys\LipseysApiClient;
use PHPUnit\Framework\TestCase;

final class LipseysApiClientTest extends TestCase
{
    /** @var array<string,string> */
    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->transients = [];
        $transients = &$this->transients;

        Functions\when('get_transient')->alias(static function ($key) use (&$transients) {
            return $transients[$key] ?? false;
        });
        Functions\when('set_transient')->alias(static function ($key, $value) use (&$transients) {
            $transients[$key] = $value;
            return true;
        });
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->alias(static fn($res) => $res['code'] ?? 0);
        Functions\when('wp_remote_retrieve_body')->alias(static fn($res) => $res['body'] ?? '');
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private static function response(int $code, array $body): array
    {
        return ['code' => $code, 'body' => json_encode($body)];
    }

    public function test_authenticate_trims_email_and_password(): void
    {
        $captured = null;
        Functions\when('wp_remote_post')->alias(static function ($url, $args) use (&$captured) {
            $captured = $args;
            return self::response(200, ['token' => 'tok-123']);
        });

        $client = new LipseysApiClient(5, '  dealer@example.com  ', "  secret \t");
        $token = $client->authenticate();

        $this->assertSame('tok-123', $token);
        $this->assertIsArray($captured);
        $sent = json_decode((string) $captured['body'], true);
        $this->assertSame('dealer@example.com', $sent['Email']);
        $this->assertSame('secret', $sent['Password']);
    }

    public function test_soft_token_rejection_forces_reauth_and_retries_once(): void
    {
        // A stale token is already cached, so the first feed call skips login.
        $this->transients['g2a_pos_lipseys_token_5'] = 'stale-token';

        $loginCalls = 0;
        Functions\when('wp_remote_post')->alias(static function () use (&$loginCalls) {
            ++$loginCalls;
            return self::response(200, ['token' => 'fresh-token']);
        });

        $feedCalls = 0;
        $tokensSent = [];
        Functions\when('wp_remote_request')->alias(static function ($url, $args) use (&$feedCalls, &$tokensSent) {
            ++$feedCalls;
            $tokensSent[] = $args['headers']['Token'] ?? '';
            if ($feedCalls === 1) {
                // Lipsey's soft rejection: HTTP 200 + authorized/success false.
                return self::response(200, [
                    'success' => false,
                    'authorized' => false,
                    'errors' => ['Token expired'],
                ]);
            }
            return self::response(200, [
                'success' => true,
                'authorized' => true,
                'data' => [['itemNo' => 'A1', 'quantity' => 3]],
            ]);
        });

        $client = new LipseysApiClient(5, 'dealer@example.com', 'secret');
        $resp = $client->fetchPricingQuantityFeed();

        $this->assertSame(2, $feedCalls, 'Soft rejection must trigger exactly one retry');
        $this->assertSame(1, $loginCalls, 'Retry must force a fresh login');
        $this->assertSame(['stale-token', 'fresh-token'], $tokensSent);
        $this->assertSame(200, $resp['_status']);
        $this->assertTrue($resp['success']);
        $this->assertSame('A1', $resp['data'][0]['itemNo']);
    }

    public function test_persistent_soft_rejection_returns_detail_and_stops_after_one_retry(): void
    {
        $this->transients['g2a_pos_lipseys_token_5'] = 'stale-token';

        Functions\when('wp_remote_post')->alias(static fn() => self::response(200, ['token' => 'fresh-token']));

        $feedCalls = 0;
        Functions\when('wp_remote_request')->alias(static function () use (&$feedCalls) {
            ++$feedCalls;
            return self::response(200, [
                'success' => false,
                'authorized' => false,
                'errors' => ['Feed not enabled for this account'],
            ]);
        });

        $client = new LipseysApiClient(5, 'dealer@example.com', 'secret');
        $resp = $client->fetchPricingQuantityFeed();

        $this->assertSame(2, $feedCalls, 'Rejection must not retry more than once');
        $this->assertSame(200, $resp['_status']);
        $this->assertFalse($resp['success']);
        $this->assertArrayHasKey('_detail', $resp);
        $this->assertStringContainsString('authorized=false', $resp['_detail']);
        $this->assertStringContainsString('Feed not enabled for this account', $resp['_detail']);
    }

    public function test_catalog_fetch_uses_raised_timeout(): void
    {
        $this->transients['g2a_pos_lipseys_token_5'] = 'tok';

        $timeouts = [];
        Functions\when('wp_remote_request')->alias(static function ($url, $args) use (&$timeouts) {
            $timeouts[] = $args['timeout'] ?? null;
            return self::response(200, [
                'success' => true,
                'authorized' => true,
                'data' => [['itemNo' => 'A1']],
            ]);
        });

        $client = new LipseysApiClient(5, 'dealer@example.com', 'secret');
        $client->fetchCatalog();
        $client->fetchPricingQuantityFeed();

        $this->assertSame([120, 60], $timeouts);
    }

    public function test_auth_failure_message_includes_envelope_detail(): void
    {
        Functions\when('wp_remote_post')->alias(static fn() => self::response(200, [
            'success' => false,
            'authorized' => false,
            'errors' => ['Login Failed'],
        ]));

        $client = new LipseysApiClient(5, 'dealer@example.com', 'wrong');

        try {
            $client->authenticate();
            $this->fail('Expected lipseys_auth_failed exception');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('lipseys_auth_failed', $e->getMessage());
            $this->assertStringContainsString('authorized=false', $e->getMessage());
            $this->assertStringContainsString('Login Failed', $e->getMessage());
        }
    }
}
