<?php
/**
 * Plugin Name: Memberistic Membership Solutions
 * Plugin URI: https://www.wordpressistic.com
 * Description: A modern membership operations engine for service businesses. Co-developed by WordPressistic and launch partner Guns 2 Ammo (https://guns2ammo.com), a US-based indoor shooting range and firearms retail business.
 * Version: 1.20.0
 * Author: WordPressistic, in partnership with Guns 2 Ammo
 * Author URI: https://www.wordpressistic.com
 * Text Domain: memberistic
 * Domain Path: /languages
 * Requires PHP: 8.0
 *
 * @package Memberistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Idempotency guard: if a second copy of this plugin is active from a
// different folder (e.g. a stray "memberistic-membership-solutions-main"
// folder left over from a GitHub zip upload, alongside the real one), its
// own define()/require_once calls would otherwise still run — define()
// on an already-defined constant only WARNS and is silently ignored (it
// does not fatal), but that warning is printed to the page before any
// header()/redirect the request needs to make, breaking check-in,
// checkout, and waiver-signing/printing flows with "headers already
// sent" errors. Bootstrapping only once, from whichever folder loads
// first, makes every later copy fully inert instead of noisy and
// half-broken.
if ( ! defined( 'MEMBERISTIC_BOOTSTRAPPED' ) ) {

	define( 'MEMBERISTIC_BOOTSTRAPPED', __FILE__ );
	define( 'MEMBERISTIC_VERSION', '1.20.0' );
	define( 'MEMBERISTIC_DB_VERSION', '1.11.0' );
	define( 'MEMBERISTIC_FILE', __FILE__ );
	define( 'MEMBERISTIC_PATH', plugin_dir_path( __FILE__ ) );
	define( 'MEMBERISTIC_URL', plugin_dir_url( __FILE__ ) );
	define( 'MEMBERISTIC_BASENAME', plugin_basename( __FILE__ ) );

	require_once MEMBERISTIC_PATH . 'includes/class-plugin.php';

	register_activation_hook(
		MEMBERISTIC_FILE,
		static function () {
			require_once MEMBERISTIC_PATH . 'includes/class-activator.php';
			WordPressistic\Memberistic\Activator::activate();
		}
	);

	register_deactivation_hook(
		MEMBERISTIC_FILE,
		static function () {
			require_once MEMBERISTIC_PATH . 'includes/class-deactivator.php';
			WordPressistic\Memberistic\Deactivator::deactivate();
		}
	);

	add_action(
		'plugins_loaded',
		static function () {
			$plugin = new WordPressistic\Memberistic\Plugin();
			$plugin->run();
		}
	);
} elseif ( MEMBERISTIC_BOOTSTRAPPED !== __FILE__ ) {
	// A second, different copy of this plugin is active. Nothing above ran
	// (no redefinition warnings, no duplicate hook registrations), but this
	// needs a human to remove the extra plugin folder — surface it loudly
	// instead of leaving it to silently rot.
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			printf(
				'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p></div>',
				esc_html__( 'Duplicate Memberistic plugin folder detected:', 'memberistic' ),
				esc_html(
					sprintf(
						/* translators: 1: path of the copy that loaded first and is active, 2: path of the inert duplicate */
						__( 'Two copies of Memberistic Membership Solutions are active — "%1$s" is running; "%2$s" is a leftover duplicate and should be deactivated and deleted from wp-content/plugins/.', 'memberistic' ),
						MEMBERISTIC_BOOTSTRAPPED,
						__FILE__
					)
				)
			);
		}
	);
}
