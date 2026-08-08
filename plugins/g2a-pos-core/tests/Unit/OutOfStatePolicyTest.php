<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Compliance\State\OutOfStatePolicy;
use PHPUnit\Framework\TestCase;

final class OutOfStatePolicyTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset the WP option stubs between tests.
        $GLOBALS['__wp_options'] = [];
    }

    public function test_modes_constant_matches_supported_set(): void
    {
        $this->assertSame(
            ['allow', 'block_with_override', 'block_all'],
            OutOfStatePolicy::MODES,
        );
    }

    public function test_default_mode_is_block_with_override(): void
    {
        $this->assertSame('block_with_override', OutOfStatePolicy::DEFAULT_MODE);
        $this->assertSame('block_with_override', OutOfStatePolicy::mode_for(null));
    }

    public function test_set_global_rejects_invalid_mode(): void
    {
        $this->assertFalse(OutOfStatePolicy::set_global('something_else'));
        $this->assertSame('block_with_override', OutOfStatePolicy::mode_for(null));
    }

    public function test_set_global_accepts_valid_modes(): void
    {
        foreach (OutOfStatePolicy::MODES as $m) {
            $this->assertTrue(OutOfStatePolicy::set_global($m), "set_global rejected $m");
            $this->assertSame($m, OutOfStatePolicy::mode_for(null));
        }
    }

    public function test_per_location_overrides_global(): void
    {
        OutOfStatePolicy::set_global('block_all');
        OutOfStatePolicy::set_for_location(7, 'allow');
        $this->assertSame('block_all', OutOfStatePolicy::mode_for(null));
        $this->assertSame('block_all', OutOfStatePolicy::mode_for(1));
        $this->assertSame('allow', OutOfStatePolicy::mode_for(7));
    }

    public function test_set_for_location_with_null_clears_the_override(): void
    {
        OutOfStatePolicy::set_global('block_all');
        OutOfStatePolicy::set_for_location(7, 'allow');
        OutOfStatePolicy::set_for_location(7, null);
        $this->assertSame('block_all', OutOfStatePolicy::mode_for(7));
    }

    public function test_evaluate_returns_no_apply_when_states_match(): void
    {
        $r = OutOfStatePolicy::evaluate('AZ', 'AZ');
        $this->assertFalse($r['applies']);
        $this->assertFalse($r['blocked']);
    }

    public function test_evaluate_blocks_under_block_with_override_without_override(): void
    {
        OutOfStatePolicy::set_global('block_with_override');
        $r = OutOfStatePolicy::evaluate('CA', 'AZ');
        $this->assertTrue($r['applies']);
        $this->assertTrue($r['blocked']);
        $this->assertTrue($r['override_allowed']);
        $this->assertStringContainsString('CA', $r['reason']);
        $this->assertStringContainsString('AZ', $r['reason']);
    }

    public function test_evaluate_releases_block_when_override_granted(): void
    {
        OutOfStatePolicy::set_global('block_with_override');
        $r = OutOfStatePolicy::evaluate('CA', 'AZ', ['override_granted' => true]);
        $this->assertTrue($r['applies']);
        $this->assertFalse($r['blocked']);
    }

    public function test_evaluate_block_all_does_not_release_on_override(): void
    {
        OutOfStatePolicy::set_global('block_all');
        $r = OutOfStatePolicy::evaluate('CA', 'AZ', ['override_granted' => true]);
        $this->assertTrue($r['applies']);
        $this->assertTrue($r['blocked']);
        $this->assertFalse($r['override_allowed']);
    }

    public function test_evaluate_allow_mode_lets_sale_through(): void
    {
        OutOfStatePolicy::set_global('allow');
        $r = OutOfStatePolicy::evaluate('CA', 'AZ');
        $this->assertTrue($r['applies']);
        $this->assertFalse($r['blocked']);
        $this->assertFalse($r['override_allowed']);
    }

    public function test_evaluate_normalizes_casing(): void
    {
        OutOfStatePolicy::set_global('block_with_override');
        $r = OutOfStatePolicy::evaluate('ca', 'az');
        $this->assertTrue($r['applies']);
        $this->assertTrue($r['blocked']);
        $this->assertStringContainsString('CA', $r['reason']);
        $this->assertStringContainsString('AZ', $r['reason']);
    }
}
