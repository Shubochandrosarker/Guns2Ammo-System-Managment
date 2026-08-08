<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Wholesalers\Media\LipseysImageUrls;
use PHPUnit\Framework\TestCase;

final class LipseysImageUrlsTest extends TestCase
{
    public function test_filename_resolves_to_cdn_url(): void
    {
        $this->assertSame(
            'https://www.lipseyscloud.com/images/g19gen67c7e.jpg',
            LipseysImageUrls::cdnUrl('g19gen67c7e.jpg')
        );
    }

    public function test_blank_filename_returns_null(): void
    {
        $this->assertNull(LipseysImageUrls::cdnUrl(''));
        $this->assertNull(LipseysImageUrls::cdnUrl('   '));
    }

    public function test_full_url_passes_through_unmodified(): void
    {
        $url = 'https://cdn.example.com/foo.png';
        $this->assertSame($url, LipseysImageUrls::cdnUrl($url));
    }

    public function test_path_traversal_attempt_is_stripped(): void
    {
        // A malicious feed value shouldn't escape the /images/ prefix.
        $url = LipseysImageUrls::cdnUrl('../../etc/passwd');
        $this->assertSame('https://www.lipseyscloud.com/images/passwd', $url);
    }

    public function test_filename_with_spaces_is_percent_encoded(): void
    {
        $this->assertSame(
            'https://www.lipseyscloud.com/images/glock%2019%20gen6.jpg',
            LipseysImageUrls::cdnUrl('glock 19 gen6.jpg')
        );
    }

    public function test_bare_dots_return_null(): void
    {
        $this->assertNull(LipseysImageUrls::cdnUrl('.'));
        $this->assertNull(LipseysImageUrls::cdnUrl('..'));
    }
}
