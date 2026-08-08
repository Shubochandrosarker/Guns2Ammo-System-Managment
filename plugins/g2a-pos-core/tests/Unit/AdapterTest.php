<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Inventory\Distributors\AdapterRegistry;
use G2A\POS\Inventory\Distributors\Davidsons;
use G2A\POS\Inventory\Distributors\Lipseys;
use G2A\POS\Inventory\Distributors\Rsr;
use G2A\POS\Inventory\Distributors\SportsSouth;
use PHPUnit\Framework\TestCase;

final class AdapterTest extends TestCase
{
    public function test_lipseys_maps_a_handgun(): void
    {
        $row = (new Lipseys())->map_row([
            'itemNo' => 'GLK19',
            'description1' => 'GLOCK 19 GEN5',
            'description2' => '9mm 4" FS 15rd',
            'upc' => '764503026164',
            'manufacturerModelNo' => 'PA195S203',
            'manufacturer' => 'Glock',
            'model' => '19 Gen5',
            'type' => 'Pistol',
            'action' => 'Semi-Auto',
            'caliberGauge' => '9mm',
            'capacity' => '15',
            'barrelLength' => '4.02"',
            'msrp' => '$599.00',
            'dealerPrice' => '499.99',
            'quantity' => '12',
        ]);
        $this->assertSame('lipseys', $row['source']);
        $this->assertSame('GLK19', $row['source_ref']);
        $this->assertSame('764503026164', $row['upc']);
        $this->assertSame('Glock', $row['manufacturer']);
        $this->assertSame('firearm', $row['item_type']);
        $this->assertSame('handgun', $row['firearm_type']);
        $this->assertSame(15, $row['magazine_capacity']);
        $this->assertSame(4.02, $row['barrel_length_in']);
        $this->assertTrue($row['is_semi_auto']);
        $this->assertSame(599.00, $row['msrp']);
        $this->assertSame(499.99, $row['dealer_cost']);
        $this->assertSame(12, $row['quantity']);
    }

    public function test_rsr_maps_an_ammo_row(): void
    {
        $row = (new Rsr())->map_row([
            'rsr_stock_no' => 'FED556',
            'upc' => '029465064778',
            'description' => 'Federal 5.56 NATO 55gr FMJ 20rd Ammunition',
            'mfg_part_no' => 'XM193',
            'mfg' => 'Federal',
            'caliber' => '5.56',
            'retail_price' => '14.99',
            'rsr_price' => '9.50',
            'qty_available' => '500',
            'dept_no' => 'Ammunition',
        ]);
        $this->assertSame('rsr', $row['source']);
        $this->assertSame('ammunition', $row['item_type']);
        $this->assertSame('029465064778', $row['upc']);
        $this->assertSame(9.50, $row['dealer_cost']);
        $this->assertSame(500, $row['quantity']);
    }

    public function test_davidsons_maps_accessory(): void
    {
        $row = (new Davidsons())->map_row([
            'item_number' => 'PMAG-30',
            'upc' => '873750000111',
            'manufacturer_part_number' => 'MAG571',
            'manufacturer' => 'Magpul',
            'description' => 'PMAG 30-round AR/M4 GEN M3',
            'retail_price' => '15.95',
            'wholesale_price' => '10.50',
            'quantity' => '300',
        ]);
        $this->assertSame('davidsons', $row['source']);
        $this->assertSame('accessory', $row['item_type']);
        $this->assertSame('PMAG-30', $row['source_ref']);
    }

    public function test_sports_south_maps_shotgun(): void
    {
        $row = (new SportsSouth())->map_row([
            'ITEMNO' => 'MOSS500-12',
            'IDESC' => 'Mossberg 500 Pump Shotgun 12ga',
            'IUPC' => '015813506748',
            'IMFG' => 'Mossberg',
            'IMFGCD' => '50120',
            'ITYPE' => 'Shotgun',
            'ICALIBER' => '12 GA',
            'IPRICE1' => '459.00',
            'IPRICE2' => '329.99',
            'QTYOH' => '7',
        ]);
        $this->assertSame('shotgun', $row['firearm_type']);
        $this->assertSame('firearm', $row['item_type']);
        $this->assertSame(329.99, $row['dealer_cost']);
    }

    public function test_registry_detects_adapter_by_headers(): void
    {
        $lip = AdapterRegistry::detect(['itemNo', 'description1', 'manufacturerModelNo', 'upc', 'msrp', 'dealerPrice']);
        $this->assertNotNull($lip);
        $this->assertSame('lipseys', $lip->slug());

        $ss = AdapterRegistry::detect(['ITEMNO', 'IDESC', 'IMFG', 'IPRICE1', 'IPRICE2', 'QTYOH']);
        $this->assertNotNull($ss);
        $this->assertSame('sports_south', $ss->slug());

        $unknown = AdapterRegistry::detect(['foo', 'bar', 'baz']);
        $this->assertNull($unknown);
    }

    public function test_skips_row_without_id(): void
    {
        $this->assertNull((new Lipseys())->map_row(['description1' => 'No item number']));
        $this->assertNull((new Rsr())->map_row(['description' => 'No stock no']));
    }
}
