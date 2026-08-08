<?php
/**
 * Phone normalization to E.164-ish form.
 *
 * Best-effort. A proper libphonenumber port is overkill for Phase 1; we
 * strip non-digits, attach the default country code when needed, and
 * prefix with "+".
 *
 * @package Messageistic\Helpers
 */

namespace Messageistic\Helpers;

defined( 'ABSPATH' ) || exit;

final class Phone_Normalizer {

    public static function normalize( string $phone, string $default_country_code = '+1' ): string {
        $phone = trim( $phone );
        if ( '' === $phone ) {
            return '';
        }

        $has_plus = str_starts_with( $phone, '+' );
        $digits   = preg_replace( '/\D+/', '', $phone );
        if ( '' === $digits ) {
            return '';
        }

        if ( $has_plus ) {
            return '+' . $digits;
        }

        $cc = preg_replace( '/\D+/', '', $default_country_code );
        if ( '' !== $cc && strlen( $digits ) <= 10 ) {
            $digits = $cc . $digits;
        }

        return '+' . $digits;
    }
}
