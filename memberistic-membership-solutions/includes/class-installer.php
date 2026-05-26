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
					'brand_label'              => 'Memberistic',
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

		if ( $is_first_install ) {
			Plans_Repository::seed_default_plans();
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
