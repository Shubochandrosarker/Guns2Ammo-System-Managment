<?php
/**
 * Self-hosted SMS provider — talks to a tenant-owned Jasmin SMS Gateway.
 *
 * Jasmin is open-source SMPP middleware (https://jasminsms.com). The
 * operator hosts it, points it at an SMPP route from any carrier or
 * aggregator (Twilio Programmable SMS over SMPP, Vonage, Sinch, BulkSMS,
 * Plivo, or a local MVNO), and this provider submits messages to its
 * HTTP API. No SMS traffic ever crosses Messageistic's vendor SaaS.
 *
 * Settings:
 *   base_url        Full HTTP base of the gateway, e.g. https://sms.example.com
 *   username        Jasmin httpapi user
 *   password        Jasmin httpapi password
 *   default_sender  Sender ID / number (DID or alphanumeric, carrier-permitting)
 *   coding          "0" = GSM-7 (default), "8" = UCS-2 (full Unicode)
 *   webhook_secret  Shared secret bound to DLR/MO callback URLs (?token=…)
 *   validate_signature  Whether to refuse DLR/MO without a matching token
 *
 * @package Messageistic\Providers
 */

namespace Messageistic\Providers;

use Messageistic\Helpers\Logger;
use Messageistic\Providers\Jasmin\Jasmin_Client;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Jasmin_Provider extends Abstract_Provider {

    public const STOP_KEYWORDS  = [ 'STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT' ];
    public const START_KEYWORDS = [ 'START', 'YES', 'UNSTOP' ];

    public function get_key(): string {
        return 'jasmin';
    }

    public function get_label(): string {
        return __( 'Self-hosted SMS (Jasmin)', 'messageistic' );
    }

    public function get_capabilities(): array {
        return [
            'send_sms'        => true,
            'receive_replies' => true,
            'delivery_status' => true,
            'campaign_push'   => false, // Jasmin has no campaign concept — campaigns run in our internal queue.
            'contact_push'    => false, // No contact universe on the gateway.
            'optout_handling' => true,
            'mms'             => false,
            'email'           => false,
        ];
    }

    public function validate_settings( array $settings ) {
        foreach ( [ 'base_url', 'username', 'password' ] as $field ) {
            if ( empty( $settings[ $field ] ) ) {
                return new WP_Error(
                    'messageistic_jasmin_missing_setting',
                    /* translators: %s: setting name. */
                    sprintf( __( 'Jasmin setting "%s" is required.', 'messageistic' ), $field )
                );
            }
        }
        if ( ! filter_var( $settings['base_url'], FILTER_VALIDATE_URL ) ) {
            return new WP_Error(
                'messageistic_jasmin_bad_url',
                __( 'Jasmin Base URL must be a valid URL.', 'messageistic' )
            );
        }
        if ( ! preg_match( '#^https?://#i', (string) $settings['base_url'] ) ) {
            return new WP_Error(
                'messageistic_jasmin_bad_scheme',
                __( 'Jasmin Base URL must use http:// or https://.', 'messageistic' )
            );
        }
        return true;
    }

    public function test_connection( array $settings ) {
        $check = $this->validate_settings( $settings );
        if ( is_wp_error( $check ) ) {
            return $check;
        }
        $client = new Jasmin_Client( $settings );
        return $client->ping();
    }

    public function send_sms( array $payload ) {
        $settings = $this->get_settings();
        $check    = $this->validate_settings( $settings );
        if ( is_wp_error( $check ) ) {
            return $check;
        }

        $client   = new Jasmin_Client( $settings );
        $dlr_url  = $this->dlr_url( $settings );
        $response = $client->send_message( $payload, $dlr_url );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $this->normalize(
            [
                'provider_message_id' => (string) ( $response['message_id'] ?? '' ),
                'status'              => (string) ( $response['status'] ?? 'queued' ),
                'raw'                 => $response,
            ]
        );
    }

    /**
     * Inbound MO (mobile-originated) message. Jasmin's httpapi delivery
     * connector POSTs application/x-www-form-urlencoded with:
     *   id, from, to, content (+ optional origin-connector, priority)
     *
     * STOP / START keywords are surfaced so the Inbound_Handler can apply
     * opt-out without us touching the opt-out table directly.
     */
    public function handle_inbound( array $payload ) {
        $verified = $this->verify_request();
        if ( is_wp_error( $verified ) ) {
            Logger::log( 'webhook.jasmin.inbound.invalid_token', [
                'level'         => 'warn',
                'provider_key'  => $this->get_key(),
                'error_message' => $verified->get_error_message(),
            ] );
            return $verified;
        }

        $body       = trim( (string) ( $payload['content'] ?? $payload['Body'] ?? '' ) );
        $from       = (string) ( $payload['from'] ?? '' );
        $to         = (string) ( $payload['to'] ?? '' );
        $message_id = (string) ( $payload['id'] ?? $payload['message_id'] ?? '' );
        $upper      = strtoupper( $body );

        $is_stop  = in_array( $upper, self::STOP_KEYWORDS, true );
        $is_start = in_array( $upper, self::START_KEYWORDS, true );

        return $this->normalize( [
            'provider_message_id' => $message_id,
            'status'              => $is_stop ? 'optout' : ( $is_start ? 'optin' : 'received' ),
            'raw'                 => [
                'from'     => $from,
                'to'       => $to,
                'body'     => $body,
                'is_stop'  => $is_stop,
                'is_start' => $is_start,
                'payload'  => $payload,
            ],
        ] );
    }

    /**
     * Delivery receipt callback. Jasmin posts DLR fields:
     *   id (message-id), message_status, level, subdate, donedate, sub, dlvrd, err, text
     *
     * `message_status` follows SMPP DLR conventions: DELIVRD, EXPIRED,
     * UNDELIV, ACCEPTD, REJECTD, ENROUTE, UNKNOWN — we normalize to our
     * vocabulary so the dashboard reports uniformly across providers.
     */
    public function handle_status_callback( array $payload ) {
        $verified = $this->verify_request();
        if ( is_wp_error( $verified ) ) {
            return $verified;
        }
        $raw_status = strtoupper( (string) ( $payload['message_status'] ?? $payload['dlvrd'] ?? '' ) );
        $normalized = match ( $raw_status ) {
            'DELIVRD'           => 'delivered',
            'ACCEPTD', 'ENROUTE' => 'sent',
            'EXPIRED', 'UNDELIV', 'REJECTD', 'DELETED' => 'failed',
            default              => 'unknown',
        };

        return $this->normalize( [
            'provider_message_id' => (string) ( $payload['id'] ?? $payload['message_id'] ?? '' ),
            'status'              => $normalized,
            'raw'                 => $payload,
        ] );
    }

    /**
     * Compose the DLR callback URL for a per-message send. Returns null
     * when the site URL isn't known (CLI installs, etc.) — Jasmin will
     * still send the message, just without per-message DLR.
     */
    private function dlr_url( array $settings ): ?string {
        $url = rest_url( 'messageistic/v1/webhooks/jasmin/status' );
        if ( '' === $url ) {
            return null;
        }
        $token = (string) ( $settings['webhook_secret'] ?? '' );
        if ( '' !== $token ) {
            $url = add_query_arg( 'token', $token, $url );
        }
        return $url;
    }

    /**
     * Validate that the inbound request carries our shared secret. Jasmin
     * has no native signature on httpapi callbacks; the operator binds the
     * Messageistic webhook URL to a `?token=…` query string when wiring
     * the DLR/MO route, and we compare with hash_equals.
     *
     * If `validate_signature` is off the request is accepted as-is, so
     * developers can spike a flow without configuring the secret. Don't
     * leave that off in production — the rejection log will tell you so.
     *
     * @return true|WP_Error
     */
    private function verify_request() {
        $settings = $this->get_settings();
        if ( empty( $settings['validate_signature'] ) ) {
            return true;
        }
        $expected = (string) ( $settings['webhook_secret'] ?? '' );
        if ( '' === $expected ) {
            return new WP_Error(
                'messageistic_jasmin_missing_secret',
                __( 'Jasmin webhook authentication is enabled but no Webhook Secret is set.', 'messageistic' ),
                [ 'status' => 403 ]
            );
        }
        $token = isset( $_GET['token'] ) ? (string) wp_unslash( $_GET['token'] ) : '';
        if ( '' === $token || ! hash_equals( $expected, $token ) ) {
            return new WP_Error(
                'messageistic_jasmin_bad_token',
                __( 'Jasmin webhook token mismatch.', 'messageistic' ),
                [ 'status' => 403 ]
            );
        }
        return true;
    }
}
