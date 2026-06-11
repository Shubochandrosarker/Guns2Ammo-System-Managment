<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Hardware\CustomerDisplay;
use PHPUnit\Framework\TestCase;

final class CustomerDisplayTest extends TestCase
{
    public function test_empty_cart_produces_idle_envelope(): void
    {
        $env = CustomerDisplay::build([]);
        $this->assertSame('idle', $env['mode']);
        $this->assertSame([], $env['lines']);
        $this->assertSame(0.0, $env['subtotal']);
    }

    public function test_envelope_sums_line_totals(): void
    {
        $env = CustomerDisplay::build([
            'items' => [
                ['name' => 'Glock 19', 'quantity' => 1, 'line_total' => 599.00],
                ['name' => '9mm 50ct', 'quantity' => 2, 'line_total' => 24.50],
            ],
            'tax_total' => 51.91,
            'grand_total' => 699.91,
        ]);
        $this->assertSame('cart', $env['mode']);
        $this->assertCount(2, $env['lines']);
        $this->assertEqualsWithDelta(623.50, $env['subtotal'], 0.001);
        $this->assertSame(51.91, $env['tax']);
        $this->assertSame(699.91, $env['total']);
    }

    public function test_long_names_are_truncated_to_20_chars(): void
    {
        $env = CustomerDisplay::build([
            'items' => [['name' => 'Smith & Wesson M&P 9 Shield Plus Performance', 'quantity' => 1, 'line_total' => 500]],
        ]);
        $this->assertLessThanOrEqual(20, strlen($env['lines'][0]['name']));
    }
}
