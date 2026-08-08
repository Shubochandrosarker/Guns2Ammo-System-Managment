<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Database\WholesalerRepository;
use G2A\POS\Wholesalers\Crypto\CredentialCipher;
use PHPUnit\Framework\TestCase;

/**
 * Minimal wpdb stand-in for WholesalerRepository::upsert().
 *
 * - get_var() answers the provider_code+account_number match query.
 * - get_row() answers find() (id lookups).
 * - update()/insert() record what the repository writes so tests can
 *   assert on the encrypted credentials payload.
 */
final class FakeWholesalerWpdb
{
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    /** @var array<int,array{table:string,data:array,where:array}> */
    public array $updates = [];
    /** @var array<int,array{table:string,data:array}> */
    public array $inserts = [];
    /** @var array<int,array<string,mixed>> rows returned by get_results (findAllByCode) */
    public array $results = [];
    /** @var string[] every query passed to get_var */
    public array $getVarQueries = [];

    public function __construct(private ?array $row, private ?string $matchId)
    {
    }

    public function prepare($query, ...$args)
    {
        return $query;
    }

    public function get_var($query)
    {
        $this->getVarQueries[] = (string) $query;
        return $this->matchId;
    }

    public function get_row($query, $output = null)
    {
        return $this->row;
    }

    public function get_results($query, $output = null)
    {
        return $this->results;
    }

    public function update($table, $data, $where)
    {
        $this->updates[] = ['table' => $table, 'data' => $data, 'where' => $where];
        return 1;
    }

    public function insert($table, $data)
    {
        $this->inserts[] = ['table' => $table, 'data' => $data];
        $this->insert_id = 99;
        return 1;
    }
}

