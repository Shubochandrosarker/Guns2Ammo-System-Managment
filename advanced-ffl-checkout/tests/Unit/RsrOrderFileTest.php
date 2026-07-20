<?php

namespace WpisticFFL\Tests\Unit;

use Brain\Monkey\Functions;
use WpisticFFL\G2A_Rsr;

require_once dirname( __DIR__, 2 ) . '/includes/class-wpistic-ffl-g2a-rsr.php';

/**
 * RSR has no REST/SOAP API -- both catalog parsing and order-file
 * construction are pure string manipulation confirmed against the actual
 * source of github.com/ammoready/rsr_group (see G2A_Rsr's own docblock).
 * These tests verify that pure logic directly, without a live FTP account.
 */
final class RsrOrderFileTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	private function sample_transfer(): object {
		return (object) [
			'transfer_ref'   => 'G2A-ABC12345',
			'business_name'  => 'Test Gun Shop',
			'premise_street' => '123 Main St',
			'premise_city'   => 'Springfield',
			'premise_state'  => 'IL',
			'premise_zip'    => '62704',
			'dealer_phone'   => '(217) 555-0100',
			'license_number' => '1-23-456-78-9A-01234',
			'customer_name'  => 'Jane Buyer',
		];
	}

	/**
	 * gmdate() is a PHP internal, not redefinable by Brain Monkey/Patchwork
	 * without a project-wide config change, so the file's embedded
	 * timestamp is asserted structurally (8 digits) rather than pinned to
	 * a mocked date -- everything else in the format is asserted exactly.
	 */
	public function test_build_order_file_produces_the_confirmed_line_type_sequence(): void {
		$built = G2A_Rsr::build_order_file( $this->sample_transfer(), 'GLOCK19', 2, 'UPS', 'GROUND', '456', 7 );

		$this->assertMatchesRegularExpression( '/^EORD-00456-\d{8}-0007\.txt$/', $built['filename'] );

		$lines = explode( "\r\n", rtrim( $built['contents'], "\r\n" ) );
		$this->assertCount( 6, $lines );
		$this->assertMatchesRegularExpression( '/^FILEHEADER;00;00456;\d{8};0007$/', $lines[0] );
		$this->assertSame( 'G2A-ABC12345;10;Test Gun Shop;;123 Main St;;Springfield;IL;62704;2175550100;N;;;', $lines[1] );
		$this->assertSame( 'G2A-ABC12345;11;1-23-456-78-9A-01234;Test Gun Shop;62704;Jane Buyer;2175550100', $lines[2] );
		$this->assertSame( 'G2A-ABC12345;20;GLOCK19;2;UPS;GROUND', $lines[3] );
		$this->assertSame( 'G2A-ABC12345;90;0000002', $lines[4] );
		$this->assertSame( 'FILETRAILER;99;00001', $lines[5] );
	}

	public function test_build_order_file_pads_merchant_and_sequence_numbers(): void {
		$built = G2A_Rsr::build_order_file( $this->sample_transfer(), 'X', 1, 'UPS', 'GROUND', '7', 42 );

		$this->assertMatchesRegularExpression( '/^EORD-00007-\d{8}-0042\.txt$/', $built['filename'] );
	}

	public function test_parse_catalog_line_extracts_the_confirmed_field_positions(): void {
		$line = implode( ';', [
			'RSR-STOCK-1',       // 0: stock number
			'012345678905',      // 1: UPC
			'A test firearm',    // 2: description
			'1',                 // 3: department number
			'MFG01',             // 4: manufacturer ID
			'999.99',            // 5: retail price
			'650.00',            // 6: RSR (dealer) price
			'48',                // 7: weight oz
			'12',                // 8: quantity
			'MODEL-X',           // 9: model
			'Acme Firearms Co.', // 10: full manufacturer name
			'MFG-PART-1',        // 11: manufacturer part number
			'',                  // 12: allocated/closeout/deleted flag
			'An expanded description', // 13
			'image.jpg',         // 14: image name
		] );

		$item = G2A_Rsr::parse_catalog_line( $line );

		$this->assertNotNull( $item );
		$this->assertSame( 'RSR-STOCK-1', $item['item_no'] );
		$this->assertSame( '012345678905', $item['upc'] );
		$this->assertSame( 'A test firearm', $item['description'] );
		$this->assertSame( 'Acme Firearms Co.', $item['manufacturer'] );
		$this->assertSame( 'MODEL-X', $item['model'] );
		$this->assertSame( 650.00, $item['price'] );
		$this->assertSame( 12, $item['quantity'] );
		$this->assertTrue( $item['is_firearm'], 'Department 1 is in the firearm-department set.' );
	}

	public function test_parse_catalog_line_returns_null_for_a_short_or_blank_line(): void {
		$this->assertNull( G2A_Rsr::parse_catalog_line( '' ) );
		$this->assertNull( G2A_Rsr::parse_catalog_line( 'too;few;fields' ) );
	}
}
