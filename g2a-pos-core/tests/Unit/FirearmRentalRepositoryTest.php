<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Database\FirearmRentalRepository;
use PHPUnit\Framework\TestCase;

/**
 * Minimal wpdb stand-in for FirearmRentalRepository.
 *
 * - get_row() answers get() and open_for_serial().
 * - insert()/update() record what the repository writes so tests can assert on
 *   the stored payload and on the optimistic-lock WHERE clause.
 */
final class FakeFirearmRentalWpdb
{
    public string $prefix = 'wp_';
    public int $insert_id = 77;
    /** @var array<int,array{table:string,data:array}> */
    public array $inserts = [];
    /** @var array<int,array{table:string,data:array,where:array}> */
    public array $updates = [];
    public int $updateResult = 1;

    /** @param array<string,mixed>|null $row */
    public function __construct(private ?array $row = null)
    {
    }

    public function prepare($query, ...$args)
    {
        // Good enough for assertions: the repository only relies on prepare()
        // returning something get_row()/query() will accept.
        return $query;
    }

    public function get_row($query, $output = null)
    {
        return $this->row;
    }

    public function get_results($query, $output = null)
    {
        return $this->row ? [$this->row] : [];
    }

    public function insert($table, $data)
    {
        $this->inserts[] = ['table' => $table, 'data' => $data];
        return 1;
    }

    public function update($table, $data, $where)
    {
        $this->updates[] = ['table' => $table, 'data' => $data, 'where' => $where];
        return $this->updateResult;
    }

    public function query($query)
    {
        return 0;
    }
}

final class FirearmRentalRepositoryTest extends TestCase
{
    private function repo(?array $row = null): array
    {
        $wpdb           = new FakeFirearmRentalWpdb($row);
        $GLOBALS['wpdb'] = $wpdb;

        return [new FirearmRentalRepository(), $wpdb];
    }

    /** @return array<string,mixed> */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'serial_number'   => 'ABC123',
            'customer_name'   => 'Jane Roe',
            'id_verified'     => true,
            'waiver_verified' => true,
            'id_last4'        => '9876',
            'rate_cents'      => 2500,
        ], $overrides);
    }

    public function test_issue_records_the_rental_and_returns_the_new_id(): void
    {
        [$repo, $wpdb] = $this->repo();

        $id = $repo->issue($this->validPayload());

        $this->assertSame(77, $id);
        $this->assertCount(1, $wpdb->inserts);

        $data = $wpdb->inserts[0]['data'];
        $this->assertSame('ABC123', $data['serial_number']);
        $this->assertSame('Jane Roe', $data['customer_name']);
        $this->assertSame(FirearmRentalRepository::STATUS_OUT, $data['status']);
        $this->assertSame(2500, $data['total_charged_cents']);
    }

    public function test_issue_is_refused_without_a_verified_id(): void
    {
        // The whole point of the feature: a serialised firearm must not leave
        // the counter without the identity check recorded. Enforced in the
        // repository so every caller is bound by it, not just the REST route.
        [$repo, $wpdb] = $this->repo();

        $result = $repo->issue($this->validPayload(['id_verified' => false]));

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('id_check_required', $result->get_error_code());
        $this->assertCount(0, $wpdb->inserts, 'nothing may be written when the ID check failed');
    }

    public function test_issue_is_refused_without_a_verified_waiver(): void
    {
        [$repo, $wpdb] = $this->repo();

        $result = $repo->issue($this->validPayload(['waiver_verified' => false]));

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('waiver_required', $result->get_error_code());
        $this->assertCount(0, $wpdb->inserts);
    }

    public function test_issue_requires_a_serial_number(): void
    {
        [$repo] = $this->repo();

        $result = $repo->issue($this->validPayload(['serial_number' => '   ']));

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_input', $result->get_error_code());
    }

    public function test_issue_requires_a_customer_name(): void
    {
        [$repo] = $this->repo();

        $result = $repo->issue($this->validPayload(['customer_name' => '']));

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_input', $result->get_error_code());
    }

    public function test_the_same_firearm_cannot_be_issued_twice_at_once(): void
    {
        // get_row() returning a row means open_for_serial() found the serial
        // still checked out.
        [$repo, $wpdb] = $this->repo(['id' => 12, 'status' => 'out']);

        $result = $repo->issue($this->validPayload());

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('already_out', $result->get_error_code());
        $this->assertStringContainsString('#12', $result->get_error_message());
        $this->assertCount(0, $wpdb->inserts);
    }

    public function test_only_the_last_four_id_characters_are_stored(): void
    {
        // A full government ID number must never land in the table; staff only
        // need enough to match the document back to the record.
        [$repo, $wpdb] = $this->repo();

        $repo->issue($this->validPayload(['id_last4' => 'D-1234-5678']));

        $this->assertSame('5678', $wpdb->inserts[0]['data']['id_last4']);
    }

    public function test_return_closes_the_rental_with_an_optimistic_lock(): void
    {
        [$repo, $wpdb] = $this->repo([
            'id'                  => 5,
            'status'              => 'out',
            'total_charged_cents' => 2500,
            'pos_order_id'        => null,
        ]);

        $result = $repo->return_firearm(5, ['condition_in' => 'no damage']);

        $this->assertTrue($result);
        $this->assertCount(1, $wpdb->updates);

        $update = $wpdb->updates[0];
        $this->assertSame(FirearmRentalRepository::STATUS_RETURNED, $update['data']['status']);
        // Two staff closing the same rental must not both succeed.
        $this->assertSame('out', $update['where']['status']);
        $this->assertSame(5, $update['where']['id']);
    }

    public function test_return_can_flag_an_incident(): void
    {
        [$repo, $wpdb] = $this->repo([
            'id'                  => 6,
            'status'              => 'out',
            'total_charged_cents' => 0,
            'pos_order_id'        => null,
        ]);

        $repo->return_firearm(6, ['status' => 'incident', 'condition_in' => 'cracked grip']);

        $this->assertSame(FirearmRentalRepository::STATUS_INCIDENT, $wpdb->updates[0]['data']['status']);
    }

    public function test_an_unknown_return_status_falls_back_to_returned(): void
    {
        [$repo, $wpdb] = $this->repo([
            'id'                  => 7,
            'status'              => 'out',
            'total_charged_cents' => 0,
            'pos_order_id'        => null,
        ]);

        $repo->return_firearm(7, ['status' => 'something_else']);

        $this->assertSame(FirearmRentalRepository::STATUS_RETURNED, $wpdb->updates[0]['data']['status']);
    }

    public function test_an_already_returned_rental_cannot_be_closed_again(): void
    {
        [$repo, $wpdb] = $this->repo([
            'id'                  => 8,
            'status'              => 'returned',
            'total_charged_cents' => 2500,
            'pos_order_id'        => null,
        ]);

        $result = $repo->return_firearm(8);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('already_returned', $result->get_error_code());
        $this->assertCount(0, $wpdb->updates);
    }

    public function test_returning_a_missing_rental_reports_not_found(): void
    {
        [$repo] = $this->repo(null);

        $result = $repo->return_firearm(999);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('not_found', $result->get_error_code());
    }

    public function test_a_lost_optimistic_lock_is_reported_rather_than_silently_ignored(): void
    {
        [$repo, $wpdb] = $this->repo([
            'id'                  => 9,
            'status'              => 'out',
            'total_charged_cents' => 0,
            'pos_order_id'        => null,
        ]);
        $wpdb->updateResult = 0; // someone else closed it first

        $result = $repo->return_firearm(9);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('not_updated', $result->get_error_code());
    }
}
