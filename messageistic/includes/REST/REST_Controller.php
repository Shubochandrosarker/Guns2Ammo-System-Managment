<?php
/**
 * Phase 1+ REST routes — provider info/test, webhooks routed through providers.
 *
 * Phase 8 controllers register their own additional routes via Plugin::boot().
 *
 * @package Messageistic\REST
 */

namespace Messageistic\REST;

use Messageistic\Core\Permissions;
use Messageistic\Helpers\Logger;
use Messageistic\Messaging\Inbound_Handler;
use Messageistic\Providers\Abstract_Provider;
use Messageistic\Providers\Provider_Manager;
use Messageistic\Providers\SMSGate_Provider;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class REST_Controller {

    public const NAMESPACE_V1 = 'messageistic/v1';

    public function __construct( private Provider_Manager $providers ) {}

    public function register_routes(): void {
        register_rest_route(
            self::NAMESPACE_V1,
            '/provider',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_provider' ],
                    'permission_callback' => [ $this, 'can_view' ],
                ],
                [
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => [ $this, 'set_provider' ],
                    'permission_callback' => [ $this, 'can_manage_providers' ],
                    'args'                => [
                        'key' => [
                            'type'              => 'string',
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_key',
                        ],
                    ],
                ],
            ]
        );

        register_rest_route( self::NAMESPACE_V1, '/provider/capabilities', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_capabilities' ],
            'permission_callback' => [ $this, 'can_view' ],
        ] );

        register_rest_route( self::NAMESPACE_V1, '/provider/test', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'test_provider' ],
            'permission_callback' => [ $this, 'can_manage_providers' ],
            'args'                => [
                'key' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE_V1, '/provider/smsgate/webhooks', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'register_smsgate_webhooks' ],
            'permission_callback' => [ $this, 'can_manage_providers' ],
        ] );

        foreach ( [ 'twilio', 'ottertext', 'jasmin', 'smsgate' ] as $provider_key ) {
            register_rest_route( self::NAMESPACE_V1, "/webhooks/{$provider_key}/inbound", [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'webhook_inbound' ],
                'permission_callback' => '__return_true',
            ] );
            register_rest_route( self::NAMESPACE_V1, "/webhooks/{$provider_key}/status", [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'webhook_status' ],
                'permission_callback' => '__return_true',
            ] );
        }
    }

    public function can_view(): bool {
        return current_user_can( Permissions::VIEW );
    }

    public function can_manage_providers(): bool {
        return current_user_can( Permissions::MANAGE_PROVIDERS );
    }

    public function get_provider(): WP_REST_Response {
        return rest_ensure_response( $this->providers->get_health() );
    }

    public function set_provider( WP_REST_Request $request ) {
        $result = $this->providers->set_active( (string) $request->get_param( 'key' ), get_current_user_id() );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( $this->providers->get_health() );
    }

    public function get_capabilities(): WP_REST_Response {
        $matrix = [];
        foreach ( $this->providers->all() as $key => $provider ) {
            $matrix[ $key ] = [
                'label'        => $provider->get_label(),
                'capabilities' => $provider->get_capabilities(),
            ];
        }
        return rest_ensure_response( $matrix );
    }

    public function test_provider( WP_REST_Request $request ) {
        $key      = sanitize_key( (string) $request->get_param( 'key' ) ) ?: $this->providers->get_active_key();
        $provider = $this->providers->get( $key );
        if ( ! $provider ) {
            return new WP_Error( 'messageistic_unknown_provider', __( 'Unknown provider.', 'messageistic' ), [ 'status' => 404 ] );
        }
        // Stored secrets are encrypted at rest; the provider's accessor decrypts them.
        $settings = $provider instanceof Abstract_Provider ? $provider->get_settings() : [];
        $result   = $provider->test_connection( $settings );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( [ 'ok' => true, 'key' => $key ] );
    }

    public function register_smsgate_webhooks() {
        $provider = $this->providers->get( 'smsgate' );
        if ( ! $provider instanceof SMSGate_Provider ) {
            return new WP_Error( 'messageistic_unknown_provider', __( 'Unknown provider.', 'messageistic' ), [ 'status' => 404 ] );
        }
        $result = $provider->register_device_webhooks();
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( [ 'ok' => true, 'webhooks' => $result ] );
    }

    /**
     * Defense-in-depth: only the ACTIVE provider's webhook routes dispatch.
     * Every provider still verifies its own signature/shared secret, but
     * this shrinks the unauthenticated surface to a single adapter. Sites
     * migrating providers (late DLRs from the old one) can widen it via the
     * `messageistic_webhook_allowed_providers` filter.
     *
     * @return true|WP_Error
     */
    private function webhook_provider_allowed( string $provider_key ) {
        $allowed = apply_filters(
            'messageistic_webhook_allowed_providers',
            [ $this->providers->get_active_key() ]
        );
        if ( in_array( $provider_key, (array) $allowed, true ) ) {
            return true;
        }
        return new WP_Error(
            'messageistic_provider_inactive',
            __( 'Webhooks for this provider are not enabled.', 'messageistic' ),
            [ 'status' => 403 ]
        );
    }

    public function webhook_inbound( WP_REST_Request $request ) {
        $provider_key = $this->detect_provider_from_route( $request );
        $provider     = $this->providers->get( $provider_key );
        if ( ! $provider ) {
            return new WP_Error( 'messageistic_unknown_provider', __( 'Unknown provider.', 'messageistic' ), [ 'status' => 404 ] );
        }
        $gate = $this->webhook_provider_allowed( $provider_key );
        if ( is_wp_error( $gate ) ) {
            return $gate;
        }
        $payload = $request->get_params();
        $result  = $provider->handle_inbound( $payload );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        Logger::log( "webhook.{$provider_key}.inbound", [
            'provider_key'        => $provider_key,
            'provider_message_id' => (string) ( $result['provider_message_id'] ?? '' ),
            'status'              => (string) ( $result['status'] ?? '' ),
            'raw_response'        => $result['raw'] ?? null,
        ] );
        ( new Inbound_Handler() )->handle( $provider_key, $result );
        return rest_ensure_response( [ 'ok' => true ] );
    }

    public function webhook_status( WP_REST_Request $request ) {
        $provider_key = $this->detect_provider_from_route( $request );
        $provider     = $this->providers->get( $provider_key );
        if ( ! $provider ) {
            return new WP_Error( 'messageistic_unknown_provider', __( 'Unknown provider.', 'messageistic' ), [ 'status' => 404 ] );
        }
        $gate = $this->webhook_provider_allowed( $provider_key );
        if ( is_wp_error( $gate ) ) {
            return $gate;
        }
        $payload = $request->get_params();
        $result  = $provider->handle_status_callback( $payload );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        Logger::log( "webhook.{$provider_key}.status", [
            'provider_key'        => $provider_key,
            'provider_message_id' => (string) ( $result['provider_message_id'] ?? '' ),
            'status'              => (string) ( $result['status'] ?? '' ),
            'raw_response'        => $result['raw'] ?? null,
        ] );
        ( new Inbound_Handler() )->update_status( $provider_key, $result );
        return rest_ensure_response( [ 'ok' => true ] );
    }

    private function detect_provider_from_route( WP_REST_Request $request ): string {
        if ( preg_match( '#/webhooks/([a-z0-9_-]+)/#', (string) $request->get_route(), $m ) ) {
            return $m[1];
        }
        return sanitize_key( (string) $request->get_param( 'provider' ) );
    }
}
