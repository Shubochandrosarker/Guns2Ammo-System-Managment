<?php

namespace G2A\POS\Wholesalers;

use G2A\POS\Database\WholesalerRepository;

/**
 * Read-only integrity check over wholesaler account rows. Flags row
 * combinations that make provider-code resolution ambiguous or that let
 * one account's credentials collide with another's:
 *
 *  - multiple rows sharing a provider_code where any row has a blank/NULL
 *    account_number (the schema's UNIQUE(provider_code, account_number)
 *    cannot prevent this because NULLs never collide in a UNIQUE key);
 *  - duplicate (provider_code, account_number) pairs;
 *  - sync items recently aborted because code-only resolution was ambiguous.
 *
 * Nothing is merged or deleted automatically — staff are pointed at the
 * Wholesalers screen to label/fix the rows.
 */
final class WholesalerIntegrityChecker {

	public const AMBIGUITY_OPTION = 'g2a_pos_wholesaler_sync_ambiguities';

	/**
	 * Per-user "I've seen this" acknowledgement. Keyed by a hash of the
	 * current issue set, so editing/adding an account number (which changes
	 * the issues) always re-surfaces the notice even if a stale dismissal is
	 * on file, and an untouched, still-broken condition comes back after
	 * DISMISS_TTL_DAYS instead of being silenced forever.
	 */
	public const DISMISS_META_KEY = 'g2a_pos_wholesaler_notice_dismissed';
	public const DISMISS_TTL_DAYS = 3;
	public const DISMISS_NONCE_ACTION = 'g2a_pos_dismiss_wholesaler_notice';

	/**
	 * @param array<int,array<string,mixed>>|null $rows Rows to inspect (defaults to all wholesaler rows).
	 * @return array<int,array{type:string,provider_code:string,message:string}>
	 */
	public static function issues( ?array $rows = null ): array {
		if ( $rows === null ) {
			$rows = ( new WholesalerRepository() )->all();
		}

		$byCode = array();
		foreach ( $rows as $row ) {
			$code              = (string) ( $row['provider_code'] ?? '' );
			$byCode[ $code ][] = $row;
		}

		$issues = array();
		foreach ( $byCode as $code => $codeRows ) {
			$blankIds = array();
			$byAcct   = array();
			foreach ( $codeRows as $row ) {
				$acct = trim( (string) ( $row['account_number'] ?? '' ) );
				if ( $acct === '' ) {
					$blankIds[] = (int) $row['id'];
				} else {
					$byAcct[ $acct ][] = (int) $row['id'];
				}
			}

			if ( count( $codeRows ) > 1 && $blankIds ) {
				$issues[] = array(
					'type'          => 'blank_account_conflict',
					'provider_code' => $code,
					'message'       => sprintf(
						'Provider "%s" has %d accounts but row(s) #%s have no account number. Feeds cannot be matched to the right account — give every "%s" row a unique account number on the Wholesalers screen.',
						$code,
						count( $codeRows ),
						implode( ', #', $blankIds ),
						$code
					),
				);
			}

			foreach ( $byAcct as $acct => $ids ) {
				if ( count( $ids ) > 1 ) {
					$issues[] = array(
						'type'          => 'duplicate_account',
						'provider_code' => $code,
						'message'       => sprintf(
							'Provider "%s" has duplicate rows (#%s) for account number "%s". Edit or relabel the duplicates on the Wholesalers screen — nothing is merged automatically.',
							$code,
							implode( ', #', $ids ),
							$acct
						),
					);
				}
			}
		}

		foreach ( self::syncAmbiguities() as $code => $entry ) {
			$issues[] = array(
				'type'          => 'sync_ambiguity',
				'provider_code' => (string) $code,
				'message'       => sprintf(
					'%s (recorded %s)',
					(string) ( $entry['message'] ?? 'A distributor sync was aborted because the wholesaler account could not be resolved unambiguously.' ),
					(string) ( $entry['recorded_at'] ?? 'recently' )
				),
			);
		}

		return $issues;
	}

	/**
	 * Record that a sync item was aborted because code-only resolution was
	 * ambiguous, so the condition stays admin-visible between cron runs.
	 */
	public static function recordSyncAmbiguity( string $code, string $message ): void {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}
		$all = get_option( self::AMBIGUITY_OPTION, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		$all[ $code ] = array(
			'message'     => $message,
			'recorded_at' => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
		);
		update_option( self::AMBIGUITY_OPTION, $all );
	}

