<?php
/**
 * Uninstall — removes all plugin data from the database.
 * Only runs when the plugin is deleted (not deactivated).
 *
 * @package WpisticFFL
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once plugin_dir_path( __FILE__ ) . 'includes/class-wpistic-ffl-db.php';

// Only drop tables if user chose "delete all data"
$settings = get_option( 'wpistic_ffl_settings', [] );
if ( ! empty( $settings['delete_data_on_uninstall'] ) ) {
	\WpisticFFL\DB::uninstall();
}
