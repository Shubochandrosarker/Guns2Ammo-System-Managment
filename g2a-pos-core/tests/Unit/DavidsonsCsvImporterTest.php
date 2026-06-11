<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Wholesalers\Davidsons\DavidsonsCsvImporter;
use PHPUnit\Framework\TestCase;

final class DavidsonsCsvImporterTest extends TestCase
{
    private function idx(array $header): array
    {
        $out = [];
        foreach ($header as $i => $col) {
            $out[strtolower(trim((string) $col))] = $i;
        }
        return $out;
    }

    public function test_row_with_minimum_required_fields_maps(): void
    {
        $header = ['item_number', 'upc', 'manufacturer', 'short_description', 'wholesale_price', 'retail_price', 'quantity', 'drop_ship', 'ffl_required'];
        $row = ['DAV1234', '012345678901', 'Smith & Wesson', 'M&P Shield 9mm', '349.50', '479.00', '5', 'Y', 'Y'];

        $m = DavidsonsCsvImporter::mapRow($row, $this->idx($header));
        $this->assertSame('DAV1234', $m['vendor_sku']);
        $this->assertSame('012345678901', $m['upc']);
        $this->assertSame('Smith & Wesson', $m['manufacturer']);
        $this->assertSame(349.5, $m['wholesale_price']);
        $this->assertSame(479.0, $m['msrp']);
        $this->assertSame(5, $m['stock_qty']);
        $this->assertSame(1, $m['can_dropship']);
        $this->assertSame(1, $m['ffl_required']);
    }

    public function test_missing_sku_returns_null(): void
    {
        $header = ['item_number', 'upc'];
        $row = ['', '012345678901'];
        $this->assertNull(DavidsonsCsvImporter::mapRow($row, $this->idx($header)));
    }

    public function test_map_price_captured_under_alternate_column(): void
    {
        $header = ['item_number', 'min_advertised_price'];
        $row = ['DAV2', '459.00'];
        $m = DavidsonsCsvImporter::mapRow($row, $this->idx($header));
        $this->assertSame(459.0, $m['map_price']);
    }

    public function test_pistol_description_classifies_as_firearm(): void
    {
        $header = ['item_number', 'category', 'short_description'];
        $row = ['DAV3', 'Pistols', 'Glock 19 Gen5 9mm'];
        $m = DavidsonsCsvImporter::mapRow($row, $this->idx($header));
        $this->assertSame('firearm', $m['item_type']);
    }

    public function test_ammo_classification(): void
    {
        $header = ['item_number', 'category', 'short_description'];
        $row = ['DAV4', 'Ammunition', 'Federal 9mm 115gr FMJ'];
        $m = DavidsonsCsvImporter::mapRow($row, $this->idx($header));
        $this->assertSame('ammunition', $m['item_type']);
    }
}
