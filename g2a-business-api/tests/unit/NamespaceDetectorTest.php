<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\System\Namespace_Detector;

class NamespaceDetectorTest extends TestCase {
	public function test_all_false_when_nothing_registered() {
		$this->assertSame(
			array(
				'rankMath'    => false,
				'redirection' => false,
				'elementor'   => false,
				'wooCommerce' => false,
			),
			Namespace_Detector::detect( array( 'wp/v2', 'g2a/v1', 'g2a-pos/v1' ) )
		);
	}

	public function test_detects_rank_math() {
		$out = Namespace_Detector::detect( array( 'wp/v2', 'rankmath/v1' ) );
		$this->assertTrue( $out['rankMath'] );
		$this->assertFalse( $out['redirection'] );
	}

	public function test_detects_redirection() {
		$out = Namespace_Detector::detect( array( 'wp/v2', 'redirection/v1' ) );
		$this->assertTrue( $out['redirection'] );
	}

	public function test_detects_elementor_by_prefix() {
		$this->assertTrue( Namespace_Detector::detect( array( 'elementor/v1' ) )['elementor'] );
		$this->assertTrue( Namespace_Detector::detect( array( 'elementor-pro/v1' ) )['elementor'] );
	}

	public function test_detects_woocommerce() {
		$out = Namespace_Detector::detect( array( 'wc/v3' ) );
		$this->assertTrue( $out['wooCommerce'] );
	}

	public function test_does_not_false_positive_on_similar_names() {
		// `g2a/v1` itself must never be mistaken for any of these.
		$out = Namespace_Detector::detect( array( 'g2a/v1', 'g2a-booking/v1', 'wc-blocks/v1' ) );
		$this->assertFalse( $out['wooCommerce'] );
		$this->assertFalse( $out['rankMath'] );
		$this->assertFalse( $out['redirection'] );
		$this->assertFalse( $out['elementor'] );
	}

	public function test_detects_everything_at_once() {
		$out = Namespace_Detector::detect(
			array( 'wp/v2', 'g2a/v1', 'rankmath/v1', 'redirection/v1', 'elementor/v1', 'wc/v3' )
		);
		$this->assertSame(
			array(
				'rankMath'    => true,
				'redirection' => true,
				'elementor'   => true,
				'wooCommerce' => true,
			),
			$out
		);
	}
}
