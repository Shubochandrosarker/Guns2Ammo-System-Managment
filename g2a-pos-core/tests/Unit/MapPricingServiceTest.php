<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Pricing\MapPricingService;
use PHPUnit\Framework\TestCase;

final class MapPricingServiceTest extends TestCase
{
    public function test_no_rule_means_no_suppression(): void
    {
        $r = MapPricingService::evaluateRule(null, 100.00);
        $this->assertSame(['has_rule' => false], $r);
    }

    public function test_zero_or_negative_map_price_is_ignored(): void
    {
        foreach ([0.0, -5.0] as $bad) {
            $r = MapPricingService::evaluateRule(['map_price' => $bad], 100.00);
            $this->assertSame(['has_rule' => false], $r, "MAP $bad should be treated as no rule");
        }
    }

    public function test_price_above_map_does_not_suppress(): void
    {
        $r = MapPricingService::evaluateRule([
            'id' => 7,
            'map_price' => 599.00,
            'display_mode' => 'click_to_reveal',
            'source' => 'lipseys_csv',
        ], 650.00);

        $this->assertTrue($r['has_rule']);
        $this->assertFalse($r['below_map']);
        $this->assertSame(599.00, $r['map_price']);
        $this->assertSame(650.00, $r['active_price']);
        $this->assertSame('click_to_reveal', $r['display_mode']);
        $this->assertSame('lipseys_csv', $r['source']);
        $this->assertSame(7, $r['rule_id']);
    }

    public function test_price_equal_to_map_does_not_suppress(): void
    {
        $r = MapPricingService::evaluateRule(['map_price' => 599.00], 599.00);
        $this->assertFalse($r['below_map'], 'price === MAP is allowed by manufacturer policy');
    }

    public function test_price_below_map_triggers_suppression(): void
    {
        $r = MapPricingService::evaluateRule([
            'id' => 9,
            'map_price' => 599.00,
            'display_mode' => 'show_map',
            'override_label' => 'See cart for price',
        ], 549.99);

        $this->assertTrue($r['has_rule']);
        $this->assertTrue($r['below_map']);
        $this->assertSame('show_map', $r['display_mode']);
        $this->assertSame('See cart for price', $r['override_label']);
    }

    public function test_zero_active_price_does_not_count_as_below_map(): void
    {
        // Defensive: an uninitialized product (price=0) should not be reported as
        // a violation. The filter only kicks in for *real* below-MAP sale prices.
        $r = MapPricingService::evaluateRule(['map_price' => 100.0], 0.0);
        $this->assertTrue($r['has_rule']);
        $this->assertFalse($r['below_map']);
    }

    public function test_defaults_when_rule_omits_optional_fields(): void
    {
        $r = MapPricingService::evaluateRule(['map_price' => 50.0], 25.0);
        $this->assertSame('click_to_reveal', $r['display_mode']);
        $this->assertSame('manual', $r['source']);
        $this->assertNull($r['override_label']);
        $this->assertSame(0, $r['rule_id']);
    }
}
