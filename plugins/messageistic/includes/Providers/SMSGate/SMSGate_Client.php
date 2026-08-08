<?php
/**
 * Thin HTTP client for "SMS Gateway for Android" (SMSGate, https://sms-gate.app)
 * — the local SMS gateway app that turns an Android phone into the SMS sender.
 *
 * Two deployment modes share one API surface:
 *
 *   Local Server  http://<phone-ip>:8080            (phone reachable on LAN/VPN)
 *   Cloud Server  https://api.sms-gate.app/3rdparty/v1  (phone pulls jobs from cloud)
 *
 * Both authenticate with HTTP Basic using the credentials shown inside the
 * app. Endpoints we touch:
 *
 *   POST   {base}/message            Submit an SMS, returns {id, state, …}
 *   GET    {base}/message/{id}       Poll a message's state
 *   GET    {base}/webhooks           List registered webhooks (used as auth probe)
 *   POST   {base}/webhooks           Register a webhook {id, url, event}
 *   DELETE {base}/webhooks/{id}      Remove a webhook
 *
 * Message states: Pending, Processed, Sent, Delivered, Failed.
 *
 * @package Messageistic\Providers\SMSGate
 */

namespace Messageistic\Providers\SMSGate;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class SMSGate_Client {

    private string $base_url;

    public function __construct( private array $settings ) {
        $this->base_url = rtrim( (string) ( $settings['base_url'] ?? '' ), '/' );
    }

    /**
     * Probe the gateway and the credentials in one round trip. GET /webhooks
     * requires Basic auth on both local and cloud mode, so a 2xx means the
     * URL points at a gateway *and* the username/password are right.
     *
     * @return true|WP_Error
     */
    public function ping() {
        $response = $this->request( 'GET', '/webhooks' );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        if ( ! is_array( $response['json'] ) ) {
            return new WP_Error(
                'messageistic_smsgate_not_gateway',
                __( 'The URL responded but does not look like an SMS Gateway app endpoint (expected a JSON webhook list).', 'messageistic' )
            );
        }
        return true;
    }

    /**
     * Submit an SMS to the gateway app.
     *
     * @param array<string,mixed> $payload Required: to, body.
     * @return array{message_id:string,status:string,raw:array<string,mixed>}|WP_Error
     */
    public function send_message( array $payload ) {
        $to   = trim( (string) ( $payload['to'] ?? '' ) );
        $body = (string) ( $payload['body'] ?? '' );
        if ( '' === $to || '' === $body ) {
            return new WP_Error( 'messageistic_smsgate_bad_payload', __( 'Local gateway send requires both "to" and "body".', 'messageistic' ) );
        }

        $message = [
            'textMessage'  => [ 'text' => $body ],
            'phoneNumbers' => [ $to ],
        ];
        if ( ! empty( $this->settings['with_delivery_report'] ) ) {
            $message['withDeliveryReport'] = true;
        }
        $sim = (int) ( $this->settings['sim_number'] ?? 0 );
        if ( $sim > 0 ) {
            $message['simNumber'] = $sim;
        }

        $response = $this->request( 'POST', '/message', $message );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $json = is_array( $response['json'] ) ? $response['json'] : [];
        $id   = (string) ( $json['id'] ?? '' );
        if ( '' === $id ) {
            return new WP_Error(
                'messageistic_smsgate_send_failed',
                sprintf(
                    /* translators: %s: response body from the gateway app. */
                    __( 'Local gateway accepted the request but returned no message id: %s', 'messageistic' ),
                    $response['body']
                )
            );
        }

        return [
            'message_id' => $id,
            'status'     => (string) ( $json['state'] ?? 'Pending' ),
            'raw'        => $json,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>|WP_Error
     */
    public function list_webhooks() {
        $response = $this->request( 'GET', '/webhooks' );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        return is_array( $response['json'] ) ? $response['json'] : [];
    }

    /**
     * Register (upsert by id) one webhook on the device.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function register_webhook( string $id, string $url, string $event ) {
        $response = $this->request( 'POST', '/webhooks', [
            'id'    => $id,
            'url'   => $url,
            'event' => $event,
        ] );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        return is_array( $response['json'] ) ? $response['json'] : [ 'id' => $id, 'url' => $url, 'event' => $event ];
    }

    /** @return true|WP_Error */
    public function delete_webhook( string $id ) {
        $response = $this->request( 'DELETE', '/webhooks/' . rawurlencode( $id ) );
        return is_wp_error( $response ) ? $response : true;
    }

    /**
     * @param array<string,mixed>|null $json_body
     * @return array{code:int,body:string,json:mixed}|WP_Error
     */
    private function request( string $method, string $path, ?array $json_body = null ) {
        if ( '' === $this->base_url ) {
            return new WP_Error( 'messageistic_smsgate_no_url', __( 'Local gateway Base URL is not configured.', 'messageistic' ) );
        }

        $args = [
            'method'  => $method,
            'timeout' => 20,
            'headers' => [
                'Accept'        => 'application/json',
                'Authorization' => 'Basic ' . base64_encode(
                    (string) ( $this->settings['username'] ?? '' ) . ':' . (string) ( $this->settings['password'] ?? '' )
                ),
            ],
        ];
        if ( null !== $json_body ) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body']                    = wp_json_encode( $json_body );
        }

        $response = wp_remote_request( $this->base_url . $path, $args );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = (string) wp_remote_retrieve_body( $response );

        if ( 401 === $code || 403 === $code ) {
            return new WP_Error(
                'messageistic_smsgate_auth_failed',
                __( 'The gateway app rejected the credentials. Copy the username and password from the app\'s Local/Cloud Server screen.', 'messageistic' ),
                [ 'status' => $code ]
            );
        }
        if ( $code < 200 || $code >= 300 ) {
            $json    = json_decode( $body, true );
            $message = is_array( $json ) && ! empty( $json['message'] ) ? (string) $json['message'] : ( $body ?: sprintf( 'HTTP %d', $code ) );
            return new WP_Error(
                'messageistic_smsgate_http_error',
                sprintf(
                    /* translators: 1: HTTP status code, 2: error detail from the gateway app. */
                    __( 'Gateway app returned HTTP %1$d: %2$s', 'messageistic' ),
                    $code,
                    $message
                ),
                [ 'status' => $code, 'body' => $body ]
            );
        }

        return [
            'code' => $code,
            'body' => $body,
            'json' => json_decode( $body, true ),
        ];
    }
}
