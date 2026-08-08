<?php
/**
 * Pure "does this namespace list contain X" checks for the
 * GET /system/namespaces endpoint.
 *
 * Deliberately dependency-free (no WP calls) so the detection logic can be
 * unit tested against a plain array of namespace strings — the exact shape
 * `rest_get_server()->get_namespaces()` returns. We never assume a
 * third-party plugin (Rank Math, Redirection, Elementor, WooCommerce) is
 * present; we only report what the live namespace list actually contains.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\System;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Namespace_Detector {
	/**
	 * @param array<int, string> $namespaces Every namespace WordPress has registered.
	 * @return array{rankMath: bool, redirection: bool, elementor: bool, wooCommerce: bool}
	 */
	public static function detect( array $namespaces ): array {
		return array(
			'rankMath'    => in_array( 'rankmath/v1', $namespaces, true ),
			'redirection' => in_array( 'redirection/v1', $namespaces, true ),
			'elementor'   => self::has_prefix( $namespaces, 'elementor' ),
			'wooCommerce' => in_array( 'wc/v3', $namespaces, true ),
		);
	}

	/**
	 * True if any namespace in the list starts with the given prefix.
	 *
	 * @param array<int, string> $namespaces
	 */
	private static function has_prefix( array $namespaces, string $prefix ): bool {
		foreach ( $namespaces as $ns ) {
			if ( 0 === strpos( (string) $ns, $prefix ) ) {
				return true;
			}
		}
		return false;
	}
}
