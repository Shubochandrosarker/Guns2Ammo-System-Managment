<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Ops\Email_Draft_Store;

class EmailDraftStoreTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['g2aba_test_options'] = array();
	}

	public function test_enqueue_persists_and_generates_id() {
		$store = new Email_Draft_Store();
		$d     = $store->enqueue( 'send renewal reminders to Priya' );

		$this->assertStringStartsWith( 'em_', $d['id'] );
		$this->assertSame( Email_Draft_Store::STATUS_PENDING, $d['status'] );
		$this->assertSame( '', $d['to'] );
		$this->assertNotEmpty( $d['subject'] );
		$this->assertCount( 1, $store->all() );
	}

	public function test_pending_filters_terminal_states() {
		$store = new Email_Draft_Store();
		$a = $store->enqueue( 'a' );
		$b = $store->enqueue( 'b' );
		$store->transition( $a['id'], Email_Draft_Store::STATUS_DISCARDED );

		$this->assertCount( 1, $store->pending() );
		$this->assertSame( $b['id'], $store->pending()[0]['id'] );
	}

	public function test_transition_refuses_terminal_states() {
		$store = new Email_Draft_Store();
		$d     = $store->enqueue( 'x' );
		$store->transition( $d['id'], Email_Draft_Store::STATUS_SENT );

		$this->assertNull( $store->transition( $d['id'], Email_Draft_Store::STATUS_DISCARDED ) );
		$this->assertNull( $store->transition( $d['id'], Email_Draft_Store::STATUS_FAILED ) );
	}

	public function test_transition_stamps_sent_at() {
		$store = new Email_Draft_Store();
		$d     = $store->enqueue( 'x' );
		$after = $store->transition( $d['id'], Email_Draft_Store::STATUS_SENT );

		$this->assertNotNull( $after );
		$this->assertNotEmpty( $after['sentAt'] );
	}

	public function test_migrates_legacy_option_shape_once() {
		// Legacy shape from Phase 4.
		$GLOBALS['g2aba_test_options']['g2aba_bridgistic_email_drafts'] = array(
			array( 'query' => 'legacy 1', 'createdAt' => '2026-06-01T10:00:00Z', 'status' => 'pending_send' ),
			array( 'query' => 'legacy 2', 'createdAt' => '2026-06-02T10:00:00Z', 'status' => 'pending_send' ),
		);

		$store   = new Email_Draft_Store();
		$drafts  = $store->all();

		$this->assertCount( 2, $drafts );
		$this->assertStringStartsWith( 'em_', $drafts[0]['id'] );
		$this->assertArrayNotHasKey( 'g2aba_bridgistic_email_drafts', $GLOBALS['g2aba_test_options'] );
	}

	public function test_first_sentence_falls_back_to_truncation() {
		$out = Email_Draft_Store::first_sentence( str_repeat( 'x', 70 ) );
		$this->assertStringEndsWith( '…', $out );
	}

	public function test_first_sentence_handles_empty() {
		$this->assertSame( 'Draft from BridGistic', Email_Draft_Store::first_sentence( '' ) );
	}
}
