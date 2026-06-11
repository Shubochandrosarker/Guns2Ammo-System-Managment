<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Wholesalers\SportsSouth\SportsSouthCsvImporter;
use PHPUnit\Framework\TestCase;

final class SportsSouthCsvImporterTest extends TestCase
{
    private function idx(array $header): array
    {
        $out = [];
        foreach ($header as $i => $col) {
            $out[strtolower(trim((string) $col))] = $i;
        }
        return $out;
    }

    public function test_native_column_names_map(): void
    {
        $header = ['ITEMNO', 'ITUPC', 'IMFGNO', 'MFGINO', 'IMODEL', 'IDESC', 'ICATEGORY', 'ITYPE', 'ICOST', 'MFPRC', 'MAPP', 'IQTY', 'CHANSHIP', 'FFLREQ', 'PIC'];
        $row    = ['SS-RUG10', '736676011223', '01102', 'Sturm Ruger', '10/22', 'Ruger 10/22 Carbine .22LR', 'Long Guns', 'Rifle', '249.00', '329.00', '309.00', '7', 'Y', 'Y', 'ruger1022.jpg'];

        $m = SportsSouthCsvImporter::mapRow($row, $this->idx($header));
        $this->assertSame('SS-RUG10', $m['vendor_sku']);
        $this->assertSame('736676011223', $m['upc']);
        $this->assertSame('Sturm Ruger', $m['manufacturer']);
        $this->assertSame('10/22', $m['model']);
        $this->assertSame(249.0, $m['wholesale_price']);
        $this->assertSame(329.0, $m['msrp']);
        $this->assertSame(309.0, $m['map_price']);
        $this->assertSame(7, $m['stock_qty']);
        $this->assertSame(1, $m['can_dropship']);
        $this->assertSame(1, $m['ffl_required']);
        $this->assertSame('firearm', $m['item_type']);
    }

    public function test_alternate_column_names_also_work(): void
    {
        $header = ['item_number', 'upc', 'manufacturer', 'description', 'cost', 'msrp', 'qty'];
        $row    = ['SSALT-1', '098765432109', 'Magpul', 'PMAG 30 GEN M3', '12.45', '17.99', '50'];
        $m = SportsSouthCsvImporter::mapRow($row, $this->idx($header));
        $this->assertSame('SSALT-1', $m['vendor_sku']);
        $this->assertSame(12.45, $m['wholesale_price']);
        $this->assertSame(17.99, $m['msrp']);
        $this->assertSame(50, $m['stock_qty']);
        $this->assertSame('accessory', $m['item_type']);
    }

    public function test_missing_sku_returns_null(): void
    {
        $header = ['itemno', 'description'];
        $row = ['', 'Something'];
        $this->assertNull(SportsSouthCsvImporter::mapRow($row, $this->idx($header)));
    }
}
