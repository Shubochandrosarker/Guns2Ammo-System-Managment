<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Ai\BrainFacade;
use G2A\POS\Ai\BrainService;
use PHPUnit\Framework\TestCase;

/**
 * Records inserts/updates and every prepared SQL string so these tests can
 * assert BrainFacade forwards its arguments (label/body/opts, k, scope) all
 * the way down to AiBrainRepository without needing a real database.
 * get_var/get_results always answer "nothing on file" — these tests care
 * about what was asked, not about ranking real rows.
 */
final class FacadeSpyWpdb
{
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    private int $next_id = 1;

    /** @var array<int, array{table:string, data:array}> */
    public array $inserts = [];
    /** @var string[] */
    public array $preparedSql = [];
    /** @var array<int, array<int, mixed>> */
    public array $preparedArgs = [];

    public function esc_like(string $s): string
    {
        return $s;
    }

    public function prepare(string $sql, ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $this->preparedSql[] = $sql;
        $this->preparedArgs[] = $args;
        foreach ($args as $arg) {
            $replacement = is_numeric($arg) ? (string) $arg : "'" . (string) $arg . "'";
            $sql = (string) preg_replace('/%[sdf]/', $replacement, $sql, 1);
        }
        return $sql;
    }

    public function get_var(string $sql)
    {
        return null;
    }

    public function get_row(string $sql, $output = null): ?array
    {
        return null;
    }

    public function get_results(string $sql, $output = null): array
    {
        return [];
    }

    public function get_col(string $sql): array
    {
        return [];
    }

    public function insert(string $table, array $data): bool
    {
        if (str_contains($table, 'documents')) {
            $data['id'] = $this->next_id++;
            $this->insert_id = $data['id'];
        }
        $this->inserts[] = ['table' => $table, 'data' => $data];
        return true;
    }

    public function update(string $table, array $data, array $where): bool
    {
        return true;
    }

    public function delete(string $table, array $where): bool
    {
        return true;
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
}

final class BrainFacadeTest extends TestCase
{
    private FacadeSpyWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('ARRAY_A')) {
            define('ARRAY_A', 'ARRAY_A');
        }
        $GLOBALS['__wp_options'] = [];
        $this->wpdb = new FacadeSpyWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb'], $GLOBALS['__wp_options']);
        parent::tearDown();
    }

    public function test_is_available_is_true(): void
    {
        $this->assertTrue(BrainFacade::is_available());
    }

    public function test_ingest_forwards_label_body_and_opts_to_the_document_row(): void
    {
        $result = BrainFacade::ingest(
            'Staff onboarding notes',
            'New hires should badge in at the north door and check the safe log.',
            ['scope' => 'staff', 'tags' => 'onboarding']
        );

        $this->assertTrue($result['ok']);
        $doc = $this->wpdb->lastInsertInto('documents');
        $this->assertNotNull($doc);
        $this->assertSame('Staff onboarding notes', $doc['source_label']);
        $this->assertSame('staff', $doc['scope']);
        $this->assertSame('onboarding', $doc['tags']);
    }

    public function test_ingest_defaults_scope_to_public_when_not_given(): void
    {
        BrainFacade::ingest('Public FAQ', 'The range is open seven days a week for walk-ins.');

        $doc = $this->wpdb->lastInsertInto('documents');
        $this->assertSame('public', $doc['scope']);
    }

    public function test_retrieve_forwards_scope_down_to_the_repository_query(): void
    {
        BrainFacade::retrieve('what are the range hours', 3, 'staff');

        $sql = end($this->wpdb->preparedSql);
        $args = end($this->wpdb->preparedArgs);
        $this->assertStringContainsString('d.scope = %s', $sql);
        $this->assertContains('staff', $args);
        $this->assertSame(3, end($args), 'k must reach the repository as the LIMIT arg');
    }

    public function test_retrieve_without_scope_omits_the_scope_condition(): void
    {
        BrainFacade::retrieve('what are the range hours', 3);

        $sql = end($this->wpdb->preparedSql);
        $this->assertStringNotContainsString('scope', $sql);
    }

    public function test_stats_forwards_scope_down_to_the_repository_query(): void
    {
        $stats = BrainFacade::stats('staff');

        $foundScopedQuery = false;
        foreach ($this->wpdb->preparedSql as $sql) {
            if (str_contains($sql, 'scope = %s')) {
                $foundScopedQuery = true;
                break;
            }
        }
        $this->assertTrue($foundScopedQuery, 'stats(scope) must issue at least one scope-filtered query');
        $this->assertContains('staff', $this->wpdb->preparedArgs[array_key_last($this->wpdb->preparedArgs)] ?? []);
        $this->assertArrayHasKey('backend', $stats);
        $this->assertSame('local', $stats['backend']);
        $this->assertSame(0, $stats['documents']);
    }

    public function test_stats_without_scope_uses_unfiltered_counts(): void
    {
        $stats = BrainFacade::stats();

        $this->assertSame([], $this->wpdb->preparedSql, 'Unscoped stats never needs $wpdb->prepare()');
        $this->assertSame(0, $stats['documents']);
        $this->assertSame(0.0, $stats['embedded_pct']);
    }

    /**
     * BrainFacade::retrieve() is a one-line pass-through — this exercises the
     * underlying BrainService::retrieve() directly to confirm the `$scope`
     * parameter it added actually reaches the repository query, independent
     * of the facade wrapper.
     */
    public function test_brain_service_retrieve_forwards_scope_directly(): void
    {
        BrainService::retrieve('range hours', 2, 'staff');

        $sql = end($this->wpdb->preparedSql);
        $args = end($this->wpdb->preparedArgs);
        $this->assertStringContainsString('d.scope = %s', $sql);
        $this->assertContains('staff', $args);
    }

    public function test_brain_service_retrieve_defaults_scope_to_null(): void
    {
        BrainService::retrieve('range hours', 2);

        $sql = end($this->wpdb->preparedSql);
        $this->assertStringNotContainsString('scope', $sql);
    }
}
