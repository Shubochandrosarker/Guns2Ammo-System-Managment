<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Wholesalers\Lipseys\LipseysDropShipPolicy;
use PHPUnit\Framework\TestCase;

final class LipseysDropShipPolicyTest extends TestCase
{
    public function test_firearms_only_order_is_allowed(): void
    {
        $result = LipseysDropShipPolicy::validate(
            [
                ['vendor_sku' => 'GLP61950203', 'item_type' => 'firearm'],
                ['vendor_sku' => 'RU1022', 'ffl_required' => 1],
            ],
            'TX'
        );

        $this->assertTrue($result['ok']);
    }

    public function test_accessories_only_order_outside_california_is_allowed(): void
    {
        $result = LipseysDropShipPolicy::validate(
            [
                ['vendor_sku' => 'ACC1', 'item_type' => 'accessory'],
                ['vendor_sku' => 'OPT1', 'item_type' => 'optic'],
            ],
            'NV'
        );

        $this->assertTrue($result['ok']);
    }

    public function test_mixed_firearm_and_accessory_is_rejected(): void
    {
        $result = LipseysDropShipPolicy::validate(
            [
                ['vendor_sku' => 'GLP61950203', 'item_type' => 'firearm'],
                ['vendor_sku' => 'ACC1', 'item_type' => 'accessory'],
            ],
            'TX'
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('mixed_firearm_accessory', $result['error']);
    }

    public function test_firearm_plus_ammo_is_rejected_as_mixed(): void
    {
        $result = LipseysDropShipPolicy::validate(
            [
                ['vendor_sku' => 'GLP61950203', 'ffl_required' => 1],
                ['vendor_sku' => 'AMMO1', 'item_type' => 'ammunition'],
            ],
            'TX'
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('mixed_firearm_accessory', $result['error']);
    }

    public function test_accessory_dropship_to_california_is_blocked(): void
    {
        $result = LipseysDropShipPolicy::validate(
            [
                ['vendor_sku' => 'ACC1', 'item_type' => 'accessory'],
            ],
            'ca'
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('ca_accessory_dropship_blocked', $result['error']);
    }

    public function test_firearm_dropship_to_california_is_allowed(): void
    {
        $result = LipseysDropShipPolicy::validate(
            [
                ['vendor_sku' => 'GLP61950203', 'item_type' => 'firearm'],
            ],
            'CA'
        );

        $this->assertTrue($result['ok']);
    }
}
