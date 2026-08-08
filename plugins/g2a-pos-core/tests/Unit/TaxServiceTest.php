<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Domain\TaxService;
use PHPUnit\Framework\TestCase;

/**
 * Behavioral tests for the server-side tax calculator: option-driven rates,
 * per-category overrides, taxable flags, discount proration, and rounding.
 */
final class TaxServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__wp_options'] = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['__wp_options'] = [];
        parent::tearDown();
    }

    public function test_default_config_taxes_at_mesa_az_rate(): void
    {
        $tax = TaxService::tax_for_lines([['line_total' => 100.00]]);
        $this->assertSame(8.30, $tax);
    }

    public function test_rate_is_read_from_option_not_hardcoded(): void
    {
        update_option(TaxService::OPTION, ['default_rate' => 5.0]);
        $this->assertSame(5.00, TaxService::tax_for_lines([['line_total' => 100.00]]));

        update_option(TaxService::OPTION, ['default_rate' => 0.0]);
        $this->assertSame(0.00, TaxService::tax_for_lines([['line_total' => 100.00]]));
    }

    public function test_disabled_flag_zeroes_all_tax(): void
    {
        update_option(TaxService::OPTION, ['enabled' => false, 'default_rate' => 8.3]);
        $this->assertSame(0.0, TaxService::tax_for_lines([['line_total' => 999.99]]));
        $this->assertSame(0.0, TaxService::rate_for_line(['line_total' => 10.0]));
    }

    public function test_category_override_applies_by_tax_class_or_category_slug(): void
    {
        update_option(TaxService::OPTION, [
            'default_rate' => 8.3,
            'category_rates' => ['labor' => 0.0, 'ammo' => 10.0],
        ]);

        // tax_class key.
        $this->assertSame(0.0, TaxService::rate_for_line(['tax_class' => 'labor']));
        // category key.
        $this->assertSame(10.0, TaxService::rate_for_line(['category' => 'ammo']));
        // Unknown slug falls back to the default rate.
        $this->assertSame(8.3, TaxService::rate_for_line(['category' => 'optics']));

        $tax = TaxService::tax_for_lines([
            ['line_total' => 50.00, 'tax_class' => 'labor'],   // 0%
            ['line_total' => 20.00, 'category' => 'ammo'],     // 10% => 2.00
            ['line_total' => 100.00],                          // 8.3% => 8.30
        ]);
        $this->assertSame(10.30, $tax);
    }

    public function test_non_taxable_lines_are_skipped(): void
    {
        $tax = TaxService::tax_for_lines([
            ['line_total' => 100.00, 'taxable' => false],
            ['line_total' => 100.00, 'taxable' => true],
        ]);
        $this->assertSame(8.30, $tax);
    }

    public function test_discount_is_prorated_before_tax(): void
    {
        // $200 subtotal, $100 discount => customer pays half; tax halves too.
        $tax = TaxService::tax_for_lines(
            [['line_total' => 150.00], ['line_total' => 50.00]],
            100.00
        );
        $this->assertSame(8.30, $tax);

        // Discount larger than the subtotal is clamped — never negative tax.
        $this->assertSame(0.0, TaxService::tax_for_lines([['line_total' => 10.00]], 999.0));
    }

    public function test_tax_rounds_to_cents(): void
    {
        update_option(TaxService::OPTION, ['default_rate' => 8.3]);
        // 19.99 * 8.3% = 1.65917 -> 1.66
        $this->assertSame(1.66, TaxService::tax_for_lines([['line_total' => 19.99]]));
        // 0.10 * 8.3% = 0.0083 -> 0.01
        $this->assertSame(0.01, TaxService::tax_for_lines([['line_total' => 0.10]]));
    }

    public function test_empty_or_zero_carts_produce_zero_tax(): void
    {
        $this->assertSame(0.0, TaxService::tax_for_lines([]));
        $this->assertSame(0.0, TaxService::tax_for_lines([['line_total' => 0.0]]));
    }

    public function test_save_config_sanitizes_rates_and_slugs(): void
    {
        $config = TaxService::save_config([
            'enabled' => 1,
            'default_rate' => -5,
            'category_rates' => ['Ammo!' => '9.1', '' => 4, 'labor' => -1],
        ]);

        $this->assertTrue($config['enabled']);
        $this->assertSame(0.0, $config['default_rate'], 'negative rates clamp to zero');
        $this->assertSame(['ammo' => 9.1, 'labor' => 0.0], $config['category_rates']);
        // Round-trips through the option.
        $this->assertSame($config, TaxService::config());
    }

    public function test_malformed_option_value_falls_back_to_defaults(): void
    {
        update_option(TaxService::OPTION, 'not-an-array');
        $config = TaxService::config();
        $this->assertTrue($config['enabled']);
        $this->assertSame(8.3, $config['default_rate']);
        $this->assertSame([], $config['category_rates']);
    }
}
