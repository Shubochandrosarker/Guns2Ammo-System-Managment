<?php
/**
 * Centralized sanitization helpers for settings fields.
 *
 * @package Messageistic\Helpers
 */

namespace Messageistic\Helpers;

defined( 'ABSPATH' ) || exit;

final class Sanitizer {

    /**
     * @param array<string,mixed> $input
     * @param array<string,string> $schema Map of field => type (text|email|url|int|bool|tel|key|textarea).
     * @return array<string,mixed>
     */
    public static function fields( array $input, array $schema ): array {
        $out = [];
        foreach ( $schema as $field => $type ) {
            $value     = $input[ $field ] ?? null;
            $out[ $field ] = self::value( $value, $type );
        }
        return $out;
    }

    public static function value( $value, string $type ) {
        switch ( $type ) {
            case 'email':
                return sanitize_email( (string) $value );
            case 'url':
                return esc_url_raw( (string) $value );
            case 'int':
                return (int) $value;
            case 'bool':
                return ! empty( $value ) ? 1 : 0;
            case 'tel':
                return preg_replace( '/[^0-9+\-() ]/', '', (string) $value );
            case 'key':
                return sanitize_key( (string) $value );
            case 'textarea':
                return sanitize_textarea_field( (string) $value );
            case 'text':
            default:
                return sanitize_text_field( (string) $value );
        }
    }
}
