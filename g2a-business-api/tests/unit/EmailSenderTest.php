<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Ops\Email_Draft_Store;
use WordPressistic\G2ABA\Ops\Email_Sender;

class EmailSenderTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['g2aba_test_options']      = array();
		$GLOBALS['g2aba_test_wp_mail_calls'] = array();
		$GLOBALS['g2aba_test_wp_mail_return'] = true;
	}

	public function test_send_uses_wp_mail_and_marks_sent() {
		$store = new Email_Draft_Store();
		$d     = $store->enqueue( 'send follow-up email to Priya' );
		$d['to'] = 'priya@example.com';

		$out = ( new Email_Sender() )->send( $d );

		$this->assertSame( Email_Draft_Store::STATUS_SENT, $out['status'] );
		$this->assertCount( 1, $GLOBALS['g2aba_test_wp_mail_calls'] );
		$this->assertSame( 'priya@example.com', $GLOBALS['g2aba_test_wp_mail_calls'][0]['to'] );

		$log = get_option( 'g2aba_audit_log', array() );
		$this->assertNotEmpty( $log );
		$this->assertSame( 'email.sent', $log[0]['kind'] );
	}

	public function test_send_marks_failed_when_wp_mail_returns_false() {
		$GLOBALS['g2aba_test_wp_mail_return'] = false;

		$store = new Email_Draft_Store();
		$d     = $store->enqueue( 'x' );
		$d['to'] = 'x@example.com';

		$out = ( new Email_Sender() )->send( $d );

		$this->assertSame( Email_Draft_Store::STATUS_FAILED, $out['status'] );
		$this->assertStringContainsString( 'wp_mail returned false', $out['error'] );
	}

	public function test_send_refuses_invalid_recipient() {
		$store = new Email_Draft_Store();
		$d     = $store->enqueue( 'x' );
		// No 'to' set at all.

		$out = ( new Email_Sender() )->send( $d );

		$this->assertSame( Email_Draft_Store::STATUS_FAILED, $out['status'] );
		$this->assertStringContainsString( 'Missing or invalid recipient', $out['error'] );
		$this->assertCount( 0, $GLOBALS['g2aba_test_wp_mail_calls'] );
	}

	public function test_is_valid_email() {
		$this->assertTrue( Email_Sender::is_valid_email( 'priya@example.com' ) );
		$this->assertFalse( Email_Sender::is_valid_email( 'priya@example' ) );
		$this->assertFalse( Email_Sender::is_valid_email( 'not-an-email' ) );
		$this->assertFalse( Email_Sender::is_valid_email( '' ) );
	}
}
