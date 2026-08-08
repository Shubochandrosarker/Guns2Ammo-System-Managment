<?php
/**
 * Contract tests for the request idempotency fingerprint.
 *
 * G2AB_REST_Bookings_Controller::booking_idempotency_key() is private, so the
 * CONTRACT is asserted via reflection on the real method (the controller file
 * is required by tests/bootstrap.php purely for its class definition):
 *
 *   - same material inputs           → same key (an exact retry replays)
 *   - any changed material value     → different key (a new request)
 *   - a different actor scope        → different key (a colliding key can
 *                                       never hand one customer another
 *                                       customer's booking)
 *
 * The method mixes in a coarse ~10-minute time bucket; the helper below
 * guards against the (rare) case of the bucket rolling over mid-test.
 */

use PHPUnit\Framework\TestCase;

final class IdempotencyFingerprintTest extends TestCase {

	private static function key( array $parts ): string {
		$controller = new G2AB_REST_Bookings_Controller();
		$method     = new ReflectionMethod( G2AB_REST_Bookings_Controller::class, 'booking_idempotency_key' );
		$method->setAccessible( true );
		return (string) $method->invoke( $controller, $parts );
	}

	/**
	 * Compute two keys inside one stable time bucket (retry once if the
	 * 600-second bucket boundary was crossed between the two calls).
	 */
	private static function keyPairInSameBucket( array $a, array $b ): array {
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$bucket_before = (int) floor( time() / 600 );
			$key_a         = self::key( $a );
			$key_b         = self::key( $b );
			$bucket_after  = (int) floor( time() / 600 );
			if ( $bucket_before === $bucket_after ) {
				return array( $key_a, $key_b );
			}
		}
		return array( $key_a, $key_b );
	}

	private function baseParts(): array {
		return array(
			'scope'      => 7,
			'kind'       => 'lane',
			'type'       => 12,
			'resource'   => 3,
			'start'      => '2026-08-09 10:00:00',
			'party_size' => 2,
			'seats'      => 2,
			'gateway'    => 'stripe',
			'amount'     => '80.00',
		);
	}

	public function testSameInputsProduceSameKey(): void {
		list( $a, $b ) = self::keyPairInSameBucket( $this->baseParts(), $this->baseParts() );
		$this->assertSame( $a, $b );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $a, 'Key must be sha256 hex.' );
	}

	public function testKeyIsOrderInsensitive(): void {
		$shuffled = $this->baseParts();
		krsort( $shuffled );
		list( $a, $b ) = self::keyPairInSameBucket( $this->baseParts(), $shuffled );
		$this->assertSame( $a, $b, 'ksort() inside the fingerprint must make part order immaterial.' );
	}

	/**
	 * @dataProvider changedMaterialValueProvider
	 */
	public function testChangedMaterialValueProducesDifferentKey( string $part, $new_value ): void {
		$changed          = $this->baseParts();
		$changed[ $part ] = $new_value;

		list( $a, $b ) = self::keyPairInSameBucket( $this->baseParts(), $changed );
		$this->assertNotSame( $a, $b, "Changing '{$part}' must produce a NEW idempotency key." );
	}

	public static function changedMaterialValueProvider(): array {
		return array(
			'party_size' => array( 'party_size', 4 ),
			'seats'      => array( 'seats', 5 ),
			'gateway'    => array( 'gateway', 'paypal' ),
			'amount'     => array( 'amount', '120.00' ),
			'start'      => array( 'start', '2026-08-09 11:00:00' ),
			'resource'   => array( 'resource', 9 ),
		);
	}

	public function testDifferentActorScopeProducesDifferentKey(): void {
		$member = $this->baseParts();          // scope = user id 7
		$guest  = $this->baseParts();
		$guest['scope'] = hash( 'sha256', '203.0.113.9' ) . '|guest@example.com';

		list( $a, $b ) = self::keyPairInSameBucket( $member, $guest );
		$this->assertNotSame( $a, $b, 'Actor scope keys the attempt to the requester.' );

		$other_member          = $this->baseParts();
		$other_member['scope'] = 8;
		list( $a, $c ) = self::keyPairInSameBucket( $member, $other_member );
		$this->assertNotSame( $a, $c, 'Two different member ids must never share a fingerprint.' );
	}
}
