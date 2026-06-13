<?php
/**
 * Bulk actions — Mark New/Read/Replied, Delete, Export Selected as CSV/JSON.
 *
 * @package WPISTIC_CF
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bulk action POST handler.
 */
class WPISTIC_CF_Bulk {

	/** Capability required. */
	const CAP = 'manage_options';

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'admin_post_WPISTIC_CF_bulk', [ $this, 'handle' ] );
	}

	/**
	 * Handle a bulk action POST.
	 */
	public function handle() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'wpistic-contact-form' ), 403 );
		}
		check_admin_referer( 'WPISTIC_CF_bulk' );

		$action = isset( $_POST['bulk_action'] ) ? sanitize_key( $_POST['bulk_action'] ) : '';
		$ids    = isset( $_POST['ids'] ) ? array_filter( array_map( 'intval', (array) $_POST['ids'] ) ) : [];

		$back = wp_get_referer() ?: admin_url( 'admin.php?page=wpistic-contact' );

		if ( '' === $action || ! $ids ) {
			$this->redirect( $back, 'none', 0 );
		}

		// Export branches delegate to WPISTIC_CF_Export and exit there.
		if ( 'export_csv' === $action || 'export_json' === $action ) {
			$exporter = new WPISTIC_CF_Export();
			$format   = 'export_json' === $action ? 'json' : 'csv';
			$exporter->stream( $format, [ 'ids' => $ids ] );
			exit;
		}

		$n = 0;
		switch ( $action ) {
			case 'mark_new':
				$n = WPISTIC_CF_Database::bulk_set_status( $ids, 'new' );
				break;
			case 'mark_read':
				$n = WPISTIC_CF_Database::bulk_set_status( $ids, 'read' );
				break;
			case 'mark_replied':
				$n = WPISTIC_CF_Database::bulk_set_status( $ids, 'replied' );
				break;
			case 'delete':
				$n = WPISTIC_CF_Database::bulk_delete( $ids );
				break;
			default:
				$this->redirect( $back, 'invalid', 0 );
		}

		$this->redirect( $back, $action, $n );
	}

	/**
	 * Redirect back to the inbox with a notice flag.
	 *
	 * @param string $back   Origin URL.
	 * @param string $notice Notice slug.
	 * @param int    $count  Affected count.
	 */
	protected function redirect( $back, $notice, $count ) {
		$url = add_query_arg(
			[
				'WPISTIC_CF_notice' => $notice,
				'n'           => (int) $count,
			],
			remove_query_arg( [ 'WPISTIC_CF_notice', 'n' ], $back )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Human-readable success/error notice text.
	 *
	 * @param string $notice Notice slug from the redirect.
	 * @param int    $count  Number affected.
	 * @return array { type:'success'|'error'|'info', text:string }|null
	 */
	public static function notice_for( $notice, $count ) {
		switch ( $notice ) {
			case 'mark_new':
				return [ 'type' => 'success', 'text' => sprintf( _n( '%d submission marked as new.', '%d submissions marked as new.', $count, 'wpistic-contact-form' ), $count ) ];
			case 'mark_read':
				return [ 'type' => 'success', 'text' => sprintf( _n( '%d submission marked as viewed.', '%d submissions marked as viewed.', $count, 'wpistic-contact-form' ), $count ) ];
			case 'mark_replied':
				return [ 'type' => 'success', 'text' => sprintf( _n( '%d submission marked as replied.', '%d submissions marked as replied.', $count, 'wpistic-contact-form' ), $count ) ];
			case 'delete':
				return [ 'type' => 'success', 'text' => sprintf( _n( '%d submission deleted.', '%d submissions deleted.', $count, 'wpistic-contact-form' ), $count ) ];
			case 'none':
				return [ 'type' => 'info',    'text' => __( 'Please select at least one submission and an action.', 'wpistic-contact-form' ) ];
			case 'invalid':
				return [ 'type' => 'error',   'text' => __( 'Unrecognized bulk action.', 'wpistic-contact-form' ) ];
			default:
				return null;
		}
	}
}
