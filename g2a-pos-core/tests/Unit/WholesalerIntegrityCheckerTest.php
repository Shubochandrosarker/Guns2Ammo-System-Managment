<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Wholesalers\WholesalerIntegrityChecker;
use PHPUnit\Framework\TestCase;

final class WholesalerIntegrityCheckerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__wp_options'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__wp_options']);
        parent::tearDown();
    }

    private function row(int $id, string $code, ?string $account): array
    {
        return [
            'id' => $id,
            'provider_code' => $code,
            'display_name' => $code . ' #' . $id,
            'account_number' => $account,
            'status' => 'active',
        ];
    }

    public function test_clean_dual_accounts_produce_no_issues(): void
    {
        $issues = WholesalerIntegrityChecker::issues([
            $this->row(1, 'lipseys', '12345'),
            $this->row(2, 'lipseys', '67890'),
            $this->row(3, 'rsr', null),
        ]);

        $this->assertSame([], $issues);
    }

    public function test_flags_blank_account_among_multiple_rows(): void
    {
        $issues = WholesalerIntegrityChecker::issues([
            $this->row(1, 'lipseys', null),
            $this->row(2, 'lipseys', '67890'),
        ]);

        $this->assertCount(1, $issues);
        $this->assertSame('blank_account_conflict', $issues[0]['type']);
        $this->assertSame('lipseys', $issues[0]['provider_code']);
        $this->assertStringContainsString('#1', $issues[0]['message']);
    }

    public function test_flags_duplicate_provider_account_pairs(): void
    {
        $issues = WholesalerIntegrityChecker::issues([
            $this->row(1, 'lipseys', '12345'),
            $this->row(2, 'lipseys', '12345'),
        ]);

        $this->assertCount(1, $issues);
        $this->assertSame('duplicate_account', $issues[0]['type']);
        $this->assertStringContainsString('12345', $issues[0]['message']);
        $this->assertStringContainsString('#1, #2', $issues[0]['message']);
    }

    public function test_single_blank_account_row_is_fine(): void
    {
        $issues = WholesalerIntegrityChecker::issues([
            $this->row(1, 'lipseys', null),
        ]);

        $this->assertSame([], $issues);
    }

    public function test_recorded_sync_ambiguities_surface_as_issues(): void
    {
        WholesalerIntegrityChecker::recordSyncAmbiguity('lipseys', 'Feed had no account number; two accounts exist.');

        $issues = WholesalerIntegrityChecker::issues([
            $this->row(1, 'lipseys', '12345'),
            $this->row(2, 'lipseys', '67890'),
        ]);

        $this->assertCount(1, $issues);
        $this->assertSame('sync_ambiguity', $issues[0]['type']);
        $this->assertStringContainsString('no account number', $issues[0]['message']);

        WholesalerIntegrityChecker::clearSyncAmbiguity('lipseys');
        $this->assertSame([], WholesalerIntegrityChecker::issues([
            $this->row(1, 'lipseys', '12345'),
            $this->row(2, 'lipseys', '67890'),
        ]));
    }
}
