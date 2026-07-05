<?php
/**
 * Bulk actions — Mark New/Read/Replied, Delete, Export Selected as CSV/JSON.
 *
 * @package Wpistic_Formistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bulk action POST handler.
 */
class Wpistic_Formistic_Bulk {

	/** Capability required. */
	const CAP = 'manage_options';

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'admin_post_wpistic_formistic_bulk', [ $this, 'handle' ] );
	}

	/**
	 * Handle a bulk action POST.
	 */
	public function handle() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'formistic' ), 403 );
		}
		check_admin_referer( 'wpistic_formistic_bulk' );

		$action = isset( $_POST['bulk_action'] ) ? sanitize_key( $_POST['bulk_action'] ) : '';
		$ids    = isset( $_POST['ids'] ) ? array_filter( array_map( 'intval', (array) $_POST['ids'] ) ) : [];

		$back = wp_get_referer() ?: admin_url( 'admin.php?page=formistic' );

		if ( '' === $action || ! $ids ) {
			$this->redirect( $back, 'none', 0 );
		}

		// Export branches delegate to Wpistic_Formistic_Export and exit there.
		if ( 'export_csv' === $action || 'export_json' === $action ) {
			$exporter = new Wpistic_Formistic_Export();
			$format   = 'export_json' === $action ? 'json' : 'csv';
			$exporter->stream( $format, [ 'ids' => $ids ] );
			exit;
		}

		$n = 0;
		switch ( $action ) {
			case 'mark_new':
				$n = Wpistic_Formistic_Database::bulk_set_status( $ids, 'new' );
				break;
			case 'mark_read':
				$n = Wpistic_Formistic_Database::bulk_set_status( $ids, 'read' );
				break;
			case 'mark_replied':
				$n = Wpistic_Formistic_Database::bulk_set_status( $ids, 'replied' );
				break;
			case 'delete':
				$n = Wpistic_Formistic_Database::bulk_delete( $ids );
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
				'wpistic_formistic_notice' => $notice,
				'n'           => (int) $count,
			],
			remove_query_arg( [ 'wpistic_formistic_notice', 'n' ], $back )
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
				return [ 'type' => 'success', 'text' => sprintf( _n( '%d submission marked as new.', '%d submissions marked as new.', $count, 'formistic' ), $count ) ];
			case 'mark_read':
				return [ 'type' => 'success', 'text' => sprintf( _n( '%d submission marked as viewed.', '%d submissions marked as viewed.', $count, 'formistic' ), $count ) ];
			case 'mark_replied':
				return [ 'type' => 'success', 'text' => sprintf( _n( '%d submission marked as replied.', '%d submissions marked as replied.', $count, 'formistic' ), $count ) ];
			case 'delete':
				return [ 'type' => 'success', 'text' => sprintf( _n( '%d submission deleted.', '%d submissions deleted.', $count, 'formistic' ), $count ) ];
			case 'none':
				return [ 'type' => 'info',    'text' => __( 'Please select at least one submission and an action.', 'formistic' ) ];
			case 'invalid':
				return [ 'type' => 'error',   'text' => __( 'Unrecognized bulk action.', 'formistic' ) ];
			default:
				return null;
		}
	}
}
