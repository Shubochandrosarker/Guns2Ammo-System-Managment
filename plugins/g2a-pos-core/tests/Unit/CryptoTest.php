<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Security\Crypto;
use PHPUnit\Framework\TestCase;

final class CryptoTest extends TestCase
{
    public function test_round_trip(): void
    {
        if (!Crypto::available()) {
            $this->markTestSkipped('sodium not available');
        }
        $ct = Crypto::encrypt('John Doe 1990-01-15');
        $this->assertStringStartsWith('g2av1.', $ct);
        $this->assertSame('John Doe 1990-01-15', Crypto::decrypt($ct));
    }

    public function test_two_encryptions_differ(): void
    {
        if (!Crypto::available()) {
            $this->markTestSkipped('sodium not available');
        }
        $a = Crypto::encrypt('payload');
        $b = Crypto::encrypt('payload');
        $this->assertNotSame($a, $b);
    }

    public function test_blind_index_stable_and_distinct(): void
    {
        $a = Crypto::blind_index('Doe|John|1990-01-15', 'transferee');
        $b = Crypto::blind_index('Doe|John|1990-01-15', 'transferee');
        $c = Crypto::blind_index('Doe|Jane|1990-01-15', 'transferee');
        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
    }

    public function test_decrypt_garbage_returns_null(): void
    {
        if (!Crypto::available()) {
            $this->markTestSkipped('sodium not available');
        }
        $this->assertNull(Crypto::decrypt('g2av1.aaa.bbb.ccc.ddd'));
    }

    public function test_plain_input_passes_through(): void
    {
        $this->assertSame('plain', Crypto::decrypt('plain'));
    }
}
