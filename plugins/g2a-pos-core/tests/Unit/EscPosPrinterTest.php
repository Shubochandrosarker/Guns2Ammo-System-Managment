<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Hardware\EscPosPrinter;
use PHPUnit\Framework\TestCase;

final class EscPosPrinterTest extends TestCase
{
    public function test_init_emits_esc_at(): void
    {
        $bytes = (new EscPosPrinter())->init()->bytes();
        $this->assertSame("\x1B@", $bytes);
    }

    public function test_drawer_kick_emits_correct_sequence(): void
    {
        $bytes = (new EscPosPrinter())->kick_drawer()->bytes();
        // ESC p 0 25 250 — Epson cash-drawer kick on connector pin 2
        $this->assertSame("\x1Bp\x00\x19\xFA", $bytes);
    }

    public function test_cut_emits_partial_cut(): void
    {
        $bytes = (new EscPosPrinter())->cut()->bytes();
        $this->assertSame("\x1DV\x42\x00", $bytes);
    }

    public function test_receipt_render_contains_order_id_and_total(): void
    {
        $bytes = EscPosPrinter::render_receipt([
            'id' => 42,
            'items' => [['name' => 'Glock 19', 'quantity' => 1, 'line_total' => 599.00]],
            'subtotal' => 599.00,
            'tax_total' => 49.42,
            'grand_total' => 648.42,
        ], ['name' => 'G2A Firearms']);
        $this->assertStringContainsString('Order #42', $bytes);
        $this->assertStringContainsString('Glock 19', $bytes);
        $this->assertStringContainsString('648.42', $bytes);
        // Code 128 barcode marker (GS k I)
        $this->assertStringContainsString("\x1Dk\x49", $bytes);
        // Partial cut at end
        $this->assertStringContainsString("\x1DV\x42\x00", $bytes);
    }
}
