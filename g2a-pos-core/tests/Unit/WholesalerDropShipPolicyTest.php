<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Wholesalers\WholesalerDropShipPolicy;
use PHPUnit\Framework\TestCase;

final class WholesalerDropShipPolicyTest extends TestCase
{
    public function test_firearm_without_destination_ffl_is_rejected_by_default(): void
    {
        $result = WholesalerDropShipPolicy::validate(
            [['vendor_sku' => 'RU1022', 'item_type' => 'firearm']],
            ['state' => 'TX']
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('firearm_dropship_requires_ffl', $result['error']);
    }

    public function test_firearm_with_destination_ffl_is_allowed(): void
    {
        $result = WholesalerDropShipPolicy::validate(
            [['vendor_sku' => 'RU1022', 'ffl_required' => 1]],
            ['state' => 'TX', 'ffl' => '1-23-456-78-9A-12345']
        );

        $this->assertTrue($result['ok']);
    }

    public function test_ffl_guard_can_be_disabled(): void
    {
        $result = WholesalerDropShipPolicy::validate(
            [['vendor_sku' => 'RU1022', 'item_type' => 'firearm']],
            ['state' => 'TX'],
            ['require_destination_ffl' => false]
        );

        $this->assertTrue($result['ok']);
    }

    public function test_accessory_only_order_needs_no_ffl(): void
    {
        $result = WholesalerDropShipPolicy::validate(
            [['vendor_sku' => 'ACC1', 'item_type' => 'accessory']],
            ['state' => 'NV']
        );

        $this->assertTrue($result['ok']);
    }

    public function test_mixed_order_is_allowed_when_separation_is_off(): void
    {
        $result = WholesalerDropShipPolicy::validate(
            [
                ['vendor_sku' => 'RU1022', 'ffl_required' => 1],
                ['vendor_sku' => 'ACC1', 'item_type' => 'accessory'],
            ],
            ['state' => 'TX', 'ffl' => '1-23-456-78-9A-12345']
        );

        $this->assertTrue($result['ok']);
    }

    public function test_mixed_order_is_rejected_when_separation_is_on(): void
    {
        $result = WholesalerDropShipPolicy::validate(
            [
                ['vendor_sku' => 'RU1022', 'ffl_required' => 1],
                ['vendor_sku' => 'ACC1', 'item_type' => 'accessory'],
            ],
            ['state' => 'TX', 'ffl' => '1-23-456-78-9A-12345'],
            ['firearm_accessory_separation' => true]
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('mixed_firearm_accessory', $result['error']);
    }

    public function test_accessory_to_restricted_state_is_blocked(): void
    {
        $result = WholesalerDropShipPolicy::validate(
            [['vendor_sku' => 'ACC1', 'item_type' => 'accessory']],
            ['state' => 'ca'],
            ['restricted_accessory_states' => ['CA']]
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('accessory_dropship_state_blocked', $result['error']);
    }

    public function test_firearm_to_restricted_state_is_not_blocked_by_accessory_rule(): void
    {
        $result = WholesalerDropShipPolicy::validate(
            [['vendor_sku' => 'RU1022', 'item_type' => 'firearm']],
            ['state' => 'CA', 'ffl' => '1-23-456-78-9A-12345'],
            ['restricted_accessory_states' => ['CA']]
        );

        $this->assertTrue($result['ok']);
    }

    public function test_overrides_remap_code_and_message(): void
    {
        $result = WholesalerDropShipPolicy::validate(
            [['vendor_sku' => 'ACC1', 'item_type' => 'accessory']],
            ['state' => 'CA'],
            [
                'restricted_accessory_states' => ['CA'],
                'overrides'                   => [
                    'accessory_dropship_state_blocked' => ['ca_accessory_dropship_blocked', 'Custom message.'],
                ],
            ]
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('ca_accessory_dropship_blocked', $result['error']);
        $this->assertSame('Custom message.', $result['message']);
    }

    public function test_classification_helpers(): void
    {
        $this->assertTrue(WholesalerDropShipPolicy::isFirearm(['item_type' => 'firearm']));
        $this->assertTrue(WholesalerDropShipPolicy::isFirearm(['item_type' => 'nfa']));
        $this->assertTrue(WholesalerDropShipPolicy::isFirearm(['ffl_required' => 1]));
        $this->assertFalse(WholesalerDropShipPolicy::isFirearm(['item_type' => 'accessory']));

        $this->assertTrue(WholesalerDropShipPolicy::isAccessory(['item_type' => 'accessory']));
        $this->assertTrue(WholesalerDropShipPolicy::isAccessory(['item_type' => 'optic']));
        $this->assertFalse(WholesalerDropShipPolicy::isAccessory(['item_type' => 'ammunition']));
    }
}
