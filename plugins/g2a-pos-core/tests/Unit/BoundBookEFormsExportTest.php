<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Compliance\ATF\BoundBookExporter;
use PHPUnit\Framework\TestCase;

/**
 * The eForms helpers (format_date + strip_ffl) are private static; assert
 * their behavior reflectively because they're the contract that keeps our
 * export accepted by ATF eForms upload.
 */
final class BoundBookEFormsExportTest extends TestCase
{
    public function test_format_date_normalizes_iso_to_us(): void
    {
        $m = new \ReflectionMethod(BoundBookExporter::class, 'format_date');
        $m->setAccessible(true);
        $this->assertSame('06/04/2026', $m->invoke(null, '2026-06-04'));
        $this->assertSame('12/31/2025', $m->invoke(null, '2025-12-31 09:15:00'));
        $this->assertSame('', $m->invoke(null, null));
        $this->assertSame('', $m->invoke(null, ''));
    }

    public function test_strip_ffl_keeps_alphanumerics_only(): void
    {
        $m = new \ReflectionMethod(BoundBookExporter::class, 'strip_ffl');
        $m->setAccessible(true);
        $this->assertSame('123456789AB12345', $m->invoke(null, '1-23-456-78-9AB-12345'));
        $this->assertSame('', $m->invoke(null, null));
        $this->assertSame('FFL12345', $m->invoke(null, 'FFL 12345'));
    }
}
