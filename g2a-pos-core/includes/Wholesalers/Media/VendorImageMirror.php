<?php

namespace G2A\POS\Wholesalers\Media;

/**
 * Side-loads a wholesaler's product image from their CDN into the WP media
 * library and (optionally) attaches it as the featured image on a WooCommerce
 * product. Idempotent — a CDN URL that has already been mirrored returns the
 * same attachment ID.
 *
 *   Postmeta used:
 *     _g2a_vendor_image_source  — the source CDN URL (lookup key for reuse)
 *     _g2a_vendor_image_sku     — vendor SKU the image belongs to (diagnostics)
 *
 * Hard failures (HTTP error, non-image content) return ['ok' => false, ...]
 * — never throw — so callers (REST, queue worker, promote-to-WC flow) can
 * decide whether to retry or log and continue.
 */
final class VendorImageMirror {

	public static function mirror( string $cdnUrl, array $opts = array() ): array {
		if ( $cdnUrl === '' || ! filter_var( $cdnUrl, FILTER_VALIDATE_URL ) ) {
			return array(
				'ok'    => false,
				'error' => 'invalid_url',
			);
		}

		if ( ! self::urlIsSafe( $cdnUrl ) ) {
			return array(
				'ok'    => false,
				'error' => 'unsafe_url',
			);
		}

		$existing = self::findExistingAttachment( $cdnUrl );
		if ( $existing ) {
			return array(
				'ok'            => true,
				'attachment_id' => $existing,
				'reused'        => true,
				'url'           => wp_get_attachment_url( $existing ) ?: null,
			);
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		$tmp = download_url( $cdnUrl, 30 );
		if ( is_wp_error( $tmp ) ) {
			return array(
				'ok'     => false,
				'error'  => 'download_failed',
				'detail' => $tmp->get_error_message(),
			);
		}

		$mime    = function_exists( 'mime_content_type' ) ? mime_content_type( $tmp ) : null;
		$allowed = (array) ( $opts['allowed_mime_prefixes'] ?? array( 'image/' ) );
		if ( $mime ) {
			$ok = false;
			foreach ( $allowed as $prefix ) {
				if ( str_starts_with( $mime, (string) $prefix ) ) {
					$ok = true;
					break;
				}
			}
			if ( ! $ok ) {
				@unlink( $tmp );
				return array(
					'ok'    => false,
					'error' => 'mime_not_allowed',
					'mime'  => $mime,
				);
			}
		}

		$filename   = self::filenameFromUrl( $cdnUrl );
		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);

		$parent_post_id = (int) ( $opts['wc_product_id'] ?? 0 );
		$attachment_id  = media_handle_sideload( $file_array, $parent_post_id ?: 0 );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp );
			return array(
				'ok'     => false,
				'error'  => 'sideload_failed',
				'detail' => $attachment_id->get_error_message(),
			);
		}

		update_post_meta( $attachment_id, '_g2a_vendor_image_source', $cdnUrl );
		if ( ! empty( $opts['vendor_sku'] ) ) {
			update_post_meta( $attachment_id, '_g2a_vendor_image_sku', (string) $opts['vendor_sku'] );
		}

		if ( $parent_post_id > 0 && ! empty( $opts['set_featured'] ) ) {
			set_post_thumbnail( $parent_post_id, $attachment_id );
		}

		return array(
			'ok'            => true,
			'attachment_id' => (int) $attachment_id,
			'reused'        => false,
			'url'           => wp_get_attachment_url( $attachment_id ) ?: null,
		);
	}

	private static function findExistingAttachment( string $cdnUrl ): ?int {
		global $wpdb;
		if ( ! $wpdb ) {
			return null;
		}
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
				'_g2a_vendor_image_source',
				$cdnUrl
			)
		);
		return $id ? (int) $id : null;
	}

	/**
	 * SSRF guard: only http(s) URLs whose host resolves to a public unicast
	 * IP may be fetched. Private, loopback, link-local and reserved ranges
	 * are rejected.
	 */
	private static function urlIsSafe( string $url ): bool {
		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}
		$host = (string) parse_url( $url, PHP_URL_HOST );
		if ( $host === '' ) {
			return false;
		}
		$host = trim( $host, '[]' ); // IPv6 literal.
		$ip   = filter_var( $host, FILTER_VALIDATE_IP ) ? $host : gethostbyname( $host );
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false; // Unresolvable host.
		}
		return filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) !== false;
	}

	private static function filenameFromUrl( string $url ): string {
		$path = parse_url( $url, PHP_URL_PATH ) ?: '';
		$name = basename( $path );
		$name = $name !== '' ? $name : ( 'vendor-image-' . md5( $url ) . '.jpg' );
		return sanitize_file_name( rawurldecode( $name ) );
	}
}
