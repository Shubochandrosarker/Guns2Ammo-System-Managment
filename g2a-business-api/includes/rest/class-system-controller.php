<?php
/**
 * Danger-zone system endpoints.
 *
 * POST /system/rotate-keys       — mark every model key for rotation
 * POST /system/revoke-sessions   — delete every application password
 * POST /system/rag/rebuild       — enqueue a RAG rebuild
 *
 * Each of these is admin-gated + audit-logged. Confirmed with a body
 * `{ confirm: true }` so a stray fetch cannot fire them by accident.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\REST;

use WordPressistic\G2ABA\Ops\Audit_Log;
use WordPressistic\G2ABA\System\System_Actions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class System_Controller extends REST_Controller {
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/system/rotate-keys',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rotate_keys' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/system/revoke-sessions',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'revoke_sessions' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/system/rag/rebuild',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rag_rebuild' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
			)
		);
	}

	public function rotate_keys( \WP_REST_Request $req ) {
		if ( ! self::confirmed( $req ) ) {
			return self::missing_confirm();
		}
		$out = System_Actions::mark_all_keys_for_rotation();
		( new Audit_Log() )->record(
			'system.rotate_keys',
			sprintf( 'Marked %d model keys for rotation', (int) ( $out['marked'] ?? 0 ) ),
			$out
		);
		return $this->ok( array( 'ok' => true, 'result' => $out ) );
	}

	public function revoke_sessions( \WP_REST_Request $req ) {
		if ( ! self::confirmed( $req ) ) {
			return self::missing_confirm();
		}
		$out = System_Actions::revoke_all_app_passwords();
		( new Audit_Log() )->record(
			'system.revoke_sessions',
			sprintf( 'Revoked %d application passwords across %d users', (int) ( $out['revoked'] ?? 0 ), (int) ( $out['users'] ?? 0 ) ),
			$out
		);
		return $this->ok( array( 'ok' => true, 'result' => $out ) );
	}

	public function rag_rebuild( \WP_REST_Request $req ) {
		if ( ! self::confirmed( $req ) ) {
			return self::missing_confirm();
		}
		$out = System_Actions::enqueue_rag_rebuild();
		( new Audit_Log() )->record(
			'system.rag_rebuild',
			'RAG rebuild enqueued',
			$out
		);
		return $this->ok( array( 'ok' => true, 'result' => $out ) );
	}

	public static function confirmed( \WP_REST_Request $req ): bool {
		$body = $req->get_json_params();
		return is_array( $body ) && ! empty( $body['confirm'] );
	}

	private static function missing_confirm(): \WP_Error {
		return new \WP_Error(
			'g2aba_system_confirm_required',
			__( 'Confirmation flag is required for destructive system actions.', 'g2a-business-api' ),
			array( 'status' => 400 )
		);
	}
}
