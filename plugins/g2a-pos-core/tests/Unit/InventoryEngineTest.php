<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Domain\InventoryEngine;
use G2A\POS\Tests\Unit\Support\RecordingWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Inventory adjustments must derive before/after from the server-side
 * ledger inside a locked transaction — a client can post any before_qty
 * it likes and it must be ignored.
 */
final class InventoryEngineTest extends TestCase
{
    private RecordingWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wpdb = new RecordingWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    public function test_client_before_qty_is_ignored_in_favor_of_ledger(): void
    {
        // Ledger says 12 on hand; the client claims 999.
        $this->wpdb->varHandler = static function (string $sql) {
            if (str_contains($sql, 'g2a_inventory_logs') && str_contains($sql, 'after_qty')) {
                return '12.000';
            }
            return null; // audit-log row_hash lookup
        };

        $result = InventoryEngine::adjust([
            'product_id' => 42,
            'location_id' => 1,
            'quantity_delta' => -3,
            'before_qty' => 999,
            'after_qty' => 9999,
            'event_type' => 'manual_adjustment',
        ]);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(12.0, $result['before_qty']);
        $this->assertSame(9.0, $result['after_qty']);

        $log = $this->wpdb->lastInsertInto('g2a_inventory_logs');
        $this->assertNotNull($log);
        $this->assertSame(12.0, $log['before_qty']);
        $this->assertSame(9.0, $log['after_qty']);
        $this->assertSame(-3.0, $log['quantity_delta']);
    }

    public function test_ledger_read_is_locked_inside_a_transaction(): void
    {
        InventoryEngine::adjust([
            'product_id' => 42,
            'location_id' => 1,
            'quantity_delta' => 5,
        ]);

        $this->assertSame('START TRANSACTION', $this->wpdb->queries[0]);
        $this->assertContains('COMMIT', $this->wpdb->queries);
        $ledger_reads = array_filter(
            $this->wpdb->getVarLog,
            static fn (string $sql) => str_contains($sql, 'g2a_inventory_logs')
        );
        $this->assertNotEmpty($ledger_reads);
        foreach ($ledger_reads as $sql) {
            $this->assertStringContainsString('FOR UPDATE', $sql);
        }
    }

    public function test_first_movement_without_history_starts_from_zero(): void
    {
        // varHandler returns null => no ledger rows, and no WooCommerce in tests.
        $result = InventoryEngine::adjust([
            'product_id' => 7,
            'location_id' => 2,
            'quantity_delta' => 4,
            'before_qty' => 500,
        ]);

        $this->assertSame(0.0, $result['before_qty']);
        $this->assertSame(4.0, $result['after_qty']);
    }

    public function test_failure_rolls_back_and_reports_error(): void
    {
        $GLOBALS['wpdb'] = $this->wpdb = new class () extends RecordingWpdb {
            public function insert(string $table, array $data): int
            {
                throw new \RuntimeException('insert exploded');
            }
        };

        $result = InventoryEngine::adjust([
            'product_id' => 42,
            'location_id' => 1,
            'quantity_delta' => 1,
        ]);

        $this->assertSame('insert exploded', $result['error'] ?? null);
        $this->assertContains('ROLLBACK', $this->wpdb->queries);
        $this->assertNotContains('COMMIT', $this->wpdb->queries);
    }
}
