<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Wholesalers\Media\VendorImageMirror;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Covers the parts of the mirror that are reachable without a live WP media
 * stack: URL validation, the SSRF guard, and the filename/extension logic
 * that decides what media_handle_sideload() is asked to accept.
 */
final class VendorImageMirrorTest extends TestCase
{
    private function filenameFor(string $url, ?string $mime = null): string
    {
        $m = new ReflectionMethod(VendorImageMirror::class, 'filenameFromUrl');
        $m->setAccessible(true);
        return $m->invoke(null, $url, $mime);
    }

    public function test_empty_and_malformed_urls_fail_cleanly(): void
    {
        foreach (['', 'not-a-url', '/images/a.jpg'] as $bad) {
            $r = VendorImageMirror::mirror($bad);
            $this->assertFalse($r['ok']);
            $this->assertSame('invalid_url', $r['error']);
            $this->assertNotSame('', $r['message']);
        }
    }

    public function test_private_and_loopback_hosts_are_refused(): void
    {
        foreach (
            [
                'http://127.0.0.1/a.jpg',
                'http://10.1.2.3/a.jpg',
                'http://192.168.1.10/a.jpg',
                'http://169.254.169.254/latest/meta-data',
            ] as $url
        ) {
            $r = VendorImageMirror::mirror($url);
            $this->assertFalse($r['ok'], $url);
            $this->assertSame('unsafe_url', $r['error'], $url);
        }
    }

    public function test_non_http_schemes_are_refused(): void
    {
        $r = VendorImageMirror::mirror('ftp://example.test/a.jpg');
        $this->assertFalse($r['ok']);
        $this->assertSame('unsafe_url', $r['error']);
    }

    public function test_failures_always_carry_a_human_readable_message(): void
    {
        $r = VendorImageMirror::mirror('http://127.0.0.1/a.jpg');
        $this->assertArrayHasKey('message', $r);
        $this->assertArrayHasKey('detail', $r);
        $this->assertIsString($r['message']);
    }

    public function test_filename_keeps_an_existing_image_extension(): void
    {
        $this->assertSame('g19gen6.jpg', $this->filenameFor('https://cdn.test/images/g19gen6.jpg'));
        $this->assertSame('g19gen6.png', $this->filenameFor('https://cdn.test/images/g19gen6.png', 'image/png'));
        $this->assertSame('g19gen6.jpeg', $this->filenameFor('https://cdn.test/images/g19gen6.jpeg'));
    }

    public function test_extensionless_url_gets_an_extension_from_the_sniffed_mime(): void
    {
        // media_handle_sideload() rejects an extensionless upload outright,
        // so a CDN that puts the type only in Content-Type was unmirrorable.
        $this->assertSame('GLPI1750203.webp', $this->filenameFor('https://cdn.test/images/GLPI1750203', 'image/webp'));
        $this->assertSame('GLPI1750203.png', $this->filenameFor('https://cdn.test/images/GLPI1750203', 'image/png'));
    }

    public function test_extensionless_url_with_no_mime_falls_back_to_jpg(): void
    {
        $this->assertSame('GLPI1750203.jpg', $this->filenameFor('https://cdn.test/images/GLPI1750203'));
    }

    public function test_non_image_extension_is_kept_but_neutralised(): void
    {
        // Never hand a .php (or any executable) name to the uploader as-is.
        $this->assertSame('evil.php.jpg', $this->filenameFor('https://cdn.test/evil.php', 'image/jpeg'));
    }

    public function test_pdf_certificates_keep_their_extension(): void
    {
        // RangeWaiverController mirrors OtterWaiver certificate PDFs through
        // this same class with allowed_mime_prefixes including
        // application/pdf — rewriting those to .pdf.jpg would break them.
        $this->assertSame(
            'waiver-12.pdf',
            $this->filenameFor('https://otter.test/certs/waiver-12.pdf', 'application/pdf')
        );
        $this->assertSame(
            '12.pdf',
            $this->filenameFor('https://otter.test/certs/12', 'application/pdf')
        );
    }

    public function test_alternate_spellings_are_left_alone(): void
    {
        $this->assertSame('a.tiff', $this->filenameFor('https://cdn.test/a.tiff', 'image/tiff'));
    }

    public function test_percent_encoded_url_path_decodes_to_a_sane_filename(): void
    {
        $this->assertSame(
            'glock-19-gen6.jpg',
            $this->filenameFor('https://cdn.test/images/glock%2019%20gen6.jpg')
        );
    }

    public function test_url_with_no_path_still_yields_a_filename(): void
    {
        $name = $this->filenameFor('https://cdn.test', 'image/jpeg');
        $this->assertStringStartsWith('vendor-image-', $name);
        $this->assertStringEndsWith('.jpg', $name);
    }
}
