<?php
/**
 * Captures the raw request body before WordPress consumes php://input.
 *
 * WordPress' REST server reads php://input internally to populate request
 * params, so by the time a controller runs, php://input is empty. Provider
 * webhook signature schemes that hash the raw body (OtterText) need the
 * unmodified bytes — we cache them on `parse_request` and serve them via
 * `Raw_Body_Cache::get()`.
 *
 * @package Messageistic\Helpers
 */

namespace Messageistic\Helpers;

defined( 'ABSPATH' ) || exit;

final class Raw_Body_Cache {

    private static ?string $body = null;

    public static function register(): void {
        add_action( 'parse_request', [ self::class, 'capture' ], 1 );
    }

    public static function capture(): void {
        if ( null !== self::$body ) {
            return;
        }
        $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
        if ( ! in_array( $method, [ 'POST', 'PUT', 'PATCH' ], true ) ) {
            self::$body = '';
            return;
        }
        $raw        = file_get_contents( 'php://input' );
        self::$body = false === $raw ? '' : $raw;
    }

    public static function get(): string {
        return self::$body ?? '';
    }
}
