<?php
/**
 * Plugin Name: Memberistic Membership Solutions
 * Plugin URI: https://www.wordpressistic.com
 * Description: A modern membership operations engine for service businesses. Co-developed by WordPressistic and launch partner Guns 2 Ammo (https://guns2ammo.com), a US-based indoor shooting range and firearms retail business.
 * Version: 1.10.3
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

define( 'MEMBERISTIC_VERSION', '1.10.3' );
define( 'MEMBERISTIC_DB_VERSION', '1.5.0' );
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
