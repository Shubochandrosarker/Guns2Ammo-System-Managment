<?php
/**
 * OtterText provider — Phase 2 implementation.
 *
 * Capabilities are detected from the active settings (toggles in Settings →
 * OtterText). When a feature is disabled or unavailable, capability resolves
 * to `false` so dependent UI can hide the action.
 *
 * @package Messageistic\Providers
 */

namespace Messageistic\Providers;

use Messageistic\Helpers\Logger;
use Messageistic\Helpers\Raw_Body_Cache;
use Messageistic\Providers\OtterText\OtterText_Client;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class OtterText_Provider extends Abstract_Provider {

    public function get_key(): string {
        return 'ottertext';
    }

    public function get_label(): string {
        return __( 'OtterText', 'messageistic' );
    }

    public function get_capabilities(): array {
        $s = $this->get_settings();
        return [
            'send_sms'        => ! empty( $s['enable_message_sending'] ),
            'receive_replies' => ! empty( $s['enable_inbound_sync'] ),
            'delivery_status' => ! empty( $s['enable_inbound_sync'] ),
            'campaign_push'   => ! empty( $s['enable_campaign_push'] ),
            'contact_push'    => ! empty( $s['enable_contact_sync'] ),
            'optout_handling' => ! empty( $s['enable_inbound_sync'] ),
            'mms'             => false,
            'email'           => false,
        ];
    }

    public function validate_settings( array $settings ) {
        if ( empty( $settings['api_base_url'] ) ) {
            return new WP_Error( 'messageistic_ottertext_missing_url', __( 'OtterText API Base URL is required.', 'messageistic' ) );
        }
        if ( empty( $settings['api_key'] ) ) {
            return new WP_Error( 'messageistic_ottertext_missing_key', __( 'OtterText API key is required.', 'messageistic' ) );
        }
        if ( ! filter_var( $settings['api_base_url'], FILTER_VALIDATE_URL ) ) {
            return new WP_Error( 'messageistic_ottertext_bad_url', __( 'OtterText API Base URL must be a valid URL.', 'messageistic' ) );
        }
        return true;
    }

    public function test_connection( array $settings ) {
        $check = $this->validate_settings( $settings );
        if ( is_wp_error( $check ) ) {
            return $check;
        }
        $client = new OtterText_Client( $settings );
        $result = $client->ping();
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return true;
    }

    public function send_sms( array $payload ) {
        $settings = $this->get_settings();
        if ( empty( $settings['enable_message_sending'] ) ) {
            return new WP_Error(
                'messageistic_ottertext_send_disabled',
                __( 'Message sending is disabled in OtterText settings.', 'messageistic' )
            );
        }
        $check = $this->validate_settings( $settings );
        if ( is_wp_error( $check ) ) {
            return $check;
        }

        $client   = new OtterText_Client( $settings );
        $response = $client->send_message( $payload );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $message_id = (string) ( $response['id'] ?? $response['message_id'] ?? '' );
        $status     = (string) ( $response['status'] ?? 'queued' );

        return $this->normalize(
            [
                'provider_message_id' => $message_id,
                'status'              => $status ?: 'queued',
                'raw'                 => $response,
            ]
        );
    }

    /**
     * Push a campaign payload to OtterText. Returns the OtterText campaign ID.
     *
     * @param array<string,mixed> $campaign
     * @return string|WP_Error
     */
    public function push_campaign( array $campaign ) {
        $settings = $this->get_settings();
        if ( empty( $settings['enable_campaign_push'] ) ) {
            return new WP_Error(
                'messageistic_ottertext_campaign_push_disabled',
                __( 'Campaign push is disabled in OtterText settings.', 'messageistic' )
            );
        }
        $check = $this->validate_settings( $settings );
        if ( is_wp_error( $check ) ) {
            return $check;
        }
        $client   = new \Messageistic\Providers\OtterText\OtterText_Client( $settings );
        $response = $client->push_campaign( $campaign );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        return (string) ( $response['id'] ?? $response['campaign_id'] ?? '' );
    }

    /**
     * Push (or upsert) a contact to OtterText. Returns the OtterText contact ID
     * on success so callers can persist it in `provider_contact_id`.
     *
     * @param array<string,mixed> $contact
     * @return string|WP_Error
     */
    public function push_contact( array $contact ) {
        $settings = $this->get_settings();
        if ( empty( $settings['enable_contact_sync'] ) ) {
            return new WP_Error(
                'messageistic_ottertext_sync_disabled',
                __( 'Contact sync is disabled in OtterText settings.', 'messageistic' )
            );
        }
        $check = $this->validate_settings( $settings );
        if ( is_wp_error( $check ) ) {
            return $check;
        }
        $client   = new OtterText_Client( $settings );
        $response = $client->push_contact( $contact );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        return (string) ( $response['id'] ?? $response['contact_id'] ?? '' );
    }

    public function handle_inbound( array $payload ) {
        $verified = $this->verify_webhook( $payload );
        if ( is_wp_error( $verified ) ) {
            Logger::log( 'webhook.ottertext.inbound.invalid_signature', [
                'level' => 'warn',
                'provider_key' => $this->get_key(),
                'error_message' => $verified->get_error_message(),
            ] );
            return $verified;
        }
        $event = $payload['event'] ?? $payload;
        return $this->normalize( [
            'provider_message_id' => (string) ( $event['id'] ?? $event['message_id'] ?? '' ),
            'status'              => 'received',
            'raw'                 => $payload,
        ] );
    }

    public function handle_status_callback( array $payload ) {
        $verified = $this->verify_webhook( $payload );
        if ( is_wp_error( $verified ) ) {
            return $verified;
        }
        $event  = $payload['event'] ?? $payload;
        $status = (string) ( $event['status'] ?? 'unknown' );
        return $this->normalize( [
            'provider_message_id' => (string) ( $event['id'] ?? $event['message_id'] ?? '' ),
            'status'              => $status,
            'raw'                 => $payload,
        ] );
    }

    /**
     * Verify an inbound webhook against the configured shared secret.
     *
     * The secret is sent as `X-OtterText-Signature` (HMAC-SHA256 of the
     * raw body). Query-string secrets are intentionally rejected because URLs
     * are commonly retained in proxy, application, and analytics logs.
     *
     * @param array<string,mixed> $payload
     * @return true|WP_Error
     */
    private function verify_webhook( array $payload ) {
        $expected = (string) ( $this->get_settings()['webhook_secret'] ?? '' );
        if ( '' === $expected ) {
            return new WP_Error(
                'messageistic_ottertext_missing_secret',
                __( 'OtterText webhook authentication is not configured.', 'messageistic' ),
                [ 'status' => 403 ]
            );
        }

        $sig = isset( $_SERVER['HTTP_X_OTTERTEXT_SIGNATURE'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_OTTERTEXT_SIGNATURE'] ) )
            : '';
        if ( '' !== $sig ) {
            $raw  = Raw_Body_Cache::get();
            $calc = hash_hmac( 'sha256', $raw, $expected );
            $sig  = str_starts_with( $sig, 'sha256=' ) ? substr( $sig, 7 ) : $sig;
            if ( hash_equals( $calc, $sig ) ) {
                return true;
            }
            return new WP_Error(
                'messageistic_ottertext_bad_signature',
                __( 'OtterText webhook signature mismatch.', 'messageistic' ),
                [ 'status' => 403 ]
            );
        }

        return new WP_Error(
            'messageistic_ottertext_missing_signature',
            __( 'OtterText webhook signature missing.', 'messageistic' ),
            [ 'status' => 403 ]
        );
    }
}