final class WholesalerRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('ARRAY_A')) {
            define('ARRAY_A', 'ARRAY_A');
        }
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    /**
     * @param array<string,mixed> $creds
     */
    private function existingRow(array $creds, int $id = 7): array
    {
        return [
            'id' => $id,
            'provider_code' => 'lipseys',
            'display_name' => "Lipsey's — Production",
            'account_number' => '12345',
            'api_endpoint' => 'https://api.lipseys.com',
            'credentials' => CredentialCipher::encrypt($creds),
            'status' => 'active',
        ];
    }

    private function decryptWritten(array $write): array
    {
        return CredentialCipher::decrypt((string) $write['data']['credentials']);
    }

    public function test_blank_password_is_retained_on_update(): void
    {
        $wpdb = new FakeWholesalerWpdb(
            $this->existingRow(['email' => 'dealer@example.com', 'password' => 'super-secret']),
            '7'
        );
        $GLOBALS['wpdb'] = $wpdb;

        $repo = new WholesalerRepository();
        $id = $repo->upsert([
            'provider_code' => 'lipseys',
            'display_name' => "Lipsey's — Production",
            'account_number' => '12345',
            'credentials' => ['email' => 'dealer@example.com', 'password' => ''],
        ]);

        $this->assertSame(7, $id);
        $this->assertCount(1, $wpdb->updates);
        $this->assertCount(0, $wpdb->inserts);

        $creds = $this->decryptWritten($wpdb->updates[0]);
        $this->assertSame('super-secret', $creds['password'], 'Blank incoming password must not wipe the stored one');
        $this->assertSame('dealer@example.com', $creds['email']);
    }

    public function test_blank_email_is_retained_on_update(): void
    {
        $wpdb = new FakeWholesalerWpdb(
            $this->existingRow(['email' => 'dealer@example.com', 'password' => 'super-secret']),
            '7'
        );
        $GLOBALS['wpdb'] = $wpdb;

        $repo = new WholesalerRepository();
        $repo->upsert([
            'provider_code' => 'lipseys',
            'account_number' => '12345',
            'credentials' => ['email' => '', 'password' => ''],
        ]);

        $creds = $this->decryptWritten($wpdb->updates[0]);
        $this->assertSame('dealer@example.com', $creds['email']);
        $this->assertSame('super-secret', $creds['password']);
    }

    public function test_new_password_overwrites_stored_one(): void
    {
        $wpdb = new FakeWholesalerWpdb(
            $this->existingRow(['email' => 'dealer@example.com', 'password' => 'old-secret']),
            '7'
        );
        $GLOBALS['wpdb'] = $wpdb;

        $repo = new WholesalerRepository();
        $repo->upsert([
            'provider_code' => 'lipseys',
            'account_number' => '12345',
            'credentials' => ['email' => 'dealer@example.com', 'password' => 'new-secret'],
        ]);

        $creds = $this->decryptWritten($wpdb->updates[0]);
        $this->assertSame('new-secret', $creds['password']);
    }

    public function test_email_and_password_are_trimmed_on_insert(): void
    {
        $wpdb = new FakeWholesalerWpdb(null, null);
        $GLOBALS['wpdb'] = $wpdb;

        $repo = new WholesalerRepository();
        $id = $repo->upsert([
            'provider_code' => 'lipseys',
            'account_number' => '12345',
            'credentials' => ['email' => '  dealer@example.com  ', 'password' => "  secret \n"],
        ]);

        $this->assertSame(99, $id);
        $this->assertCount(1, $wpdb->inserts);

        $creds = CredentialCipher::decrypt((string) $wpdb->inserts[0]['data']['credentials']);
        $this->assertSame('dealer@example.com', $creds['email']);
        $this->assertSame('secret', $creds['password']);
    }

    public function test_whitespace_only_password_is_treated_as_blank_on_update(): void
    {
        $wpdb = new FakeWholesalerWpdb(
            $this->existingRow(['email' => 'dealer@example.com', 'password' => 'super-secret']),
            '7'
        );
        $GLOBALS['wpdb'] = $wpdb;

        $repo = new WholesalerRepository();
        $repo->upsert([
            'provider_code' => 'lipseys',
            'account_number' => '12345',
            'credentials' => ['email' => 'dealer@example.com', 'password' => '   '],
        ]);

        $creds = $this->decryptWritten($wpdb->updates[0]);
        $this->assertSame('super-secret', $creds['password']);
    }

    public function test_explicit_id_updates_row_even_when_account_number_changed(): void
    {
        // get_var (provider_code+account match) finds nothing because the
        // account number was edited; the explicit id must win and update
        // the row instead of inserting a duplicate.
        $wpdb = new FakeWholesalerWpdb(
            $this->existingRow(['email' => 'dealer@example.com', 'password' => 'super-secret']),
            null
        );
        $GLOBALS['wpdb'] = $wpdb;

        $repo = new WholesalerRepository();
        $id = $repo->upsert([
            'id' => 7,
            'provider_code' => 'lipseys',
            'account_number' => '99999',
            'credentials' => ['email' => '', 'password' => ''],
        ]);

        $this->assertSame(7, $id);
        $this->assertCount(1, $wpdb->updates);
        $this->assertCount(0, $wpdb->inserts);
        $this->assertSame('99999', $wpdb->updates[0]['data']['account_number']);

        $creds = $this->decryptWritten($wpdb->updates[0]);
        $this->assertSame('super-secret', $creds['password']);
    }

    public function test_insert_when_no_match_and_no_id(): void
    {
        $wpdb = new FakeWholesalerWpdb(null, null);
        $GLOBALS['wpdb'] = $wpdb;

        $repo = new WholesalerRepository();
        $id = $repo->upsert([
            'provider_code' => 'lipseys',
            'account_number' => '12345',
            'credentials' => ['email' => 'dealer@example.com', 'password' => 'secret'],
        ]);

        $this->assertSame(99, $id);
        $this->assertCount(1, $wpdb->inserts);
        $this->assertCount(0, $wpdb->updates);
    }

    public function test_blank_account_upsert_inserts_new_row_and_never_matches_existing(): void
    {
        // Regression for the credential-overwrite defect: an existing lipseys
        // row with a blank account number is present and the fake would match
        // it (matchId '7') if the repository still ran the blank-account
        // query. With no id and a blank incoming account number the upsert
        // must INSERT a new row — never UPDATE (and re-encrypt over) the
        // existing row's credentials.
        $existing = $this->existingRow(['email' => 'firearms@example.com', 'password' => 'firearms-secret']);
        $existing['account_number'] = null;
        $wpdb = new FakeWholesalerWpdb($existing, '7');
        $GLOBALS['wpdb'] = $wpdb;

        $repo = new WholesalerRepository();
        $id = $repo->upsert([
            'provider_code' => 'lipseys',
            'display_name' => "Lipsey's — Accessories",
            'account_number' => '',
            'credentials' => ['email' => 'accessories@example.com', 'password' => 'accessories-secret'],
        ]);

        $this->assertSame(99, $id, 'Blank-account save must create a new row');
        $this->assertCount(1, $wpdb->inserts);
        $this->assertCount(0, $wpdb->updates, 'Existing row credentials must not be touched');
        $this->assertSame([], $wpdb->getVarQueries, 'No provider/account match query may run for a blank account');

        $creds = CredentialCipher::decrypt((string) $wpdb->inserts[0]['data']['credentials']);
        $this->assertSame('accessories-secret', $creds['password']);
    }

    public function test_whitespace_only_account_is_treated_as_blank_on_upsert(): void
    {
        $wpdb = new FakeWholesalerWpdb(null, '7');
        $GLOBALS['wpdb'] = $wpdb;

        $repo = new WholesalerRepository();
        $id = $repo->upsert([
            'provider_code' => 'lipseys',
            'account_number' => '   ',
            'credentials' => ['email' => 'a@example.com', 'password' => 'x'],
        ]);

        $this->assertSame(99, $id);
        $this->assertCount(1, $wpdb->inserts);
        $this->assertNull($wpdb->inserts[0]['data']['account_number']);
        $this->assertSame([], $wpdb->getVarQueries);
    }

    public function test_find_by_code_returns_single_row(): void
    {
        $row = $this->existingRow(['email' => 'a@example.com', 'password' => 'x']);
        $wpdb = new FakeWholesalerWpdb(null, null);
        $wpdb->results = [$row];
        $GLOBALS['wpdb'] = $wpdb;

        $repo = new WholesalerRepository();
        $found = $repo->findByCode('lipseys');

        $this->assertNotNull($found);
        $this->assertSame(7, (int) $found['id']);
    }

    public function test_find_by_code_returns_null_and_logs_when_ambiguous(): void
    {
        $rowA = $this->existingRow(['email' => 'a@example.com', 'password' => 'x'], 7);
        $rowB = $this->existingRow(['email' => 'b@example.com', 'password' => 'y'], 9);
        $rowB['account_number'] = '67890';
        $wpdb = new FakeWholesalerWpdb(null, null);
        $wpdb->results = [$rowA, $rowB];
        $GLOBALS['wpdb'] = $wpdb;

        $tmp = tempnam(sys_get_temp_dir(), 'g2a-log-');
        $previous = ini_set('error_log', $tmp);
        try {
            $repo = new WholesalerRepository();
            $found = $repo->findByCode('lipseys');

            $this->assertNull($found, 'Ambiguous code-only lookup must refuse to pick a row');
            $log = (string) file_get_contents($tmp);
            $this->assertStringContainsString('[G2A POS][ERROR]', $log);
            $this->assertStringContainsString('Ambiguous wholesaler lookup', $log);
            $this->assertStringContainsString('lipseys', $log);
        } finally {
            ini_set('error_log', $previous);
            @unlink($tmp);
        }
    }

    public function test_find_by_code_and_account_returns_exact_match(): void
    {
        $row = $this->existingRow(['email' => 'a@example.com', 'password' => 'x'], 9);
        $row['account_number'] = '67890';
        $wpdb = new FakeWholesalerWpdb($row, null);
        $GLOBALS['wpdb'] = $wpdb;

        $repo = new WholesalerRepository();
        $found = $repo->findByCodeAndAccount('lipseys', '67890');

        $this->assertNotNull($found);
        $this->assertSame(9, (int) $found['id']);
    }

    public function test_find_by_code_and_account_never_matches_blank_account(): void
    {
        // Even with a row available to return, a blank account number must
        // resolve to nothing — blank never aliases onto an existing row.
        $wpdb = new FakeWholesalerWpdb($this->existingRow(['email' => 'a@example.com', 'password' => 'x']), null);
        $GLOBALS['wpdb'] = $wpdb;

        $repo = new WholesalerRepository();
        $this->assertNull($repo->findByCodeAndAccount('lipseys', ''));
        $this->assertNull($repo->findByCodeAndAccount('lipseys', '   '));
    }
}
