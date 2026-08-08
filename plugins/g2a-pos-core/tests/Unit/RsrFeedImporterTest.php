<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Wholesalers\Rsr\RsrFeedImporter;
use PHPUnit\Framework\TestCase;

final class RsrFeedImporterTest extends TestCase
{
    /** Build a 76-field RSR row, filling the positions we use. */
    private function row(array $overrides): array
    {
        $cols = array_fill(0, 76, '');
        foreach ($overrides as $i => $v) {
            $cols[$i] = (string) $v;
        }
        return $cols;
    }

    public function test_handgun_row_maps_correctly(): void
    {
        $cols = $this->row([
            0 => 'RSR-GLK19',
            1 => '764503914195',
            2 => 'GLOCK 19 G5 9MM 4.02 15RD',
            3 => '1', // Dept 1 = handguns
            4 => 'GLOCK',
            5 => '619.00',
            6 => '498.00',
            7 => '3.5',
            8 => '12',
            9 => 'PA195S203',
            10 => 'UA195S203',
            12 => 'GLOCK 19 Gen 5, 9mm, 4.02" barrel, 15-round capacity',
            13 => 'g19g5.jpg',
            46 => '559.00',
            73 => 'Y',
        ]);
        $m = RsrFeedImporter::mapRow($cols);
        $this->assertSame('764503914195', $m['upc']);
        $this->assertSame('GLOCK', $m['manufacturer']);
        $this->assertSame('PA195S203', $m['model']);
        $this->assertSame('UA195S203', $m['mfg_part']);
        $this->assertSame(619.0, $m['msrp']);
        $this->assertSame(498.0, $m['wholesale_price']);
        $this->assertSame(559.0, $m['map_price']);
        $this->assertSame(12, $m['stock_qty']);
        $this->assertSame(1, $m['can_dropship']);
        $this->assertSame('firearm', $m['item_type']);
        $this->assertSame('1', $m['vendor_category']);
        $this->assertSame('g19g5.jpg', $m['image_filename']);
    }

    public function test_ammo_department_18(): void
    {
        $cols = $this->row([0 => 'RSR-AMMO', 3 => '18', 2 => 'Federal 9mm']);
        $this->assertSame('ammunition', RsrFeedImporter::mapRow($cols)['item_type']);
    }

    public function test_optic_department_30(): void
    {
        $cols = $this->row([0 => 'RSR-OPT', 3 => '30', 2 => 'Vortex Strikefire']);
        $this->assertSame('optic', RsrFeedImporter::mapRow($cols)['item_type']);
    }

    public function test_silencer_description_classifies_as_nfa(): void
    {
        $cols = $this->row([0 => 'RSR-NFA', 3 => '99', 2 => 'SilencerCo Omega 9K Suppressor']);
        $this->assertSame('nfa', RsrFeedImporter::mapRow($cols)['item_type']);
    }

    public function test_dropship_n_evaluates_to_zero(): void
    {
        $cols = $this->row([0 => 'RSR-X', 73 => 'N']);
        $this->assertSame(0, RsrFeedImporter::mapRow($cols)['can_dropship']);
    }
}
