<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Wholesalers\AmbiguousWholesalerAccountException;
use G2A\POS\Wholesalers\WholesalerImportBridge;
use G2A\POS\Wholesalers\WholesalerIntegrityChecker;
use G2A\POS\Wholesalers\WholesalerRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Fake wpdb for the bridge's account-resolution paths:
 * get_results answers findAllByCode, get_row answers
 * findByCodeAndAccount/find, get_var answers the upsert match query,
 * insert records auto-created rows.
 */
final class FakeBridgeWpdb
{
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    /** @var array<int,array<string,mixed>> */
    public array $results = [];
    public ?array $row = null;
    /** @var array<int,array{table:string,data:array}> */
    public array $inserts = [];

    public function prepare($query, ...$args)
    {
        return $query;
    }

    public function get_results($query, $output = null)
    {
        return $this->results;
    }

    public function get_row($query, $output = null)
    {
        return $this->row;
    }

    public function get_var($query)
    {
        return null;
    }

    public function insert($table, $data)
    {
        $this->inserts[] = ['table' => $table, 'data' => $data];
        $this->insert_id = 99;
        return 1;
    }

    public function update($table, $data, $where)
    {
        return 1;
    }
}

final class WholesalerImportBridgeTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb'], $GLOBALS['__wp_options']);
        parent::tearDown();
    }

    private function resolve(array $context): int
    {
        $provider = WholesalerRegistry::get('lipseys');
        $this->assertNotNull($provider);
        $m = new \ReflectionMethod(WholesalerImportBridge::class, 'resolve_wholesaler_id');
        $m->setAccessible(true);
        return (int) $m->invoke(null, $provider, $context);
    }

    private function lipseysRow(int $id, ?string $account): array
    {
        return [
            'id' => $id,
            'provider_code' => 'lipseys',
            'display_name' => "Lipsey's #{$id}",
            'account_number' => $account,
            'status' => 'active',
        ];
    }

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

    public function test_resolves_exact_account_match_when_context_has_account_number(): void
    {
        $wpdb = new FakeBridgeWpdb();
        // findByCodeAndAccount → get_row returns the accessories row (id 9),
        // even though a lower-id firearms row exists in findAllByCode output.
        $wpdb->row = $this->lipseysRow(9, '67890');
        $wpdb->results = [$this->lipseysRow(7, '12345'), $this->lipseysRow(9, '67890')];
        $GLOBALS['wpdb'] = $wpdb;

        $id = $this->resolve(['account_number' => '67890']);

        $this->assertSame(9, $id, 'Resolution must be exact on (provider_code, account_number), not lowest id');
        $this->assertCount(0, $wpdb->inserts);
    }

    public function test_creates_row_with_context_account_number_when_missing(): void
    {
        $wpdb = new FakeBridgeWpdb();
        $wpdb->row = null; // no (code, account) match
        $GLOBALS['wpdb'] = $wpdb;

        $id = $this->resolve(['account_number' => '67890', 'display_name' => "Lipsey's — Accessories"]);

        $this->assertSame(99, $id);
        $this->assertCount(1, $wpdb->inserts);
        $this->assertSame('67890', $wpdb->inserts[0]['data']['account_number'], 'Auto-created row must carry the real account number, not a blank');
        $this->assertSame("Lipsey's — Accessories", $wpdb->inserts[0]['data']['display_name']);
    }

    public function test_resolves_single_row_when_context_has_no_account_number(): void
    {
        $wpdb = new FakeBridgeWpdb();
        $wpdb->results = [$this->lipseysRow(7, null)];
        $GLOBALS['wpdb'] = $wpdb;

        $this->assertSame(7, $this->resolve([]));
    }

    public function test_aborts_loudly_when_multiple_rows_and_no_account_number(): void
    {
        $wpdb = new FakeBridgeWpdb();
        $wpdb->results = [$this->lipseysRow(7, '12345'), $this->lipseysRow(9, '67890')];
        $GLOBALS['wpdb'] = $wpdb;
        $GLOBALS['__wp_options'] = [];

        $tmp = tempnam(sys_get_temp_dir(), 'g2a-log-');
        $previous = ini_set('error_log', $tmp);
        try {
            try {
                $this->resolve([]);
                $this->fail('Expected AmbiguousWholesalerAccountException');
            } catch (AmbiguousWholesalerAccountException $e) {
                $this->assertStringContainsString('lipseys', $e->getMessage());
            }

            $log = (string) file_get_contents($tmp);
            $this->assertStringContainsString('[G2A POS][ERROR]', $log);
            $this->assertStringContainsString('ambiguous account resolution', $log);

            $recorded = $GLOBALS['__wp_options'][WholesalerIntegrityChecker::AMBIGUITY_OPTION] ?? [];
            $this->assertArrayHasKey('lipseys', $recorded, 'Ambiguity must be recorded for admin visibility');
        } finally {
            ini_set('error_log', $previous);
            @unlink($tmp);
        }
    }

    public function test_mirror_csv_returns_structured_ambiguity_error(): void
    {
        $wpdb = new FakeBridgeWpdb();
        $wpdb->results = [$this->lipseysRow(7, '12345'), $this->lipseysRow(9, '67890')];
        $GLOBALS['wpdb'] = $wpdb;
        $GLOBALS['__wp_options'] = [];

        $tmp = tempnam(sys_get_temp_dir(), 'g2a-log-');
        $previous = ini_set('error_log', $tmp);
        try {
            $result = WholesalerImportBridge::mirror_csv('lipseys', __FILE__);

            $this->assertFalse($result['ok']);
            $this->assertFalse($result['mirrored']);
            $this->assertSame('ambiguous_wholesaler_account', $result['reason']);
            $this->assertStringContainsString('account number', (string) $result['error']);
        } finally {
            ini_set('error_log', $previous);
            @unlink($tmp);
        }
    }

    public function test_successful_exact_resolution_clears_recorded_ambiguity(): void
    {
        $wpdb = new FakeBridgeWpdb();
        $wpdb->row = $this->lipseysRow(9, '67890');
        $GLOBALS['wpdb'] = $wpdb;
        $GLOBALS['__wp_options'] = [
            WholesalerIntegrityChecker::AMBIGUITY_OPTION => [
                'lipseys' => ['message' => 'stale', 'recorded_at' => '2026-01-01 00:00:00'],
            ],
        ];

        $this->resolve(['account_number' => '67890']);

        $recorded = $GLOBALS['__wp_options'][WholesalerIntegrityChecker::AMBIGUITY_OPTION];
        $this->assertArrayNotHasKey('lipseys', $recorded);
    }
}
