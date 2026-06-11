<?php
/**
 * Twilio provider — Phase 3 implementation.
 *
 * Settings: account_sid, auth_token, messaging_service_sid, from_number,
 * validate_signature.
 *
 * @package Messageistic\Providers
 */

namespace Messageistic\Providers;

use Messageistic\Helpers\Logger;
use Messageistic\Providers\Twilio\Twilio_Client;
use Messageistic\Providers\Twilio\Twilio_Signature;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Twilio_Provider extends Abstract_Provider {

    public const STOP_KEYWORDS  = [ 'STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT' ];
    public const START_KEYWORDS = [ 'START', 'YES', 'UNSTOP' ];

    public function get_key(): string {
        return 'twilio';
    }

    public function get_label(): string {
        return __( 'Twilio', 'messageistic' );
    }

    public function get_capabilities(): array {
        return [
            'send_sms'        => true,
            'receive_replies' => true,
            'delivery_status' => true,
            'campaign_push'   => false,
            'contact_push'    => false,
            'optout_handling' => true,
            'mms'             => false,
            'email'           => false,
        ];
    }

    public function validate_settings( array $settings ) {
        foreach ( [ 'account_sid', 'auth_token' ] as $field ) {
            if ( empty( $settings[ $field ] ) ) {
                return new WP_Error(
                    'messageistic_twilio_missing_setting',
                    /* translators: %s: setting name. */
                    sprintf( __( 'Twilio setting "%s" is required.', 'messageistic' ), $field )
                );
            }
        }
        if ( empty( $settings['messaging_service_sid'] ) && empty( $settings['from_number'] ) ) {
            return new WP_Error(
                'messageistic_twilio_missing_sender',
                __( 'Either a Messaging Service SID or a From Number is required.', 'messageistic' )
            );
        }
        return true;
    }

    public function test_connection( array $settings ) {
        $check = $this->validate_settings( $settings );
        if ( is_wp_error( $check ) ) {
            return $check;
        }
        $client   = new Twilio_Client( $settings );
        $response = $client->fetch_account();
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        return true;
    }

    public function send_sms( array $payload ) {
        $settings = $this->get_settings();
        $check    = $this->validate_settings( $settings );
        if ( is_wp_error( $check ) ) {
            return $check;
        }

        $client          = new Twilio_Client( $settings );
        $status_callback = rest_url( 'messageistic/v1/webhooks/twilio/status' );
        $response        = $client->send_message( $payload, $status_callback );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $this->normalize(
            [
                'provider_message_id' => (string) ( $response['sid'] ?? '' ),
                'status'              => (string) ( $response['status'] ?? 'queued' ),
                'raw'                 => $response,
            ]
        );
    }

    public function handle_inbound( array $payload ) {
        $verified = $this->verify_request( $payload );
        if ( is_wp_error( $verified ) ) {
            Logger::log( 'webhook.twilio.inbound.invalid_signature', [
                'level'         => 'warn',
                'provider_key'  => $this->get_key(),
                'error_message' => $verified->get_error_message(),
            ] );
            return $verified;
        }

        $body         = trim( (string) ( $payload['Body'] ?? '' ) );
        $from         = (string) ( $payload['From'] ?? '' );
        $to           = (string) ( $payload['To'] ?? '' );
        $message_sid  = (string) ( $payload['MessageSid'] ?? $payload['SmsSid'] ?? '' );
        $upper_body   = strtoupper( $body );

        $is_stop  = in_array( $upper_body, self::STOP_KEYWORDS, true );
        $is_start = in_array( $upper_body, self::START_KEYWORDS, true );

        return $this->normalize( [
            'provider_message_id' => $message_sid,
            'status'              => $is_stop ? 'optout' : ( $is_start ? 'optin' : 'received' ),
            'raw'                 => [
                'from'    => $from,
                'to'      => $to,
                'body'    => $body,
                'is_stop' => $is_stop,
                'is_start' => $is_start,
                'payload' => $payload,
            ],
        ] );
    }

    public function handle_status_callback( array $payload ) {
        $verified = $this->verify_request( $payload );
        if ( is_wp_error( $verified ) ) {
            return $verified;
        }
        return $this->normalize( [
            'provider_message_id' => (string) ( $payload['MessageSid'] ?? $payload['SmsSid'] ?? '' ),
            'status'              => (string) ( $payload['MessageStatus'] ?? $payload['SmsStatus'] ?? 'unknown' ),
            'raw'                 => $payload,
        ] );
    }

    /**
     * @param array<string,mixed> $payload
     * @return true|WP_Error
     */
    private function verify_request( array $payload ) {
        $settings   = $this->get_settings();
        $auth_token = (string) ( $settings['auth_token'] ?? '' );
        if ( '' === $auth_token ) {
            return new WP_Error(
                'messageistic_twilio_missing_auth_token',
                __( 'Twilio webhook authentication is not configured.', 'messageistic' ),
                [ 'status' => 403 ]
            );
        }

        $signature = isset( $_SERVER['HTTP_X_TWILIO_SIGNATURE'] )
            ? (string) wp_unslash( $_SERVER['HTTP_X_TWILIO_SIGNATURE'] )
            : '';

        // Twilio signs the EXACT form-encoded POST fields it transmitted.
        // The REST-layer $payload merges route vars and query params on top
        // of the body, which corrupts the signature base string — so verify
        // against the raw $_POST set instead, falling back to $payload only
        // for non-form deliveries.
        $raw    = ! empty( $_POST ) ? wp_unslash( $_POST ) : $payload; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $params = [];
        foreach ( (array) $raw as $key => $value ) {
            if ( is_scalar( $value ) ) {
                $params[ (string) $key ] = (string) $value;
            }
        }

        $url   = ( is_ssl() ? 'https://' : 'http://' ) . ( $_SERVER['HTTP_HOST'] ?? '' ) . ( $_SERVER['REQUEST_URI'] ?? '' );
        $valid = Twilio_Signature::is_valid( $auth_token, $url, $params, $signature );

        if ( ! $valid ) {
            return new WP_Error(
                'messageistic_twilio_bad_signature',
                __( 'Twilio webhook signature mismatch.', 'messageistic' ),
                [ 'status' => 403 ]
            );
        }
        return true;
    }
}
