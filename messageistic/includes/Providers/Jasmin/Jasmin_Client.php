<?php
/**
 * Thin HTTP client for a self-hosted Jasmin SMS Gateway.
 *
 * Jasmin (https://jasminsms.com) is open-source SMPP middleware the
 * operator self-hosts. We never speak SMPP directly — we submit
 * messages to Jasmin's HTTP API and Jasmin relays them over its SMPP
 * route to the carrier. Two endpoints we touch:
 *
 *   GET  {baseUrl}/ping            -> "Jasmin/PONG"
 *   POST {baseUrl}/send            -> 'Success "<message-id>"' / 'Error "<reason>"'
 *
 * Delivery receipts (DLR) and mobile-originated messages (MO) come back
 * to Messageistic at /webhooks/jasmin/status and /webhooks/jasmin/inbound;
 * those endpoints are configured per-message (DLR) or once inside Jasmin
 * itself (MO route).
 *
 * @package Messageistic\Providers\Jasmin
 */

namespace Messageistic\Providers\Jasmin;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Jasmin_Client {

    private string $base_url;

    public function __construct( private array $settings ) {
        $this->base_url = rtrim( (string) ( $settings['base_url'] ?? '' ), '/' );
    }

    /**
     * Probe the gateway, then verify the httpapi credentials.
     *
     * Jasmin replies "Jasmin/PONG" on /ping — without ever asking for
     * credentials. Basic auth is still sent (harmless to vanilla Jasmin)
     * so deployments behind an HTTP-auth reverse proxy pass too. Any
     * other 2xx body is treated as a foreign server and rejected so
     * misconfigured URLs surface immediately. Once the gateway is
     * confirmed, /balance is called with the configured username and
     * password — the only httpapi route that authenticates without
     * sending a message.
     *
     * @return true|WP_Error
     */
    public function ping() {
        if ( '' === $this->base_url ) {
            return new WP_Error( 'messageistic_jasmin_no_url', __( 'Jasmin Base URL is not configured.', 'messageistic' ) );
        }

        $headers  = [ 'Accept' => 'text/plain' ];
        $username = (string) ( $this->settings['username'] ?? '' );
        $password = (string) ( $this->settings['password'] ?? '' );
        if ( '' !== $username ) {
            $headers['Authorization'] = 'Basic ' . base64_encode( $username . ':' . $password );
        }

        $response = wp_remote_get( $this->base_url . '/ping', [
            'timeout' => 15,
            'headers' => $headers,
        ] );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = trim( (string) wp_remote_retrieve_body( $response ) );

        if ( in_array( $code, [ 401, 403, 407 ], true ) ) {
            return new WP_Error( 'messageistic_jasmin_ping_auth', sprintf(
                /* translators: %d: HTTP status code from the gateway. */
                __( 'The URL answered /ping with HTTP %d, but Jasmin\'s own /ping never asks for credentials — something in front of it is. Check that the Base URL points at the Jasmin httpapi (default port 1401, not the REST API on 8080), that any reverse-proxy HTTP auth matches the username/password entered here, and that this isn\'t actually the Android gateway app (configure that on the "Local Gateway App" tab instead).', 'messageistic' ),
                $code
            ) );
        }
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'messageistic_jasmin_ping_http', sprintf(
                /* translators: %d: HTTP status code from Jasmin gateway. */
                __( 'Jasmin gateway returned HTTP %d for /ping.', 'messageistic' ),
                $code
            ) );
        }
        if ( ! preg_match( '/PONG/i', $body ) ) {
            return new WP_Error( 'messageistic_jasmin_not_jasmin', __( 'The URL responded but is not a Jasmin gateway (/ping did not return PONG).', 'messageistic' ) );
        }

        return $this->check_credentials();
    }

    /**
     * Verify the httpapi user against /balance. Fail-soft: only an explicit
     * authentication rejection fails the test — deployments that disable
     * the balance route still pass once /ping has confirmed the gateway.
     *
     * @return true|WP_Error
     */
    private function check_credentials() {
        $url = add_query_arg(
            [
                'username' => (string) ( $this->settings['username'] ?? '' ),
                'password' => (string) ( $this->settings['password'] ?? '' ),
            ],
            $this->base_url . '/balance'
        );

        $response = wp_remote_get( $url, [
            'timeout' => 15,
            'headers' => [ 'Accept' => 'text/plain' ],
        ] );
        if ( is_wp_error( $response ) ) {
            return true; // Gateway already confirmed; a transient /balance failure is not an auth verdict.
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = trim( (string) wp_remote_retrieve_body( $response ) );

        if ( in_array( $code, [ 401, 403 ], true ) || preg_match( '/Authentication failure/i', $body ) ) {
            return new WP_Error(
                'messageistic_jasmin_bad_credentials',
                __( 'The Jasmin gateway is reachable, but it rejected the HTTP API username/password (checked via /balance). Use the credentials of the httpapi user created with jcli (user -a).', 'messageistic' )
            );
        }
        return true;
    }

    /**
     * Submit an SMS to Jasmin.
     *
     * @param array<string,mixed> $payload Required: to, body. Optional: from.
     * @param string|null         $dlr_url Per-message delivery receipt URL.
     * @return array{message_id:string,status:string,raw:string}|WP_Error
     */
    public function send_message( array $payload, ?string $dlr_url = null ) {
        $to   = $this->msisdn( (string) ( $payload['to'] ?? '' ) );
        $body = (string) ( $payload['body'] ?? '' );
        if ( '' === $to || '' === $body ) {
            return new WP_Error( 'messageistic_jasmin_bad_payload', __( 'Jasmin send requires both "to" and "body".', 'messageistic' ) );
        }

        $from = (string) ( $payload['from'] ?? ( $this->settings['default_sender'] ?? '' ) );

        $params = [
            'username' => (string) ( $this->settings['username'] ?? '' ),
            'password' => (string) ( $this->settings['password'] ?? '' ),
            'to'       => $to,
            'content'  => $body,
        ];
        if ( '' !== $from ) {
            $params['from'] = $from;
        }
        if ( ! empty( $this->settings['coding'] ) ) {
            $params['coding'] = (string) $this->settings['coding']; // 0 = GSM-7, 8 = UCS-2 (Unicode)
        }
        if ( $dlr_url ) {
            $params['dlr']        = 'yes';
            $params['dlr-url']    = $dlr_url;
            $params['dlr-level']  = '3'; // SMSC + terminal receipts
            $params['dlr-method'] = 'POST';
        }

        $response = wp_remote_post( $this->base_url . '/send', [
            'timeout' => 20,
            'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
            'body'    => $params,
        ] );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $raw  = trim( (string) wp_remote_retrieve_body( $response ) );

        if ( preg_match( '/^Success\s+"?([^"\s]+)"?/i', $raw, $m ) ) {
            return [
                'message_id' => $m[1],
                'status'     => 'queued',
                'raw'        => $raw,
            ];
        }

        $reason = '';
        if ( preg_match( '/^Error\s+"?([^"]+)"?/i', $raw, $err ) ) {
            $reason = $err[1];
        }
        $reason = $reason ?: ( $raw ?: sprintf( 'HTTP %d', $code ) );

        return new WP_Error(
            'messageistic_jasmin_send_failed',
            sprintf(
                /* translators: %s: reason returned by Jasmin gateway. */
                __( 'Jasmin gateway rejected the send: %s', 'messageistic' ),
                $reason
            ),
            [ 'status' => $code ?: 502, 'body' => $raw ]
        );
    }

    /** Jasmin expects bare international MSISDNs (no leading '+'). */
    private function msisdn( string $number ): string {
        return ltrim( trim( $number ), '+' );
    }
}
