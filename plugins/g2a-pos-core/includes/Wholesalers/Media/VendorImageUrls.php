<?php

namespace G2A\POS\Wholesalers\Media;

use G2A\POS\Wholesalers\Davidsons\DavidsonsProvider;
use G2A\POS\Wholesalers\Lipseys\LipseysProvider;
use G2A\POS\Wholesalers\Rsr\RsrProvider;
use G2A\POS\Wholesalers\SportsSouth\SportsSouthProvider;

/**
 * Resolves a wholesaler catalog's image column into fetchable CDN URLs.
 *
 * Every provider's importer stores its image reference in the same
 * `image_filename` column, but the *shape* of that value differs per vendor:
 * Lipsey's and RSR ship a bare filename that has to be prefixed with the
 * vendor's image host, Sports South ships a picture reference, and Davidsons
 * ships an already-absolute URL. Before this class only Lipsey's had a
 * mapping, so every non-Lipsey's row failed with "provider has no CDN
 * mapping" (REST) or was silently skipped (CSV importer) even when the feed
 * carried a perfectly good image reference.
 *
 * Resolution order for a value:
 *   1. Absolute http(s) URL  → used as-is (Davidsons, and any feed that has
 *                              been re-pointed at a full URL).
 *   2. Bare filename         → prefixed with the provider's image base.
 *   3. No base for provider  → null (caller reports "no CDN mapping").
 *
 * The per-provider bases below are the vendors' documented public image
 * hosts. They are deliberately overridable without a code change — a vendor
 * moving hosts, or a dealer account served from a different bucket, only
 * needs the `g2a_pos_vendor_image_cdn_base` filter or the
 * `g2a_pos_vendor_image_cdn_bases` option (a provider_code => base map).
 */
final class VendorImageUrls {

	/**
	 * Default image host per provider code. An empty string means "this
	 * vendor's feed carries absolute URLs" — bare filenames can't be
	 * resolved for it, but full URLs still pass through.
	 *
	 * @var array<string,string>
	 */
	private const DEFAULT_BASES = array(
		LipseysProvider::CODE     => LipseysImageUrls::CDN_BASE,
		RsrProvider::CODE         => 'https://img.rsrgroup.com/pimages/',
		SportsSouthProvider::CODE => 'https://media.server.theshootingwarehouse.com/large/',
		DavidsonsProvider::CODE   => '',
	);

	/** Separators a feed may use to pack several images into one column. */
	private const MULTI_SEPARATORS = array( '|', ';', ',' );

	/**
	 * The first (primary) image URL for a catalog row, or null when the row
	 * has no usable image reference.
	 */
	public static function cdnUrl( string $providerCode, string $imageFilename ): ?string {
		$urls = self::cdnUrls( $providerCode, $imageFilename );
		return $urls[0] ?? null;
	}

	/**
	 * Every image URL a catalog row's image column resolves to. Feeds that
	 * pack multiple images into one field (pipe/semicolon/comma separated)
	 * yield one URL per entry, de-duplicated and in feed order.
	 *
	 * @return list<string>
	 */
	public static function cdnUrls( string $providerCode, string $imageFilename ): array {
		$out = array();
		foreach ( self::splitValues( $imageFilename ) as $value ) {
			$url = self::resolveOne( $providerCode, $value );
			if ( $url !== null && ! in_array( $url, $out, true ) ) {
				$out[] = $url;
			}
		}
		return $out;
	}

	/** True when this provider can resolve bare filenames (not just full URLs). */
	public static function hasCdnMapping( string $providerCode ): bool {
		return self::baseFor( $providerCode ) !== '';
	}

	/**
	 * The image host used for a provider's bare filenames. Empty string when
	 * the provider has none configured.
	 */
	public static function baseFor( string $providerCode ): string {
		$code = strtolower( trim( $providerCode ) );
		$base = self::DEFAULT_BASES[ $code ] ?? '';

		if ( function_exists( 'get_option' ) ) {
			$overrides = get_option( 'g2a_pos_vendor_image_cdn_bases', array() );
			if ( is_array( $overrides ) && isset( $overrides[ $code ] ) && is_string( $overrides[ $code ] ) ) {
				$base = $overrides[ $code ];
			}
		}

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the image host used to expand a vendor's bare image
			 * filenames into fetchable URLs.
			 *
			 * @param string $base Base URL, expected to end in a slash.
			 * @param string $code Provider code (lipseys, rsr, ...).
			 */
			$base = (string) apply_filters( 'g2a_pos_vendor_image_cdn_base', $base, $code );
		}

		$base = trim( $base );
		if ( $base === '' ) {
			return '';
		}
		return rtrim( $base, '/' ) . '/';
	}

	private static function resolveOne( string $providerCode, string $value ): ?string {
		$value = trim( $value );
		if ( $value === '' ) {
			return null;
		}

		// Already a full URL (Davidsons' image_url column, or any feed that
		// has been re-pointed at absolute URLs). Anything else carrying a
		// scheme — data:, file:, javascript:, protocol-relative — is
		// rejected outright rather than run through basename(), which would
		// happily reduce "data:image/png;base64,…" to the filename "png".
		if ( self::hasScheme( $value ) ) {
			return preg_match( '#^https?://#i', $value ) ? $value : null;
		}

		$base = self::baseFor( $providerCode );
		if ( $base === '' ) {
			return null;
		}

		// Defense-in-depth: keep only the file leaf so a hostile feed value
		// can't climb out of the vendor's image directory.
		$leaf = basename( str_replace( '\\', '/', $value ) );
		if ( $leaf === '' || $leaf === '.' || $leaf === '..' ) {
			return null;
		}

		return $base . rawurlencode( $leaf );
	}

	/**
	 * @return list<string>
	 */
	private static function splitValues( string $raw ): array {
		$raw = trim( $raw );
		if ( $raw === '' ) {
			return array();
		}
		// A value carrying a non-http(s) scheme is discarded whole. Splitting
		// it first would be worse than useless: "data:image/png;base64,AAAA"
		// chopped on ';' leaves fragments that look exactly like bare
		// filenames and would be happily prefixed onto the vendor's host.
		if ( self::hasScheme( $raw ) && ! preg_match( '#^https?://#i', $raw ) ) {
			return array();
		}
		// A single absolute URL is never split — query strings legitimately
		// contain commas and semicolons.
		if ( preg_match( '#^https?://#i', $raw ) && ! self::looksMultiUrl( $raw ) ) {
			return array( $raw );
		}
		foreach ( self::MULTI_SEPARATORS as $sep ) {
			if ( str_contains( $raw, $sep ) ) {
				return array_values( array_filter( array_map( 'trim', explode( $sep, $raw ) ), static fn( $v ) => $v !== '' ) );
			}
		}
		return array( $raw );
	}

	/** Several absolute URLs packed into one column. */
	private static function looksMultiUrl( string $raw ): bool {
		return preg_match_all( '#https?://#i', $raw ) > 1;
	}

	/** A URI scheme prefix, or a protocol-relative "//host/…" reference. */
	private static function hasScheme( string $value ): bool {
		return preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $value ) === 1 || str_starts_with( $value, '//' );
	}
}
