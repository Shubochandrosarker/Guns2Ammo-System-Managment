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

		if ( ! get_option( 'g2aba_automations' ) ) {
			update_option( 'g2aba_automations', array(), false );
		}
		if ( ! get_option( 'g2aba_agents' ) ) {
			update_option( 'g2aba_agents', array(), false );
		}
		if ( ! get_option( 'g2aba_models' ) ) {
			update_option( 'g2aba_models', array(), false );
		}

		update_option( 'g2aba_db_version', G2ABA_VERSION, false );
	}
}
