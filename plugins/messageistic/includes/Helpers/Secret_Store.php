<?php
/**
 * Encrypt provider credentials at rest using WordPress authentication salts.
 *
 * @package Messageistic\Helpers
 */

namespace Messageistic\Helpers;

defined( 'ABSPATH' ) || exit;

final class Secret_Store {
    private const PREFIX = 'messageistic:v1:';

    public static function encrypt( string $value ): string {
        if ( '' === $value || str_starts_with( $value, self::PREFIX ) || ! function_exists( 'openssl_encrypt' ) ) {
            return $value;
        }
        $key = hash( 'sha256', wp_salt( 'auth' ), true );
        $iv  = random_bytes( 12 );
        $tag = '';
        $cipher = openssl_encrypt( $value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
        return false === $cipher ? $value : self::PREFIX . base64_encode( $iv . $tag . $cipher );
    }

    public static function decrypt( string $value ): string {
        if ( ! str_starts_with( $value, self::PREFIX ) || ! function_exists( 'openssl_decrypt' ) ) {
            return $value;
        }
        $decoded = base64_decode( substr( $value, strlen( self::PREFIX ) ), true );
        if ( false === $decoded || strlen( $decoded ) < 29 ) {
            return '';
        }
        $plain = openssl_decrypt( substr( $decoded, 28 ), 'aes-256-gcm', hash( 'sha256', wp_salt( 'auth' ), true ), OPENSSL_RAW_DATA, substr( $decoded, 0, 12 ), substr( $decoded, 12, 16 ) );
        return false === $plain ? '' : $plain;
    }

    public static function decrypt_array( array $settings ): array {
        array_walk_recursive( $settings, static function ( &$value ): void {
            if ( is_string( $value ) ) {
                $value = self::decrypt( $value );
            }
        } );
        return $settings;
    }
}
