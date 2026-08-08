<?php
/**
 * Move an uploaded CSV file into a private plugin-scoped directory.
 *
 * Stored at `wp-content/uploads/messageistic-imports/` with a random filename
 * and an `.htaccess` deny-from-all guard so the file is never web-accessible.
 *
 * @package Messageistic\Import
 */

namespace Messageistic\Import;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Import_File_Handler {

    /**
     * @param array<string,mixed> $file $_FILES entry
     * @return array{path:string,name:string}|WP_Error
     */
    public static function accept( array $file ) {
        if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
            return new WP_Error( 'messageistic_no_upload', __( 'No file uploaded.', 'messageistic' ) );
        }
        if ( ! empty( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
            return new WP_Error( 'messageistic_upload_error', __( 'Upload failed.', 'messageistic' ) );
        }

        $max_bytes = (int) apply_filters( 'messageistic_import_max_bytes', 10 * MB_IN_BYTES );
        if ( (int) ( $file['size'] ?? 0 ) > $max_bytes ) {
            return new WP_Error( 'messageistic_file_too_large', __( 'Import file exceeds the 10 MB safety limit.', 'messageistic' ) );
        }

        $original = sanitize_file_name( (string) ( $file['name'] ?? 'import.csv' ) );
        $ext      = strtolower( pathinfo( $original, PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, [ 'csv', 'txt' ], true ) ) {
            return new WP_Error( 'messageistic_bad_ext', __( 'Only .csv files are accepted.', 'messageistic' ) );
        }

        $dir = self::ensure_dir();
        if ( is_wp_error( $dir ) ) {
            return $dir;
        }
        $target = trailingslashit( $dir ) . wp_generate_uuid4() . '.csv';
        if ( ! @move_uploaded_file( $file['tmp_name'], $target ) ) {
            return new WP_Error( 'messageistic_move_failed', __( 'Could not move uploaded file.', 'messageistic' ) );
        }
        @chmod( $target, 0600 );
        return [ 'path' => $target, 'name' => $original ];
    }

    /**
     * @return string|WP_Error
     */
    private static function ensure_dir() {
        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) ) {
            return new WP_Error( 'messageistic_uploads_unavailable', (string) $uploads['error'] );
        }
        $dir = trailingslashit( $uploads['basedir'] ) . 'messageistic-imports';
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        $htaccess = trailingslashit( $dir ) . '.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, "Require all denied\nDeny from all\n" );
        }
        $index = trailingslashit( $dir ) . 'index.php';
        if ( ! file_exists( $index ) ) {
            file_put_contents( $index, "<?php\n// Silence.\n" );
        }
        return $dir;
    }
}
