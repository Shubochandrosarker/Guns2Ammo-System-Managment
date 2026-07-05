<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Database\LayawayRepository;
use G2A\POS\Tests\Unit\Support\RecordingWpdb;
use PHPUnit\Framework\TestCase;

/**
 * record_payment must be a locked read-modify-write: the layaway row is
 * SELECT ... FOR UPDATE'd inside a transaction and the running paid/balance
 * arithmetic is derived from the locked row, never from the caller.
 */
final class LayawayRepositoryTest extends TestCase
{
    private RecordingWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('ARRAY_A')) {
            define('ARRAY_A', 'ARRAY_A');
        }
        $this->wpdb = new RecordingWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    private function activeLayaway(float $grand = 500.0, float $paid = 100.0): void
    {
        $this->wpdb->rowHandler = static function (string $sql) use ($grand, $paid) {
            if (str_contains($sql, 'g2a_layaways')) {
                return [
                    'grand_total' => (string) $grand,
                    'paid_amount' => (string) $paid,
                    'status' => 'active',
                ];
            }
            return null;
        };
    }

    public function test_partial_payment_updates_running_totals(): void
    {
        $this->activeLayaway(500.0, 100.0);

        $payment_id = (new LayawayRepository())->record_payment(7, 150.0, 'cash', ['reference' => 'r-1']);

        $this->assertGreaterThan(0, $payment_id);

        $payment = $this->wpdb->lastInsertInto('g2a_layaway_payments');
        $this->assertSame(150.0, $payment['amount']);
        $this->assertSame('cash', $payment['payment_method']);

        $update = $this->wpdb->lastUpdateOf('g2a_layaways');
        $this->assertSame(250.0, $update['data']['paid_amount']);
        $this->assertSame(250.0, $update['data']['balance_due']);
        $this->assertArrayNotHasKey('status', $update['data'], 'partial payment must not complete the layaway');
        $this->assertSame(['id' => 7], $update['where']);
    }

    public function test_final_payment_marks_layaway_paid(): void
    {
        $this->activeLayaway(500.0, 100.0);

        (new LayawayRepository())->record_payment(7, 400.0, 'card');

        $update = $this->wpdb->lastUpdateOf('g2a_layaways');
        $this->assertSame(500.0, $update['data']['paid_amount']);
        $this->assertEquals(0.0, $update['data']['balance_due']);
        $this->assertSame('paid', $update['data']['status']);
        $this->assertNotEmpty($update['data']['completed_at']);
    }

    public function test_row_is_locked_inside_a_transaction(): void
    {
        $this->activeLayaway();

        (new LayawayRepository())->record_payment(7, 10.0, 'cash');

        $this->assertSame('START TRANSACTION', $this->wpdb->queries[0]);
        $this->assertContains('COMMIT', $this->wpdb->queries);
        $this->assertStringContainsString('FOR UPDATE', $this->wpdb->getRowLog[0]);
    }

    public function test_inactive_layaway_rejects_payment_and_rolls_back(): void
    {
        $this->wpdb->rowHandler = static fn (string $sql) => [
            'grand_total' => '500.00',
            'paid_amount' => '500.00',
            'status' => 'paid',
        ];

        $payment_id = (new LayawayRepository())->record_payment(7, 50.0, 'cash');

        $this->assertSame(0, $payment_id);
        $this->assertContains('ROLLBACK', $this->wpdb->queries);
        $this->assertSame([], $this->wpdb->inserts);
        $this->assertSame([], $this->wpdb->updates);
    }
}
