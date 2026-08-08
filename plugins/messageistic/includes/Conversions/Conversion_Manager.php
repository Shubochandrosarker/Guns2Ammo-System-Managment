<?php
/** Conversion event listener. @package Messageistic\Conversions */
namespace Messageistic\Conversions;

defined( 'ABSPATH' ) || exit;

final class Conversion_Manager {
    public static function register(): void {
        add_action( 'messageistic_conversion', [ self::class, 'record' ], 10, 1 );
    }

    public static function record( array $data ): void {
        Conversion_Repository::record( $data );
    }
}
