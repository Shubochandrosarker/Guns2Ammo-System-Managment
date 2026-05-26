<?php
/**
 * Plugin Name:       WPistic Contact Form
 * Plugin URI:        https://www.wordpressistic.com/marketplace/plugins/wpistic-contact-form/
 * Description:       Collect every contact form and website form submission in one branded WordPress dashboard. View the full submitted data and message, then reply to the sender by email directly from wp-admin.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Wordpressistic
 * Author URI:        https://www.wordpressistic.com
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wpistic-contact-form
 * Domain Path:       /languages
 *
 * @package WPISTIC_CF
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin constants.
 *
 * WPISTIC_CF_VERSION    — Plugin version. Bump on every release.
 * WPISTIC_CF_DB_VERSION — Schema version. Bump only when DB tables change.
 * WPISTIC_CF_FILE       — Absolute path to this bootstrap file.
 * WPISTIC_CF_PATH       — Absolute path to plugin directory (trailing slash).
 * WPISTIC_CF_URL        — URL to plugin directory (trailing slash).
 * WPISTIC_CF_BASENAME   — Plugin basename (folder/file.php) for hooks.
 */
define( 'WPISTIC_CF_VERSION', '1.0.0' );
define( 'WPISTIC_CF_DB_VERSION', '1.0.0' );
define( 'WPISTIC_CF_FILE', __FILE__ );
define( 'WPISTIC_CF_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPISTIC_CF_URL', plugin_dir_url( __FILE__ ) );
define( 'WPISTIC_CF_BASENAME', plugin_basename( __FILE__ ) );

require_once WPISTIC_CF_PATH . 'includes/class-wpcf-database.php';
require_once WPISTIC_CF_PATH . 'includes/class-wpcf-attachments.php';
require_once WPISTIC_CF_PATH . 'includes/class-wpcf-spam.php';
require_once WPISTIC_CF_PATH . 'includes/class-wpcf-capture.php';
require_once WPISTIC_CF_PATH . 'includes/class-wpcf-shortcode.php';
require_once WPISTIC_CF_PATH . 'includes/class-wpcf-autoresponder.php';
require_once WPISTIC_CF_PATH . 'includes/class-wpcf-export.php';
require_once WPISTIC_CF_PATH . 'includes/class-wpcf-bulk.php';
require_once WPISTIC_CF_PATH . 'includes/class-wpcf-gdpr.php';
require_once WPISTIC_CF_PATH . 'includes/class-wpcf-webhooks.php';
require_once WPISTIC_CF_PATH . 'includes/class-wpcf-forms.php';
require_once WPISTIC_CF_PATH . 'includes/class-wpcf-templates.php';
require_once WPISTIC_CF_PATH . 'includes/class-wpcf-analytics.php';
require_once WPISTIC_CF_PATH . 'includes/class-wpcf-settings.php';
require_once WPISTIC_CF_PATH . 'includes/class-wpistic-cf-ai.php';
require_once WPISTIC_CF_PATH . 'includes/class-wpcf-admin.php';
require_once WPISTIC_CF_PATH . 'includes/class-wpcf-ajax.php';
require_once WPISTIC_CF_PATH . 'includes/class-wpcf-plugin.php';

/* Activation — create database tables + schedule daily cleanup cron. */
register_activation_hook( __FILE__, function () {
	WPISTIC_CF_Database::install();
	WPISTIC_CF_Gdpr::maybe_schedule();
} );

/* Deactivation — unschedule the cron (options + data persist). */
register_deactivation_hook( __FILE__, [ 'WPISTIC_CF_Gdpr', 'unschedule' ] );

/* Boot the plugin once all plugins are loaded. */
add_action( 'plugins_loaded', function () {
	WPISTIC_CF_Plugin::instance()->boot();
} );
