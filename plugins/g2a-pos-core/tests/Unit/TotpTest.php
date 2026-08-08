<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Security\Totp;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    public function test_rfc6238_vector(): void
    {
        // RFC 6238 Appendix B vector at T=59 with key "12345678901234567890"
        $secret = Totp::base32_encode('12345678901234567890');
        $code = Totp::code($secret, 59);
        $this->assertSame('287082', $code);
    }

    public function test_verify_within_window(): void
    {
        $secret = Totp::generate_secret();
        $now = time();
        $code = Totp::code($secret, $now);
        $this->assertTrue(Totp::verify($secret, $code, 1, $now));
        $this->assertTrue(Totp::verify($secret, $code, 1, $now + 25));
        $this->assertFalse(Totp::verify($secret, '000000', 1, $now));
    }

    public function test_base32_roundtrip(): void
    {
        $bin = random_bytes(20);
        $this->assertSame($bin, Totp::base32_decode(Totp::base32_encode($bin)));
    }
}
