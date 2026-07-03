<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Integrations\Google_JWT_Signer;

class GoogleJwtSignerTest extends TestCase {
	public function test_claims_for_shape() {
		$signer = new Google_JWT_Signer();
		$claims = $signer->claims_for(
			'sa@example.iam.gserviceaccount.com',
			'https://www.googleapis.com/auth/webmasters.readonly',
			1_700_000_000,
			3600
		);
		$this->assertSame( 'sa@example.iam.gserviceaccount.com', $claims['iss'] );
		$this->assertSame( 'https://www.googleapis.com/auth/webmasters.readonly', $claims['scope'] );
		$this->assertSame( 'https://oauth2.googleapis.com/token', $claims['aud'] );
		$this->assertSame( 1_700_000_000, $claims['iat'] );
		$this->assertSame( 1_700_003_600, $claims['exp'] );
	}

	public function test_claims_for_clamps_short_ttl() {
		$signer = new Google_JWT_Signer();
		$claims = $signer->claims_for( 'sa', 'scope', 100, 10 );
		// TTL below 60 gets clamped so we don't accidentally mint expired tokens.
		$this->assertSame( 160, $claims['exp'] );
	}

	public function test_b64url_matches_jwt_spec() {
		// RFC 7515 §2: base64url is unpadded, `+` → `-`, `/` → `_`.
		$this->assertSame( 'AQID', Google_JWT_Signer::b64url( "\x01\x02\x03" ) );
		$this->assertSame( '-_8', Google_JWT_Signer::b64url( "\xfb\xff" ) );
	}

	public function test_sign_produces_three_dot_separated_segments() {
		[$pem] = $this->generate_rsa();
		$signer = new Google_JWT_Signer();
		$jwt    = $signer->sign(
			$signer->claims_for( 'sa@example.iam.gserviceaccount.com', 'scope', 1_700_000_000 ),
			$pem
		);
		$parts = explode( '.', $jwt );
		$this->assertCount( 3, $parts );
		foreach ( $parts as $p ) {
			$this->assertNotEmpty( $p );
			$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]+$/', $p );
		}
	}

	public function test_signature_verifies_against_the_public_key() {
		[$pem, $pub] = $this->generate_rsa();
		$signer      = new Google_JWT_Signer();
		$jwt         = $signer->sign(
			$signer->claims_for( 'sa', 'scope', 1_700_000_000 ),
			$pem
		);

		[$h, $p, $s] = explode( '.', $jwt );
		$signature   = self::b64url_decode( $s );
		$ok          = openssl_verify( $h . '.' . $p, $signature, $pub, OPENSSL_ALGO_SHA256 );
		$this->assertSame( 1, $ok, 'Signed JWT must verify against the matching public key.' );
	}

	public function test_sign_throws_on_bad_pem() {
		$signer = new Google_JWT_Signer();
		$this->expectException( \RuntimeException::class );
		$signer->sign( array( 'x' => 1 ), 'not a real PEM' );
	}

	/**
	 * @return array{0:string,1:string}
	 */
	private function generate_rsa(): array {
		$key = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		openssl_pkey_export( $key, $priv );
		$pub = openssl_pkey_get_details( $key )['key'];
		return array( $priv, $pub );
	}

	private static function b64url_decode( string $s ): string {
		$padded = strtr( $s, '-_', '+/' );
		$pad    = strlen( $padded ) % 4;
		if ( $pad ) {
			$padded .= str_repeat( '=', 4 - $pad );
		}
		return base64_decode( $padded ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- test.
	}
}
