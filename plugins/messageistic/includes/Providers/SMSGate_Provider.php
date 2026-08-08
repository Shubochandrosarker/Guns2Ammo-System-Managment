<?php
/**
 * Local SMS gateway app provider — "SMS Gateway for Android" (SMSGate,
 * https://sms-gate.app). The operator installs the open-source app on an
 * Android phone with a SIM; the phone becomes the SMS sender/receiver and
 * no traffic crosses a third-party SaaS.
 *
 * Two ways to reach the phone, same API:
 *   Local Server  http://<phone-ip>:8080 — WordPress and the phone share a
 *                 LAN/VPN; fully self-hosted.
 *   Cloud Server  https://api.sms-gate.app/3rdparty/v1 — the phone pulls
 *                 jobs from the vendor relay; use when WP cannot reach the
 *                 phone directly. Message content transits the relay.
 *
 * Settings:
 *   base_url             Gateway base URL (one of the two above)
 *   username / password  Basic auth credentials shown inside the app
 *   sim_number           Optional SIM slot (1/2/3); empty = device default
 *   with_delivery_report Request delivery reports per message (default on)
 *   webhook_secret       Shared secret bound to webhook URLs (?token=…)
 *   signing_key          Optional HMAC key — must match the app's webhook
 *                        "signing key"; verifies X-Signature/X-Timestamp
 *   validate_signature   Refuse callbacks without a valid token/signature
 *
 * Webhook events the app pushes to WordPress (registered via the
 * "Register Webhooks" button or manually):
 *   sms:received  -> /webhooks/smsgate/inbound   (replies, STOP/START)
 *   sms:sent      -> /webhooks/smsgate/status
 *   sms:delivered -> /webhooks/smsgate/status
 *   sms:failed    -> /webhooks/smsgate/status
 *
 * @package Messageistic\Providers
 */

namespace Messageistic\Providers;

