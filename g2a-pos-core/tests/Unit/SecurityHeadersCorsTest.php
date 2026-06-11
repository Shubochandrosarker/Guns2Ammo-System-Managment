<?php

namespace G2A\POS\Tests\Unit;

use Brain\Monkey;
use G2A\POS\Support\SecurityHeaders;
use PHPUnit\Framework\TestCase;

final class SecurityHeadersCorsTest extends TestCase
{
    protected function setUp(): void
    {
        Monkey\setUp();
        $GLOBALS['__wp_options'] = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['__wp_options'] = [];
        Monkey\tearDown();
    }

    public function test_normalize_origin_lowercases_and_strips_trailing_slash(): void
    {
        $this->assertSame('https://pos.guns2ammo.com', SecurityHeaders::normalize_origin('HTTPS://Pos.Guns2Ammo.com/'));
        $this->assertSame('https://pos.guns2ammo.com:8443', SecurityHeaders::normalize_origin('  https://pos.guns2ammo.com:8443  '));
        $this->assertSame('', SecurityHeaders::normalize_origin('   '));
    }

    public function test_is_origin_allowed_is_case_and_slash_insensitive(): void
    {
        $allow = ['https://pos.guns2ammo.com'];
        $this->assertTrue(SecurityHeaders::is_origin_allowed('https://pos.guns2ammo.com', $allow));
        $this->assertTrue(SecurityHeaders::is_origin_allowed('HTTPS://POS.GUNS2AMMO.COM/', $allow));
        $this->assertFalse(SecurityHeaders::is_origin_allowed('https://evil.example.com', $allow));
        $this->assertFalse(SecurityHeaders::is_origin_allowed('', $allow));
    }

    public function test_is_origin_allowed_does_not_match_substrings(): void
    {
        $allow = ['https://guns2ammo.com'];
        $this->assertFalse(SecurityHeaders::is_origin_allowed('https://guns2ammo.com.evil.com', $allow));
        $this->assertFalse(SecurityHeaders::is_origin_allowed('https://notguns2ammo.com', $allow));
    }

    public function test_allowed_origins_reads_string_option(): void
    {
        $GLOBALS['__wp_options']['g2a_pos_cors_origins'] = "https://pos.guns2ammo.com, https://kiosk.guns2ammo.com\nhttps://pos.guns2ammo.com";
        $origins = SecurityHeaders::allowed_origins();

        // Normalized + de-duplicated.
        $this->assertSame(
            ['https://pos.guns2ammo.com', 'https://kiosk.guns2ammo.com'],
            $origins
        );
    }

    public function test_allowed_origins_reads_array_option(): void
    {
        $GLOBALS['__wp_options']['g2a_pos_cors_origins'] = ['HTTPS://Pos.Guns2Ammo.com/', ''];
        $this->assertSame(['https://pos.guns2ammo.com'], SecurityHeaders::allowed_origins());
    }

    public function test_allowed_origins_defaults_to_empty(): void
    {
        $this->assertSame([], SecurityHeaders::allowed_origins());
    }
}
