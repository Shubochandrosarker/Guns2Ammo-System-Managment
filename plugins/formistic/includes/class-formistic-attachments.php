<?php
/**
 * Attachments — file uploads from the [wpistic_contact_form] shortcode and
 * attachment-reference capture from CF7, WPForms, Gravity & Fluent.
 *
 * Local files live in a protected directory inside wp-content/uploads/ and
 * are served back through an authenticated admin-post endpoint so they can't
 * be hit directly via the public URL.
 *
 * @package Wpistic_Formistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * File upload + download orchestration.
 */
class Wpistic_Formistic_Attachments {

	/** Storage subdirectory under uploads. */
	const SUBDIR = 'formistic-form';

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'admin_post_wpistic_formistic_download',        [ $this, 'download' ] );
		add_action( 'admin_post_nopriv_wpistic_formistic_download', [ $this, 'download' ] );
	}

	/**
	 * Are attachments enabled in settings?
	 *
	 * @return bool
	 */
	public static function enabled() {
		return '1' === get_option( 'wpistic_formistic_att_enabled', '1' );
	}

	/**
	 * Absolute path to the storage root (created on demand).
	 *
	 * @return string
	 */
	public static function storage_dir() {
		$uploads = wp_get_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . self::SUBDIR;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			self::write_protect_files( $dir );
		} else {
			// Repair protection files if they were removed.
			if ( ! file_exists( $dir . '/.htaccess' ) || ! file_exists( $dir . '/index.php' ) ) {
				self::write_protect_files( $dir );
			}
		}
		return $dir;
	}

	/**
	 * Per-submission subfolder under the storage root.
	 *
	 * @param int $submission_id Submission ID.
	 * @return string
	 */
	public static function submission_dir( $submission_id ) {
		$dir = trailingslashit( self::storage_dir() ) . (int) $submission_id;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	/**
	 * Drop .htaccess and a silent index.php into a directory.
	 *
	 * @param string $dir Absolute directory path.
	 */
	protected static function write_protect_files( $dir ) {
		$dir = untrailingslashit( $dir );
		// .htaccess — block direct hits on Apache. NGINX users must rely on
		// the unguessable filename + capability-gated download endpoint.
		file_put_contents( $dir . '/.htaccess', "Order allow,deny\nDeny from all\n" );
		// Empty index.php silences directory listings.
		file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
	}

	/**
	 * Sanitize the configured allowed-extensions list.
	 *
	 * @return string[]
	 */
	public static function allowed_extensions() {
		$raw = (string) get_option( 'wpistic_formistic_att_allowed_types', 'jpg,jpeg,png,gif,pdf,doc,docx' );
		$out = array_filter( array_map( 'trim', explode( ',', strtolower( $raw ) ) ) );
		return array_values( array_unique( $out ) );
	}

	/**
	 * Configured max upload size in bytes.
	 *
	 * @return int
	 */
	public static function max_bytes() {
		return max( 1, (int) get_option( 'wpistic_formistic_att_max_size_mb', 5 ) ) * 1024 * 1024;
	}

	/**
	 * Server-executable extensions that must NEVER be accepted as an
	 * upload, regardless of the admin's allow-list. Catches the classic
	 * webshell upload paths on every common stack (Apache/PHP, NGINX/PHP,
	 * Perl, Python CGI, classic ASP, JSP, etc.) and the double-extension
	 * smuggling trick (evil.php.jpg, etc.).
	 *
	 * Filterable via wpistic_formistic_executable_extensions if a site has a
	 * legitimate reason to relax the list (it should not).
	 */
	public static function executable_extensions() {
		return apply_filters(
			'wpistic_formistic_executable_extensions',
			array(
				// PHP family.
				'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar',
				// Apache config that can affect serving rules.
				'htaccess', 'htpasswd', 'user.ini',
				// Server-parsed HTML.
				'shtml', 'shtm',
				// Classic CGI / scripting languages.
				'cgi', 'pl', 'py', 'rb',
				// JSP / ASP / ASPX.
				'jsp', 'jspx', 'asp', 'aspx', 'asa', 'asax', 'cer',
				// Java + .NET buildables.
				'jar', 'war',
				// Windows-executable / shell scripts.
				'exe', 'msi', 'bat', 'cmd', 'com', 'scr', 'ps1', 'vbs', 'wsf', 'sh',
				// SVG can carry script payloads.
				'svg',
				// Misc embedded scripts.
				'hta', 'jse',
			)
		);
	}

	/**
	 * True when ANY dotted segment of the filename matches the executable
	 * deny list. Comparing every segment (not just PATHINFO_EXTENSION)
	 * is what blocks the "evil.php.jpg" double-extension trick: pathinfo
	 * returns "jpg" only, but the apache mod_mime + AddHandler path will
	 * execute it as PHP on some configs.
	 */
	public static function path_has_executable_extension( $filename ) {
		$filename = (string) $filename;
		if ( '' === $filename ) {
			return false;
		}
		$deny = array_map( 'strtolower', self::executable_extensions() );
		// Strip any path component an attacker tried to smuggle in.
		$basename = basename( $filename );
		$parts    = explode( '.', $basename );
		// Skip the first segment (the actual base name, never an extension).
		array_shift( $parts );
		foreach ( $parts as $part ) {
			$part = strtolower( trim( $part ) );
			if ( '' !== $part && in_array( $part, $deny, true ) ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------
	 * Local uploads (shortcode)
	 * ------------------------------------------------------------------ */

	/**
	 * Validate and store every file in a `$_FILES[ $input_name ]` slot
	 * (which may be a single file or an HTML-array of files).
	 *
	 * @param string $input_name    Field name in $_FILES.
	 * @param int    $submission_id Submission ID.
	 * @return array { stored:int[], errors:string[] }
	 */
	public static function ingest_post_files( $input_name, $submission_id ) {
		$stored = [];
		$errors = [];
		if ( ! self::enabled() || empty( $_FILES[ $input_name ] ) ) {
			return [ 'stored' => $stored, 'errors' => $errors ];
		}

		// Normalize single + multi-file shapes into a uniform array.
		$slot  = $_FILES[ $input_name ];
		$files = [];
		if ( is_array( $slot['name'] ) ) {
			foreach ( $slot['name'] as $i => $name ) {
				$files[] = [
					'name'     => $name,
					'type'     => $slot['type'][ $i ]     ?? '',
					'tmp_name' => $slot['tmp_name'][ $i ] ?? '',
					'error'    => (int) ( $slot['error'][ $i ] ?? UPLOAD_ERR_NO_FILE ),
					'size'     => (int) ( $slot['size'][ $i ]  ?? 0 ),
				];
			}
		} else {
			$files[] = [
				'name'     => $slot['name']     ?? '',
				'type'     => $slot['type']     ?? '',
				'tmp_name' => $slot['tmp_name'] ?? '',
				'error'    => (int) ( $slot['error'] ?? UPLOAD_ERR_NO_FILE ),
				'size'     => (int) ( $slot['size']  ?? 0 ),
			];
		}

		$allowed_ext = self::allowed_extensions();
		$max_bytes   = self::max_bytes();
		$dir         = self::submission_dir( $submission_id );

		foreach ( $files as $file ) {
			if ( UPLOAD_ERR_NO_FILE === $file['error'] || '' === $file['name'] ) {
				continue;
			}
			if ( UPLOAD_ERR_OK !== $file['error'] ) {
				$errors[] = sprintf( __( 'Upload error for "%s".', 'formistic' ), $file['name'] );
				continue;
			}
			if ( $file['size'] > $max_bytes ) {
				$errors[] = sprintf( __( '"%s" exceeds the max size.', 'formistic' ), $file['name'] );
				continue;
			}
			$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

			// Defence-in-depth deny list. Runs BEFORE the admin's allow-list
			// and BEFORE wp_check_filetype_and_ext, so a misconfigured
			// allow-list (empty / overly broad / matching a server-executable
			// extension) can never let an uploaded webshell through. Also
			// catches the classic "evil.php.jpg" + double-extension trick by
			// scanning every dotted segment.
			if ( self::path_has_executable_extension( $file['name'] ) ) {
				$errors[] = sprintf( __( '"%s" is a disallowed file type.', 'formistic' ), $file['name'] );
				continue;
			}

			if ( $allowed_ext && ! in_array( $ext, $allowed_ext, true ) ) {
				$errors[] = sprintf( __( '"%s" is a disallowed file type.', 'formistic' ), $file['name'] );
				continue;
			}
			// Second-layer MIME guard via WP's filetype check.
			$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
			if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
				$errors[] = sprintf( __( '"%s" failed mime validation.', 'formistic' ), $file['name'] );
				continue;
			}

			$stored_name = wp_generate_password( 16, false, false ) . '.' . $ext;
			$target      = trailingslashit( $dir ) . $stored_name;

			if ( ! @move_uploaded_file( $file['tmp_name'], $target ) ) {
				$errors[] = sprintf( __( 'Could not save "%s".', 'formistic' ), $file['name'] );
				continue;
			}
			chmod( $target, 0640 );

			$id = Wpistic_Formistic_Database::insert_attachment( [
				'submission_id' => $submission_id,
				'original_name' => sanitize_file_name( $file['name'] ),
				'stored_name'   => $stored_name,
				'mime_type'     => $check['type'],
				'size_bytes'    => $file['size'],
				'source'        => 'local',
				'external_url'  => '',
			] );
			if ( $id ) {
				$stored[] = $id;
			}
		}

		return [ 'stored' => $stored, 'errors' => $errors ];
	}

	/**
	 * Record an external (host-plugin-owned) file by URL — no copy.
	 *
	 * @param int    $submission_id Submission ID.
	 * @param string $url           Public URL of the file.
	 * @param string $original_name Optional original filename.
	 * @return int Attachment ID, or 0.
	 */
	public static function ingest_external_url( $submission_id, $url, $original_name = '' ) {
		if ( ! self::enabled() || ! $url ) {
			return 0;
		}
		$url = esc_url_raw( $url );
		if ( ! $url ) {
			return 0;
		}
		if ( '' === $original_name ) {
			$original_name = basename( wp_parse_url( $url, PHP_URL_PATH ) ?: $url );
		}
		return Wpistic_Formistic_Database::insert_attachment( [
			'submission_id' => $submission_id,
			'original_name' => sanitize_file_name( $original_name ),
			'stored_name'   => '',
			'mime_type'     => '',
			'size_bytes'    => 0,
			'source'        => 'external',
			'external_url'  => $url,
		] );
	}

	/* ------------------------------------------------------------------
	 * Download endpoint
	 * ------------------------------------------------------------------ */

	/**
	 * Build a signed download URL for an attachment row.
	 *
	 * @param object $attachment DB row.
	 * @return string
	 */
	public static function download_url( $attachment ) {
		if ( 'external' === $attachment->source && $attachment->external_url ) {
			return $attachment->external_url;
		}
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=wpistic_formistic_download&id=' . (int) $attachment->id ),
			'wpistic_formistic_download_' . (int) $attachment->id
		);
	}

	/**
	 * Stream a local attachment back to a logged-in admin user.
	 */
	public function download() {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to download this file.', 'formistic' ), 403 );
		}
		check_admin_referer( 'wpistic_formistic_download_' . $id );

		$att = Wpistic_Formistic_Database::get_attachment( $id );
		if ( ! $att || 'local' !== $att->source ) {
			wp_die( esc_html__( 'Attachment not found.', 'formistic' ), 404 );
		}

		$file = trailingslashit( self::submission_dir( (int) $att->submission_id ) ) . $att->stored_name;
		if ( ! is_readable( $file ) ) {
			wp_die( esc_html__( 'File missing on disk.', 'formistic' ), 404 );
		}

		nocache_headers();
		header( 'Content-Type: ' . ( $att->mime_type ?: 'application/octet-stream' ) );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $att->original_name ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile
		exit;
	}

	/**
	 * Delete a single file from disk (called from Wpistic_Formistic_Database::delete_submission).
	 *
	 * @param int    $submission_id Submission ID.
	 * @param string $stored_name   Stored filename.
	 */
	public static function delete_file( $submission_id, $stored_name ) {
		if ( '' === $stored_name ) {
			return;
		}
		$dir  = trailingslashit( self::storage_dir() ) . (int) $submission_id;
		$path = $dir . '/' . basename( $stored_name );
		if ( is_file( $path ) ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}
		// Remove empty per-submission folder when last file goes.
		if ( is_dir( $dir ) ) {
			$remaining = array_diff( (array) scandir( $dir ), [ '.', '..' ] );
			if ( empty( $remaining ) ) {
				rmdir( $dir );
			}
		}
	}

	/**
	 * Human-readable file size.
	 *
	 * @param int $bytes File size in bytes.
	 * @return string
	 */
	public static function format_size( $bytes ) {
		return size_format( max( 0, (int) $bytes ) ) ?: '—';
	}
}
