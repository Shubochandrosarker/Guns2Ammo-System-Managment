<?php
/**
 * PSR-4-ish autoloader mapping WordPressistic\G2ABA\* to includes/.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Autoloader {
	public static function register(): void {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	public static function load( string $class ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strncmp( $prefix, $class, strlen( $prefix ) ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$parts    = explode( '\\', $relative );
		$leaf     = array_pop( $parts );
		$dir      = strtolower( implode( DIRECTORY_SEPARATOR, $parts ) );

		// WordPress files style: class-<kebab-lowercase>.php.
		$file = 'class-' . strtolower( str_replace( '_', '-', $leaf ) ) . '.php';

		$path = G2ABA_PATH . 'includes/' . ( '' === $dir ? '' : $dir . DIRECTORY_SEPARATOR ) . $file;

		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
}