	/** Clear the recorded ambiguity once resolution succeeds for the code. */
	public static function clearSyncAmbiguity( string $code ): void {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}
		$all = get_option( self::AMBIGUITY_OPTION, array() );
		if ( is_array( $all ) && isset( $all[ $code ] ) ) {
			unset( $all[ $code ] );
			update_option( self::AMBIGUITY_OPTION, $all );
		}
	}

	/** @return array<string,array{message?:string,recorded_at?:string}> */
	private static function syncAmbiguities(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return array();
		}
		$all = get_option( self::AMBIGUITY_OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * Admin notice (hooked on admin_notices) surfacing integrity issues to
	 * staff who can act on them. Read-only — mirrors the plugin's existing
	 * notice-warning pattern. Dismissible per-user; a dismissal only holds
	 * while the underlying issue set is unchanged, and expires after
	 * DISMISS_TTL_DAYS so a still-broken condition keeps coming back.
	 */
	public static function render_admin_notice(): void {
		if ( ! function_exists( 'current_user_can' )
			|| ( ! current_user_can( 'g2a_pos_manage_wholesalers' ) && ! current_user_can( 'manage_options' ) ) ) {
			return;
		}
		try {
			$issues = self::issues();
		} catch ( \Throwable $e ) {
			return;
		}
		if ( ! $issues ) {
			return;
		}

		$hash = self::issuesHash( $issues );
		if ( self::isDismissed( $hash ) ) {
			return;
		}

		echo '<div id="g2a-pos-wholesaler-notice" class="notice notice-warning is-dismissible" data-g2a-issue-hash="' . esc_attr( $hash ) . '">';
		echo '<p><strong>G2A POS — wholesaler account check:</strong></p><ul style="list-style:disc;margin-left:20px;">';
		foreach ( $issues as $issue ) {
			echo '<li>' . esc_html( (string) $issue['message'] ) . '</li>';
		}
		echo '</ul><p>' . esc_html( 'Give every wholesaler account a unique, non-blank account number on the Wholesalers screen. No rows were merged or deleted automatically.' ) . '</p></div>';

		if ( function_exists( 'wp_create_nonce' ) ) {
			$nonce = wp_create_nonce( self::DISMISS_NONCE_ACTION );
			echo '<script>(function(){var n=document.getElementById("g2a-pos-wholesaler-notice");if(!n)return;document.addEventListener("click",function(e){if(!e.target.closest("#g2a-pos-wholesaler-notice .notice-dismiss"))return;var b=new FormData();b.append("action","g2a_pos_dismiss_wholesaler_notice");b.append("nonce","' . esc_js( $nonce ) . '");b.append("hash",n.dataset.g2aIssueHash);fetch(ajaxurl,{method:"POST",credentials:"same-origin",body:b});});})();</script>';
		}
	}

	/**
	 * wp_ajax_g2a_pos_dismiss_wholesaler_notice — records the current user's
	 * dismissal against the issue-set hash they saw, so the same unresolved
	 * problem can't be waved away and then silently forgotten forever.
	 */
	public static function handle_dismiss_ajax(): void {
		if ( ! function_exists( 'check_ajax_referer' ) || ! check_ajax_referer( self::DISMISS_NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce' ), 403 );
		}
		if ( ! current_user_can( 'g2a_pos_manage_wholesalers' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}
		$hash = isset( $_POST['hash'] ) ? sanitize_text_field( wp_unslash( $_POST['hash'] ) ) : '';
		if ( $hash === '' ) {
			wp_send_json_error( array( 'message' => 'Missing hash' ), 400 );
		}
		update_user_meta(
			get_current_user_id(),
			self::DISMISS_META_KEY,
			array(
				'hash'         => $hash,
				'dismissed_at' => time(),
			)
		);
		wp_send_json_success();
	}

	private static function issuesHash( array $issues ): string {
		return md5( (string) wp_json_encode( array_column( $issues, 'message' ) ) );
	}

	private static function isDismissed( string $currentHash ): bool {
		$record = get_user_meta( get_current_user_id(), self::DISMISS_META_KEY, true );
		if ( ! is_array( $record ) || ( $record['hash'] ?? '' ) !== $currentHash ) {
			return false;
		}
		$dismissedAt = (int) ( $record['dismissed_at'] ?? 0 );
		return $dismissedAt > 0 && ( time() - $dismissedAt ) < ( self::DISMISS_TTL_DAYS * DAY_IN_SECONDS );
	}
}
