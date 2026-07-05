<?php
/**
 * Activation: grant capabilities, seed defaults.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Activator {
	public static function activate(): void {
		Capabilities::grant_defaults();

		\WordPressistic\G2ABA\Automation\Automation_Store::seed_defaults();
		\WordPressistic\G2ABA\Automation\Cron_Scheduler::sync_all();
		\WordPressistic\G2ABA\Agents\Agent_Store::seed_defaults();
		\WordPressistic\G2ABA\Leads\Leads_Installer::maybe_install();

		// A fresh install is already fully seeded above — stamp the defs
		// version now so Plugin::run()'s Automation_Store::maybe_reseed()
		// check is a true no-op on the very next request instead of redoing
		// this same work.
		update_option( 'g2aba_automation_defs_version', \WordPressistic\G2ABA\Automation\Automation_Store::DEFS_VERSION, false );

		if ( ! get_option( 'g2aba_models' ) ) {
			update_option( 'g2aba_models', array(), false );
		}

		update_option( 'g2aba_db_version', G2ABA_VERSION, false );
	}
}