use Messageistic\Helpers\Logger;
use Messageistic\Helpers\Raw_Body_Cache;
use Messageistic\Providers\SMSGate\SMSGate_Client;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class SMSGate_Provider extends Abstract_Provider {

    public const STOP_KEYWORDS  = [ 'STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT' ];
    public const START_KEYWORDS = [ 'START', 'YES', 'UNSTOP' ];

    /** Maximum allowed skew for signed webhook timestamps (seconds). */
    private const SIGNATURE_TTL = 300;

    public function get_key(): string {
        return 'smsgate';
    }

    public function get_label(): string {
        return __( 'Local Gateway App (Android phone)', 'messageistic' );
    }

    public function get_capabilities(): array {
        return [
            'send_sms'        => true,
            'receive_replies' => true,
            'delivery_status' => true,
            'campaign_push'   => false, // Campaigns run in our internal queue.
            'contact_push'    => false, // The phone has no contact universe to push to.
            'optout_handling' => true,
            'mms'             => false,
            'email'           => false,
        ];
    }

    public function validate_settings( array $settings ) {
        foreach ( [ 'base_url', 'username', 'password' ] as $field ) {
            if ( empty( $settings[ $field ] ) ) {
                return new WP_Error(
                    'messageistic_smsgate_missing_setting',
                    /* translators: %s: setting name. */
                    sprintf( __( 'Local gateway setting "%s" is required.', 'messageistic' ), $field )
                );
            }
        }
        if ( ! filter_var( $settings['base_url'], FILTER_VALIDATE_URL ) || ! preg_match( '#^https?://#i', (string) $settings['base_url'] ) ) {
            return new WP_Error(
                'messageistic_smsgate_bad_url',
                __( 'Local gateway Base URL must be a valid http:// or https:// URL.', 'messageistic' )
            );
        }
        return true;
    }

    public function test_connection( array $settings ) {
        $check = $this->validate_settings( $settings );
        if ( is_wp_error( $check ) ) {
            return $check;
        }
        return ( new SMSGate_Client( $settings ) )->ping();
    }

    public function send_sms( array $payload ) {
        $settings = $this->get_settings();
        $check    = $this->validate_settings( $settings );
        if ( is_wp_error( $check ) ) {
            return $check;
        }

        $response = ( new SMSGate_Client( $settings ) )->send_message( $payload );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $this->normalize(
            [
                'provider_message_id' => (string) ( $response['message_id'] ?? '' ),
                'status'              => self::map_state( (string) ( $response['status'] ?? '' ) ),
                'raw'                 => $response,
            ]
        );
    }

    /**
     * Push the four Messageistic webhooks onto the device so inbound
     * replies and delivery events flow back automatically. Idempotent —
     * the gateway upserts by webhook id.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function register_device_webhooks() {
        $settings = $this->get_settings();
        $check    = $this->validate_settings( $settings );
        if ( is_wp_error( $check ) ) {
            return $check;
        }

        $token       = (string) ( $settings['webhook_secret'] ?? '' );
        $inbound_url = rest_url( 'messageistic/v1/webhooks/smsgate/inbound' );
        $status_url  = rest_url( 'messageistic/v1/webhooks/smsgate/status' );
        if ( '' !== $token ) {
            $inbound_url = add_query_arg( 'token', $token, $inbound_url );
            $status_url  = add_query_arg( 'token', $token, $status_url );
        }

        $client  = new SMSGate_Client( $settings );
        $hooks   = [
            'messageistic-received'  => [ $inbound_url, 'sms:received' ],
            'messageistic-sent'      => [ $status_url, 'sms:sent' ],
            'messageistic-delivered' => [ $status_url, 'sms:delivered' ],
            'messageistic-failed'    => [ $status_url, 'sms:failed' ],
        ];
        $results = [];
        foreach ( $hooks as $id => [ $url, $event ] ) {
            $result = $client->register_webhook( $id, $url, $event );
            if ( is_wp_error( $result ) ) {
                return new WP_Error(
                    'messageistic_smsgate_webhook_registration_failed',
                    sprintf(
                        /* translators: 1: webhook event name, 2: error detail. */
                        __( 'Could not register the %1$s webhook: %2$s', 'messageistic' ),
                        $event,
                        $result->get_error_message()
                    )
                );
            }
            $results[ $event ] = $url;
        }

        Logger::log( 'provider.smsgate.webhooks_registered', [
            'provider_key' => $this->get_key(),
            'status'       => 'registered',
            'context'      => [ 'events' => array_keys( $results ) ],
        ] );

        return $results;
    }

    /**
     * Inbound MO message. The app POSTs JSON:
     *   { "event": "sms:received", "deviceId": "…", "webhookId": "…",
     *     "payload": { "messageId": "…", "message": "…", "phoneNumber": "+1…",
     *                  "simNumber": 1, "receivedAt": "…" } }
     */
    public function handle_inbound( array $payload ) {
        $verified = $this->verify_request();
        if ( is_wp_error( $verified ) ) {
            Logger::log( 'webhook.smsgate.inbound.rejected', [
                'level'         => 'warn',
                'provider_key'  => $this->get_key(),
                'error_message' => $verified->get_error_message(),
            ] );
            return $verified;
        }

        return $this->normalize( self::parse_webhook( $payload ) );
    }

    /**
     * Delivery lifecycle callback — sms:sent / sms:delivered / sms:failed,
     * same envelope as inbound with payload.messageId referencing the id
     * returned at send time.
     */
    public function handle_status_callback( array $payload ) {
        $verified = $this->verify_request();
        if ( is_wp_error( $verified ) ) {
            Logger::log( 'webhook.smsgate.status.rejected', [
                'level'         => 'warn',
                'provider_key'  => $this->get_key(),
                'error_message' => $verified->get_error_message(),
            ] );
            return $verified;
        }

        return $this->normalize( self::parse_webhook( $payload ) );
    }

    /**
     * Map a gateway message/recipient state onto Messageistic's vocabulary.
     * Pure — unit-tested without WordPress.
     */
    public static function map_state( string $state ): string {
        return match ( strtolower( $state ) ) {
            'pending', 'processed' => 'queued',
            'sent'                 => 'sent',
            'delivered'            => 'delivered',
            'failed'               => 'failed',
            default                => 'unknown',
        };
    }

    /**
     * Normalize a webhook envelope (inbound or status) into the shape the
     * Inbound_Handler consumes. Pure — unit-tested without WordPress.
     *
     * @param array<string,mixed> $payload Decoded webhook request body.
     * @return array{provider_message_id:string,status:string,raw:array<string,mixed>}
     */
    public static function parse_webhook( array $payload ): array {
        $event = (string) ( $payload['event'] ?? '' );
        $data  = isset( $payload['payload'] ) && is_array( $payload['payload'] ) ? $payload['payload'] : $payload;

        $message_id = (string) ( $data['messageId'] ?? $data['id'] ?? '' );
        $phone      = (string) ( $data['phoneNumber'] ?? '' );
        $body       = trim( (string) ( $data['message'] ?? '' ) );

        switch ( $event ) {
            case 'sms:sent':
                $status = 'sent';
                break;
            case 'sms:delivered':
                $status = 'delivered';
                break;
            case 'sms:failed':
                $status = 'failed';
                break;
            case 'system:ping':
                $status = 'ping';
                break;
            default: // sms:received and legacy payloads without an event field.
                $upper  = strtoupper( $body );
                $status = in_array( $upper, self::STOP_KEYWORDS, true )
                    ? 'optout'
                    : ( in_array( $upper, self::START_KEYWORDS, true ) ? 'optin' : 'received' );
                break;
        }

        return [
            'provider_message_id' => $message_id,
            'status'              => $status,
            'raw'                 => [
                'event'    => $event,
                'from'     => $phone,
                'to'       => '',
                'body'     => $body,
                'is_stop'  => 'optout' === $status,
                'is_start' => 'optin' === $status,
                'payload'  => $payload,
            ],
        ];
    }

    /**
     * Authenticate a callback. Two independent factors, both optional but
     * at least one required when validation is on:
     *
     *  - `?token=…` shared secret bound to the webhook URL at registration;
     *  - HMAC-SHA256 signature: the app sends X-Signature (hex) and
     *    X-Timestamp, where signature = HMAC(raw_body . timestamp, signing_key).
     *    Timestamps older than five minutes are rejected to stop replays.
     *
     * @return true|WP_Error
     */
    private function verify_request() {
        $settings = $this->get_settings();
        if ( empty( $settings['validate_signature'] ) ) {
            return true;
        }

        $secret      = (string) ( $settings['webhook_secret'] ?? '' );
        $signing_key = (string) ( $settings['signing_key'] ?? '' );
        if ( '' === $secret && '' === $signing_key ) {
            return new WP_Error(
                'messageistic_smsgate_missing_secret',
                __( 'Webhook validation is enabled but neither a Webhook Secret nor a Signing Key is configured.', 'messageistic' ),
                [ 'status' => 403 ]
            );
        }

        if ( '' !== $secret ) {
            $token = isset( $_GET['token'] ) ? (string) wp_unslash( $_GET['token'] ) : '';
            if ( '' === $token || ! hash_equals( $secret, $token ) ) {
                return new WP_Error(
                    'messageistic_smsgate_bad_token',
                    __( 'Local gateway webhook token mismatch.', 'messageistic' ),
                    [ 'status' => 403 ]
                );
            }
        }

        if ( '' !== $signing_key ) {
            $signature = isset( $_SERVER['HTTP_X_SIGNATURE'] ) ? strtolower( trim( (string) wp_unslash( $_SERVER['HTTP_X_SIGNATURE'] ) ) ) : '';
            $timestamp = isset( $_SERVER['HTTP_X_TIMESTAMP'] ) ? trim( (string) wp_unslash( $_SERVER['HTTP_X_TIMESTAMP'] ) ) : '';
            if ( '' === $signature || '' === $timestamp ) {
                return new WP_Error(
                    'messageistic_smsgate_missing_signature',
                    __( 'Local gateway webhook is missing the X-Signature / X-Timestamp headers.', 'messageistic' ),
                    [ 'status' => 403 ]
                );
            }
            if ( abs( time() - (int) $timestamp ) > self::SIGNATURE_TTL ) {
                return new WP_Error(
                    'messageistic_smsgate_stale_signature',
                    __( 'Local gateway webhook timestamp is outside the allowed window.', 'messageistic' ),
                    [ 'status' => 403 ]
                );
            }
            $expected = hash_hmac( 'sha256', Raw_Body_Cache::get() . $timestamp, $signing_key );
            if ( ! hash_equals( $expected, $signature ) ) {
                return new WP_Error(
                    'messageistic_smsgate_bad_signature',
                    __( 'Local gateway webhook signature mismatch.', 'messageistic' ),
                    [ 'status' => 403 ]
                );
            }
        }

        return true;
    }
}
