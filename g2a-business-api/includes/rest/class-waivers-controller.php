<?php
/**
 * REST controller for waiver operations — powers the dashboard Waivers page.
 *
 * GET  /waivers                 Search/list waivers (unified archive store).
 * GET  /waivers/stats           Aggregate counts for the stats tiles.
 * GET  /waivers/today           Today's bookings with waiver flags (ops view).
 * GET  /waivers/{id}            Archive row detail + signature history.
 * GET  /waivers/{id}/pdf        Stream the signed PDF (API-authenticated).
 * POST /waivers/send-link       Email a sign link to any address.
 * POST /waivers/{id}/void       Void a waiver on file (staff decision).
 *
 * Reads Memberistic's unified waiver archive (which, since Phase 0 of the
 * waiver plan, carries both the Ottertext import AND every new e-signature).
 * Every handler degrades to an empty/inactive shape when Memberistic is not
 * active, matching the other providers in this plugin.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\REST;

use WordPressistic\G2ABA\Ops\Audit_Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Waivers_Controller extends REST_Controller {

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/waivers',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_waivers' ),
				'permission_callback' => array( $this, 'read_permissions_check' ),
				'args'                => array(
					'search'   => array( 'type' => 'string' ),
					'status'   => array( 'type' => 'string' ),
					'page'     => array( 'type' => 'integer' ),
					'per_page' => array( 'type' => 'integer' ),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/waivers/stats',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'stats' ),
				'permission_callback' => array( $this, 'read_permissions_check' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/waivers/today',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'today' ),
				'permission_callback' => array( $this, 'read_permissions_check' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/waivers/send-link',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'send_link' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
				'args'                => array(
					'email' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/waivers/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'detail' ),
				'permission_callback' => array( $this, 'read_permissions_check' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/waivers/(?P<id>\d+)/pdf',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'pdf' ),
				'permission_callback' => array( $this, 'read_permissions_check' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/waivers/(?P<id>\d+)/void',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'void_waiver' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Memberistic bridge helpers                                          */
	/* ------------------------------------------------------------------ */

	private function memberistic_active(): bool {
		global $wpdb;
		if ( ! class_exists( '\WordPressistic\Memberistic\Waivers\Waivers_Archive' ) ) {
			return false;
		}
		$table = $wpdb->prefix . 'memberistic_waivers_archive';
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	private function archive_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'memberistic_waivers_archive';
	}

	private function validity_days(): int {
		if ( class_exists( '\WordPressistic\Memberistic\Waivers\Waiver_Service' ) ) {
			return (int) \WordPressistic\Memberistic\Waivers\Waiver_Service::validity_days();
		}
		return 365;
	}

	/** Oldest signed_at that still satisfies the gate (validity + re-consent). */
	private function cutoff(): string {
		$cutoff = wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $this->validity_days() * DAY_IN_SECONDS ) );
		if ( class_exists( '\WordPressistic\Memberistic\Waivers\Waiver_Versions' ) ) {
			$boundary = (string) \WordPressistic\Memberistic\Waivers\Waiver_Versions::reconsent_boundary();
			if ( '' !== $boundary && $boundary > $cutoff ) {
				$cutoff = $boundary;
			}
		}
		return $cutoff;
	}

	/** Row status: current | expiring | expired (relative to cutoff + 30d). */
	private function row_status( ?string $signed_at, string $cutoff ): string {
		if ( empty( $signed_at ) ) {
			return 'expired';
		}
		if ( $signed_at < $cutoff ) {
			return 'expired';
		}
		$soon = wp_date( 'Y-m-d H:i:s', strtotime( $cutoff ) + ( 30 * DAY_IN_SECONDS ) );
		return $signed_at < $soon ? 'expiring' : 'current';
	}

	private function shape_row( array $r, string $cutoff ): array {
		$signed  = ! empty( $r['signed_at'] ) ? (string) $r['signed_at'] : null;
		$expires = $signed ? wp_date( 'Y-m-d H:i:s', strtotime( $signed ) + ( $this->validity_days() * DAY_IN_SECONDS ) ) : null;
		return array(
			'id'         => (int) $r['id'],
			'name'       => trim( (string) $r['first_name'] . ' ' . (string) $r['last_name'] ),
			'email'      => (string) $r['email'],
			'phone'      => (string) ( $r['phone'] ?? '' ),
			'dob'        => (string) ( $r['dob'] ?? '' ),
			'signed_at'  => $signed,
			'expires_at' => $expires,
			'source'     => (string) ( $r['source'] ?? '' ),
			'minor_name' => (string) ( $r['minor_name'] ?? '' ),
			'is_current' => 1 === (int) $r['is_current'],
			'status'     => 1 === (int) $r['is_current'] ? $this->row_status( $signed, $cutoff ) : 'superseded',
			'matched'    => ! empty( $r['matched_user_id'] ),
			'has_pdf'    => ! empty( $r['local_path'] ) || ! empty( $r['attachment_id'] ) || $this->esign_doc_id( $r ) > 0,
		);
	}

	/** For e-sign mirror rows: the Documents row id of the generated PDF. */
	private function esign_doc_id( array $r ): int {
		if ( empty( $r['raw_json'] ) || ! class_exists( '\WordPressistic\Memberistic\Waivers\Waiver_Service' ) ) {
			return 0;
		}
		$raw = json_decode( (string) $r['raw_json'], true );
		$sid = is_array( $raw ) && ! empty( $raw['signature_id'] ) ? (int) $raw['signature_id'] : 0;
		if ( $sid <= 0 ) {
			return 0;
		}
		$sig = \WordPressistic\Memberistic\Waivers\Waiver_Service::get_signature( $sid );
		return $sig && ! empty( $sig['attachment_id'] ) ? (int) $sig['attachment_id'] : 0;
	}

	/* ------------------------------------------------------------------ */
	/* GET /waivers                                                        */
	/* ------------------------------------------------------------------ */

	public function list_waivers( \WP_REST_Request $request ) {
		if ( ! $this->memberistic_active() ) {
			return $this->ok( array( 'active' => false, 'items' => array(), 'total' => 0 ) );
		}
		global $wpdb;
		$table    = $this->archive_table();
		$cutoff   = $this->cutoff();
		$search   = trim( (string) $request->get_param( 'search' ) );
		$status   = sanitize_key( (string) $request->get_param( 'status' ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 25 ) ) );

		$where  = array( 'is_current = 1' );
		$params = array();
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(email LIKE %s OR first_name LIKE %s OR last_name LIKE %s OR phone LIKE %s OR CONCAT(first_name, " ", last_name) LIKE %s)';
			$params   = array_merge( $params, array( $like, $like, $like, $like, $like ) );
		}
		if ( 'current' === $status ) {
			$soon     = wp_date( 'Y-m-d H:i:s', strtotime( $cutoff ) + ( 30 * DAY_IN_SECONDS ) );
			$where[]  = 'signed_at IS NOT NULL AND signed_at >= %s';
			$params[] = $soon;
		} elseif ( 'expiring' === $status ) {
			$soon     = wp_date( 'Y-m-d H:i:s', strtotime( $cutoff ) + ( 30 * DAY_IN_SECONDS ) );
			$where[]  = 'signed_at IS NOT NULL AND signed_at >= %s AND signed_at < %s';
			$params[] = $cutoff;
			$params[] = $soon;
		} elseif ( 'expired' === $status ) {
			$where[]  = '(signed_at IS NULL OR signed_at < %s)';
			$params[] = $cutoff;
		}

		$where_sql = implode( ' AND ', $where );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );

		$list_sql    = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY signed_at DESC, id DESC LIMIT %d OFFSET %d";
		$list_params = array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) );
		$rows        = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A ) ?: array();
		// phpcs:enable

		$items = array();
		foreach ( $rows as $r ) {
			$items[] = $this->shape_row( $r, $cutoff );
		}
		return $this->ok(
			array(
				'active' => true,
				'items'  => $items,
				'total'  => $total,
				'page'   => $page,
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* GET /waivers/stats                                                  */
	/* ------------------------------------------------------------------ */

	public function stats() {
		if ( ! $this->memberistic_active() ) {
			return $this->ok( array( 'active' => false ) );
		}
		global $wpdb;
		$table  = $this->archive_table();
		$cutoff = $this->cutoff();
		$soon   = wp_date( 'Y-m-d H:i:s', strtotime( $cutoff ) + ( 30 * DAY_IN_SECONDS ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_current = 1" );
		$current  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE is_current = 1 AND signed_at IS NOT NULL AND signed_at >= %s", $soon ) );
		$expiring = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE is_current = 1 AND signed_at IS NOT NULL AND signed_at >= %s AND signed_at < %s", $cutoff, $soon ) );
		$expired  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE is_current = 1 AND (signed_at IS NULL OR signed_at < %s)", $cutoff ) );
		// phpcs:enable

		// Member buckets straight from Memberistic (active people).
		$people = array();
		if ( class_exists( '\WordPressistic\Memberistic\Waivers\Waiver_Service' ) ) {
			$people = \WordPressistic\Memberistic\Waivers\Waiver_Service::counts();
		}

		// Bookings in the next 48h with no on-file waiver — the front-desk
		// heads-up number (column-aware; the bookings table may be absent).
		$upcoming_missing = 0;
		$bookings         = $wpdb->prefix . 'g2ab_bookings';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bookings ) ) ) {
			$cols = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$bookings}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( in_array( 'waiver_signed', $cols, true ) && in_array( 'customer_email', $cols, true ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT customer_email FROM {$bookings} WHERE waiver_signed = 0 AND status IN ('paid','completed','pending','unpaid') AND start_at BETWEEN %s AND %s",
						current_time( 'mysql' ),
						wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + 2 * DAY_IN_SECONDS )
					),
					ARRAY_A
				);
				foreach ( (array) $rows as $b ) {
					$email = (string) ( $b['customer_email'] ?? '' );
					if ( '' === $email || ! \WordPressistic\Memberistic\Waivers\Waivers_Archive::has_on_file( $email ) ) {
						$upcoming_missing++;
					}
				}
			}
		}

		return $this->ok(
			array(
				'active'                => true,
				'on_file'               => $total,
				'current'               => $current,
				'expiring_30d'          => $expiring,
				'expired'               => $expired,
				'people'                => $people,
				'upcoming_missing_48h'  => $upcoming_missing,
				'validity_days'         => $this->validity_days(),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* GET /waivers/today — bookings + waiver flags for the ops strip      */
	/* ------------------------------------------------------------------ */

	public function today() {
		global $wpdb;
		$bookings = $wpdb->prefix . 'g2ab_bookings';
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bookings ) ) ) {
			return $this->ok( array( 'active' => false, 'items' => array() ) );
		}
		$cols = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$bookings}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! in_array( 'customer_email', $cols, true ) ) {
			return $this->ok( array( 'active' => false, 'items' => array() ) );
		}
		$day_start = wp_date( 'Y-m-d 00:00:00' );
		$day_end   = wp_date( 'Y-m-d 23:59:59' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, customer_name, customer_email, start_at, status, waiver_signed FROM {$bookings} WHERE start_at BETWEEN %s AND %s AND status NOT IN ('cancelled') ORDER BY start_at ASC LIMIT 200",
				$day_start,
				$day_end
			),
			ARRAY_A
		) ?: array();

		$can_lookup = $this->memberistic_active();
		$items      = array();
		foreach ( $rows as $r ) {
			$on_file = false;
			if ( $can_lookup && ! empty( $r['customer_email'] ) ) {
				$on_file = \WordPressistic\Memberistic\Waivers\Waivers_Archive::has_on_file( (string) $r['customer_email'] );
			}
			$items[] = array(
				'booking_id'    => (int) $r['id'],
				'name'          => (string) $r['customer_name'],
				'email'         => (string) $r['customer_email'],
				'start_at'      => (string) $r['start_at'],
				'status'        => (string) $r['status'],
				'waiver_signed' => 1 === (int) ( $r['waiver_signed'] ?? 0 ),
				'waiver_ok'     => ( 1 === (int) ( $r['waiver_signed'] ?? 0 ) ) || $on_file,
			);
		}
		return $this->ok( array( 'active' => true, 'items' => $items ) );
	}

	/* ------------------------------------------------------------------ */
	/* GET /waivers/{id}                                                   */
	/* ------------------------------------------------------------------ */

	public function detail( \WP_REST_Request $request ) {
		if ( ! $this->memberistic_active() ) {
			return new \WP_Error( 'g2aba_not_found', __( 'Waiver not found.', 'g2a-business-api' ), array( 'status' => 404 ) );
		}
		global $wpdb;
		$id  = (int) $request['id'];
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->archive_table() . ' WHERE id = %d', $id ), ARRAY_A );
		if ( ! $row ) {
			return new \WP_Error( 'g2aba_not_found', __( 'Waiver not found.', 'g2a-business-api' ), array( 'status' => 404 ) );
		}
		$cutoff = $this->cutoff();
		$item   = $this->shape_row( $row, $cutoff );

		$item['emergency_name']  = (string) ( $row['emergency_name'] ?? '' );
		$item['emergency_phone'] = (string) ( $row['emergency_phone'] ?? '' );
		$item['minor_age']       = (string) ( $row['minor_age'] ?? '' );

		// Full signature history for this person (all e-signs by email).
		$history = array();
		if ( '' !== (string) $row['email'] && class_exists( '\WordPressistic\Memberistic\Waivers\Waiver_Service' ) ) {
			$sig_table = \WordPressistic\Memberistic\Waivers\Waiver_Service::signatures_table();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sigs = $wpdb->get_results( $wpdb->prepare( "SELECT id, signed_at, expires_at, source, station, waiver_version_id, attachment_id FROM {$sig_table} WHERE signer_email = %s ORDER BY signed_at DESC LIMIT 20", (string) $row['email'] ), ARRAY_A ) ?: array();
			foreach ( $sigs as $s ) {
				$history[] = array(
					'signature_id' => (int) $s['id'],
					'signed_at'    => (string) $s['signed_at'],
					'expires_at'   => (string) ( $s['expires_at'] ?? '' ),
					'source'       => (string) $s['source'] . ( ! empty( $s['station'] ) ? ' @ ' . $s['station'] : '' ),
					'version'      => $s['waiver_version_id'] ? (int) $s['waiver_version_id'] : null,
					'has_pdf'      => ! empty( $s['attachment_id'] ),
				);
			}
		}
		$item['history'] = $history;

		return $this->ok( $item );
	}

	/* ------------------------------------------------------------------ */
	/* GET /waivers/{id}/pdf — stream via the dashboard's own API auth     */
	/* ------------------------------------------------------------------ */

	public function pdf( \WP_REST_Request $request ) {
		if ( ! $this->memberistic_active() ) {
			return new \WP_Error( 'g2aba_not_found', __( 'Document not found.', 'g2a-business-api' ), array( 'status' => 404 ) );
		}
		global $wpdb;
		$id  = (int) $request['id'];
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->archive_table() . ' WHERE id = %d', $id ), ARRAY_A );
		if ( ! $row ) {
			return new \WP_Error( 'g2aba_not_found', __( 'Document not found.', 'g2a-business-api' ), array( 'status' => 404 ) );
		}
		$up   = wp_upload_dir();
		$base = realpath( (string) $up['basedir'] );

		$file = '';
		$mime = 'application/pdf';
		if ( ! empty( $row['local_path'] ) ) {
			$file = trailingslashit( (string) $up['basedir'] ) . ltrim( (string) $row['local_path'], '/\\' );
		} elseif ( ! empty( $row['attachment_id'] ) ) {
			$file = (string) get_attached_file( (int) $row['attachment_id'] );
		} else {
			$doc_id = $this->esign_doc_id( $row );
			if ( $doc_id > 0 && class_exists( '\WordPressistic\Memberistic\Waivers\Documents' ) ) {
				$doc = \WordPressistic\Memberistic\Waivers\Documents::get( $doc_id );
				if ( $doc && ! empty( $doc['file_path'] ) ) {
					$file = trailingslashit( (string) $up['basedir'] ) . ltrim( (string) $doc['file_path'], '/\\' );
					$mime = ! empty( $doc['mime'] ) ? (string) $doc['mime'] : $mime;
				}
			}
		}

		$real = $file ? realpath( $file ) : false;
		if ( ! $real || ! $base || 0 !== strpos( $real, $base ) || ! is_readable( $real ) ) {
			return new \WP_Error( 'g2aba_not_found', __( 'Document not available.', 'g2a-business-api' ), array( 'status' => 404 ) );
		}

		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: inline; filename="waiver-' . $id . '.pdf"' );
		header( 'Content-Length: ' . filesize( $real ) );
		readfile( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* POST /waivers/send-link                                             */
	/* ------------------------------------------------------------------ */

	public function send_link( \WP_REST_Request $request ) {
		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return new \WP_Error( 'g2aba_bad_email', __( 'A valid email address is required.', 'g2a-business-api' ), array( 'status' => 400 ) );
		}

		$url  = home_url( '/' );
		$user = get_user_by( 'email', $email );
		if ( $user && class_exists( '\WordPressistic\Memberistic\Waivers\Waiver_Service' ) ) {
			$url = (string) \WordPressistic\Memberistic\Waivers\Waiver_Service::waiver_url( $user->ID );
		} elseif ( class_exists( '\WordPressistic\Memberistic\Waivers\Waiver_Public' ) ) {
			$url = (string) \WordPressistic\Memberistic\Waivers\Waiver_Public::guest_url();
		}

		$brand = get_bloginfo( 'name' );
		$sent  = wp_mail(
			$email,
			sprintf( /* translators: %s: brand */ __( 'Sign your %s range waiver', 'g2a-business-api' ), $brand ),
			sprintf(
				/* translators: 1: link, 2: brand */
				__( "Hi,\n\nPlease sign the range waiver before your visit — it takes about a minute from any device, no login required:\n\n%1\$s\n\nSee you at the range.\n%2\$s\n", 'g2a-business-api' ),
				$url,
				$brand
			)
		);
		if ( $sent ) {
			( new Audit_Log() )->record( 'waiver_link_sent', 'Waiver sign link emailed to ' . $email );
		}
		return $this->ok( array( 'sent' => (bool) $sent ) );
	}

	/* ------------------------------------------------------------------ */
	/* POST /waivers/{id}/void                                             */
	/* ------------------------------------------------------------------ */

	public function void_waiver( \WP_REST_Request $request ) {
		if ( ! $this->memberistic_active() ) {
			return new \WP_Error( 'g2aba_not_found', __( 'Waiver not found.', 'g2a-business-api' ), array( 'status' => 404 ) );
		}
		global $wpdb;
		$id    = (int) $request['id'];
		$table = $this->archive_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, email, matched_user_id FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			return new \WP_Error( 'g2aba_not_found', __( 'Waiver not found.', 'g2a-business-api' ), array( 'status' => 404 ) );
		}
		$wpdb->update( $table, array( 'is_current' => 0 ), array( 'id' => $id ) );

		// A voided waiver means the person must sign again — reflect that on
		// their member record so staff surfaces stop showing them as signed.
		if ( ! empty( $row['matched_user_id'] ) ) {
			$people = $wpdb->prefix . 'memberistic_people';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $people ) ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( $wpdb->prepare( "UPDATE {$people} SET waiver_status = 'needs_review' WHERE wp_user_id = %d AND waiver_status = 'signed'", (int) $row['matched_user_id'] ) );
			}
		}
		( new Audit_Log() )->record( 'waiver_voided', 'Waiver #' . $id . ' voided (' . (string) $row['email'] . ')' );
		return $this->ok( array( 'voided' => true ) );
	}
}
