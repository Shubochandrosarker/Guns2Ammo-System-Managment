<?php

namespace G2A\POS\Wholesalers\Media;

/**
 * Side-loads a wholesaler's product image from their CDN into the WP media
 * library and (optionally) attaches it as the featured image on a WooCommerce
 * product. Idempotent — a CDN URL that has already been mirrored returns the
 * same attachment ID.
 *
 *   Postmeta used:
 *     _g2a_vendor_image_source    — the source CDN URL (lookup key for reuse)
 *     _g2a_vendor_image_sku       — vendor SKU the image belongs to (diagnostics)
 *     _g2a_vendor_image_provider  — provider code the image came from
 *
 * Hard failures (HTTP error, non-image content) return ['ok' => false, ...]
 * — never throw — so callers (REST, queue worker, promote-to-WC flow) can
 * decide whether to retry or log and continue.
 */
final class VendorImageMirror {

	/**
	 * Extension to fall back on per sniffed MIME type when the source URL has
	 * no usable file extension of its own. media_handle_sideload() rejects an
	 * extensionless upload outright, so a bare "/images/12345" URL could
	 * never be mirrored without this.
	 *
	 * @var array<string,string>
	 */
	private const MIME_EXTENSIONS = array(
		'image/jpeg'      => 'jpg',
		'image/pjpeg'     => 'jpg',
		'image/png'       => 'png',
		'image/gif'       => 'gif',
		'image/webp'      => 'webp',
		'image/avif'      => 'avif',
		'image/bmp'       => 'bmp',
		'image/tiff'      => 'tif',
		'image/svg+xml'   => 'svg',
		'application/pdf' => 'pdf',
	);

	/**
	 * Extensions already good enough to keep. Includes the spellings that
	 * MIME_EXTENSIONS doesn't use as its canonical value — a URL ending
	 * `.jpeg` must not be rewritten to `.jpeg.jpg`. PDF is here because the
	 * range-waiver certificate mirror shares this class and legitimately
	 * side-loads PDFs.
	 *
	 * @var list<string>
	 */
	private const KEPT_EXTENSIONS = array(
		'jpg',
		'jpeg',
		'png',
		'gif',
		'webp',
		'avif',
		'bmp',
		'tif',
		'tiff',
		'svg',
		'pdf',
	);

