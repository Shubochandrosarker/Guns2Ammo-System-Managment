<?php
/**
 * Export — streams submissions to CSV or JSON. Driven by an admin-post
 * endpoint so the response can flush row-by-row without buffering everything
 * into PHP memory.
 *
 * Used directly by the "Export filtered (CSV/JSON)" buttons and indirectly
 * by the bulk "Export Selected" actions.
 *
 * @package Wpistic_Formistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSV / JSON exporter.
 */
class Wpistic_Formistic_Export {

	/** Capability required. */
	const CAP = 'manage_options';

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'admin_post_wpistic_formistic_export', [ $this, 'handle_export' ] );
	}

	/**
	 * CSV columns, in order.
	 *
	 * @return string[]
	 */
	public static function columns() {
		return [
			'id',
			'created_at',
			'form_name',
			'status',
			'sender_name',
			'sender_email',
			'sender_phone',
			'subject',
			'message',
			'fields_json',
			'source_url',
			'ip_address',
			'attachment_count',
		];
	}

	/**
	 * Build a row array from a DB record + attachment count.
	 *
	 * @param object $row              Submission row.
	 * @param int    $attachment_count Attachment count.
	 * @return array
	 */
	protected function build_row( $row, $attachment_count ) {
		return [
			'id'               => (int) $row->id,
			'created_at'       => (string) $row->created_at,
			'form_name'        => (string) $row->form_name,
			'status'           => (string) $row->status,
			'sender_name'      => (string) $row->sender_name,
			'sender_email'     => (string) $row->sender_email,
			'sender_phone'     => (string) $row->sender_phone,
			'subject'          => (string) $row->subject,
			'message'          => (string) $row->message,
			'fields_json'      => (string) $row->fields,
			'source_url'       => (string) $row->source_url,
			'ip_address'       => (string) $row->ip_address,
			'attachment_count' => (int) $attachment_count,
		];
	}

	/**
	 * Entry point: validates request and streams the export.
	 */
	public function handle_export() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'formistic' ), 403 );
		}
		check_admin_referer( 'wpistic_formistic_export' );

		$format = isset( $_REQUEST['format'] ) ? sanitize_key( $_REQUEST['format'] ) : 'csv';
		$format = in_array( $format, [ 'csv', 'json' ], true ) ? $format : 'csv';

		$scope = isset( $_REQUEST['scope'] ) ? sanitize_key( $_REQUEST['scope'] ) : 'filtered';
		$scope = in_array( $scope, [ 'filtered', 'selected' ], true ) ? $scope : 'filtered';

		$args = [];
		if ( 'selected' === $scope ) {
			$args['ids'] = isset( $_REQUEST['ids'] ) ? (array) $_REQUEST['ids'] : [];
		} else {
			$args['search'] = isset( $_REQUEST['s'] )      ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) )      : '';
			$args['form']   = isset( $_REQUEST['form'] )   ? sanitize_text_field( wp_unslash( $_REQUEST['form'] ) )   : '';
			$args['status'] = isset( $_REQUEST['status'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['status'] ) ) : '';
		}

		$this->stream( $format, $args );
		exit;
	}

	/**
	 * Stream the export with appropriate headers.
	 *
	 * @param string $format csv|json.
	 * @param array  $args   Filter args for Wpistic_Formistic_Database::each_submission.
	 */
	public function stream( $format, array $args ) {
		nocache_headers();
		$stamp    = gmdate( 'Y-m-d-His' );
		$filename = 'formistic-export-' . $stamp . '.' . $format;

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		if ( 'json' === $format ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			$this->stream_json( $args );
			return;
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		$this->stream_csv( $args );
	}

	/**
	 * Stream CSV directly to php://output.
	 *
	 * @param array $args Filter args.
	 */
	protected function stream_csv( array $args ) {
		$out = fopen( 'php://output', 'w' );
		// UTF-8 BOM so Excel opens with the right encoding.
		fwrite( $out, "\xEF\xBB\xBF" );

		fputcsv( $out, array_map( array( $this, 'neutralize_csv_cell' ), self::columns() ) );

		Wpistic_Formistic_Database::each_submission( $args, function ( $row ) use ( $out ) {
			$counts = Wpistic_Formistic_Database::attachment_counts( [ (int) $row->id ] );
			$count  = $counts[ (int) $row->id ] ?? 0;
			$cells  = array_map( array( $this, 'neutralize_csv_cell' ), array_values( $this->build_row( $row, $count ) ) );
			fputcsv( $out, $cells );
		} );

		fclose( $out );
	}

	/**
	 * Neutralise leading characters that turn a CSV cell into a formula
	 * when opened in Excel / Google Sheets / LibreOffice Calc.
	 *
	 * A submitter who types "=cmd|'/c calc'!A1" or "@SUM(1+1)" or
	 * "+HYPERLINK(...)" into any form field would otherwise have that
	 * string evaluated the moment an admin opens the exported CSV.
	 *
	 * Prefix-with-single-quote is the OWASP-recommended mitigation
	 * (https://owasp.org/www-community/attacks/CSV_Injection): the
	 * leading apostrophe tells spreadsheet apps to treat the cell as
	 * text, and the apostrophe itself is not displayed.
	 */
	protected function neutralize_csv_cell( $value ) {
		if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) || null === $value ) {
			return $value;
		}
		$value = (string) $value;
		if ( '' === $value ) {
			return $value;
		}
		$first = $value[0];
		// Plus, minus, equals, at, tab, carriage-return — the six
		// characters spreadsheet apps interpret as formula prefixes.
		if ( in_array( $first, array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Stream JSON as an array — emit `[`, comma-separated objects, `]`.
	 *
	 * @param array $args Filter args.
	 */
	protected function stream_json( array $args ) {
		echo '[';
		$first = true;
		Wpistic_Formistic_Database::each_submission( $args, function ( $row ) use ( &$first ) {
			$counts = Wpistic_Formistic_Database::attachment_counts( [ (int) $row->id ] );
			$count  = $counts[ (int) $row->id ] ?? 0;
			$rec    = $this->build_row( $row, $count );
			// Decode fields_json into a real object inside the JSON output.
			$decoded             = json_decode( $rec['fields_json'], true );
			$rec['fields']       = is_array( $decoded ) ? $decoded : null;
			unset( $rec['fields_json'] );

			echo $first ? '' : ',';
			echo wp_json_encode( $rec ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- json
			$first = false;
		} );
		echo ']';
	}
}
