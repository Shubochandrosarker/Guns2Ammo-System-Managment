<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Compliance\ATF\BoundBookExporter;
use PHPUnit\Framework\TestCase;

final class BoundBookExporterTest extends TestCase
{
    public function test_csv_columns_are_the_atf_set(): void
    {
        $this->assertSame([
            'entry_number',
            'acquisition_date',
            'manufacturer',
            'importer',
            'model',
            'serial_number',
            'caliber',
            'firearm_type',
            'acquired_from_name',
            'acquired_from_address',
            'acquired_from_ffl',
            'disposition_date',
            'disposed_to_name',
            'disposed_to_address',
            'disposed_to_ffl_or_4473',
        ], BoundBookExporter::COLUMNS);
    }
}
