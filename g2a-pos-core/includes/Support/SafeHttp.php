<?php

namespace G2A\POS\Support;

final class SafeHttp {

	public static function validateUrl( string $url, bool $allowPrivate = false ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return false;
		}
		$allowedSchemes = $allowPrivate ? array( 'https', 'http' ) : array( 'https' );
		if ( ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), $allowedSchemes, true ) ) {
			return false;
		}
		$host = strtolower( rtrim( (string) ( $parts['host'] ?? '' ), '.' ) );
		if ( $host === '' || $host === 'localhost' || str_ends_with( $host, '.localhost' ) ) {
			return false;
		}
		if ( $allowPrivate ) {
			return true;
		}
		$addresses = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : self::resolve( $host );
		if ( $addresses === array() ) {
			return false;
		}
		foreach ( $addresses as $address ) {
			if ( ! filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return false;
			}
		}
		return true;
	}

	public static function get( string $url, array $args = array() ) {
		if ( ! self::validateUrl( $url, false ) ) {
			return new \WP_Error( 'unsafe_url', 'The URL must use HTTPS and resolve to a public network address.' );
		}
		$args['redirection']         = min( 3, max( 0, (int) ( $args['redirection'] ?? 3 ) ) );
		$args['reject_unsafe_urls']  = true;
		$args['limit_response_size'] = min( 5 * MB_IN_BYTES, max( 1, (int) ( $args['limit_response_size'] ?? MB_IN_BYTES ) ) );
		return wp_safe_remote_get( $url, $args );
	}

	public static function post( string $url, array $args = array() ) {
		if ( ! self::validateUrl( $url, self::privateEndpointsAllowed() ) ) {
			return new \WP_Error( 'unsafe_url', 'The URL must use HTTPS and resolve to a public network address.' );
		}
		$args['redirection']         = min( 3, max( 0, (int) ( $args['redirection'] ?? 0 ) ) );
		$args['reject_unsafe_urls']  = true;
		$args['limit_response_size'] = min( 5 * MB_IN_BYTES, max( 1, (int) ( $args['limit_response_size'] ?? MB_IN_BYTES ) ) );
		return wp_safe_remote_post( $url, $args );
	}

	private static function privateEndpointsAllowed(): bool {
		return defined( 'G2A_POS_ALLOW_PRIVATE_ENDPOINTS' ) && G2A_POS_ALLOW_PRIVATE_ENDPOINTS === true;
	}

	private static function resolve( string $host ): array {
		$records = function_exists( 'dns_get_record' ) ? @dns_get_record( $host, DNS_A | DNS_AAAA ) : false;
		if ( ! is_array( $records ) ) {
			return array();
		}
		$addresses = array();
		foreach ( $records as $record ) {
			if ( ! empty( $record['ip'] ) ) {
				$addresses[] = $record['ip'];
			}
			if ( ! empty( $record['ipv6'] ) ) {
				$addresses[] = $record['ipv6'];
			}
		}
		return array_values( array_unique( $addresses ) );
	}
}
