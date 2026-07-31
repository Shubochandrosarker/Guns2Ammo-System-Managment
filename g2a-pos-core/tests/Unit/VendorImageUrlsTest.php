<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Wholesalers\Media\VendorImageUrls;
use PHPUnit\Framework\TestCase;

final class VendorImageUrlsTest extends TestCase
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

    public function test_lipseys_filename_uses_the_lipseys_cloud_host(): void
    {
        $this->assertSame(
            'https://www.lipseyscloud.com/images/g19gen67c7e.jpg',
            VendorImageUrls::cdnUrl('lipseys', 'g19gen67c7e.jpg')
        );
    }

    public function test_rsr_filename_resolves_instead_of_returning_null(): void
    {
        // The regression this class exists for: every non-Lipsey's provider
        // used to fall through to `default => null`, so an RSR row with a
        // perfectly good image name reported "provider has no CDN mapping".
        $this->assertSame(
            'https://img.rsrgroup.com/pimages/GLPI1750203.jpg',
            VendorImageUrls::cdnUrl('rsr', 'GLPI1750203.jpg')
        );
    }

    public function test_sports_south_filename_resolves(): void
    {
        $this->assertSame(
            'https://media.server.theshootingwarehouse.com/large/12345.jpg',
            VendorImageUrls::cdnUrl('sports_south', '12345.jpg')
        );
    }

    public function test_davidsons_absolute_url_passes_through(): void
    {
        $url = 'https://cdn.davidsonsinc.com/images/GLK-19-GEN5.jpg';
        $this->assertSame($url, VendorImageUrls::cdnUrl('davidsons', $url));
    }

    public function test_davidsons_bare_filename_has_no_host_to_expand_to(): void
    {
        // Davidsons' feed ships absolute URLs; a bare filename can't be
        // guessed at, and guessing wrong would mean 404 downloads all night.
        $this->assertNull(VendorImageUrls::cdnUrl('davidsons', 'GLK-19-GEN5.jpg'));
        $this->assertFalse(VendorImageUrls::hasCdnMapping('davidsons'));
    }

    public function test_unknown_provider_still_honours_absolute_urls(): void
    {
        $this->assertSame(
            'https://example.test/a.png',
            VendorImageUrls::cdnUrl('some_new_vendor', 'https://example.test/a.png')
        );
        $this->assertNull(VendorImageUrls::cdnUrl('some_new_vendor', 'a.png'));
    }

    public function test_blank_and_whitespace_values_return_null(): void
    {
        $this->assertNull(VendorImageUrls::cdnUrl('lipseys', ''));
        $this->assertNull(VendorImageUrls::cdnUrl('lipseys', '   '));
        $this->assertSame([], VendorImageUrls::cdnUrls('lipseys', ''));
    }

    public function test_path_traversal_is_stripped_to_the_file_leaf(): void
    {
        $this->assertSame(
            'https://img.rsrgroup.com/pimages/passwd',
            VendorImageUrls::cdnUrl('rsr', '../../etc/passwd')
        );
        $this->assertNull(VendorImageUrls::cdnUrl('rsr', '..'));
    }

    public function test_non_http_schemes_are_rejected(): void
    {
        $this->assertNull(VendorImageUrls::cdnUrl('lipseys', 'ftp://example.test/a.jpg'));
        $this->assertNull(VendorImageUrls::cdnUrl('lipseys', 'file:///etc/passwd'));
        $this->assertNull(VendorImageUrls::cdnUrl('lipseys', 'data:image/png;base64,AAAA'));
    }

    public function test_spaces_are_percent_encoded(): void
    {
        $this->assertSame(
            'https://www.lipseyscloud.com/images/glock%2019%20gen6.jpg',
            VendorImageUrls::cdnUrl('lipseys', 'glock 19 gen6.jpg')
        );
    }

    public function test_multi_image_column_yields_one_url_per_entry(): void
    {
        $this->assertSame(
            [
                'https://www.lipseyscloud.com/images/a.jpg',
                'https://www.lipseyscloud.com/images/b.jpg',
                'https://www.lipseyscloud.com/images/c.jpg',
            ],
            VendorImageUrls::cdnUrls('lipseys', 'a.jpg|b.jpg|c.jpg')
        );
        $this->assertSame(
            'https://www.lipseyscloud.com/images/a.jpg',
            VendorImageUrls::cdnUrl('lipseys', 'a.jpg,b.jpg')
        );
    }

    public function test_a_single_url_with_a_comma_in_its_query_is_not_split(): void
    {
        $url = 'https://cdn.example.test/i?id=1&sizes=200,400';
        $this->assertSame([$url], VendorImageUrls::cdnUrls('davidsons', $url));
    }

    public function test_duplicate_entries_are_collapsed(): void
    {
        $this->assertSame(
            ['https://www.lipseyscloud.com/images/a.jpg'],
            VendorImageUrls::cdnUrls('lipseys', 'a.jpg|a.jpg')
        );
    }

    public function test_option_override_repoints_a_provider_without_a_code_change(): void
    {
        $GLOBALS['__wp_options']['g2a_pos_vendor_image_cdn_bases'] = [
            'rsr' => 'https://images.example.test/rsr',
        ];
        $this->assertSame(
            'https://images.example.test/rsr/ABC.jpg',
            VendorImageUrls::cdnUrl('rsr', 'ABC.jpg')
        );
    }

    public function test_option_override_can_add_a_host_for_a_provider_that_has_none(): void
    {
        $GLOBALS['__wp_options']['g2a_pos_vendor_image_cdn_bases'] = [
            'davidsons' => 'https://images.example.test/dav/',
        ];
        $this->assertTrue(VendorImageUrls::hasCdnMapping('davidsons'));
        $this->assertSame(
            'https://images.example.test/dav/GLK.jpg',
            VendorImageUrls::cdnUrl('davidsons', 'GLK.jpg')
        );
    }

    public function test_provider_code_matching_is_case_insensitive(): void
    {
        $this->assertSame(
            'https://www.lipseyscloud.com/images/a.jpg',
            VendorImageUrls::cdnUrl('  Lipseys ', 'a.jpg')
        );
    }
}
