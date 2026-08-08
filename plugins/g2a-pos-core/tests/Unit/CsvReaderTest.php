<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Inventory\CsvReader;
use PHPUnit\Framework\TestCase;

final class CsvReaderTest extends TestCase
{
    private function with_csv(string $body, callable $fn): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'g2acsv_');
        file_put_contents($tmp, $body);
        try { $fn($tmp); } finally { @unlink($tmp); }
    }

    public function test_reads_comma_delimited(): void
    {
        $csv = "upc,name,price\n012345678905,Glock 19,599.00\n012345678906,Magpul PMAG,12.99\n";
        $this->with_csv($csv, function (string $path) {
            $rows = iterator_to_array(CsvReader::rows($path));
            $this->assertCount(2, $rows);
            $this->assertSame('Glock 19', $rows[0]['name']);
            $this->assertSame('599.00', $rows[0]['price']);
        });
    }

    public function test_auto_detects_tab(): void
    {
        $csv = "upc\tname\tprice\n012345678905\tGlock 19\t599.00\n";
        $this->with_csv($csv, function (string $path) {
            $rows = iterator_to_array(CsvReader::rows($path));
            $this->assertCount(1, $rows);
            $this->assertSame('Glock 19', $rows[0]['name']);
        });
    }

    public function test_strips_bom(): void
    {
        $csv = "\xEF\xBB\xBFupc,name\n012345,Test\n";
        $this->with_csv($csv, function (string $path) {
            $headers = CsvReader::peek_headers($path);
            $this->assertSame(['upc', 'name'], $headers);
        });
    }
}
