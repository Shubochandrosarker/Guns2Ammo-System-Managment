<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Ops\Email_Draft_Store;
use WordPressistic\G2ABA\Ops\Email_Sender;
use WordPressistic\G2ABA\Ops\Opt_Out_Store;

class EmailSenderOptOutTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['g2aba_test_options']       = array();
		$GLOBALS['g2aba_test_wp_mail_calls'] = array();
		$GLOBALS['g2aba_test_wp_mail_return'] = true;
	}

	public function test_send_refuses_opted_out_recipient() {
		( new Opt_Out_Store() )->record( 'priya@example.com', 'user_requested' );

		$store = new Email_Draft_Store();
		$d     = $store->enqueue( 'x' );
		$d['to'] = 'PRIYA@example.com'; // Case-insensitive match must fire.

		$out = ( new Email_Sender() )->send( $d );

		$this->assertSame( Email_Draft_Store::STATUS_FAILED, $out['status'] );
		$this->assertStringContainsString( 'opted out', $out['error'] );
		$this->assertCount( 0, $GLOBALS['g2aba_test_wp_mail_calls'], 'wp_mail must NOT be called for opted-out recipients.' );

		$log = get_option( 'g2aba_audit_log', array() );
		$this->assertSame( 'email.suppressed_opt_out', $log[0]['kind'] );
	}

	public function test_send_adds_list_unsubscribe_header() {
		$store = new Email_Draft_Store();
		$d     = $store->enqueue( 'renewal' );
		$d['to'] = 'priya@example.com';

		( new Email_Sender() )->send( $d );

		$this->assertCount( 1, $GLOBALS['g2aba_test_wp_mail_calls'] );
		$headers = $GLOBALS['g2aba_test_wp_mail_calls'][0]['headers'];
		$joined  = implode( "\n", (array) $headers );
		$this->assertStringContainsString( 'List-Unsubscribe:', $joined );
		$this->assertStringContainsString( 'List-Unsubscribe-Post: List-Unsubscribe=One-Click', $joined );

		$body = $GLOBALS['g2aba_test_wp_mail_calls'][0]['body'];
		$this->assertStringContainsString( 'To stop receiving Guns2Ammo emails', $body );
	}
}
