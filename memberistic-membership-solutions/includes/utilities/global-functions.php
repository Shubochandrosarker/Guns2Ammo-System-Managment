<?php
/**
 * Global helper wrappers.
 *
 * @package Memberistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'memberistic_get_setting' ) ) {
	/**
	 * Get a Memberistic setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 */
	function memberistic_get_setting( $key, $default = null ) {
		return WordPressistic\Memberistic\memberistic_get_setting( $key, $default );
	}
}

if ( ! function_exists( 'memberistic_get_brand_label' ) ) {
	/**
	 * Get the filtered Memberistic brand label.
	 */
	function memberistic_get_brand_label() {
		return WordPressistic\Memberistic\memberistic_get_brand_label();
	}
}
