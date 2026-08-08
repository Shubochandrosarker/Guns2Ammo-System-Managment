<?php
/**
 * Uninstall hook for G2A Business API.
 *
 * @package G2ABA
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$options_to_drop = array(
	'g2aba_secrets',
	'g2aba_automations',
	'g2aba_agents',
	'g2aba_models',
	'g2aba_insights_cache',
	'g2aba_db_version',
);

foreach ( $options_to_drop as $option ) {
	delete_option( $option );
}

if ( function_exists( 'get_role' ) ) {
	$roles = array( 'administrator', 'shop_manager', 'editor' );
	foreach ( $roles as $role_name ) {
		$role = get_role( $role_name );
		if ( $role ) {
			$role->remove_cap( 'g2a_dashboard' );
			$role->remove_cap( 'g2a_dashboard_admin' );
		}
	}
}
