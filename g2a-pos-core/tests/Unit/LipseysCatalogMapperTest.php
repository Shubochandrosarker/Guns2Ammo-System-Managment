<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Wholesalers\Lipseys\LipseysCatalogMapper;
use PHPUnit\Framework\TestCase;

final class LipseysCatalogMapperTest extends TestCase
{
    /**
     * The canonical Lipsey's dealer-catalog header (78 columns), exactly as
     * found in the dealer-portal CSV downloads we audited.
     */
    private const HEADER = [
        'ITEMNO', 'DESCRIPTION1', 'DESCRIPTION2', 'UPC', 'MANUFACTURERMODELNO', 'MSRP',
        'MODEL', 'CALIBERGAUGE', 'MANUFACTURER', 'TYPE', 'ACTION', 'BARRELLENGTH',
        'CAPACITY', 'FINISH', 'OVERALLLENGTH', 'RECEIVER', 'SAFETY', 'SIGHTS',
        'STOCKFRAMEGRIPS', 'MAGAZINE', 'WEIGHT', 'IMAGENAME', 'CHAMBER', 'DRILLEDANDTAPPED',
        'RATEOFTWIST', 'ITEMTYPE', 'ADDITIONALFEATURE1', 'ADDITIONALFEATURE2',
        'ADDITIONALFEATURE3', 'SHIPPINGWEIGHT', 'BOUNDBOOKMANUFACTURER', 'BOUNDBOOKMODEL',
        'BOUNDBOOKTYPE', 'NFATHREADPATTERN', 'NFAATTACHMENTMETHOD', 'NFABAFFLETYPE',
        'SILENCERCANBEDISASSEMBLED', 'SILENCERCONSTRUCTIONMATERIAL', 'NFADBREDUCTION',
        'SILENCEROUTSIDEDIAMETER', 'NFAFORM3CALIBER', 'OPTICMAGNIFICATION', 'MAINTUBESIZE',
        'ADJUSTABLEOBJECTIVE', 'OBJECTIVESIZE', 'OPTICADJUSTMENTS', 'ILLUMINATEDRETICLE',
        'RETICLE', 'EXCLUSIVE', 'QUANTITY', 'ALLOCATED', 'CANDROPSHIP', 'ONSALE', 'PRICE',
        'CURRENTPRICE', 'RETAILMAP', 'FFLREQUIRED', 'SOTREQUIRED', 'EXCLUSIVETYPE',
        'SCOPECOVERINCLUDED', 'SPECIAL', 'SIGHTSTYPE', 'CASE', 'CHOKE', 'DBREDUCTION',
        'FAMILY', 'FINISHTYPE', 'FRAME', 'GRIPTYPE', 'HANDGUNSLIDEMATERIAL',
        'COUNTRYOFORIGIN', 'ITEMLENGTH', 'ITEMWIDTH', 'ITEMHEIGHT', 'PACKAGELENGTH',
        'PACKAGEWIDTH', 'PACKAGEHEIGHT', 'ITEMGROUP',
    ];

    private function idx(): array
    {
        return array_flip(self::HEADER);
    }

    /** Build a row matching self::HEADER, with overrides. */
    private function row(array $overrides): array
    {
        $row = array_fill(0, count(self::HEADER), '');
        foreach ($overrides as $col => $val) {
            $i = array_search($col, self::HEADER, true);
            self::assertNotFalse($i, "Unknown column $col in test fixture");
            $row[$i] = (string) $val;
        }
        return $row;
    }

