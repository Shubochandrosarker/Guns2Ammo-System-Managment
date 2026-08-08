<?php
/**
 * Unit tests for G2AB_Email_Actions token issue/validate
 * (includes/services/class-email-actions.php).
 *
 * Only the pure token layer is exercised — the template_redirect endpoint
 * handlers touch the database and rendering and are not unit-tested here.
 */

use PHPUnit\Framework\TestCase;

final class EmailActionsTest extends TestCase {

	private const UUID = '123e4567-e89b-42d3-a456-426614174000';

	protected function setUp(): void {
		g2ab_tests_reset_state();
	}

	public static function actionProvider(): array {
		return array(
			'pay'        => array( G2AB_Email_Actions::ACTION_PAY ),
			'view'       => array( G2AB_Email_Actions::ACTION_VIEW ),
			'cancel'     => array( G2AB_Email_Actions::ACTION_CANCEL ),
			'reschedule' => array( G2AB_Email_Actions::ACTION_RESCHEDULE ),
		);
	}

	/**
	 * @dataProvider actionProvider
	 */
	public function testIssueValidateRoundTripPerAction( string $action ): void {
		$token = G2AB_Email_Actions::issue( self::UUID, $action );

		$this->assertNotSame( '', $token );
		$this->assertStringStartsWith( 'v1.', $token );

		$result = G2AB_Email_Actions::validate( $token, $action );
		$this->assertIsArray( $result );
		$this->assertSame( self::UUID, $result['uuid'] );
	}

	public function testWrongPurposeTokenIsRejected(): void {
		$token  = G2AB_Email_Actions::issue( self::UUID, G2AB_Email_Actions::ACTION_PAY );
		$result = G2AB_Email_Actions::validate( $token, G2AB_Email_Actions::ACTION_CANCEL );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'g2ab_wrong_purpose', $result->get_error_code() );
	}

	public function testExpiredTokenIsRejected(): void {
		// Freeze/expire: force a negative TTL so the token is born expired.
		add_filter( 'g2ab_email_action_ttl', static fn() => -10 );

		$token  = G2AB_Email_Actions::issue( self::UUID, G2AB_Email_Actions::ACTION_VIEW );
		$result = G2AB_Email_Actions::validate( $token, G2AB_Email_Actions::ACTION_VIEW );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'g2ab_token_expired', $result->get_error_code() );
	}

	public function testVersionBumpRevokesIssuedTokens(): void {
		$token = G2AB_Email_Actions::issue( self::UUID, G2AB_Email_Actions::ACTION_CANCEL );
		$this->assertIsArray( G2AB_Email_Actions::validate( $token, G2AB_Email_Actions::ACTION_CANCEL ) );

		update_option( 'g2ab_action_token_version', 2 );

		$result = G2AB_Email_Actions::validate( $token, G2AB_Email_Actions::ACTION_CANCEL );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'g2ab_token_revoked', $result->get_error_code() );
	}

	public function testTamperedPayloadIsRejected(): void {
		$token = G2AB_Email_Actions::issue( self::UUID, G2AB_Email_Actions::ACTION_VIEW );
		$parts = explode( '.', $token );

		// Swap the booking UUID inside the payload without re-signing.
		$payload      = json_decode( base64_decode( strtr( $parts[1], '-_', '+/' ) ), true );
		$payload['u'] = 'ffffffff-ffff-4fff-afff-ffffffffffff';
		$parts[1]     = rtrim( strtr( base64_encode( json_encode( $payload ) ), '+/', '-_' ), '=' );
		$tampered     = implode( '.', $parts );

		$result = G2AB_Email_Actions::validate( $tampered, G2AB_Email_Actions::ACTION_VIEW );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'g2ab_bad_token', $result->get_error_code() );
	}

	public function testTamperedMacIsRejected(): void {
		$token = G2AB_Email_Actions::issue( self::UUID, G2AB_Email_Actions::ACTION_VIEW );
		$parts = explode( '.', $token );

		$parts[2] = strrev( $parts[2] );
		if ( implode( '.', $parts ) === $token ) { // palindromic mac — flip a char instead.
			$parts[2][0] = '0' === $parts[2][0] ? '1' : '0';
		}

		$result = G2AB_Email_Actions::validate( implode( '.', $parts ), G2AB_Email_Actions::ACTION_VIEW );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'g2ab_bad_token', $result->get_error_code() );
	}

	public function testMalformedTokenIsRejected(): void {
		foreach ( array( '', 'v1.only-two', 'v2.a.b', 'a.b.c.d' ) as $bad ) {
			$result = G2AB_Email_Actions::validate( $bad, G2AB_Email_Actions::ACTION_VIEW );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'g2ab_bad_token', $result->get_error_code() );
		}
	}

	public function testIssueRejectsInvalidUuid(): void {
		$this->assertSame( '', G2AB_Email_Actions::issue( 'not-a-uuid', G2AB_Email_Actions::ACTION_PAY ) );
	}
}
