<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Auth\JWT;
use PHPUnit\Framework\TestCase;

/**
 * Covers the Authorization-header extraction that lets a cross-origin SPA
 * authenticate by Bearer token even on SAPIs that drop HTTP_AUTHORIZATION.
 */
final class JwtBearerTokenTest extends TestCase
{
    /** @var array<string,string> */
    private array $savedServer = [];

    protected function setUp(): void
    {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (isset($_SERVER[$key])) {
                $this->savedServer[$key] = $_SERVER[$key];
            }
            unset($_SERVER[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            unset($_SERVER[$key]);
            if (isset($this->savedServer[$key])) {
                $_SERVER[$key] = $this->savedServer[$key];
            }
        }
    }

    private function readToken(): string
    {
        $m = new \ReflectionMethod(JWT::class, 'read_bearer_token');
        $m->setAccessible(true);
        return (string) $m->invoke(null);
    }

    public function test_reads_standard_authorization_header(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer aaa.bbb.ccc';
        $this->assertSame('aaa.bbb.ccc', $this->readToken());
    }

    public function test_bearer_keyword_is_case_insensitive(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'bearer aaa.bbb.ccc';
        $this->assertSame('aaa.bbb.ccc', $this->readToken());
    }

    public function test_falls_back_to_redirect_variant(): void
    {
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer xxx.yyy.zzz';
        $this->assertSame('xxx.yyy.zzz', $this->readToken());
    }

    public function test_standard_header_wins_over_redirect(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer primary.token';
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer fallback.token';
        $this->assertSame('primary.token', $this->readToken());
    }

    public function test_returns_empty_without_header(): void
    {
        $this->assertSame('', $this->readToken());
    }

    public function test_ignores_non_bearer_schemes(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz';
        $this->assertSame('', $this->readToken());
    }
}
