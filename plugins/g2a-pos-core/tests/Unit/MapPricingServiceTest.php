<?php

namespace G2A\POS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use G2A\POS\Pricing\MapPricingService;
use PHPUnit\Framework\TestCase;

/**
 * Fake WC_Product stand-in that deliberately has NO get_meta() method.
 *
 * Regression guard: MapPricingService must never call WC_Product->get_meta()
 * for '_upc' / '_global_unique_id' inside the price-html filter — doing so
 * triggers "is_internal_meta_key was called incorrectly" notices beside every
 * shop price. If the service regresses to get_meta(), these tests fatal.
 */
final class FakeWcProductWithoutGetMeta
{
    public function __construct(private int $id, private float $price)
    {
    }

    public function get_id(): int
    {
        return $this->id;
    }

    public function get_price(): float
    {
        return $this->price;
    }

    public function get_sku(): string
    {
        return 'SKU-' . $this->id;
    }
}

final class MapPricingServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('ARRAY_A')) {
            define('ARRAY_A', 'ARRAY_A');
        }
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_no_rule_means_no_suppression(): void
    {
        $r = MapPricingService::evaluateRule(null, 100.00);
        $this->assertSame(['has_rule' => false], $r);
    }

    public function test_zero_or_negative_map_price_is_ignored(): void
    {
        foreach ([0.0, -5.0] as $bad) {
            $r = MapPricingService::evaluateRule(['map_price' => $bad], 100.00);
            $this->assertSame(['has_rule' => false], $r, "MAP $bad should be treated as no rule");
        }
    }

    public function test_price_above_map_does_not_suppress(): void
    {
        $r = MapPricingService::evaluateRule([
            'id' => 7,
            'map_price' => 599.00,
            'display_mode' => 'click_to_reveal',
            'source' => 'lipseys_csv',
        ], 650.00);

        $this->assertTrue($r['has_rule']);
        $this->assertFalse($r['below_map']);
        $this->assertSame(599.00, $r['map_price']);
        $this->assertSame(650.00, $r['active_price']);
        $this->assertSame('click_to_reveal', $r['display_mode']);
        $this->assertSame('lipseys_csv', $r['source']);
        $this->assertSame(7, $r['rule_id']);
    }

    public function test_price_equal_to_map_does_not_suppress(): void
    {
        $r = MapPricingService::evaluateRule(['map_price' => 599.00], 599.00);
        $this->assertFalse($r['below_map'], 'price === MAP is allowed by manufacturer policy');
    }

    public function test_price_below_map_triggers_suppression(): void
    {
        $r = MapPricingService::evaluateRule([
            'id' => 9,
            'map_price' => 599.00,
            'display_mode' => 'show_map',
            'override_label' => 'See cart for price',
        ], 549.99);

        $this->assertTrue($r['has_rule']);
        $this->assertTrue($r['below_map']);
        $this->assertSame('show_map', $r['display_mode']);
        $this->assertSame('See cart for price', $r['override_label']);
    }

    public function test_zero_active_price_does_not_count_as_below_map(): void
    {
        // Defensive: an uninitialized product (price=0) should not be reported as
        // a violation. The filter only kicks in for *real* below-MAP sale prices.
        $r = MapPricingService::evaluateRule(['map_price' => 100.0], 0.0);
        $this->assertTrue($r['has_rule']);
        $this->assertFalse($r['below_map']);
    }

    public function test_defaults_when_rule_omits_optional_fields(): void
    {
        $r = MapPricingService::evaluateRule(['map_price' => 50.0], 25.0);
        $this->assertSame('click_to_reveal', $r['display_mode']);
        $this->assertSame('manual', $r['source']);
        $this->assertNull($r['override_label']);
        $this->assertSame(0, $r['rule_id']);
    }

    public function test_filter_price_html_returns_original_html_for_invalid_product(): void
    {
        $html = '<span class="price">$10.00</span>';
        $this->assertSame($html, MapPricingService::filter_price_html($html, null));
        $this->assertSame($html, MapPricingService::filter_price_html($html, 'not-a-product'));
        $this->assertSame($html, MapPricingService::filter_price_html($html, new \stdClass()));
    }

    public function test_filter_price_html_returns_original_html_for_zero_product_id(): void
    {
        $html = '<span class="price">$10.00</span>';
        $this->assertSame($html, MapPricingService::filter_price_html($html, new FakeWcProductWithoutGetMeta(0, 10.0)));
    }

    public function test_filter_price_html_swallows_internal_failures(): void
    {
        Functions\when('is_admin')->justReturn(false);
        // The repository lookup throws -> the filter must catch it and fall
        // back to WooCommerce's own HTML instead of breaking the storefront.
        $GLOBALS['wpdb'] = new class () {
            public string $prefix = 'wp_';

            public function prepare(string $sql, ...$args): string
            {
                return $sql;
            }

            public function get_row(string $sql, $output = null): never
            {
                throw new \RuntimeException('db exploded');
            }
        };
        $html = '<span class="price">$25.00</span>';
        $out = MapPricingService::filter_price_html($html, new FakeWcProductWithoutGetMeta(901, 25.0));
        $this->assertSame($html, $out);
    }

    public function test_filter_price_html_reads_identifiers_via_get_post_meta_not_wc_get_meta(): void
    {
        Functions\when('is_admin')->justReturn(false);
        Functions\when('get_transient')->justReturn(true); // skip violation logging
        Functions\when('__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\expect('get_post_meta')->with(902, '_sku', true)->once()->andReturn('SKU-902');
        Functions\expect('get_post_meta')->with(902, '_upc', true)->once()->andReturn('');
        Functions\expect('get_post_meta')->with(902, '_global_unique_id', true)->once()->andReturn('012345678905');

        $GLOBALS['wpdb'] = new class () {
            public string $prefix = 'wp_';

            public function prepare(string $sql, ...$args): string
            {
                return $sql;
            }

            public function get_row(string $sql, $output = ARRAY_A): array
            {
                return [
                    'id' => 11,
                    'map_price' => 50.0,
                    'display_mode' => 'click_to_reveal',
                    'source' => 'manual',
                ];
            }
        };

        $out = MapPricingService::filter_price_html(
            '<span class="price">$25.00</span>',
            new FakeWcProductWithoutGetMeta(902, 25.0)
        );

        $this->assertStringContainsString('g2a-map-hidden', $out);
        $this->assertStringContainsString('data-map-product="902"', $out);
    }

    public function test_filter_price_html_per_request_cache_avoids_repeat_lookups(): void
    {
        Functions\when('is_admin')->justReturn(false);
        // Identifiers + rule lookup must run exactly once for a given product id
        // even when the filter fires multiple times in a request (shop loops).
        Functions\expect('get_post_meta')->with(903, \Mockery::any(), true)->times(3)->andReturn('');

        $GLOBALS['wpdb'] = new class () {
            public string $prefix = 'wp_';
            public int $queries = 0;

            public function prepare(string $sql, ...$args): string
            {
                return $sql;
            }

            public function get_row(string $sql, $output = null)
            {
                ++$this->queries;
                return null;
            }
        };

        $html = '<span class="price">$25.00</span>';
        $product = new FakeWcProductWithoutGetMeta(903, 25.0);
        $this->assertSame($html, MapPricingService::filter_price_html($html, $product));
        $this->assertSame($html, MapPricingService::filter_price_html($html, $product));
        $this->assertSame($html, MapPricingService::filter_price_html($html, $product));
        $this->assertSame(1, $GLOBALS['wpdb']->queries, 'rule lookup must be cached per request');
    }
}
