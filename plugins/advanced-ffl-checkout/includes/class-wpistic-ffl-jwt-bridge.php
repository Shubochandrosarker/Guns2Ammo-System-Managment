<?php
/**
 * JWT Bridge — injects G2A custom claims into JWT tokens.
 *
 * Works with the "JWT Authentication for WP REST API" plugin
 * (wp-api-jwt-auth by Enrique Chavez).
 *
 * Injects into the token payload:
 *   - g2a_user_id     → WP user ID
 *   - g2a_role        → owner | manager | staff
 *   - g2a_permissions → array of module slugs the user may access
 *   - g2a_display     → display name
 *   - g2a_email       → email address
 *   - g2a_avatar      → Gravatar URL
 *
 * The dashboard reads these claims directly from the decoded JWT —
 * no second API call required after login.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class JWT_Bridge {

	/** All available G2A module slugs */
	const ALL_MODULES = [
		'dashboard', 'crm', 'automation', 'comms', 'kiosk',
		'commerce', 'ffl', 'booking', 'analytics', 'ai_agents',
		'seo', 'user_management',
	];

	/** Modules that are always visible to owners (cannot be removed) */
	const OWNER_ALWAYS = [ 'dashboard', 'ffl', 'user_management' ];

	public function __construct() {
		// Fires just before JWT token is returned to the client
		add_filter( 'jwt_auth_token_before_dispatch', [ $this, 'inject_claims' ], 10, 2 );
	}

	/**
	 * Inject custom G2A claims into the JWT response payload.
	 *
	 * @param array<string,mixed> $data    Current response data from jwt-auth plugin.
	 * @param \WP_User            $user    The user who is logging in.
	 * @return array<string,mixed>
	 */
	public function inject_claims( array $data, \WP_User $user ): array {
		$role        = self::resolve_role( $user );
		$permissions = self::resolve_permissions( $user, $role );

		$data['g2a_user_id']     = $user->ID;
		$data['g2a_role']        = $role;
		$data['g2a_permissions'] = $permissions;
		$data['g2a_display']     = $user->display_name;
		$data['g2a_email']       = $user->user_email;
		$data['g2a_avatar']      = get_avatar_url( $user->ID, [ 'size' => 64 ] );

		return $data;
	}

	/**
	 * Resolve the G2A role for a user.
	 *
	 * Priority order:
	 *   1. Explicit meta `wpistic_ffl_g2a_role`
	 *   2. Mapping from WP capabilities
	 */
	public static function resolve_role( \WP_User $user ): string {
		$meta_role = get_user_meta( $user->ID, 'wpistic_ffl_g2a_role', true );
		if ( $meta_role && in_array( $meta_role, [ 'owner', 'manager', 'staff' ], true ) ) {
			return $meta_role;
		}

		// Map from WP capabilities
		if ( user_can( $user, 'manage_options' ) || user_can( $user, 'administrator' ) ) {
			return 'owner';
		}
		if ( user_can( $user, 'edit_others_posts' ) || user_can( $user, 'manage_woocommerce' ) ) {
			return 'manager';
		}
		// 'staff' must be earned: either an explicit WC capability or the meta
		// flag set by an admin. Defaulting to 'staff' meant any WP subscriber
		// who could mint a JWT got dashboard/crm/comms/ffl access.
		if ( user_can( $user, 'manage_woocommerce' ) ) {
			return 'staff';
		}
		return 'none';
	}

	/**
	 * Resolve the list of permitted module slugs for a user.
	 *
	 * Owners always get all modules.
	 * Managers + staff get whatever is stored in user meta,
	 * defaulting to a sensible base set if nothing is set.
	 */
	public static function resolve_permissions( \WP_User $user, string $role ): array {
		if ( 'none' === $role ) {
			return [];
		}
		if ( 'owner' === $role ) {
			return self::ALL_MODULES;
		}

		$stored = get_user_meta( $user->ID, 'wpistic_ffl_g2a_permissions', true );
		if ( is_array( $stored ) && ! empty( $stored ) ) {
			// Validate — only return known module slugs
			return array_values( array_intersect( $stored, self::ALL_MODULES ) );
		}

		// Default permissions when not explicitly set
		if ( 'manager' === $role ) {
			return [ 'dashboard', 'crm', 'comms', 'commerce', 'ffl', 'booking', 'analytics' ];
		}

		// staff default
		return [ 'dashboard', 'crm', 'comms', 'ffl' ];
	}
}
