<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Response_Envelope;

class ResponseEnvelopeTest extends TestCase {
	private const UUID4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
	private const ISO   = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

	protected function setUp(): void {
		unset( $GLOBALS['g2aba_test_timezone_string'] );
	}

	public function test_success_envelope_shape() {
		$GLOBALS['g2aba_test_timezone_string'] = 'America/Phoenix';

		$env = Response_Envelope::success( array( 'x' => 1 ), array( 'g2a-booking-engine', 'woocommerce' ) );

		$this->assertTrue( $env['success'] );
		$this->assertSame( array( 'x' => 1 ), $env['data'] );

		$meta = $env['meta'];
		$this->assertSame( array( 'requestId', 'generatedAt', 'timezone', 'currency', 'source', 'freshness' ), array_keys( $meta ) );
		$this->assertMatchesRegularExpression( self::UUID4, $meta['requestId'] );
		$this->assertMatchesRegularExpression( self::ISO, $meta['generatedAt'] );
		$this->assertSame( 'America/Phoenix', $meta['timezone'] );
		$this->assertSame( 'USD', $meta['currency'] );
		$this->assertSame( array( 'g2a-booking-engine', 'woocommerce' ), $meta['source'] );
		$this->assertSame( 'fresh', $meta['freshness']['status'] );
		$this->assertMatchesRegularExpression( self::ISO, $meta['freshness']['lastUpdatedAt'] );
	}

	public function test_success_respects_explicit_freshness() {
		$env = Response_Envelope::success(
			array(),
			array(),
			array( 'status' => 'unavailable', 'lastUpdatedAt' => null )
		);
		$this->assertSame( 'unavailable', $env['meta']['freshness']['status'] );
		$this->assertNull( $env['meta']['freshness']['lastUpdatedAt'] );
	}

	public function test_error_envelope_shape_and_status() {
		$res = Response_Envelope::error_response( 'invalid_date_format', 'Bad date.', 400 );

		$this->assertInstanceOf( \WP_REST_Response::class, $res );
		$this->assertSame( 400, $res->status );

		$body = $res->data;
		$this->assertFalse( $body['success'] );
		$this->assertSame( 'invalid_date_format', $body['error']['code'] );
		$this->assertSame( 'Bad date.', $body['error']['message'] );
		$this->assertMatchesRegularExpression( self::UUID4, $body['error']['requestId'] );
		$this->assertArrayNotHasKey( 'data', $body );
	}

	public function test_request_ids_are_unique() {
		$this->assertNotSame( Response_Envelope::request_id(), Response_Envelope::request_id() );
	}

	public function test_combine_freshness_rollup() {
		$fresh = array( 'status' => 'fresh', 'lastUpdatedAt' => '2026-07-01T00:00:00Z' );
		$later = array( 'status' => 'fresh', 'lastUpdatedAt' => '2026-07-10T00:00:00Z' );
		$una   = array( 'status' => 'unavailable', 'lastUpdatedAt' => null );

		$all_fresh = Response_Envelope::combine_freshness( array( $fresh, $later ) );
		$this->assertSame( 'fresh', $all_fresh['status'] );
		$this->assertSame( '2026-07-10T00:00:00Z', $all_fresh['lastUpdatedAt'] );

		$mixed = Response_Envelope::combine_freshness( array( $fresh, $una ) );
		$this->assertSame( 'stale', $mixed['status'] );

		$none = Response_Envelope::combine_freshness( array( $una, $una ) );
		$this->assertSame( 'unavailable', $none['status'] );
		$this->assertNull( $none['lastUpdatedAt'] );

		$empty = Response_Envelope::combine_freshness( array() );
		$this->assertSame( 'unavailable', $empty['status'] );
	}
}
