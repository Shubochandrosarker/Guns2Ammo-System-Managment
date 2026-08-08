<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Identity\AamvaParser;
use PHPUnit\Framework\TestCase;

final class AamvaParserTest extends TestCase
{
    public function test_parses_sample_az_license(): void
    {
        $payload = "@\n\x1eANSI 636026080002DL00410288ZA03290015\nDL\nDAQD12345678\nDCSSMITH\nDDEN\nDACJOHN\nDDFN\nDADWILLIAM\nDDGN\nDBB01151990\nDBA01152030\nDBD05012024\nDBCM\nDAU069 in\nDAW180\nDAG123 MAIN ST\nDAIMESA\nDAJAZ\nDAK852101234\nDCGUSA\n";

        $out = AamvaParser::parse($payload);
        $this->assertIsArray($out);
        $this->assertSame('SMITH', $out['family_name']);
        $this->assertSame('JOHN', $out['given_name']);
        $this->assertSame('WILLIAM', $out['middle_name']);
        $this->assertSame('1990-01-15', $out['dob']);
        $this->assertSame('D12345678', $out['license_number']);
        $this->assertSame('MESA', $out['address']['city']);
        $this->assertSame('AZ', $out['address']['state']);
        $this->assertSame('85210', $out['address']['zip']);
        $this->assertSame('M', $out['sex']);
    }

    public function test_rejects_non_aamva(): void
    {
        $this->assertNull(AamvaParser::parse('just some random text'));
    }
}
