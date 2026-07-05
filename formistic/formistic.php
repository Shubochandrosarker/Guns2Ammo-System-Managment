<?php

/**
 * Plugin Name:       Formistic — Smart Contact Forms for WordPress Leads
 * Plugin URI:        https://www.wordpressistic.com/marketplace/plugins/formistic/
 * Description:       Formistic centralizes form capture, lead inbox management, replies, analytics, automation, and AI-assisted workflows in WordPress.
 * Version:           2.0.5
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Wordpressistic Organization
 * Author URI:        https://www.wordpressistic.com
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       formistic
 * Domain Path:       /languages
 *
 * @package Wpistic_Formistic
 */

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Plugin constants.
 *
 * WPISTIC_FORMISTIC_VERSION    — Plugin version. Bump on every release.
 * WPISTIC_FORMISTIC_DB_VERSION — Schema version. Bump only when DB tables change.
 * WPISTIC_FORMISTIC_FILE       — Absolute path to this bootstrap file.
 * WPISTIC_FORMISTIC_PATH       — Absolute path to plugin directory (trailing slash).
 * WPISTIC_FORMISTIC_URL        — URL to plugin directory (trailing slash).
 * WPISTIC_FORMISTIC_BASENAME   — Plugin basename (folder/file.php) for hooks.
 */
define('WPISTIC_FORMISTIC_VERSION', '2.0.5');
define('WPISTIC_FORMISTIC_DB_VERSION', '1.3.0');
define('WPISTIC_FORMISTIC_FILE', __FILE__);
define('WPISTIC_FORMISTIC_PATH', plugin_dir_path(__FILE__));
define('WPISTIC_FORMISTIC_URL', plugin_dir_url(__FILE__));
define('WPISTIC_FORMISTIC_BASENAME', plugin_basename(__FILE__));

require_once WPISTIC_FORMISTIC_PATH . 'includes/class-formistic-database.php';
require_once WPISTIC_FORMISTIC_PATH . 'includes/class-formistic-attachments.php';
require_once WPISTIC_FORMISTIC_PATH . 'includes/class-formistic-spam.php';
require_once WPISTIC_FORMISTIC_PATH . 'includes/class-formistic-capture.php';
require_once WPISTIC_FORMISTIC_PATH . 'includes/class-formistic-shortcode.php';
require_once WPISTIC_FORMISTIC_PATH . 'includes/class-formistic-autoresponder.php';
require_once WPISTIC_FORMISTIC_PATH . 'includes/class-formistic-export.php';
require_once WPISTIC_FORMISTIC_PATH . 'includes/class-formistic-bulk.php';
require_once WPISTIC_FORMISTIC_PATH . 'includes/class-formistic-gdpr.php';
require_once WPISTIC_FORMISTIC_PATH . 'includes/class-formistic-webhooks.php';
require_once WPISTIC_FORMISTIC_PATH . 'includes/class-formistic-forms.php';
require_once WPISTIC_FORMISTIC_PATH . 'includes/class-formistic-templates.php';
require_once WPISTIC_FORMISTIC_PATH . 'includes/class-formistic-analytics.php';
require_once WPISTIC_FORMISTIC_PATH . 'includes/class-formistic-settings.php';
require_once WPISTIC_FORMISTIC_PATH . 'includes/class-formistic-ai.php';
require_once WPISTIC_FORMISTIC_PATH . 'includes/class-formistic-addons.php';
require_once WPISTIC_FORMISTIC_PATH . 'includes/class-formistic-api.php';
require_once WPISTIC_FORMISTIC_PATH . 'includes/class-formistic-admin.php';
require_once WPISTIC_FORMISTIC_PATH . 'includes/class-formistic-newsletter.php';
require_once WPISTIC_FORMISTIC_PATH . 'includes/class-formistic-ajax.php';
require_once WPISTIC_FORMISTIC_PATH . 'includes/class-formistic-plugin.php';

/* Activation — create database tables + schedule daily cleanup cron. */
register_activation_hook(__FILE__, function () {
	Wpistic_Formistic_Database::install();
	Wpistic_Formistic_Newsletter::install();
	Wpistic_Formistic_Gdpr::maybe_schedule();
});

/* Deactivation — unschedule the cron (options + data persist). */
register_deactivation_hook(__FILE__, ['Wpistic_Formistic_Gdpr', 'unschedule']);

/* Boot the plugin once all plugins are loaded. */
add_action('plugins_loaded', function () {
	Wpistic_Formistic_Plugin::instance()->boot();
});
