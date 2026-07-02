<?php
/**
 * Custom capabilities used by every G2ABA REST route.
 *
 * `g2a_dashboard`       — read access to analytics endpoints.
 * `g2a_dashboard_admin` — mutating endpoints (toggle automations, run agents,
 *                        test model connections). Only owners get this.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Capabilities {
	public const READ  = 'g2a_dashboard';
	public const ADMIN = 'g2a_dashboard_admin';

	public static function grant_defaults(): void {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( self::READ );
			$admin->add_cap( self::ADMIN );
		}

		$shop_manager = get_role( 'shop_manager' );
		if ( $shop_manager ) {
			$shop_manager->add_cap( self::READ );
		}
	}

	public static function current_user_can_read(): bool {
		return current_user_can( self::READ ) || current_user_can( 'manage_options' );
	}

	public static function current_user_can_admin(): bool {
		return current_user_can( self::ADMIN ) || current_user_can( 'manage_options' );
	}
}
