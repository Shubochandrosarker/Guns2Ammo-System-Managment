<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Wholesalers\Lipseys\LipseysPayloadBuilder;
use PHPUnit\Framework\TestCase;

final class LipseysPayloadBuilderTest extends TestCase
{
    public function test_warehouse_payload_has_minimal_schema(): void
    {
        $payload = LipseysPayloadBuilder::warehouseOrder([
            'po_number' => 'PO-42',
            'email_confirmation' => false,
            'items' => [
                ['vendor_sku' => 'RU1022RB', 'quantity' => 2, 'note' => 'Web order'],
                ['vendor_sku' => 'GLP61950203'],
            ],
        ]);

        $this->assertSame(['PONumber', 'EmailConfirmation', 'Items'], array_keys($payload));
        $this->assertSame('PO-42', $payload['PONumber']);
        $this->assertFalse($payload['EmailConfirmation']);
        $this->assertCount(2, $payload['Items']);
        $this->assertSame(['ItemNo' => 'RU1022RB', 'Quantity' => 2, 'Note' => 'Web order'], $payload['Items'][0]);
        $this->assertSame(['ItemNo' => 'GLP61950203', 'Quantity' => 1, 'Note' => ''], $payload['Items'][1]);
    }

    public function test_warehouse_payload_synthesizes_po_number_from_pos_order_id(): void
    {
        $payload = LipseysPayloadBuilder::warehouseOrder([
            'pos_order_id' => 7,
            'items' => [['vendor_sku' => 'X']],
        ]);
        $this->assertSame('G2A-7', $payload['PONumber']);
        $this->assertTrue($payload['EmailConfirmation'], 'email confirmation defaults to true');
    }

    public function test_dropship_payload_uses_lipseys_billing_and_shipping_schema(): void
    {
        $payload = LipseysPayloadBuilder::dropShip([
            'po_number' => 'DS-1',
            'warehouse' => 'AR',
            'billing' => [
                'name' => 'Guns2Ammo LLC',
                'address' => '100 Main St',
                'address_line_2' => 'Suite 5',
                'city' => 'Miami',
                'state' => 'FL',
                'zip' => '33101',
            ],
            'ship_to' => [
                'name' => 'John Buyer',
                'address' => '500 Oak Ave',
                'city' => 'Lansing',
                'state' => 'MI',
                'zip' => '48864',
                'ffl' => '4-12-345-67-8X-12345',
            ],
            'items' => [['vendor_sku' => 'SUM640DFTN-PRO', 'quantity' => 1, 'note' => 'TEST']],
            'overnight' => true,
        ]);

        // Exact key set Lipsey's DropShip endpoint expects.
        $expectedKeys = [
            'Warehouse', 'PoNumber',
            'BillingName', 'BillingAddressLine1', 'BillingAddressLine2',
            'BillingAddressCity', 'BillingAddressState', 'BillingAddressZip',
            'ShippingName', 'ShippingAddressLine1', 'ShippingAddressLine2',
            'ShippingAddressCity', 'ShippingAddressState', 'ShippingAddressZip',
            'MessageForSalesExec', 'Items', 'Overnight',
        ];
        $this->assertSame($expectedKeys, array_keys($payload));

        $this->assertSame('AR', $payload['Warehouse']);
        $this->assertSame('DS-1', $payload['PoNumber']);
        $this->assertSame('Guns2Ammo LLC', $payload['BillingName']);
        $this->assertSame('100 Main St', $payload['BillingAddressLine1']);
        $this->assertSame('Suite 5', $payload['BillingAddressLine2']);
        $this->assertSame('Miami', $payload['BillingAddressCity']);
        $this->assertSame('FL', $payload['BillingAddressState']);
        $this->assertSame('33101', $payload['BillingAddressZip']);
        $this->assertSame('John Buyer', $payload['ShippingName']);
        $this->assertSame('500 Oak Ave', $payload['ShippingAddressLine1']);
        $this->assertSame('Lansing', $payload['ShippingAddressCity']);
        $this->assertSame('MI', $payload['ShippingAddressState']);
        $this->assertSame('48864', $payload['ShippingAddressZip']);
        $this->assertStringContainsString('4-12-345-67-8X-12345', $payload['MessageForSalesExec']);
        $this->assertTrue($payload['Overnight']);

        $this->assertSame([['ItemNo' => 'SUM640DFTN-PRO', 'Quantity' => 1, 'Note' => 'TEST']], $payload['Items']);
    }

    public function test_dropship_message_blank_when_no_ffl_and_no_override(): void
    {
        $payload = LipseysPayloadBuilder::dropShip([
            'items' => [['vendor_sku' => 'X']],
            'ship_to' => ['name' => 'Buyer'],
        ]);
        $this->assertSame('', $payload['MessageForSalesExec']);
        $this->assertFalse($payload['Overnight']);
    }

    public function test_dropship_message_override_wins_over_ffl(): void
    {
        $payload = LipseysPayloadBuilder::dropShip([
            'items' => [['vendor_sku' => 'X']],
            'ship_to' => ['ffl' => '1-2-3'],
            'message_for_sales_exec' => 'Rush — customer waiting',
        ]);
        $this->assertSame('Rush — customer waiting', $payload['MessageForSalesExec']);
    }
}
