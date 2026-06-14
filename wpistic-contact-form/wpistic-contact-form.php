<?php
/**
 * Plugin Name:       WPistic Contact Form
 * Plugin URI:        https://www.wordpressistic.com/marketplace/plugins/wpistic-contact-form/
 * Description:       Collect every contact form and website form submission in one branded WordPress dashboard. View the full submitted data and message, then reply to the sender by email directly from wp-admin.
 * Version:           1.5.5
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
 * Duplicate-install guard.
 *
 * WordPress treats two plugin folders (e.g. `wpistic-contact-form` and
 * `wpistic-contact-form-main`) as two separate plugins. If both are active the
 * second one to load re-declares `WPISTIC_CF_*` classes → a "Cannot redeclare
 * class" FATAL that white-screens the ENTIRE site (every page, every form,
 * wp-admin). Bail out cleanly here instead, and tell the admin to remove the
 * duplicate. The canonical folder is `wpistic-contact-form`.
 */
if ( defined( 'WPISTIC_CF_VERSION' ) ) {
	if ( ! function_exists( 'wpistic_cf_duplicate_notice' ) ) {
		function wpistic_cf_duplicate_notice() {
			echo '<div class="notice notice-error"><p><strong>WPistic Contact Form:</strong> '
				. esc_html__( 'A duplicate copy of this plugin is installed and active. Deactivate and delete the extra copy — only one “WPistic Contact Form” (folder: wpistic-contact-form) should be active.', 'wpistic-contact-form' )
				. '</p></div>';
		}
	}
	add_action( 'admin_notices', 'wpistic_cf_duplicate_notice' );
	return;
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
define( 'WPISTIC_CF_VERSION', '1.5.5' );
define( 'WPISTIC_CF_DB_VERSION', '1.2.0' );
define( 'WPISTIC_CF_FILE', __FILE__ );
define( 'WPISTIC_CF_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPISTIC_CF_URL', plugin_dir_url( __FILE__ ) );
define( 'WPISTIC_CF_BASENAME', plugin_basename( __FILE__ ) );

require_once WPISTIC_CF_PATH . 'includes/class-wpcf-database.php';
require_once WPISTIC_CF_PATH . 'includes/class-wpcf-attachments.php';
require_once WPISTIC_CF_PATH . 'includes/class-wpcf-spam.php';
require_once WPISTIC_CF_PATH . 'includes/class-wpcf-capture.php';
require_once WPISTIC_CF_PATH . 'includes/class-wpcf-email-template.php';
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
require_once WPISTIC_CF_PATH . 'includes/class-wpcf-newsletter.php';
require_once WPISTIC_CF_PATH . 'includes/class-wpcf-emails.php';
require_once WPISTIC_CF_PATH . 'includes/class-wpcf-ajax.php';
require_once WPISTIC_CF_PATH . 'includes/class-wpcf-plugin.php';

/* Activation — create database tables + schedule daily cleanup cron. */
register_activation_hook( __FILE__, function () {
	WPISTIC_CF_Database::install();
	WPISTIC_CF_Newsletter::install();
	WPISTIC_CF_Gdpr::maybe_schedule();
} );

/* Deactivation — unschedule the cron (options + data persist). */
register_deactivation_hook( __FILE__, [ 'WPISTIC_CF_Gdpr', 'unschedule' ] );

/* Boot the plugin once all plugins are loaded. */
add_action( 'plugins_loaded', function () {
	WPISTIC_CF_Plugin::instance()->boot();
} );