	public static function mirror( string $cdnUrl, array $opts = array() ): array {
		if ( $cdnUrl === '' || ! filter_var( $cdnUrl, FILTER_VALIDATE_URL ) ) {
			return self::failure( 'invalid_url', 'Image URL is empty or malformed.' );
		}

		if ( ! self::urlIsSafe( $cdnUrl ) ) {
			return self::failure( 'unsafe_url', 'Image URL does not resolve to a public host.' );
		}

		$parent_post_id = (int) ( $opts['wc_product_id'] ?? 0 );
		$set_featured   = ! empty( $opts['set_featured'] );

		$existing = self::findExistingAttachment( $cdnUrl );
		if ( $existing ) {
			// The attachment already exists (e.g. two products sharing one
			// vendor photo, or this exact URL was mirrored on an earlier
			// import run) — skip the re-download, but still attach it to
			// *this* product. Reuse previously meant "skip the download AND
			// skip the attach," which left the image sitting in the media
			// library while the current product stayed imageless.
			if ( $parent_post_id > 0 && $set_featured ) {
				set_post_thumbnail( $parent_post_id, $existing );
			}
			return array(
				'ok'            => true,
				'attachment_id' => $existing,
				'reused'        => true,
				'url'           => wp_get_attachment_url( $existing ) ?: null,
				'message'       => 'Reused attachment #' . $existing,
			);
		}

		self::ensureAdminIncludes();

		// Belt and braces: if the media stack still isn't loadable (an
		// unusual bootstrap, a mu-plugin running before wp-admin exists) say
		// so as a normal failure rather than fataling on an undefined
		// function — the whole point of this class's contract is that it
		// never throws at its callers.
		if ( ! function_exists( 'download_url' ) || ! function_exists( 'media_handle_sideload' ) ) {
			return self::failure(
				'media_api_unavailable',
				'WordPress media API is unavailable in this request context.'
			);
		}

		$tmp = download_url( $cdnUrl, 30 );
		if ( is_wp_error( $tmp ) ) {
			return self::failure( 'download_failed', $tmp->get_error_message() );
		}

		$mime    = self::sniffMime( $tmp );
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
				return self::failure( 'mime_not_allowed', 'Source served an unsupported content type (' . $mime . ') — usually a CDN error page rather than the image.' ) + array( 'mime' => $mime );
			}
		}

		$filename   = self::filenameFromUrl( $cdnUrl, $mime );
		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);

		// Core's own admin-ajax upload handler raises PHP's memory limit before
		// generating attachment thumbnails (wp_ajax_upload_attachment calls this
		// same helper) — without it, a large-but-ordinary product photo can blow
		// past a stock host's default limit during resizing and fatal with a raw
		// "There has been a critical error on this website" page instead of a
		// clean JSON error response.
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}

		$description   = isset( $opts['description'] ) ? (string) $opts['description'] : null;
		$attachment_id = media_handle_sideload( $file_array, $parent_post_id ?: 0, $description );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp );
			return self::failure( 'sideload_failed', $attachment_id->get_error_message() );
		}

		update_post_meta( $attachment_id, '_g2a_vendor_image_source', $cdnUrl );
		if ( ! empty( $opts['vendor_sku'] ) ) {
			update_post_meta( $attachment_id, '_g2a_vendor_image_sku', (string) $opts['vendor_sku'] );
		}
		if ( ! empty( $opts['provider_code'] ) ) {
			update_post_meta( $attachment_id, '_g2a_vendor_image_provider', (string) $opts['provider_code'] );
		}
		if ( ! empty( $opts['alt'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $opts['alt'] ) );
		}

		if ( $parent_post_id > 0 && $set_featured ) {
			set_post_thumbnail( $parent_post_id, $attachment_id );
		}

		return array(
			'ok'            => true,
			'attachment_id' => (int) $attachment_id,
			'reused'        => false,
			'url'           => wp_get_attachment_url( $attachment_id ) ?: null,
			'message'       => 'Imported attachment #' . (int) $attachment_id,
		);
	}

	/**
	 * Loads the wp-admin media stack this class depends on.
	 *
	 * Each include is gated on a function *that file* defines, not on a
	 * single sentinel. The previous single `function_exists('download_url')`
	 * guard was wrong in the most common real-world case: plenty of request
	 * contexts (a REST call on a site where another plugin, or core's own
	 * update machinery, has already pulled in wp-admin/includes/file.php)
	 * have download_url() defined while media.php has never been loaded. The
	 * guard then skipped all three requires and the call fataled on
	 * "Call to undefined function media_handle_sideload()".
	 *
	 * Order matters: media.php's sideload path calls into file.php and
	 * image.php.
	 */
	private static function ensureAdminIncludes(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			return;
		}
		$includes = array(
			'download_url'           => ABSPATH . 'wp-admin/includes/file.php',
			'wp_read_image_metadata' => ABSPATH . 'wp-admin/includes/image.php',
			'media_handle_sideload'  => ABSPATH . 'wp-admin/includes/media.php',
		);
		foreach ( $includes as $fn => $path ) {
			if ( ! function_exists( $fn ) && is_readable( $path ) ) {
				require_once $path;
			}
		}
	}

	/**
	 * Best-effort MIME sniff of a downloaded temp file. mime_content_type()
	 * is not guaranteed to exist (fileinfo is a compile-time extension that
	 * some shared hosts drop), so fall back to getimagesize(), which covers
	 * every raster format we care about.
	 */
	private static function sniffMime( string $path ): ?string {
		if ( function_exists( 'mime_content_type' ) ) {
			$mime = @mime_content_type( $path );
			if ( is_string( $mime ) && $mime !== '' ) {
				return $mime;
			}
		}
		if ( function_exists( 'getimagesize' ) ) {
			$info = @getimagesize( $path );
			if ( is_array( $info ) && ! empty( $info['mime'] ) ) {
				return (string) $info['mime'];
			}
		}
		return null;
	}

	private static function findExistingAttachment( string $cdnUrl ): ?int {
		global $wpdb;
		if ( ! $wpdb ) {
			return null;
		}
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s ORDER BY post_id DESC LIMIT 1",
				'_g2a_vendor_image_source',
				$cdnUrl
			)
		);
		if ( ! $id ) {
			return null;
		}
		$id = (int) $id;

		// Postmeta can outlive its attachment — someone empties the Media
		// Library, or a partial import is rolled back, and the row is left
		// pointing at a post ID that no longer exists. Treating that as a
		// valid reuse permanently poisoned the URL: every later mirror
		// "succeeded" against a dead attachment and the product never got a
		// picture. Verify, and clear the stale row so the next attempt
		// re-downloads.
		if ( function_exists( 'get_post_type' ) && get_post_type( $id ) !== 'attachment' ) {
			if ( function_exists( 'delete_post_meta' ) ) {
				delete_post_meta( $id, '_g2a_vendor_image_source' );
			}
			return null;
		}

		return $id;
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

	/**
	 * Filename to store the sideloaded file under. Vendor CDNs are not
	 * uniformly tidy — some serve `/images/GLPI1750203` with the type only in
	 * the Content-Type header — so when the URL path has no recognisable
	 * image extension, one is derived from the sniffed MIME type. Without an
	 * extension media_handle_sideload() fails with "Sorry, this file type is
	 * not permitted for security reasons."
	 */
	private static function filenameFromUrl( string $url, ?string $mime = null ): string {
		$path = parse_url( $url, PHP_URL_PATH ) ?: '';
		$name = basename( rawurldecode( $path ) );
		if ( $name === '' || $name === '.' || $name === '..' ) {
			$name = 'vendor-image-' . md5( $url );
		}

		$ext = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::KEPT_EXTENSIONS, true ) ) {
			// Either no extension at all, or one WP's uploader would refuse
			// (including anything executable). Append the extension the
			// sniffed type calls for; leave the original in the name so the
			// attachment is still traceable back to the source file.
			$fallback = $mime !== null ? ( self::MIME_EXTENSIONS[ strtolower( $mime ) ] ?? null ) : null;
			$name    .= '.' . ( $fallback ?? 'jpg' );
		}

		return sanitize_file_name( $name );
	}

	/**
	 * @return array{ok:false,error:string,detail:string,message:string}
	 */
	private static function failure( string $code, string $detail ): array {
		return array(
			'ok'      => false,
			'error'   => $code,
			'detail'  => $detail,
			'message' => $detail,
		);
	}
}
