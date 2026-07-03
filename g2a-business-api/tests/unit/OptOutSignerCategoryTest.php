<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Ops\Opt_Out_Signer;
use WordPressistic\G2ABA\Ops\Opt_Out_Store;

class OptOutSignerCategoryTest extends TestCase {
	public function test_category_is_bound_into_the_hmac() {
		$expires = time() + 3600;
		$marketing = Opt_Out_Signer::sign( 'priya@example.com', $expires, Opt_Out_Store::CATEGORY_MARKETING );
		$all       = Opt_Out_Signer::sign( 'priya@example.com', $expires, Opt_Out_Store::CATEGORY_ALL );

		$this->assertNotSame( $marketing, $all, 'Different categories must produce different tokens.' );

		// Marketing token verifies as marketing, not as all.
		$this->assertTrue(
			Opt_Out_Signer::verify( 'priya@example.com', $expires, $marketing, Opt_Out_Store::CATEGORY_MARKETING )['ok']
		);
		$this->assertFalse(
			Opt_Out_Signer::verify( 'priya@example.com', $expires, $marketing, Opt_Out_Store::CATEGORY_ALL )['ok'],
			'A marketing token must not verify as `all`.'
		);
	}

	public function test_unknown_category_rejected() {
		$expires = time() + 3600;
		$out = Opt_Out_Signer::verify( 'x@example.com', $expires, 'anything', 'not-a-cat' );
		$this->assertFalse( $out['ok'] );
		$this->assertSame( 'invalid_category', $out['error'] );
	}

	public function test_make_link_includes_category_param() {
		$link = Opt_Out_Signer::make_link( 'priya@example.com', Opt_Out_Store::CATEGORY_MARKETING, 'https://guns2ammo.com/opt-out' );
		$this->assertStringContainsString( 'category=marketing', $link );
	}
}
