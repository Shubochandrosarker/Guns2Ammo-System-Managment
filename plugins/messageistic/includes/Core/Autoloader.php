<?php
/**
 * PSR-4 style autoloader for the Messageistic namespace.
 *
 * @package Messageistic\Core
 */

namespace Messageistic\Core;

defined( 'ABSPATH' ) || exit;

final class Autoloader {

    private const PREFIX  = 'Messageistic\\';
    private const BASEDIR = MESSAGEISTIC_DIR . 'includes/';

    public static function register(): void {
        spl_autoload_register( [ self::class, 'load' ] );
    }

    public static function load( string $class ): void {
        if ( strpos( $class, self::PREFIX ) !== 0 ) {
            return;
        }

        $relative = substr( $class, strlen( self::PREFIX ) );
        $relative = str_replace( '\\', DIRECTORY_SEPARATOR, $relative );
        $file     = self::BASEDIR . $relative . '.php';

        if ( is_readable( $file ) ) {
            require_once $file;
        }
    }
}
