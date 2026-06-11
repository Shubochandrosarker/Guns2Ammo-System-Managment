<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Wholesalers\WholesalerImportBridge;
use PHPUnit\Framework\TestCase;

final class WholesalerImportBridgeTest extends TestCase
{
    public function test_returns_no_matching_provider_for_unknown_adapter(): void
    {
        $result = WholesalerImportBridge::mirror_csv('this-adapter-does-not-exist', __FILE__);

        $this->assertFalse($result['ok']);
        $this->assertFalse($result['mirrored']);
        $this->assertSame('no_matching_provider', $result['reason']);
    }

    public function test_returns_csv_not_readable_when_path_missing(): void
    {
        // Lipsey's provider IS registered, so the no-provider short circuit
        // doesn't trigger and we reach the readability check.
        $result = WholesalerImportBridge::mirror_csv('lipseys', '/tmp/does-not-exist-' . uniqid() . '.csv');

        $this->assertFalse($result['ok']);
        $this->assertFalse($result['mirrored']);
        $this->assertSame('csv_not_readable', $result['reason']);
    }

    public function test_returns_no_matching_provider_for_empty_slug(): void
    {
        $result = WholesalerImportBridge::mirror_csv('', __FILE__);

        $this->assertFalse($result['ok']);
        $this->assertSame('no_matching_provider', $result['reason']);
    }
}