    public function test_real_firearm_row_maps_correctly(): void
    {
        // Closely modeled on a real Lipsey's row: Glock 19 Gen6.
        $row = $this->row([
            'ITEMNO' => 'GLP61950203',
            'DESCRIPTION1' => 'G19 G6 9MM 15+1 4.0" ORS FS',
            'DESCRIPTION2' => '3-15RD MAGS | FRONT SERRATIONS',
            'UPC' => '764503068256',
            'MANUFACTURERMODELNO' => 'P61950203',
            'MSRP' => '745',
            'MODEL' => 'G19 G6 ORS',
            'CALIBERGAUGE' => '9mm',
            'MANUFACTURER' => 'GLOCK',
            'TYPE' => 'Semi-Auto Pistol',
            'ACTION' => 'Safe Action',
            'BARRELLENGTH' => '4.02"',
            'CAPACITY' => '15 + 1',
            'ITEMTYPE' => 'Firearm',
            'QUANTITY' => '0',
            'CANDROPSHIP' => 'FALSE',
            'ONSALE' => 'FALSE',
            'FFLREQUIRED' => 'TRUE',
            'SOTREQUIRED' => 'FALSE',
            'PRICE' => '546',
            'CURRENTPRICE' => '546',
            'RETAILMAP' => '620',
            'IMAGENAME' => 'g19gen67c7e.jpg',
            'FAMILY' => 'G19 Series',
            'COUNTRYOFORIGIN' => '',
            'ITEMGROUP' => 'Polymer Centerfire Pistols',
            'SHIPPINGWEIGHT' => '3.3',
        ]);

        $mapped = LipseysCatalogMapper::mapRow($row, $this->idx());

        $this->assertSame('GLP61950203', $mapped['vendor_sku']);
        $this->assertSame('764503068256', $mapped['upc']);
        $this->assertSame('P61950203', $mapped['mfg_part']);
        $this->assertSame('GLOCK', $mapped['manufacturer']);
        $this->assertSame('Semi-Auto Pistol', $mapped['vendor_category']);
        $this->assertSame('firearm', $mapped['item_type']);
        $this->assertSame('9mm', $mapped['caliber']);
        $this->assertSame('G19 G6 9MM 15+1 4.0" ORS FS', $mapped['name']);

        $this->assertSame(745.0, $mapped['msrp']);
        $this->assertSame(546.0, $mapped['wholesale_price']);
        $this->assertSame(546.0, $mapped['current_price']);
        $this->assertSame(620.0, $mapped['map_price']);
        $this->assertSame(3.3, $mapped['shipping_weight']);
        $this->assertSame(0, $mapped['stock_qty']);

        $this->assertSame(1, $mapped['ffl_required']);
        $this->assertSame(0, $mapped['sot_required']);
        $this->assertSame(0, $mapped['can_dropship']);
        $this->assertSame(0, $mapped['on_sale']);

        $this->assertSame('g19gen67c7e.jpg', $mapped['image_filename']);
        $this->assertSame('G19 Series', $mapped['family']);
        $this->assertSame('Polymer Centerfire Pistols', $mapped['item_group']);

        $attrs = json_decode((string) $mapped['attributes'], true);
        $this->assertSame('Safe Action', $attrs['action']);
        $this->assertSame('4.02"', $attrs['barrellength']);
        $this->assertSame('15 + 1', $attrs['capacity']);
        $this->assertArrayNotHasKey('chamber', $attrs, 'empty cells should not appear in attributes');
    }

    public function test_accessory_row_normalizes_item_type(): void
    {
        $row = $this->row([
            'ITEMNO' => 'SP0010640010',
            'DESCRIPTION1' => 'MXM SCOPE MNT 30MM 20MOA BLK',
            'UPC' => '811452025400',
            'MANUFACTURER' => 'Seekins Precision',
            'TYPE' => 'Accessory-Rings & Ring Mounts',
            'ITEMTYPE' => 'Accessory',
            'QUANTITY' => '0',
            'CANDROPSHIP' => 'TRUE',
            'FFLREQUIRED' => 'FALSE',
            'PRICE' => '193',
            'CURRENTPRICE' => '193',
            'RETAILMAP' => '266',
            'MSRP' => '279',
            'IMAGENAME' => 'spcantilever4fda.jpg',
        ]);

        $mapped = LipseysCatalogMapper::mapRow($row, $this->idx());
        $this->assertSame('accessory', $mapped['item_type']);
        $this->assertSame(1, $mapped['can_dropship']);
        $this->assertSame(0, $mapped['ffl_required']);
        $this->assertSame(266.0, $mapped['map_price']);
    }

    public function test_unknown_item_type_passes_through_lowercased(): void
    {
        $row = $this->row(['ITEMNO' => 'X1', 'ITEMTYPE' => 'Mystery', 'DESCRIPTION1' => 'X']);
        $this->assertSame('mystery', LipseysCatalogMapper::mapRow($row, $this->idx())['item_type']);
    }

    public function test_empty_item_type_becomes_null(): void
    {
        $row = $this->row(['ITEMNO' => 'X1', 'ITEMTYPE' => '', 'DESCRIPTION1' => 'X']);
        $this->assertNull(LipseysCatalogMapper::mapRow($row, $this->idx())['item_type']);
    }

    public function test_silencer_item_type_collapses_to_nfa(): void
    {
        $row = $this->row(['ITEMNO' => 'X1', 'ITEMTYPE' => 'Silencer', 'DESCRIPTION1' => 'X']);
        $this->assertSame('nfa', LipseysCatalogMapper::mapRow($row, $this->idx())['item_type']);
    }

    public function test_missing_columns_in_idx_are_safely_null(): void
    {
        // Header sniffing was case-insensitive — but row data must still survive
        // when the source CSV omits optional columns entirely.
        $sparseIdx = ['ITEMNO' => 0, 'DESCRIPTION1' => 1];
        $sparseRow = ['ABC123', 'Sample'];
        $mapped = LipseysCatalogMapper::mapRow($sparseRow, $sparseIdx);
        $this->assertSame('ABC123', $mapped['vendor_sku']);
        $this->assertSame('Sample', $mapped['name']);
        $this->assertNull($mapped['upc']);
        $this->assertNull($mapped['map_price']);
        $this->assertSame(0, $mapped['stock_qty']);
        $this->assertSame(0, $mapped['ffl_required']);
        $this->assertNull($mapped['attributes']);
    }
}
