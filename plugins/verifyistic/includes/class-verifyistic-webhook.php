<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Verifyistic_Webhook {

    /**
     * Send a POST webhook request.
     *
     * @param string      $url     Destination URL.
     * @param array       $payload Data to send as JSON.
     * @param string|null $secret  Per-connection HMAC secret. When null the
     *                             legacy global secret option is used.
     * @return array|WP_Error WP_HTTP response or WP_Error.
     */
    public static function send( $url, array $payload, $secret = null ) {
        if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            return new WP_Error( 'invalid_url', __( 'Invalid webhook URL.', 'verifyistic' ) );
        }

        // SECURITY: SSRF guard. Reject non-HTTPS in production, private/link-local
        // IPs, localhost/loopback. Bypass with constant VERIFYISTIC_ALLOW_PRIVATE_WEBHOOKS=true.
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
            return new WP_Error( 'invalid_url', __( 'Invalid webhook URL.', 'verifyistic' ) );
        }
        $scheme = strtolower( $parts['scheme'] ?? '' );
        if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
            return new WP_Error( 'invalid_scheme', __( 'Webhook URL must be http or https.', 'verifyistic' ) );
        }
        $allow_private = ( defined( 'VERIFYISTIC_ALLOW_PRIVATE_WEBHOOKS' ) && VERIFYISTIC_ALLOW_PRIVATE_WEBHOOKS );
        if ( ! $allow_private ) {
            $host = $parts['host'];
            $ip   = filter_var( $host, FILTER_VALIDATE_IP ) ? $host : gethostbyname( $host );
            if ( $ip === $host && ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                // DNS failed; let wp_remote_post handle it but block obvious literals.
            } elseif ( $ip ) {
                // Reject private + reserved ranges.
                if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                    return new WP_Error( 'private_webhook', __( 'Webhook host resolves to a private or reserved IP.', 'verifyistic' ) );
                }
            }
            if ( in_array( strtolower( $host ), array( 'localhost', '127.0.0.1', '::1', '0.0.0.0' ), true ) ) {
                return new WP_Error( 'localhost_webhook', __( 'Localhost webhooks are not allowed.', 'verifyistic' ) );
            }
        }

        $body    = wp_json_encode( $payload );
        $secret  = ( null === $secret ) ? get_option( 'verifyistic_webhook_secret', '' ) : (string) $secret;
        $headers = array(
            'Content-Type' => 'application/json',
            'User-Agent'   => 'Verifyistic/' . VERIFYISTIC_VERSION . ' WordPress/' . get_bloginfo( 'version' ),
            'X-Verifyistic-Event' => $payload['event'] ?? 'verifyistic.event',
            'X-Verifyistic-Site'  => get_site_url(),
        );

        // HMAC signature if secret is set
        if ( ! empty( $secret ) ) {
            $headers['X-Verifyistic-Signature'] = 'sha256=' . hash_hmac( 'sha256', $body, $secret );
        }

        $response = wp_remote_post( $url, array(
            'method'      => 'POST',
            'timeout'     => 10,
            'redirection' => 3,
            'headers'     => $headers,
            'body'        => $body,
            'data_format' => 'body',
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'webhook_http_error', sprintf(
                __( 'Webhook returned HTTP %d.', 'verifyistic' ),
                $code
            ) );
        }

        return $response;
    }
}
