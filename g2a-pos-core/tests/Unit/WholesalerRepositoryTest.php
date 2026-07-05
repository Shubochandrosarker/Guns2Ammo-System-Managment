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

    public function __construct(private ?array $row, private ?string $matchId)
    {
    }

    public function prepare($query, ...$args)
    {
        return $query;
    }

    public function get_var($query)
    {
        return $this->matchId;
    }

    public function get_row($query, $output = null)
    {
        return $this->row;
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
}
