<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\REST\Brain_Controller;

/**
 * Exercises Brain_Controller::query()/stats() directly against a WP_REST_Request
 * stub, bypassing register_rest_route()/permission callbacks (not stubbed in
 * this suite — see RouterTest for the generic "every controller is
 * registerable" coverage). g2a-pos-core is never loaded here, so every call
 * flows through Brain_Client's degrade-to-inactive path; these tests assert
 * the controller's own param handling (required q, k clamping, scope
 * normalization) rather than a live brain response.
 */
class BrainControllerTest extends TestCase {
	public function test_query_requires_a_non_empty_q_param() {
		$controller = new Brain_Controller();
		$result     = $controller->query( new \WP_REST_Request( array( 'q' => '' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'g2aba_brain_query_empty', $result->code );
	}

	public function test_query_rejects_whitespace_only_q() {
		$controller = new Brain_Controller();
		$result     = $controller->query( new \WP_REST_Request( array( 'q' => '   ' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * @runInSeparateProcess
	 *
	 * Isolated because BrainClientTest may have eval()'d a fake
	 * \G2A\POS\Ai\BrainFacade elsewhere in this same suite run — a fresh
	 * process guarantees this test observes the real "g2a-pos-core not
	 * loaded" state, matching production when that plugin is inactive.
	 */
	public function test_query_degrades_to_inactive_brain_when_pos_core_absent() {
		$controller = new Brain_Controller();
		$response   = $controller->query( new \WP_REST_Request( array( 'q' => 'range hours' ) ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertFalse( $response->data['ok'] );
		$this->assertSame( array(), $response->data['results'] );
		$this->assertSame( 'pos_brain_inactive', $response->data['reason'] );
	}

	/** @runInSeparateProcess */
	public function test_stats_degrades_to_inactive_brain_when_pos_core_absent() {
		$controller = new Brain_Controller();
		$response   = $controller->stats( new \WP_REST_Request( array() ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertFalse( $response->data['ok'] );
		$this->assertSame( 'pos_brain_inactive', $response->data['reason'] );
	}

	public function test_ingest_requires_a_non_empty_label() {
		$controller = new Brain_Controller();
		$result     = $controller->ingest(
			new \WP_REST_Request(
				array(
					'label' => '',
					'body'  => 'A body long enough to pass.',
				)
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'g2aba_brain_ingest_invalid', $result->code );
	}

	public function test_ingest_rejects_whitespace_only_label() {
		$controller = new Brain_Controller();
		$result     = $controller->ingest(
			new \WP_REST_Request(
				array(
					'label' => '   ',
					'body'  => 'A body long enough to pass.',
				)
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'g2aba_brain_ingest_invalid', $result->code );
	}

	public function test_ingest_requires_a_non_empty_body() {
		$controller = new Brain_Controller();
		$result     = $controller->ingest(
			new \WP_REST_Request(
				array(
					'label' => 'Doc',
					'body'  => '',
				)
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'g2aba_brain_ingest_invalid', $result->code );
	}

	public function test_ingest_rejects_a_body_shorter_than_the_minimum_length() {
		$controller = new Brain_Controller();
		$result     = $controller->ingest(
			new \WP_REST_Request(
				array(
					'label' => 'Doc',
					'body'  => 'too short',
				)
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'g2aba_brain_ingest_invalid', $result->code );
	}

	/** @runInSeparateProcess */
	public function test_ingest_degrades_to_inactive_brain_when_pos_core_absent() {
		$controller = new Brain_Controller();
		$response   = $controller->ingest(
			new \WP_REST_Request(
				array(
					'label' => 'Doc',
					'body'  => 'A body long enough to pass.',
				)
			)
		);

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertFalse( $response->data['ok'] );
		$this->assertSame( array(), $response->data['results'] );
		$this->assertSame( 'pos_brain_inactive', $response->data['reason'] );
	}

	/**
	 * @runInSeparateProcess
	 *
	 * Defines the fake \G2A\POS\Ai\BrainFacade (same seam BrainClientTest
	 * uses) in a fresh process so this test observes the "g2a-pos-core is
	 * active" branch without leaking that class into any other test here.
	 */
	public function test_ingest_forwards_label_body_tags_and_scope_when_brain_is_available() {
		eval(
			'namespace G2A\POS\Ai;
			class BrainFacade {
				public static function ingest(string $label, string $body, array $opts = []): array {
					return ["ok" => true, "document_id" => 1, "chunks" => 1, "embedded" => false, "opts" => $opts];
				}
			}'
		);

		$controller = new Brain_Controller();
		$response   = $controller->ingest(
			new \WP_REST_Request(
				array(
					'label' => 'Doc',
					'body'  => 'A body long enough to pass.',
					'tags'  => 'faq,hours',
					'scope' => 'staff',
				)
			)
		);

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertTrue( $response->data['ok'] );
		$this->assertSame( 1, $response->data['results']['document_id'] );
		$this->assertSame(
			array(
				'tags'  => 'faq,hours',
				'scope' => 'staff',
			),
			$response->data['results']['opts']
		);
	}
}
