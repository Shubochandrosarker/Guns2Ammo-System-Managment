<?php
/**
 * Twilio request signature validation.
 *
 * Twilio signs requests with HMAC-SHA1 of the URL plus a sorted concatenation
 * of POST parameters, base64-encoded, using the Auth Token as the key.
 *
 * @see https://www.twilio.com/docs/usage/security#validating-requests
 *
 * @package Messageistic\Providers\Twilio
 */

namespace Messageistic\Providers\Twilio;

defined( 'ABSPATH' ) || exit;

final class Twilio_Signature {

    public static function is_valid( string $auth_token, string $url, array $params, string $signature ): bool {
        if ( '' === $signature || '' === $auth_token ) {
            return false;
        }

        ksort( $params );
        $data = $url;
        foreach ( $params as $key => $value ) {
            $data .= $key . (string) $value;
        }

        $expected = base64_encode( hash_hmac( 'sha1', $data, $auth_token, true ) );
        return hash_equals( $expected, $signature );
    }
}
