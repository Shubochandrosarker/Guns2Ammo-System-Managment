<?php
/**
 * Plugin Name: G2A Theme Control
 * Description: Dynamic template field controls for Guns2Ammo theme templates.
 * Version: 1.0.1
 * Author: Wordpressistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'G2A_TC_PATH', plugin_dir_path( __FILE__ ) );
define( 'G2A_TC_URL', plugin_dir_url( __FILE__ ) );

require_once G2A_TC_PATH . 'includes/helpers.php';
require_once G2A_TC_PATH . 'includes/fields-config.php';
require_once G2A_TC_PATH . 'includes/meta-boxes.php';
require_once G2A_TC_PATH . 'includes/save-handler.php';
require_once G2A_TC_PATH . 'includes/render-helpers.php';
