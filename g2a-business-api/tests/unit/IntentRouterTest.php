<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\BridGistic\Intent_Router;

class IntentRouterTest extends TestCase {
	private Intent_Router $router;

	protected function setUp(): void {
		$this->router = new Intent_Router();
	}

	public function test_routes_cancel_booking_before_send_email() {
		// "cancel booking 4128 and send confirmation" has both cancel + send.
		// Rule order guarantees cancel_booking wins because it's evaluated first.
		$this->assertSame(
			Intent_Router::INTENT_CANCEL_BOOKING,
			$this->router->route( 'cancel booking 4128 and send confirmation email' )
		);
	}

	public function test_send_email_requires_both_verb_and_topic_noun() {
		$this->assertSame(
			Intent_Router::INTENT_SEND_EMAIL,
			$this->router->route( 'send follow-up email to expired members' )
		);
	}

	public function test_send_alone_is_not_email_intent() {
		// "send" without an email-adjacent noun should NOT route to send_email.
		$this->assertSame(
			Intent_Router::INTENT_UNKNOWN,
			$this->router->route( 'send the SEO report to Slack' ) // 'report' isn't a rule noun.
		);
	}

	public function test_create_task() {
		$this->assertSame(
			Intent_Router::INTENT_CREATE_TASK,
			$this->router->route( 'create a task to fix the CCW class page' )
		);
	}

	public function test_unknown_when_neither_verb_nor_noun_matches() {
		$this->assertSame(
			Intent_Router::INTENT_UNKNOWN,
			$this->router->route( 'what happened last quarter with membership' )
		);
	}
}
