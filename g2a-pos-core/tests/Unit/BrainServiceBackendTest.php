<?php

// Global-namespace shims for the HTTP layer BrainService/CloudflareBrain sit
// on (SafeHttp → wp_safe_remote_* + WP_Error). Guarded so any other test file
// that ships the same shims wins the race harmlessly.
namespace {
    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }
    if (!defined('G2A_POS_ALLOW_PRIVATE_ENDPOINTS')) {
        // Lets SafeHttp::validateUrl pass for the fake Worker hosts below
        // without doing real DNS lookups inside the unit suite.
        define('G2A_POS_ALLOW_PRIVATE_ENDPOINTS', true);
    }
    if (!class_exists('WP_Error')) {
        class WP_Error
        {
            public function __construct(private string $code = '', private string $message = '')
            {
            }

            public function get_error_message(): string
            {
                return $this->message;
            }
        }
    }
    if (!function_exists('is_wp_error')) {
        function is_wp_error($thing): bool
        {
            return $thing instanceof \WP_Error;
        }
    }
    if (!function_exists('wp_parse_url')) {
        function wp_parse_url(string $url)
        {
            return parse_url($url);
        }
    }
    if (!function_exists('esc_url_raw')) {
        function esc_url_raw($url): string
        {
            return trim((string) $url);
        }
    }
}

namespace G2A\POS\Tests\Unit {

    use Brain\Monkey;
    use Brain\Monkey\Functions;
    use G2A\POS\Ai\BrainService;
    use PHPUnit\Framework\TestCase;

    /**
     * In-memory wpdb double covering exactly the queries AiBrainRepository
     * issues during ingest/search, so BrainService's hash-gating and backend
     * selection can run end-to-end without MySQL.
     */
    final class FakeBrainWpdb
    {
        public string $prefix = 'wp_';
        public int $insert_id = 0;
        public int $chunk_inserts = 0;
        /** @var array<int,array<string,mixed>> */
        public array $docs = [];
        /** @var array<int,array<string,mixed>> */
        public array $chunks = [];
        /** @var array<int,array<string,mixed>> Canned rows for search_text(). */
        public array $search_text_rows = [];
        private int $next_id = 1;

        public function esc_like(string $s): string
        {
            return $s;
        }

        public function prepare(string $query, ...$args): string
        {
            if (count($args) === 1 && is_array($args[0])) {
                $args = $args[0];
            }
            foreach ($args as $a) {
                $replacement = is_int($a) || is_float($a) ? (string) $a : "'" . $a . "'";
                $query = (string) preg_replace('/%[sdf]/', $replacement, $query, 1);
            }
            return $query;
        }

        public function get_row(string $sql, string $output = 'OBJECT'): ?array
        {
            if (preg_match("/content_hash = '([0-9a-f]{64})'/", $sql, $m)) {
                foreach ($this->docs as $doc) {
                    if ($doc['content_hash'] === $m[1]) {
                        return $doc;
                    }
                }
            }
            return null;
        }

        public function get_var(string $sql)
        {
            if (str_contains($sql, 'content_hash') && preg_match("/content_hash = '([0-9a-f]{64})'/", $sql, $m)) {
                foreach ($this->docs as $doc) {
                    if ($doc['content_hash'] === $m[1]) {
                        return $doc['id'];
                    }
                }
                return null;
            }
            if (str_contains($sql, 'embedding IS NOT NULL')) {
                return null; // No embedded chunks in these tests.
            }
            if (str_contains($sql, 'COUNT(*)')) {
                return count($this->docs);
            }
            return null;
        }

        public function get_results(string $sql, string $output = 'OBJECT'): array
        {
            if (str_contains($sql, 'g2a_ai_brain_chunks')) {
                return $this->search_text_rows;
            }
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
                $this->docs[$data['id']] = $data;
                $this->insert_id = $data['id'];
            } else {
                ++$this->chunk_inserts;
                $this->chunks[] = $data;
            }
            return true;
        }

        public function update(string $table, array $data, array $where): bool
        {
            if (str_contains($table, 'documents') && isset($where['id'], $this->docs[$where['id']])) {
                $this->docs[$where['id']] = array_merge($this->docs[$where['id']], $data);
            }
            return true;
        }

