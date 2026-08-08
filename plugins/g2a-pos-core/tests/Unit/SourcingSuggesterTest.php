<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Pricing\SourcingSuggester;
use PHPUnit\Framework\TestCase;

final class SourcingSuggesterTest extends TestCase
{
    public function test_clamp_price_lifts_below_map_to_map_floor(): void
    {
        $this->assertSame(599.99, SourcingSuggester::clamp_price(500.0, 599.99, null, 50.0));
    }

    public function test_clamp_price_passes_through_when_no_map(): void
    {
        $this->assertSame(500.0, SourcingSuggester::clamp_price(500.0, null, null, 50.0));
    }

    public function test_clamp_price_caps_at_msrp_overage_ceiling(): void
    {
        // MSRP 100 with 50% overage = ceiling 150. Proposed 200 should clip.
        $this->assertSame(150.0, SourcingSuggester::clamp_price(200.0, null, 100.0, 50.0));
    }

    public function test_clamp_price_lets_value_through_when_under_ceiling(): void
    {
        $this->assertSame(125.0, SourcingSuggester::clamp_price(125.0, null, 100.0, 50.0));
    }

    public function test_shelf_recommendation_when_on_hand_and_clamped_to_map(): void
    {
        $out = SourcingSuggester::suggest(
            ['on_hand' => 3, 'price' => 450.0],
            [],
            ['map_price' => 499.99]
        );
        $this->assertSame('shelf', $out['recommendation']['source']);
        $this->assertSame(499.99, $out['shelf']['suggested_sell_price']);
        $this->assertSame(499.99, $out['recommendation']['sell_price']);
    }

    public function test_vendor_stock_winner_picks_cheapest_in_stock(): void
    {
        $vendors = [
            ['vendor_name' => 'Sport South', 'vendor_sku' => 'SS-1', 'current_price' => 450.0, 'stock_qty' => 5, 'msrp' => 599.99],
            ['vendor_name' => 'Lipseys',     'vendor_sku' => 'LP-1', 'current_price' => 420.0, 'stock_qty' => 2, 'msrp' => 599.99],
            ['vendor_name' => 'RSR',         'vendor_sku' => 'RSR-1','current_price' => 410.0, 'stock_qty' => 0, 'can_dropship' => 1, 'msrp' => 599.99],
        ];
        $out = SourcingSuggester::suggest(['on_hand' => 0, 'price' => null], $vendors, null,
            ['markup_pct' => 25, 'max_overage_pct' => 50, 'dropship_fee' => 25]);

        // Should pick Lipseys (cheapest in-stock), NOT RSR (drop-ship, even though cheaper raw cost).
        $this->assertSame('vendor_stock', $out['recommendation']['source']);
        $this->assertSame('Lipseys', $out['recommendation']['vendor_name']);

        // First row in the matrix should also be Lipseys (lowest effective_cost among in-stock).
        $this->assertSame('Lipseys', $out['vendors'][0]['vendor_name']);
        // Drop-ship-only row should be after the in-stock rows.
        $rsr_idx = null;
        foreach ($out['vendors'] as $i => $r) { if ($r['vendor_name'] === 'RSR') { $rsr_idx = $i; break; } }
        $this->assertNotNull($rsr_idx);
        $this->assertGreaterThanOrEqual(2, $rsr_idx);
    }

    public function test_dropship_only_when_no_one_in_stock(): void
    {
        $vendors = [
            ['vendor_name' => 'Lipseys', 'vendor_sku' => 'LP-1', 'current_price' => 410.0, 'stock_qty' => 0, 'can_dropship' => 1],
        ];
        $out = SourcingSuggester::suggest(['on_hand' => 0, 'price' => null], $vendors);
        $this->assertSame('dropship', $out['recommendation']['source']);
        $this->assertSame('Lipseys', $out['recommendation']['vendor_name']);
    }

    public function test_no_recommendation_when_everything_unavailable(): void
    {
        $vendors = [
            ['vendor_name' => 'RSR', 'vendor_sku' => 'RSR-1', 'current_price' => 400.0, 'stock_qty' => 0, 'can_dropship' => 0],
        ];
        $out = SourcingSuggester::suggest(['on_hand' => 0, 'price' => null], $vendors);
        $this->assertSame('none', $out['recommendation']['source']);
    }

    public function test_zero_cost_vendor_row_is_dropped(): void
    {
        $vendors = [
            ['vendor_name' => 'BadFeed', 'vendor_sku' => 'X', 'current_price' => 0.0, 'stock_qty' => 5],
            ['vendor_name' => 'GoodFeed', 'vendor_sku' => 'Y', 'current_price' => 400.0, 'stock_qty' => 5],
        ];
        $out = SourcingSuggester::suggest(['on_hand' => 0, 'price' => null], $vendors);
        $this->assertCount(1, $out['vendors']);
        $this->assertSame('GoodFeed', $out['vendors'][0]['vendor_name']);
    }

    public function test_margin_math_is_consistent(): void
    {
        $vendors = [
            ['vendor_name' => 'A', 'vendor_sku' => 'A1', 'current_price' => 100.0, 'stock_qty' => 1, 'msrp' => 200.0],
        ];
        $out = SourcingSuggester::suggest(['on_hand' => 0, 'price' => null], $vendors,
            null, ['markup_pct' => 30, 'max_overage_pct' => 50]);
        $row = $out['vendors'][0];
        $this->assertSame(100.0, $row['wholesale_price']);
        $this->assertSame(130.0, $row['suggested_sell_price']);
        $this->assertSame(30.0, $row['margin_dollars']);
        $this->assertSame(30.0, $row['margin_pct']);
    }

    public function test_map_floor_lifts_low_markup_to_map(): void
    {
        // 25% markup on $400 = $500. MAP is $599.99 → should lift.
        $vendors = [
            ['vendor_name' => 'A', 'vendor_sku' => 'A1', 'current_price' => 400.0, 'stock_qty' => 5, 'msrp' => 599.99],
        ];
        $out = SourcingSuggester::suggest(['on_hand' => 0, 'price' => null], $vendors,
            ['map_price' => 599.99], ['markup_pct' => 25, 'max_overage_pct' => 50]);
        $this->assertSame(599.99, $out['vendors'][0]['suggested_sell_price']);
        $this->assertSame(599.99, $out['vendors'][0]['map_price']);
    }
}
