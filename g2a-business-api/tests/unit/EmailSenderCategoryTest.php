<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Ops\Email_Draft_Store;
use WordPressistic\G2ABA\Ops\Email_Sender;
use WordPressistic\G2ABA\Ops\Opt_Out_Store;

class EmailSenderCategoryTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['g2aba_test_options']        = array();
		$GLOBALS['g2aba_test_wp_mail_calls']  = array();
		$GLOBALS['g2aba_test_wp_mail_return'] = true;
	}

	public function test_internal_category_bypasses_opt_out_gate() {
		// Even if the address is opted out of `all`, an internal-category
		// staff email should still be delivered.
		( new Opt_Out_Store() )->record( 'staff@guns2ammo.com', Opt_Out_Store::CATEGORY_ALL );

		$store = new Email_Draft_Store();
		$d     = $store->enqueue( 'Weekly report', 'staff@guns2ammo.com', 'Weekly report', 'body', Opt_Out_Store::CATEGORY_INTERNAL );
		$out   = ( new Email_Sender() )->send( $d );

		$this->assertSame( Email_Draft_Store::STATUS_SENT, $out['status'] );
		$this->assertCount( 1, $GLOBALS['g2aba_test_wp_mail_calls'] );
	}

	public function test_marketing_opt_out_does_not_block_renewals() {
		( new Opt_Out_Store() )->record( 'x@example.com', Opt_Out_Store::CATEGORY_MARKETING );

		$store = new Email_Draft_Store();
		$renewals = $store->enqueue( 'Renewal', 'x@example.com', 'Renewal', 'body', Opt_Out_Store::CATEGORY_RENEWALS );
		$out      = ( new Email_Sender() )->send( $renewals );

		$this->assertSame( Email_Draft_Store::STATUS_SENT, $out['status'], 'Renewals should go through when only marketing is opted out.' );
	}

	public function test_marketing_opt_out_blocks_marketing() {
		( new Opt_Out_Store() )->record( 'x@example.com', Opt_Out_Store::CATEGORY_MARKETING );

		$store    = new Email_Draft_Store();
		$marketing = $store->enqueue( 'Promo', 'x@example.com', 'Promo', 'body', Opt_Out_Store::CATEGORY_MARKETING );
		$out       = ( new Email_Sender() )->send( $marketing );

		$this->assertSame( Email_Draft_Store::STATUS_FAILED, $out['status'] );
		$this->assertStringContainsString( 'marketing', $out['error'] );
	}

	public function test_internal_send_omits_unsubscribe_headers() {
		$store = new Email_Draft_Store();
		$d     = $store->enqueue( 'Weekly report', 'staff@guns2ammo.com', 'Report', 'body', Opt_Out_Store::CATEGORY_INTERNAL );
		( new Email_Sender() )->send( $d );

		$headers = implode( "\n", (array) $GLOBALS['g2aba_test_wp_mail_calls'][0]['headers'] );
		$this->assertStringNotContainsString( 'List-Unsubscribe', $headers );
		$this->assertStringNotContainsString( 'To stop receiving', $GLOBALS['g2aba_test_wp_mail_calls'][0]['body'] );
	}

	public function test_customer_send_includes_category_specific_link() {
		$store = new Email_Draft_Store();
		$d     = $store->enqueue( 'Renewal', 'x@example.com', 'Renewal', 'body', Opt_Out_Store::CATEGORY_RENEWALS );
		( new Email_Sender() )->send( $d );

		$headers = implode( "\n", (array) $GLOBALS['g2aba_test_wp_mail_calls'][0]['headers'] );
		$this->assertStringContainsString( 'List-Unsubscribe:', $headers );
		$this->assertStringContainsString( 'category=renewals', $headers );
	}
}
