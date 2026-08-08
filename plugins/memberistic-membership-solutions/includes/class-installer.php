<?php
/**
 * Install and upgrade coordinator.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic;

use WordPressistic\Memberistic\Database\Migrations;
use WordPressistic\Memberistic\Database\Plans_Repository;
use WordPressistic\Memberistic\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Installer {
	/**
	 * Create/upgrade database and seed required defaults.
	 */
	public static function install() {
		$is_first_install = false === get_option( 'memberistic_version', false );

		Schema::create_tables();
		Migrations::run();

		update_option( 'memberistic_version', MEMBERISTIC_VERSION, false );

		if ( false === get_option( 'memberistic_settings', false ) ) {
			add_option(
				'memberistic_settings',
				array(
					// Default to the WP site name so the member dashboard
					// and digital card show the customer's brand (e.g.
					// "GUNS 2 AMMO"), never the plugin's own name.
					'brand_label'              => (string) get_bloginfo( 'name' ),
					'business_name'            => '',
					'business_phone'           => '',
					'business_address'         => '',
					'primary_brand_color'      => '#0F2044',
					'enable_debug_logging'     => 'no',
					'delete_data_on_uninstall' => 'no',
				),
				'',
				false
			);
		}

		// One-shot migration: older installs hard-coded the brand label
		// to 'Memberistic'. If we see that literal value AND the site
		// name is different, treat it as an un-customized default and
		// switch it to the site name. The admin can still override via
		// Memberistic → Settings.
		$settings = get_option( 'memberistic_settings', array() );
		if ( is_array( $settings ) && 'Memberistic' === ( $settings['brand_label'] ?? '' ) ) {
			$site_name = (string) get_bloginfo( 'name' );
			if ( $site_name && 'Memberistic' !== $site_name ) {
				$settings['brand_label'] = $site_name;
				update_option( 'memberistic_settings', $settings, false );
			}
		}

		if ( $is_first_install ) {
			Plans_Repository::seed_default_plans();
		}

		self::ensure_check_in_page();
	}

	/**
	 * Create the public /check-in/ kiosk page once, so the friendly URL
	 * exists without manual setup. Idempotent: guarded by an option flag and
	 * a slug check, and never recreated if the admin later deletes it.
	 */
	private static function ensure_check_in_page() {
		if ( get_option( 'memberistic_checkin_page_id', false ) ) {
			return;
		}
		if ( ! function_exists( 'wp_insert_post' ) ) {
			return;
		}
		// Adopt an existing /check-in/ page in ANY non-trash status (a draft or
		// private page won't be seen by get_page_by_path's publish default, so
		// query directly) — never create a duplicate that WP would slug as
		// "check-in-2".
		global $wpdb;
		$existing_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = 'page' AND post_status NOT IN ( 'trash', 'auto-draft' ) ORDER BY ID ASC LIMIT 1",
				'check-in'
			)
		);
		if ( $existing_id ) {
			update_option( 'memberistic_checkin_page_id', $existing_id, false );
			return;
		}
		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'Check In', 'memberistic' ),
				'post_name'    => 'check-in',
				'post_content' => '[memberistic_kiosk]',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);
		if ( $page_id && ! is_wp_error( $page_id ) ) {
			update_option( 'memberistic_checkin_page_id', (int) $page_id, false );
		}
	}

	/**
	 * Run lightweight upgrade tasks after a plugin file replacement.
	 */
	public static function maybe_upgrade() {
		$installed = (string) get_option( 'memberistic_version', '' );

		if ( MEMBERISTIC_VERSION === $installed ) {
			return;
		}

		self::install();
		Roles::add_roles();
		Capabilities::assign_capabilities();
	}
}
