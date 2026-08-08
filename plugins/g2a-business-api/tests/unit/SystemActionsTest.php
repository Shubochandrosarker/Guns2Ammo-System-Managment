<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\System\System_Actions;

class SystemActionsTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['g2aba_test_options']     = array();
		$GLOBALS['g2aba_test_actions']     = array();
		$GLOBALS['g2aba_test_cron_single'] = array();
	}

	public function test_mark_all_keys_for_rotation_records_every_id() {
		$GLOBALS['g2aba_test_options']['g2aba_models'] = array(
			'm1' => array( 'id' => 'm1' ),
			'm2' => array( 'id' => 'm2' ),
			'm3' => array( 'id' => 'm3' ),
		);
		$out = System_Actions::mark_all_keys_for_rotation();
		$this->assertSame( 3, $out['marked'] );
		$this->assertEqualsCanonicalizing( array( 'm1', 'm2', 'm3' ), $out['ids'] );

		$log = System_Actions::last_rotation();
		$this->assertNotNull( $log );
		$this->assertNotEmpty( $log['stampedAt'] );
	}

	public function test_mark_all_keys_handles_empty_option() {
		$out = System_Actions::mark_all_keys_for_rotation();
		$this->assertSame( 0, $out['marked'] );
		$this->assertSame( array(), $out['ids'] );
	}

	public function test_revoke_all_app_passwords_in_test_env_is_noop_but_stamps() {
		// The test bootstrap doesn't expose WP_Application_Passwords, so the
		// method takes the safe branch. It still stamps for observability.
		$out = System_Actions::revoke_all_app_passwords();
		$this->assertSame( 0, $out['revoked'] );
		$this->assertSame( 0, $out['users'] );
		$this->assertNotEmpty( get_option( 'g2aba_last_revoke_ts', '' ) );
	}

	public function test_enqueue_rag_rebuild_schedules_single_event_and_fires_action() {
		$out = System_Actions::enqueue_rag_rebuild();
		$this->assertTrue( $out['queued'] );
		$this->assertNotEmpty( $out['at'] );

		$state = System_Actions::rag_state();
		$this->assertNotNull( $state );
		$this->assertSame( 'requested', $state['status'] );

		// A single-event cron was scheduled.
		$this->assertNotEmpty( $GLOBALS['g2aba_test_cron_single'] );
		$this->assertSame( 'g2aba_rag_rebuild', $GLOBALS['g2aba_test_cron_single'][0]['hook'] );

		// The rebuild-requested action fires so listeners can subscribe.
		$fired = array_column( $GLOBALS['g2aba_test_actions'], 0 );
		$this->assertContains( 'g2aba_rag_rebuild_requested', $fired );
	}
}
