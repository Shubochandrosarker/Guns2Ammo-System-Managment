<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Domain\TenderService;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for the parts of TenderService that don't touch wpdb:
 *   - the allow-list of tender methods (clients depend on it)
 *   - the gift-card masking helper (PCI hygiene — never log the full code)
 */
final class TenderServiceTest extends TestCase
{
    public function test_methods_constant_matches_supported_set(): void
    {
        $this->assertSame(
            ['cash', 'card', 'gift_card', 'store_credit', 'tradein_credit', 'check', 'house_charge'],
            TenderService::METHODS,
        );
    }

    public function test_mask_gift_code_returns_only_last_four_digits(): void
    {
        $m = new \ReflectionMethod(TenderService::class, 'mask_gift_code');
        $m->setAccessible(true);
        $this->assertSame('Gift card ****MNOP', $m->invoke(null, 'ABCD-EFGH-IJKL-MNOP'));
        $this->assertSame('Gift card ****ZZ12', $m->invoke(null, 'X1Y2-Z3A4-B5C6-DZZ12'));
        $this->assertSame('Gift card ****hijk', $m->invoke(null, 'abcdefghijk'));
    }

    public function test_mask_strips_separators_before_picking_tail(): void
    {
        $m = new \ReflectionMethod(TenderService::class, 'mask_gift_code');
        $m->setAccessible(true);
        // Spaces / dashes / dots removed first → last 4 alphanumerics shown.
        $this->assertSame('Gift card ****WXYZ', $m->invoke(null, 'AAAA BBBB CCCC WXYZ'));
        $this->assertSame('Gift card ****1234', $m->invoke(null, 'aa.bb-cc.1234'));
    }
}
