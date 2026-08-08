<?php
/**
 * Danger-zone system actions.
 *
 * Each method here mutates cross-cutting state — model keys, WP application
 * passwords, RAG store metadata. The System_Controller wraps them in
 * admin-only REST routes.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\System;

use WordPressistic\G2ABA\Secrets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class System_Actions {
	private const OPTION_ROTATION_LOG = 'g2aba_key_rotations';
	private const OPTION_RAG_STATE    = 'g2aba_rag_state';

	/**
	 * Mark every stored model key as "must be rotated". The dashboard
	 * surfaces this on the AIModels page; the operator then supplies the
	 * new key via POST /model-connections/{id}/key which clears the flag.
	 *
	 * We deliberately do NOT delete the existing keys — that would break
	 * production instantly. This is a soft-marker, not a delete.
	 *
	 * @return array{marked:int, ids:array<int,string>}
	 */
	public static function mark_all_keys_for_rotation(): array {
		$models = get_option( 'g2aba_models', array() );
		if ( ! is_array( $models ) ) {
			$models = array();
		}
		$ids = array();
		foreach ( array_keys( $models ) as $id ) {
			$ids[] = (string) $id;
		}
		update_option( self::OPTION_ROTATION_LOG, array(
			'stampedAt' => gmdate( 'c' ),
			'ids'       => $ids,
		), false );
		return array( 'marked' => count( $ids ), 'ids' => $ids );
	}

	/**
	 * Revoke every currently-issued WordPress application password across
	 * users with the g2a_dashboard capability. This forces every dashboard
	 * session to prompt for a fresh password on next request.
	 *
	 * We use `WP_Application_Passwords::delete_all_application_passwords`
	 * per-user because it emits the correct action hooks so third-party
	 * loggers see the revocation.
	 *
	 * @return array{revoked:int, users:int}
	 */
	public static function revoke_all_app_passwords(): array {
		if ( ! class_exists( '\\WP_Application_Passwords' ) || ! function_exists( 'get_users' ) ) {
			// In test / non-WP contexts this is a no-op. Still record a stamp
			// so audit tests can assert the endpoint fired.
			update_option( 'g2aba_last_revoke_ts', gmdate( 'c' ), false );
			return array( 'revoked' => 0, 'users' => 0 );
		}
		$users   = get_users( array( 'capability' => 'g2a_dashboard' ) );
		$total   = 0;
		$touched = 0;
		foreach ( $users as $user ) {
			$before = \WP_Application_Passwords::get_user_application_passwords( $user->ID );
			$count  = is_array( $before ) ? count( $before ) : 0;
			if ( $count > 0 ) {
				\WP_Application_Passwords::delete_all_application_passwords( $user->ID );
				$total   += $count;
				$touched += 1;
			}
		}
		update_option( 'g2aba_last_revoke_ts', gmdate( 'c' ), false );
		return array( 'revoked' => $total, 'users' => $touched );
	}

	/**
	 * Enqueue a RAG rebuild. In this build the rebuild is a stub —
	 * there's no RAG store yet — but the endpoint exists so the dashboard
	 * button doesn't lie, and so a future RAG worker can subscribe to
	 * `g2aba_rag_rebuild` without touching the frontend.
	 *
	 * @return array{queued:bool, at:string}
	 */
	public static function enqueue_rag_rebuild(): array {
		$stamp = gmdate( 'c' );
		update_option( self::OPTION_RAG_STATE, array(
			'lastRequestedAt' => $stamp,
			'status'          => 'requested',
		), false );
		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( time() + 30, 'g2aba_rag_rebuild' );
		}
		if ( function_exists( 'do_action' ) ) {
			do_action( 'g2aba_rag_rebuild_requested', $stamp );
		}
		return array( 'queued' => true, 'at' => $stamp );
	}

	public static function last_rotation(): ?array {
		$raw = get_option( self::OPTION_ROTATION_LOG, null );
		return is_array( $raw ) ? $raw : null;
	}

	public static function rag_state(): ?array {
		$raw = get_option( self::OPTION_RAG_STATE, null );
		return is_array( $raw ) ? $raw : null;
	}
}
