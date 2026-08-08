<?php

namespace G2A\POS\Core;

final class Autoloader {

	public static function register(): void {
		spl_autoload_register( array( self::class, 'autoload' ) );
	}

	private static function autoload( string $fqcn ): void {
		$prefix = 'G2A\\POS\\';

		if ( strpos( $fqcn, $prefix ) !== 0 ) {
			return;
		}

		$relative = str_replace( '\\', DIRECTORY_SEPARATOR, substr( $fqcn, strlen( $prefix ) ) );
		$path     = G2A_POS_CORE_PATH . 'includes' . DIRECTORY_SEPARATOR . $relative . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
