<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Database\AiBrainRepository;
use PHPUnit\Framework\TestCase;

/**
 * Records every prepared SQL string + its bound args so scope filtering can
 * be asserted without a real database. get_results always answers empty —
 * these tests only care about the query shape that was issued.
 */
final class ScopeAssertingWpdb
{
    public string $prefix = 'wp_';

    /** @var string[] raw SQL templates (before %s/%d substitution) */
    public array $preparedSql = [];
    /** @var array<int, array<int, mixed>> args passed to each prepare() call */
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

    public function get_results(string $sql, string $output = 'ARRAY_A'): array
    {
        return [];
    }
}

final class AiBrainRepositoryScopeTest extends TestCase
{
    private ScopeAssertingWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('ARRAY_A')) {
            define('ARRAY_A', 'ARRAY_A');
        }
        $this->wpdb = new ScopeAssertingWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    public function test_search_without_scope_preserves_previous_query_shape(): void
    {
        (new AiBrainRepository())->search([0.1, 0.2, 0.3], 5);

        $sql = end($this->wpdb->preparedSql);
        $this->assertStringNotContainsString('scope', $sql);
        $this->assertSame([3], $this->wpdb->preparedArgs[array_key_last($this->wpdb->preparedArgs)]);
    }

    public function test_search_with_scope_adds_scope_condition_and_binds_it(): void
    {
        (new AiBrainRepository())->search([0.1, 0.2], 5, null, 'staff');

        $sql = end($this->wpdb->preparedSql);
        $args = end($this->wpdb->preparedArgs);
        $this->assertStringContainsString('d.scope = %s', $sql);
        $this->assertContains('staff', $args);
    }

    public function test_search_with_scope_and_model_binds_both(): void
    {
        (new AiBrainRepository())->search([0.1], 5, 'text-embed-3', 'staff');

        $args = end($this->wpdb->preparedArgs);
        $this->assertSame([1, 'text-embed-3', 'staff'], $args);
    }

    public function test_search_text_without_scope_preserves_previous_query_shape(): void
    {
        (new AiBrainRepository())->search_text('range hours', 5);

        $sql = end($this->wpdb->preparedSql);
        $this->assertStringNotContainsString('scope', $sql);
    }

    public function test_search_text_with_scope_adds_scope_condition_and_binds_it(): void
    {
        (new AiBrainRepository())->search_text('range hours', 5, 'staff');

        $sql = end($this->wpdb->preparedSql);
        $args = end($this->wpdb->preparedArgs);
        $this->assertStringContainsString('d.scope = %s', $sql);
        $this->assertContains('staff', $args);
        // Scope binds after the token LIKE args, before the trailing LIMIT.
        $this->assertSame(5, end($args));
    }

    public function test_search_text_verbatim_fallback_with_scope_adds_scope_condition(): void
    {
        // Query with only stopwords/short tokens hits the verbatim LIKE branch.
        (new AiBrainRepository())->search_text('a', 5, 'staff');

        $sql = end($this->wpdb->preparedSql);
        $args = end($this->wpdb->preparedArgs);
        $this->assertStringContainsString('d.scope = %s', $sql);
        $this->assertSame(['%a%', 'staff', 5], $args);
    }
}
