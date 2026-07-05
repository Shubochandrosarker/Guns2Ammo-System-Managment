<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Ai\Brain_Client;

/**
 * g2a-pos-core is never loaded in this suite, so \G2A\POS\Ai\BrainFacade
 * genuinely does not exist at first — the tests below that rely on that
 * (declared first, per PHPUnit's default declaration-order execution) cover
 * Brain_Client's real-world "plugin inactive" path.
 *
 * The later tests need the "available" branch, so `define_fake_brain_facade()`
 * lazily `eval()`s a stand-in under the exact FQCN g2a-pos-core would use.
 * It must stay lazy (not a top-level `class` declaration) because PHPUnit
 * requires every test file up front to discover test methods, which would
 * otherwise define the class before the "inactive" tests below ever run.
 */
final class BrainClientTest extends TestCase {

	/**
	 * @runInSeparateProcess
	 *
	 * Isolated so this genuinely observes "class never loaded" even if a
	 * test elsewhere in the suite has already eval()'d the fake — a fresh
	 * process only requires this file (defining the test class), it never
	 * executes another test's method body that would eval() the fake.
	 */
	public function test_is_available_is_false_before_pos_core_is_loaded() {
		$this->assertFalse( class_exists( '\G2A\POS\Ai\BrainFacade', false ) );
		$this->assertFalse( ( new Brain_Client() )->is_available() );
	}

	/** @runInSeparateProcess */
	public function test_retrieve_degrades_gracefully_when_brain_facade_is_absent() {
		$out = ( new Brain_Client() )->retrieve( 'what are the range hours' );
		$this->assertSame(
			array(
				'ok'      => false,
				'results' => array(),
				'reason'  => 'pos_brain_inactive',
			),
			$out
		);
	}

	/** @runInSeparateProcess */
	public function test_ingest_degrades_gracefully_when_brain_facade_is_absent() {
		$out = ( new Brain_Client() )->ingest( 'Doc', 'Body text' );
		$this->assertFalse( $out['ok'] );
		$this->assertSame( array(), $out['results'] );
		$this->assertSame( 'pos_brain_inactive', $out['reason'] );
	}

	/** @runInSeparateProcess */
	public function test_stats_degrades_gracefully_when_brain_facade_is_absent() {
		$out = ( new Brain_Client() )->stats();
		$this->assertFalse( $out['ok'] );
		$this->assertSame( array(), $out['results'] );
		$this->assertSame( 'pos_brain_inactive', $out['reason'] );
	}

	/**
	 * Everything from here on defines/uses the fake BrainFacade to exercise
	 * the "g2a-pos-core is active" branch.
	 */
	private static function define_fake_brain_facade(): void {
		if ( class_exists( '\G2A\POS\Ai\BrainFacade', false ) ) {
			return;
		}
		eval(
			'namespace G2A\POS\Ai;
			class BrainFacade {
				public static array $ingest_calls = [];
				public static array $retrieve_calls = [];
				public static array $stats_calls = [];
				public static bool $throw = false;

				public static function ingest(string $label, string $body, array $opts = []): array {
					if (self::$throw) { throw new \RuntimeException("ingest boom"); }
					self::$ingest_calls[] = [$label, $body, $opts];
					return ["ok" => true, "document_id" => 1, "chunks" => 1, "embedded" => false];
				}

				public static function retrieve(string $query, int $k = 5, ?string $scope = null): array {
					if (self::$throw) { throw new \RuntimeException("retrieve boom"); }
					self::$retrieve_calls[] = [$query, $k, $scope];
					return [["text_content" => "Range hours are 10am-6pm.", "score" => 0.9]];
				}

				public static function stats(?string $scope = null): array {
					if (self::$throw) { throw new \RuntimeException("stats boom"); }
					self::$stats_calls[] = [$scope];
					return ["documents" => 3, "chunks" => 12, "backend" => "local"];
				}

				public static function is_available(): bool {
					return true;
				}
			}'
		);
	}

	protected function tearDown(): void {
		if ( class_exists( '\G2A\POS\Ai\BrainFacade', false ) ) {
			\G2A\POS\Ai\BrainFacade::$ingest_calls   = array();
			\G2A\POS\Ai\BrainFacade::$retrieve_calls = array();
			\G2A\POS\Ai\BrainFacade::$stats_calls    = array();
			\G2A\POS\Ai\BrainFacade::$throw          = false;
		}
		parent::tearDown();
	}

	public function test_is_available_is_true_once_brain_facade_exists() {
		self::define_fake_brain_facade();
		$this->assertTrue( ( new Brain_Client() )->is_available() );
	}

	public function test_retrieve_forwards_query_k_and_scope_to_brain_facade() {
		self::define_fake_brain_facade();
		$out = ( new Brain_Client() )->retrieve( 'what are the range hours', 3, 'staff' );

		$this->assertTrue( $out['ok'] );
		$this->assertNotEmpty( $out['results'] );
		$this->assertSame(
			array( array( 'what are the range hours', 3, 'staff' ) ),
			\G2A\POS\Ai\BrainFacade::$retrieve_calls
		);
	}

	public function test_ingest_forwards_label_body_and_opts_to_brain_facade() {
		self::define_fake_brain_facade();
		$opts = array( 'scope' => 'staff' );
		$out  = ( new Brain_Client() )->ingest( 'Doc', 'Body text', $opts );

		$this->assertTrue( $out['ok'] );
		$this->assertSame(
			array( array( 'Doc', 'Body text', $opts ) ),
			\G2A\POS\Ai\BrainFacade::$ingest_calls
		);
	}

	public function test_stats_forwards_scope_to_brain_facade() {
		self::define_fake_brain_facade();
		$out = ( new Brain_Client() )->stats( 'staff' );

		$this->assertTrue( $out['ok'] );
		$this->assertSame( array( array( 'staff' ) ), \G2A\POS\Ai\BrainFacade::$stats_calls );
	}

	public function test_retrieve_degrades_gracefully_on_exception_instead_of_throwing() {
		self::define_fake_brain_facade();
		\G2A\POS\Ai\BrainFacade::$throw = true;

		$out = ( new Brain_Client() )->retrieve( 'anything' );

		$this->assertFalse( $out['ok'] );
		$this->assertSame( array(), $out['results'] );
		$this->assertStringStartsWith( 'error: ', $out['reason'] );
		$this->assertStringContainsString( 'retrieve boom', $out['reason'] );
	}

	public function test_ingest_degrades_gracefully_on_exception_instead_of_throwing() {
		self::define_fake_brain_facade();
		\G2A\POS\Ai\BrainFacade::$throw = true;

		$out = ( new Brain_Client() )->ingest( 'Doc', 'Body' );

		$this->assertFalse( $out['ok'] );
		$this->assertStringStartsWith( 'error: ', $out['reason'] );
	}

	public function test_stats_degrades_gracefully_on_exception_instead_of_throwing() {
		self::define_fake_brain_facade();
		\G2A\POS\Ai\BrainFacade::$throw = true;

		$out = ( new Brain_Client() )->stats();

		$this->assertFalse( $out['ok'] );
		$this->assertStringStartsWith( 'error: ', $out['reason'] );
	}
}
