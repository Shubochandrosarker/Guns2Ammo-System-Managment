<?php

namespace G2A\POS\Wholesalers\Media;

/**
 * Builds CDN URLs for Lipsey's product imagery. The dealer catalog ships an
 * IMAGENAME column with a bare filename; the canonical CDN is at
 * https://www.lipseyscloud.com/images/<filename>.
 *
 * Pure helper so the mapping can be unit-tested without HTTP.
 */
final class LipseysImageUrls {

	public const CDN_BASE = 'https://www.lipseyscloud.com/images/';

	public static function cdnUrl( string $imageFilename ): ?string {
		$name = trim( $imageFilename );
		if ( $name === '' ) {
			return null;
		}
		// If a full URL is already stored, pass it through.
		if ( preg_match( '#^https?://#i', $name ) ) {
			return $name;
		}
		// Defense-in-depth: strip any path components — we only want the file leaf.
		$name = basename( $name );
		if ( $name === '' || $name === '.' || $name === '..' ) {
			return null;
		}
		return self::CDN_BASE . rawurlencode( $name );
	}
}
