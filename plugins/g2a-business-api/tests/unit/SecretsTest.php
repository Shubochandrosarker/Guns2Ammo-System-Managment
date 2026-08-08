<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Secrets;

class SecretsTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['g2aba_test_options'] = array();
	}

	public function test_round_trip_recovers_plaintext() {
		Secrets::put( 'model:m1', 'sk-ant-super-secret-value-xyz' );
		$this->assertSame( 'sk-ant-super-secret-value-xyz', Secrets::get( 'model:m1' ) );
	}

	public function test_missing_key_returns_null() {
		$this->assertNull( Secrets::get( 'model:missing' ) );
	}

	public function test_forget_removes_key() {
		Secrets::put( 'model:m2', 'abc12345' );
		Secrets::forget( 'model:m2' );
		$this->assertFalse( Secrets::has( 'model:m2' ) );
		$this->assertNull( Secrets::get( 'model:m2' ) );
	}

	public function test_mask_preserves_prefix_and_suffix() {
		Secrets::put( 'model:m3', 'sk-1234567890abcdef' );
		$mask = Secrets::mask( 'model:m3' );
		$this->assertStringStartsWith( 'sk-1', $mask );
		$this->assertStringContainsString( '****', $mask );
		$this->assertStringEndsWith( 'cdef', $mask );
	}

	public function test_ciphertext_is_not_plaintext() {
		$packed = Secrets::encrypt( 'plaintext-value' );
		$this->assertStringNotContainsString( 'plaintext-value', $packed );
	}

	public function test_two_encryptions_of_the_same_plaintext_differ() {
		// Random IV should make each ciphertext unique.
		$this->assertNotSame(
			Secrets::encrypt( 'same' ),
			Secrets::encrypt( 'same' ),
			'Random IV must diversify the ciphertext.'
		);
	}
}