        public function delete(string $table, array $where): bool
        {
            if (str_contains($table, 'chunks') && isset($where['document_id'])) {
                $this->chunks = array_values(array_filter(
                    $this->chunks,
                    static fn($c) => ($c['document_id'] ?? null) !== $where['document_id']
                ));
            }
            return true;
        }
    }

    final class BrainServiceBackendTest extends TestCase
    {
        private FakeBrainWpdb $wpdb;

        protected function setUp(): void
        {
            parent::setUp();
            Monkey\setUp();
            $GLOBALS['__wp_options'] = [];
            $this->wpdb = new FakeBrainWpdb();
            $GLOBALS['wpdb'] = $this->wpdb;
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['wpdb'], $GLOBALS['__wp_options']);
            Monkey\tearDown();
            parent::tearDown();
        }

        private function useCloudflare(string $host = 'worker.example.workers.dev'): void
        {
            $GLOBALS['__wp_options']['g2a_pos_ai_gateway'] = [
                'brain_backend'           => 'cloudflare',
                'cloudflare_worker_url'   => 'https://' . $host,
                'cloudflare_worker_token' => 'test-token',
            ];
        }

        public function test_backend_defaults_to_local(): void
        {
            $this->assertSame('local', BrainService::backend());
        }

        public function test_backend_is_cloudflare_only_when_fully_configured(): void
        {
            $GLOBALS['__wp_options']['g2a_pos_ai_gateway'] = ['brain_backend' => 'cloudflare'];
            $this->assertSame('local', BrainService::backend(), 'No URL/token → stay local');

            $this->useCloudflare();
            $this->assertSame('cloudflare', BrainService::backend());
        }

        public function test_reingesting_identical_content_is_skipped(): void
        {
            $body = 'Guns 2 Ammo has six indoor shooting lanes and is open seven days a week for shooters.';

            $first = BrainService::ingest_text('Facility facts', $body, ['source_type' => 'default_pack']);
            $this->assertTrue($first['ok']);
            $this->assertArrayNotHasKey('skipped', $first);
            $chunksAfterFirst = $this->wpdb->chunk_inserts;
            $this->assertGreaterThan(0, $chunksAfterFirst);

            $second = BrainService::ingest_text('Facility facts', $body, ['source_type' => 'default_pack']);
            $this->assertTrue($second['ok']);
            $this->assertTrue($second['skipped'] ?? false, 'Identical content must be hash-skipped');
            $this->assertSame($first['document_id'], $second['document_id']);
            $this->assertSame($chunksAfterFirst, $this->wpdb->chunk_inserts, 'No chunks re-written on re-seed');
            $this->assertCount(1, $this->wpdb->docs, 'No duplicate document rows');
        }

        public function test_changed_content_is_reingested(): void
        {
            $first  = BrainService::ingest_text('Facility facts', 'Version one of the policy document text here.');
            $second = BrainService::ingest_text('Facility facts', 'Version two of the policy document text here.');
            $this->assertArrayNotHasKey('skipped', $second);
            $this->assertNotSame($first['document_id'], $second['document_id']);
        }

        public function test_retrieve_uses_cloudflare_worker_when_configured(): void
        {
            $this->useCloudflare();
            $captured = null;
            Functions\when('wp_safe_remote_post')->alias(function ($url, $args) use (&$captured) {
                $captured = ['url' => $url, 'args' => $args];
                return [
                    'response' => ['code' => 200],
                    'body'     => json_encode([
                        'ok'      => true,
                        'results' => [[
                            'id'          => '7:0',
                            'doc_id'      => 7,
                            'text'        => 'Range hours are 10am to 6pm.',
                            'label'       => 'Guns 2 Ammo — business identity, location and hours',
                            'source_type' => 'default_pack',
                            'source_uri'  => '',
                            'score'       => 0.87,
                        ]],
                    ]),
                ];
            });
            Functions\when('wp_remote_retrieve_response_code')->alias(static fn($res) => $res['response']['code'] ?? 0);
            Functions\when('wp_remote_retrieve_body')->alias(static fn($res) => $res['body'] ?? '');

            $res = BrainService::retrieve_with_meta('what are the range hours?', 3);

            $this->assertSame('cloudflare', $res['backend']);
            $this->assertNull($res['notice']);
            $this->assertCount(1, $res['hits']);
            // Mapped to the same row shape AiBrainRepository::search() returns.
            $hit = $res['hits'][0];
            $this->assertSame(7, $hit['document_id']);
            $this->assertSame('Range hours are 10am to 6pm.', $hit['text_content']);
            $this->assertSame('Guns 2 Ammo — business identity, location and hours', $hit['source_label']);
            $this->assertSame(0.87, $hit['score']);
            // The Worker got the bearer token and the /query path.
            $this->assertStringEndsWith('/query', $captured['url']);
            $this->assertSame('Bearer test-token', $captured['args']['headers']['Authorization']);
        }

        public function test_retrieve_falls_back_to_local_text_search_on_worker_error(): void
        {
            $this->useCloudflare();
            $this->wpdb->search_text_rows = [[
                'id'           => 3,
                'document_id'  => 2,
                'text_content' => 'Range hours: Monday to Thursday 10am to 6pm.',
                'source_type'  => 'seed',
                'source_label' => 'Guns 2 Ammo — business facts',
                'source_uri'   => '',
            ]];
            Functions\when('wp_safe_remote_post')->alias(
                static fn() => new \WP_Error('http_request_failed', 'Could not resolve host')
            );

            $res = BrainService::retrieve_with_meta('range hours', 3);

            $this->assertSame('local_fallback', $res['backend']);
            $this->assertStringContainsString('Cloudflare Worker unavailable', (string) $res['notice']);
            $this->assertSame($this->wpdb->search_text_rows, $res['hits']);
        }

        public function test_cloudflare_ingest_failure_keeps_local_copy_and_surfaces_notice(): void
        {
            $this->useCloudflare();
            Functions\when('wp_safe_remote_post')->alias(
                static fn() => new \WP_Error('http_request_failed', 'Worker down')
            );

            $res = BrainService::ingest_text('Doc', 'Some knowledge base content that should stay searchable locally.');

            $this->assertTrue($res['ok'], 'Worker outage must not fail the ingest');
            $this->assertStringContainsString('cloudflare_ingest_failed', (string) ($res['notice'] ?? ''));
            $this->assertCount(1, $this->wpdb->docs);
            $this->assertGreaterThan(0, $this->wpdb->chunk_inserts);
        }
    }
}
