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

if ( ! function_exists( 'memberistic_secret_setting_keys' ) ) {
	function memberistic_secret_setting_keys() {
		return WordPressistic\Memberistic\memberistic_secret_setting_keys();
	}
}

if ( ! function_exists( 'memberistic_setting_is_locked_by_constant' ) ) {
	function memberistic_setting_is_locked_by_constant( $key ) {
		return WordPressistic\Memberistic\memberistic_setting_is_locked_by_constant( $key );
	}
}

if ( ! function_exists( 'memberistic_mask_secret' ) ) {
	function memberistic_mask_secret( $value ) {
		return WordPressistic\Memberistic\memberistic_mask_secret( $value );
	}
}

if ( ! function_exists( 'memberistic_waiver_on_file' ) ) {
	/**
	 * Public lookup: the current waiver-on-file for a person, by email (then
	 * name + optional DOB). Returns the archive row array, or null. Safe to call
	 * from other plugins (e.g. the booking engine) to auto-satisfy a waiver step.
	 *
	 * @param string $email
	 * @param string $name "First Last"
	 * @param string $dob  Y-m-d
	 * @return array|null
	 */
	function memberistic_waiver_on_file( $email, $name = '', $dob = '' ) {
		if ( ! class_exists( '\\WordPressistic\\Memberistic\\Waivers\\Waivers_Archive' ) ) {
			return null;
		}
		return \WordPressistic\Memberistic\Waivers\Waivers_Archive::find_on_file( $email, $name, $dob );
	}
}
