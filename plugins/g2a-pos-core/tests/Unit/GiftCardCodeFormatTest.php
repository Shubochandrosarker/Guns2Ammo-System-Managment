<?php

namespace G2A\POS\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Reflective test against the private generator: we just want to assert
 * the algorithm produces four 4-char groups separated by hyphens using
 * only the unambiguous alphabet (no 0/O/1/I).
 */
final class GiftCardCodeFormatTest extends TestCase
{
    public function test_generator_format_is_four_groups_of_four(): void
    {
        $repo = new \G2A\POS\Database\GiftCardRepository();
        $ref = new \ReflectionMethod($repo, 'generate_code');
        $ref->setAccessible(true);
        for ($i = 0; $i < 25; $i++) {
            $code = $ref->invoke($repo);
            $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code);
            $this->assertStringNotContainsString('0', $code, 'no ambiguous zero');
            $this->assertStringNotContainsString('O', $code, 'no ambiguous O');
            $this->assertStringNotContainsString('1', $code, 'no ambiguous 1');
            $this->assertStringNotContainsString('I', $code, 'no ambiguous I');
        }
    }
}
