<?php
/**
 * Referral code format.
 *
 * @package G2AR
 */

declare( strict_types=1 );

namespace WordPressistic\G2AReferrals\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2AReferrals\Codes;

final class CodesTest extends TestCase {

	public function test_generated_codes_use_the_crockford_alphabet(): void {
		for ( $i = 0; $i < 200; $i++ ) {
			$code = Codes::generate();

			$this->assertMatchesRegularExpression( '/^G2A-[0-9A-Z]{6}$/', $code );

			$body = substr( $code, 4 );
			$this->assertSame( 6, strspn( $body, Codes::ALPHABET ), 'Codes must stay inside the Crockford alphabet.' );

			// I, L, O and U are excluded so staff can read a code aloud at
			// the counter without it being heard as 1, 0 or something worse.
			foreach ( array( 'I', 'L', 'O', 'U' ) as $banned ) {
				$this->assertStringNotContainsString( $banned, $body );
			}
		}
	}

	public function test_generated_codes_are_effectively_unique(): void {
		$seen = array();

		for ( $i = 0; $i < 2000; $i++ ) {
			$seen[ Codes::generate() ] = true;
		}

		// 32^6 keyspace against 2,000 draws: a collision here would mean the
		// generator is not actually random, not that we got unlucky.
		$this->assertGreaterThan( 1990, count( $seen ) );
	}

	/**
	 * @dataProvider provideNormalisable
	 */
	public function test_normalize_accepts_what_a_human_would_type( string $input, string $expected ): void {
		$this->assertSame( $expected, Codes::normalize( $input ) );
	}

	public static function provideNormalisable(): array {
		return array(
			'canonical'          => array( 'G2A-X1Y2Z3', 'G2A-X1Y2Z3' ),
			'lower case'         => array( 'g2a-x1y2z3', 'G2A-X1Y2Z3' ),
			'no prefix'          => array( 'X1Y2Z3', 'G2A-X1Y2Z3' ),
			'spaces'             => array( '  G2A X1Y2Z3 ', 'G2A-X1Y2Z3' ),
			// Crockford substitutions: someone read it aloud and the letter
			// was heard as the digit.
			'I heard as 1'       => array( 'G2A-XIY2Z3', 'G2A-X1Y2Z3' ),
			'L heard as 1'       => array( 'G2A-XLY2Z3', 'G2A-X1Y2Z3' ),
			'O heard as 0'       => array( 'G2A-X1Y2Z0', 'G2A-X1Y2Z0' ),
			'O letter to zero'   => array( 'G2A-X1Y2ZO', 'G2A-X1Y2Z0' ),
		);
	}

	/**
	 * @dataProvider provideRejected
	 */
	public function test_normalize_rejects_malformed_input( string $input ): void {
		$this->assertSame( '', Codes::normalize( $input ) );
		$this->assertFalse( Codes::is_valid_format( $input ) );
	}

	public static function provideRejected(): array {
		return array(
			'empty'      => array( '' ),
			'too short'  => array( 'G2A-X1Y2' ),
			'too long'   => array( 'G2A-X1Y2Z34' ),
			'punctuated' => array( 'G2A-X1Y2Z!' ),
			'U excluded' => array( 'G2A-X1Y2ZU' ),
		);
	}

	public function test_share_url_carries_the_code(): void {
		$this->assertStringContainsString( 'ref=G2A-X1Y2Z3', Codes::share_url( 'G2A-X1Y2Z3' ) );
	}
}
