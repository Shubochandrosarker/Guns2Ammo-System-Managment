<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Database\GiftCardRepository;
use G2A\POS\Domain\TenderService;
use G2A\POS\Tests\Unit\Support\RecordingWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Gift-card value integrity: redeem exposes the card id for later reversal,
 * credit() restores value under the same locked pattern, and voiding a
 * gift_card tender line puts the debited amount back on the card.
 */
final class GiftCardRepositoryTest extends TestCase
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

    public function test_redeem_returns_the_card_id_for_reversals(): void
    {
        $this->wpdb->rowHandler = static fn (string $sql) => [
            'id' => 5,
            'balance_cents' => 3000,
            'status' => 'active',
            'expires_at' => null,
        ];

        $result = (new GiftCardRepository())->redeem('AAAA-BBBB-CCCC-DDDD', 1000, 9);

        $this->assertTrue($result['ok']);
        $this->assertSame(5, $result['gift_card_id']);
        $this->assertSame(1000, $result['applied_cents']);
        $this->assertSame(2000, $result['balance_cents']);
    }

    public function test_credit_restores_balance_and_reactivates_depleted_card(): void
    {
        $this->wpdb->rowHandler = static fn (string $sql) => [
            'id' => 5,
            'balance_cents' => 0,
            'status' => 'depleted',
        ];

        $result = (new GiftCardRepository())->credit(5, 2500, 9, 'void_refund');

        $this->assertTrue($result['ok']);
        $this->assertSame(2500, $result['balance_cents']);

        $update = $this->wpdb->lastUpdateOf('g2a_gift_cards');
        $this->assertSame(2500, $update['data']['balance_cents']);
        $this->assertSame('active', $update['data']['status']);
        $this->assertSame(['id' => 5], $update['where']);

        $tx = $this->wpdb->lastInsertInto('g2a_gift_card_transactions');
        $this->assertSame('void_refund', $tx['tx_type']);
        $this->assertSame(2500, $tx['delta_cents']);
        $this->assertSame(2500, $tx['balance_after_cents']);
        $this->assertSame(9, $tx['pos_order_id']);

        $this->assertSame('START TRANSACTION', $this->wpdb->queries[0]);
        $this->assertContains('COMMIT', $this->wpdb->queries);
        $this->assertStringContainsString('FOR UPDATE', $this->wpdb->getRowLog[0]);
    }

    public function test_credit_rejects_non_positive_amounts_without_touching_db(): void
    {
        $result = (new GiftCardRepository())->credit(5, 0);
        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_credit', $result['error']);
        $this->assertSame([], $this->wpdb->queries);
    }

    public function test_credit_rejects_cancelled_cards(): void
    {
        $this->wpdb->rowHandler = static fn (string $sql) => [
            'id' => 5,
            'balance_cents' => 0,
            'status' => 'cancelled',
        ];

        $result = (new GiftCardRepository())->credit(5, 1000);

        $this->assertFalse($result['ok']);
        $this->assertSame('card_not_creditable', $result['error']);
        $this->assertContains('ROLLBACK', $this->wpdb->queries);
        $this->assertSame([], $this->wpdb->updates);
    }

    public function test_voiding_a_gift_card_tender_restores_the_card_balance(): void
    {
        $this->wpdb->rowHandler = static function (string $sql) {
            if (str_contains($sql, 'g2a_pos_tender_lines')) {
                return [
                    'id' => 3,
                    'pos_order_id' => 9,
                    'tender_method' => 'gift_card',
                    'amount' => '25.00',
                    'change_due' => '0.00',
                    'reference' => 'Gift card ****DDDD',
                    'external_ref' => 'gift_card_id=5;balance_cents_after=0',
                    'status' => 'captured',
                    'refunded_amount' => '0.00',
                ];
            }
            if (str_contains($sql, 'g2a_gift_cards')) {
                return [
                    'id' => 5,
                    'balance_cents' => 0,
                    'status' => 'depleted',
                ];
            }
            return null; // pos_orders lookup for the trailing balance recompute
        };

        $result = TenderService::void_tender(3, 'clerk error');

        $this->assertTrue($result['ok']);

        // The card got its $25.00 back, atomically.
        $card_update = $this->wpdb->lastUpdateOf('g2a_gift_cards');
        $this->assertSame(2500, $card_update['data']['balance_cents']);
        $this->assertSame('active', $card_update['data']['status']);

        $tx = $this->wpdb->lastInsertInto('g2a_gift_card_transactions');
        $this->assertSame('void_refund', $tx['tx_type']);
        $this->assertSame(2500, $tx['delta_cents']);

        // And the tender line itself was voided.
        $line_update = $this->wpdb->lastUpdateOf('g2a_pos_tender_lines');
        $this->assertSame('voided', $line_update['data']['status']);
        $this->assertSame(['id' => 3, 'status' => 'captured'], $line_update['where']);
    }
}
