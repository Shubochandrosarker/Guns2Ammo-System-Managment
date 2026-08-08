<?php

namespace G2A\POS\Tests\Unit\Support;

/**
 * Recording $wpdb stand-in for repository/engine tests.
 *
 * Captures every query/insert/update so tests can assert on the exact SQL
 * (e.g. presence of FOR UPDATE / START TRANSACTION) and written row data.
 * Route read results by assigning $rowHandler / $varHandler callables that
 * receive the prepared SQL string.
 */
class RecordingWpdb
{
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    public int $nextInsertId = 1;

    /** @var string[] raw SQL passed to query() (transactions etc.) */
    public array $queries = [];
    /** @var array<int, array{table: string, data: array}> */
    public array $inserts = [];
    /** @var array<int, array{table: string, data: array, where: array}> */
    public array $updates = [];
    /** @var string[] */
    public array $getVarLog = [];
    /** @var string[] */
    public array $getRowLog = [];

    /** @var callable|null fn(string $sql): mixed */
    public $varHandler = null;
    /** @var callable|null fn(string $sql): ?array */
    public $rowHandler = null;

    public function prepare(string $sql, ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        foreach ($args as $arg) {
            $replacement = is_numeric($arg) ? (string) $arg : "'" . (string) $arg . "'";
            $sql = (string) preg_replace('/%[dsf]/', $replacement, $sql, 1);
        }
        return $sql;
    }

    public function query(string $sql): bool
    {
        $this->queries[] = $sql;
        return true;
    }

    public function get_var(string $sql)
    {
        $this->getVarLog[] = $sql;
        return $this->varHandler ? ($this->varHandler)($sql) : null;
    }

    public function get_row(string $sql, $output = null)
    {
        $this->getRowLog[] = $sql;
        return $this->rowHandler ? ($this->rowHandler)($sql) : null;
    }

    public function get_results(string $sql, $output = null): array
    {
        return [];
    }

    public function insert(string $table, array $data): int
    {
        $this->inserts[] = ['table' => $table, 'data' => $data];
        $this->insert_id = $this->nextInsertId++;
        return 1;
    }

    public function update(string $table, array $data, array $where): int
    {
        $this->updates[] = ['table' => $table, 'data' => $data, 'where' => $where];
        return 1;
    }

    /** Last insert into a table whose name contains $needle, or null. */
    public function lastInsertInto(string $needle): ?array
    {
        foreach (array_reverse($this->inserts) as $insert) {
            if (str_contains($insert['table'], $needle)) {
                return $insert['data'];
            }
        }
        return null;
    }

    /** Last update against a table whose name contains $needle, or null. */
    public function lastUpdateOf(string $needle): ?array
    {
        foreach (array_reverse($this->updates) as $update) {
            if (str_contains($update['table'], $needle)) {
                return $update;
            }
        }
        return null;
    }
}
