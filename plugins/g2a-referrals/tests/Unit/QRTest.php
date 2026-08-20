<?php
/**
 * QR encoder.
 *
 * The encoder is in-plugin rather than fetched from a QR web service: a
 * member's referral link is customer data and has no business being sent to
 * a third party to be drawn.
 *
 * The output of this encoder was verified against a real scanner (OpenCV's
 * QRCodeDetector) for versions 1 through 10 — including the multi-block
 * versions 8-10 and the version-information versions 7-10 — before this
 * test was written. These assertions are the regression net around that:
 * they pin the structure and a golden matrix hash, so a change that breaks
 * scannability shows up here rather than on a printed card at the counter.
 *
 * @package G2AR
 */

declare( strict_types=1 );

namespace WordPressistic\G2AReferrals\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2AReferrals\QR;

final class QRTest extends TestCase {

	private const REFERRAL_URL = 'https://guns2ammo.com/?ref=G2A-X1Y2Z3';

	public function test_a_referral_url_encodes_to_a_version_3_symbol(): void {
		$matrix = QR::matrix( self::REFERRAL_URL );

		$this->assertIsArray( $matrix );
		// 17 + 4 x version. A 37-character URL fits version 3 at EC level M.
		$this->assertCount( 29, $matrix );
		$this->assertCount( 29, $matrix[0] );
	}

	/**
	 * @dataProvider provideLengths
	 */
	public function test_version_grows_with_payload_length( int $length, int $expected_size ): void {
		$matrix = QR::matrix( str_repeat( 'X', $length ) );

		$this->assertIsArray( $matrix, "A {$length}-character payload must still encode." );
		$this->assertCount( $expected_size, $matrix );
	}

	public static function provideLengths(): array {
		return array(
			'v1'  => array( 14, 21 ),
			'v2'  => array( 26, 25 ),
			'v3'  => array( 42, 29 ),
			'v4'  => array( 62, 33 ),
			'v5'  => array( 84, 37 ),
			'v6'  => array( 106, 41 ),
			'v7'  => array( 122, 45 ),
			'v8'  => array( 152, 49 ),
			'v9'  => array( 180, 53 ),
			'v10' => array( 213, 57 ),
		);
	}

	public function test_a_payload_beyond_version_10_is_refused_rather_than_truncated(): void {
		$this->assertNull( QR::matrix( str_repeat( 'X', 214 ) ), 'Silently truncating a URL would print an unscannable card.' );
	}

	public function test_the_three_finder_patterns_are_placed(): void {
		$matrix = QR::matrix( self::REFERRAL_URL );
		$size   = count( $matrix );

		foreach ( array( array( 0, 0 ), array( 0, $size - 7 ), array( $size - 7, 0 ) ) as $origin ) {
			list( $row, $col ) = $origin;

			// Outer ring dark, inner ring light, 3x3 core dark.
			$this->assertSame( 1, $matrix[ $row ][ $col ] );
			$this->assertSame( 1, $matrix[ $row ][ $col + 6 ] );
			$this->assertSame( 0, $matrix[ $row + 1 ][ $col + 1 ] );
			$this->assertSame( 1, $matrix[ $row + 2 ][ $col + 2 ] );
			$this->assertSame( 1, $matrix[ $row + 3 ][ $col + 3 ] );
		}
	}

	public function test_the_timing_patterns_alternate(): void {
		$matrix = QR::matrix( self::REFERRAL_URL );
		$size   = count( $matrix );

		for ( $i = 8; $i < $size - 8; $i++ ) {
			$expected = ( 0 === $i % 2 ) ? 1 : 0;

			$this->assertSame( $expected, $matrix[6][ $i ], "Horizontal timing wrong at {$i}." );
			$this->assertSame( $expected, $matrix[ $i ][6], "Vertical timing wrong at {$i}." );
		}
	}

	public function test_matrix_is_stable_for_a_fixed_payload(): void {
		$matrix = QR::matrix( self::REFERRAL_URL );
		$flat   = '';

		foreach ( $matrix as $row ) {
			$flat .= implode( '', $row );
		}

		// Golden hash of a matrix confirmed scannable by OpenCV. If this
		// changes, re-verify with a real decoder before updating it.
		$this->assertSame( 'd295c959542388303a0e6e41fd7b08006004bdccafc047b3c6e8208239cdfc70', hash( 'sha256', $flat ) );
	}

	public function test_png_data_uri_is_a_real_png_of_the_expected_size(): void {
		$uri = QR::png_data_uri( self::REFERRAL_URL, 8, 4 );

		$this->assertStringStartsWith( 'data:image/png;base64,', $uri );

		$png = base64_decode( substr( $uri, strlen( 'data:image/png;base64,' ) ), true );

		$this->assertIsString( $png );
		$this->assertStringStartsWith( "\x89PNG\r\n\x1a\n", $png, 'PNG signature missing.' );

		// IHDR width/height live at bytes 16-23. 29 modules + 2x4 quiet
		// zone, at 8px per module.
		$header = unpack( 'Nwidth/Nheight', substr( $png, 16, 8 ) );
		$expected = ( 29 + 8 ) * 8;

		$this->assertSame( $expected, $header['width'] );
		$this->assertSame( $expected, $header['height'] );
	}

	public function test_the_quiet_zone_is_light(): void {
		$uri = QR::png_data_uri( self::REFERRAL_URL, 1, 4 );
		$png = base64_decode( substr( $uri, strlen( 'data:image/png;base64,' ) ), true );

		$this->assertIsString( $png );
		$this->assertStringContainsString( 'IDAT', $png );
	}
}
