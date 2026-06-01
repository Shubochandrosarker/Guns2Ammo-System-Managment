<?php
/**
 * G2A User Management — REST endpoints for creating/editing G2A users.
 *
 * Routes (namespace: wpistic-ffl/v1):
 *   GET    /users          — list G2A users (owner/manager)
 *   POST   /users          — create new WP user + assign G2A role & permissions
 *   GET    /users/{id}     — get single user
 *   PUT    /users/{id}     — update role/permissions
 *   DELETE /users/{id}     — deactivate user (never hard-delete)
 *   GET    /users/me       — current user info (any auth'd user)
 *   PUT    /users/me       — update own display name / password
 *
 * Creating a user here:
 *   1. Creates a real WP user via wp_insert_user()
 *   2. Sets `wpistic_ffl_g2a_role` + `wpistic_ffl_g2a_permissions` user meta
 *   3. Returns the new user's JWT-ready profile
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class Users {

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		$ns = WPISTIC_FFL_REST_NS;

		register_rest_route( $ns, '/users', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list_users' ],
				'permission_callback' => [ $this, 'require_manager' ],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_user' ],
				'permission_callback' => [ $this, 'require_owner' ],
			],
		] );

		register_rest_route( $ns, '/users/me', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_me' ],
				'permission_callback' => [ $this, 'require_auth' ],
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_me' ],
				'permission_callback' => [ $this, 'require_auth' ],
			],
		] );

		register_rest_route( $ns, '/users/(?P<id>\d+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_user' ],
				'permission_callback' => [ $this, 'require_manager' ],
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_user' ],
				'permission_callback' => [ $this, 'require_owner' ],
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'deactivate_user' ],
				'permission_callback' => [ $this, 'require_owner' ],
			],
		] );
	}

	// ── Permission callbacks ──────────────────────────────────────────────────

	public function require_auth(): bool|\WP_Error {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'unauthorized', 'Authentication required.', [ 'status' => 401 ] );
		}
		return true;
	}

	public function require_manager(): bool|\WP_Error {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'unauthorized', 'Authentication required.', [ 'status' => 401 ] );
		}
		$role = JWT_Bridge::resolve_role( wp_get_current_user() );
		if ( ! in_array( $role, [ 'owner', 'manager' ], true ) ) {
			return new \WP_Error( 'forbidden', 'Manager access required.', [ 'status' => 403 ] );
		}
		return true;
	}

	public function require_owner(): bool|\WP_Error {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'unauthorized', 'Authentication required.', [ 'status' => 401 ] );
		}
		$role = JWT_Bridge::resolve_role( wp_get_current_user() );
		if ( 'owner' !== $role ) {
			return new \WP_Error( 'forbidden', 'Owner access required.', [ 'status' => 403 ] );
		}
		return true;
	}

	// ── Handlers ─────────────────────────────────────────────────────────────

	public function list_users(): \WP_REST_Response {
		$users = get_users( [
			'meta_key'   => 'wpistic_ffl_g2a_role',
			'meta_compare' => 'EXISTS',
			'number'     => 200,
			'orderby'    => 'display_name',
			'order'      => 'ASC',
		] );

		$result = [];
		foreach ( $users as $user ) {
			$result[] = $this->format_user( $user );
		}

		// Also include admins who may not have the meta set yet
		$admins = get_users( [
			'role'   => 'administrator',
			'number' => 50,
		] );
		foreach ( $admins as $admin ) {
			$already = false;
			foreach ( $result as $r ) {
				if ( (int) $r['id'] === $admin->ID ) {
					$already = true;
					break;
				}
			}
			if ( ! $already ) {
				$result[] = $this->format_user( $admin );
			}
		}

		return rest_ensure_response( [
			'users' => $result,
			'total' => count( $result ),
		] );
	}

	public function get_me(): \WP_REST_Response {
		return rest_ensure_response( $this->format_user( wp_get_current_user() ) );
	}

	public function update_me( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		$user = wp_get_current_user();
		$data = [];

		if ( $req->has_param( 'display_name' ) ) {
			$data['display_name'] = sanitize_text_field( $req->get_param( 'display_name' ) );
		}
		if ( $req->has_param( 'password' ) ) {
			$pass = $req->get_param( 'password' );
			if ( strlen( $pass ) < 8 ) {
				return new \WP_Error( 'weak_password', 'Password must be at least 8 characters.', [ 'status' => 400 ] );
			}
			$data['user_pass'] = $pass;
		}

		if ( ! empty( $data ) ) {
			$data['ID'] = $user->ID;
			$result     = wp_update_user( $data );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return rest_ensure_response( $this->format_user( get_userdata( $user->ID ) ) );
	}

	public function get_user( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		$user = get_userdata( (int) $req['id'] );
		if ( ! $user ) {
			return new \WP_Error( 'not_found', 'User not found.', [ 'status' => 404 ] );
		}
		return rest_ensure_response( $this->format_user( $user ) );
	}

	public function create_user( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		$username = sanitize_user( $req->get_param( 'username' ) ?? '' );
		$email    = sanitize_email( $req->get_param( 'email' ) ?? '' );
		$password = $req->get_param( 'password' ) ?? '';
		$g2a_role = sanitize_text_field( $req->get_param( 'g2a_role' ) ?? 'staff' );
		$perms    = $req->get_param( 'permissions' ) ?? [];

		if ( ! $username || ! $email || ! $password ) {
			return new \WP_Error( 'missing_fields', 'Username, email, and password are required.', [ 'status' => 400 ] );
		}

		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'invalid_email', 'Invalid email address.', [ 'status' => 400 ] );
		}

		if ( username_exists( $username ) ) {
			return new \WP_Error( 'username_taken', 'Username already exists.', [ 'status' => 409 ] );
		}

		if ( email_exists( $email ) ) {
			return new \WP_Error( 'email_taken', 'Email address already in use.', [ 'status' => 409 ] );
		}

		if ( ! in_array( $g2a_role, [ 'owner', 'manager', 'staff' ], true ) ) {
			$g2a_role = 'staff';
		}

		if ( strlen( $password ) < 8 ) {
			return new \WP_Error( 'weak_password', 'Password must be at least 8 characters.', [ 'status' => 400 ] );
		}

		// Map G2A role → WP role
		$wp_role = match ( $g2a_role ) {
			'owner'   => 'administrator',
			'manager' => 'editor',
			default   => 'subscriber',
		};

		$user_id = wp_insert_user( [
			'user_login'   => $username,
			'user_email'   => $email,
			'user_pass'    => $password,
			'display_name' => sanitize_text_field( $req->get_param( 'display_name' ) ?? $username ),
			'first_name'   => sanitize_text_field( $req->get_param( 'first_name' ) ?? '' ),
			'last_name'    => sanitize_text_field( $req->get_param( 'last_name' ) ?? '' ),
			'role'         => $wp_role,
		] );

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		// Validate + store G2A permissions
		$valid_perms = is_array( $perms )
			? array_values( array_intersect( $perms, JWT_Bridge::ALL_MODULES ) )
			: JWT_Bridge::resolve_permissions( get_userdata( $user_id ), $g2a_role );

		update_user_meta( $user_id, 'wpistic_ffl_g2a_role',        $g2a_role );
		update_user_meta( $user_id, 'wpistic_ffl_g2a_permissions', $valid_perms );

		return rest_ensure_response( [
			'success' => true,
			'user'    => $this->format_user( get_userdata( $user_id ) ),
		] );
	}

	public function update_user( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		$user_id  = (int) $req['id'];
		$user     = get_userdata( $user_id );

		if ( ! $user ) {
			return new \WP_Error( 'not_found', 'User not found.', [ 'status' => 404 ] );
		}

		// Prevent owner from demoting themselves
		$current_user = wp_get_current_user();
		if ( $current_user->ID === $user_id && $req->has_param( 'g2a_role' ) && $req->get_param( 'g2a_role' ) !== 'owner' ) {
			return new \WP_Error( 'cannot_demote_self', 'You cannot change your own role.', [ 'status' => 403 ] );
		}

		if ( $req->has_param( 'g2a_role' ) ) {
			$new_role = sanitize_text_field( $req->get_param( 'g2a_role' ) );
			if ( in_array( $new_role, [ 'owner', 'manager', 'staff' ], true ) ) {
				update_user_meta( $user_id, 'wpistic_ffl_g2a_role', $new_role );
			}
		}

		if ( $req->has_param( 'permissions' ) ) {
			$perms       = $req->get_param( 'permissions' );
			$valid_perms = is_array( $perms )
				? array_values( array_intersect( $perms, JWT_Bridge::ALL_MODULES ) )
				: [];
			update_user_meta( $user_id, 'wpistic_ffl_g2a_permissions', $valid_perms );
		}

		if ( $req->has_param( 'display_name' ) ) {
			wp_update_user( [
				'ID'           => $user_id,
				'display_name' => sanitize_text_field( $req->get_param( 'display_name' ) ),
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'user'    => $this->format_user( get_userdata( $user_id ) ),
		] );
	}

	public function deactivate_user( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		$user_id     = (int) $req['id'];
		$current_uid = get_current_user_id();

		if ( $user_id === $current_uid ) {
			return new \WP_Error( 'cannot_deactivate_self', 'You cannot deactivate your own account.', [ 'status' => 403 ] );
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new \WP_Error( 'not_found', 'User not found.', [ 'status' => 404 ] );
		}

		update_user_meta( $user_id, 'wpistic_ffl_g2a_active', 0 );

		// Remove capabilities so they can't login to WP admin
		$user_obj = new \WP_User( $user_id );
		$user_obj->remove_all_caps();
		$user_obj->set_role( '' );

		return rest_ensure_response( [ 'success' => true, 'deactivated' => $user_id ] );
	}

	// ── Formatting ────────────────────────────────────────────────────────────

	private function format_user( \WP_User $user ): array {
		$role        = JWT_Bridge::resolve_role( $user );
		$permissions = JWT_Bridge::resolve_permissions( $user, $role );

		return [
			'id'          => $user->ID,
			'username'    => $user->user_login,
			'email'       => $user->user_email,
			'display_name'=> $user->display_name,
			'first_name'  => $user->first_name,
			'last_name'   => $user->last_name,
			'g2a_role'    => $role,
			'permissions' => $permissions,
			'avatar'      => get_avatar_url( $user->ID, [ 'size' => 64 ] ),
			'registered'  => $user->user_registered,
			'active'      => (bool) ( get_user_meta( $user->ID, 'wpistic_ffl_g2a_active', true ) !== '0' ),
		];
	}
}
