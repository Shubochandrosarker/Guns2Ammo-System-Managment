<?php
/**
 * Thin Twilio API client (Basic auth, form-encoded body).
 *
 * @package Messageistic\Providers\Twilio
 */

namespace Messageistic\Providers\Twilio;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Twilio_Client {

    private const API_BASE = 'https://api.twilio.com/2010-04-01';

    public function __construct( private array $settings ) {}

    public function fetch_account() {
        $sid = (string) ( $this->settings['account_sid'] ?? '' );
        return $this->request( 'GET', "/Accounts/{$sid}.json" );
    }

    /**
     * Scan Messages with pagination.
     *
     * Twilio paginates with `Page` + `PageSize` and returns `next_page_uri`.
     * If `$next_page_uri` is supplied we fetch it absolute; otherwise we
     * build the standard /Messages.json call. Inbound contact extraction
     * filters by `To` (our number) so we only get messages sent TO us.
     *
     * @param array<string,mixed> $args { direction?, page_size?, next_page_uri?, to?, from? }
     * @return array{items:array<int,array<string,mixed>>,next_page_uri:string|null,raw:mixed}|\WP_Error
     */
    public function list_messages( array $args = [] ) {
        $sid = (string) ( $this->settings['account_sid'] ?? '' );
        $next = (string) ( $args['next_page_uri'] ?? '' );

        if ( '' !== $next ) {
            $response = $this->raw_request( 'GET', $next );
        } else {
            $query = [
                'PageSize' => max( 1, min( 1000, (int) ( $args['page_size'] ?? 100 ) ) ),
            ];
            foreach ( [ 'To', 'From' ] as $f ) {
                $key = strtolower( $f );
                if ( ! empty( $args[ $key ] ) ) {
                    $query[ $f ] = (string) $args[ $key ];
                }
            }
            $url = self::API_BASE . "/Accounts/{$sid}/Messages.json?" . http_build_query( $query );
            $response = $this->raw_request( 'GET', $url );
        }

        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $items   = is_array( $response['messages'] ?? null ) ? $response['messages'] : [];
        $next_uri = isset( $response['next_page_uri'] ) ? 'https://api.twilio.com' . (string) $response['next_page_uri'] : null;
        if ( ! empty( $args['direction'] ) && in_array( $args['direction'], [ 'inbound', 'outbound-api' ], true ) ) {
            $dir   = (string) $args['direction'];
            $items = array_values( array_filter( $items, static fn ( $m ) => ( $m['direction'] ?? '' ) === $dir ) );
        }
        return [ 'items' => $items, 'next_page_uri' => $next_uri, 'raw' => $response ];
    }

    /**
     * @param array<string,mixed> $payload Must include: to, body. Optional: from.
     */
    public function send_message( array $payload, ?string $status_callback = null ) {
        $body = [
            'To'   => (string) ( $payload['to'] ?? '' ),
            'Body' => (string) ( $payload['body'] ?? '' ),
        ];

        if ( ! empty( $this->settings['messaging_service_sid'] ) ) {
            $body['MessagingServiceSid'] = (string) $this->settings['messaging_service_sid'];
        } elseif ( ! empty( $this->settings['from_number'] ) || ! empty( $payload['from'] ) ) {
            $body['From'] = (string) ( $payload['from'] ?? $this->settings['from_number'] );
        }

        if ( $status_callback ) {
            $body['StatusCallback'] = $status_callback;
        }

        $sid = (string) ( $this->settings['account_sid'] ?? '' );
        return $this->request( 'POST', "/Accounts/{$sid}/Messages.json", $body );
    }

    private function request( string $method, string $path, array $body = [] ) {
        return $this->raw_request( $method, self::API_BASE . $path, $body );
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    private function raw_request( string $method, string $url, array $body = [] ) {
        $sid   = (string) ( $this->settings['account_sid'] ?? '' );
        $token = (string) ( $this->settings['auth_token'] ?? '' );

        $args = [
            'method'  => $method,
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( $sid . ':' . $token ),
                'Accept'        => 'application/json',
            ],
        ];

        if ( ! empty( $body ) && in_array( $method, [ 'POST', 'PUT' ], true ) ) {
            $args['body'] = $body;
        }

        $response = wp_remote_request( $url, $args );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $raw  = (string) wp_remote_retrieve_body( $response );
        $data = json_decode( $raw, true );

        if ( $code < 200 || $code >= 300 ) {
            $message = is_array( $data ) && ! empty( $data['message'] )
                ? (string) $data['message']
                : sprintf( 'Twilio returned HTTP %d', $code );
            return new WP_Error( 'messageistic_twilio_http_' . $code, $message, [ 'status' => $code, 'body' => $raw ] );
        }

        return is_array( $data ) ? $data : [ 'raw' => $raw ];
    }
}
