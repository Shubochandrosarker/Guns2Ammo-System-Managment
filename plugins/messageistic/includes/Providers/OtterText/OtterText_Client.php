<?php
/**
 * Thin HTTP client for the OtterText REST API.
 *
 * The exact endpoint shapes vary by OtterText account / API version, so this
 * client keeps endpoint paths configurable and falls back to common defaults.
 * It uses WordPress' wp_remote_request so it benefits from filters/proxies.
 *
 * @package Messageistic\Providers\OtterText
 */

namespace Messageistic\Providers\OtterText;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class OtterText_Client {

    public function __construct( private array $settings ) {}

    public function ping() {
        return $this->request( 'GET', $this->path( 'ping_path', '/account' ) );
    }

    /**
     * @param array<string,mixed> $contact Normalized contact: phone, first_name, last_name, email, tags.
     */
    public function push_contact( array $contact ) {
        $body = [
            'phone'      => $contact['phone'] ?? '',
            'first_name' => $contact['first_name'] ?? '',
            'last_name'  => $contact['last_name'] ?? '',
            'email'      => $contact['email'] ?? '',
            'tags'       => $contact['tags'] ?? [],
        ];
        if ( ! empty( $this->settings['default_list_id'] ) ) {
            $body['list_id'] = $this->settings['default_list_id'];
        }
        if ( ! empty( $this->settings['location_id'] ) ) {
            $body['location_id'] = $this->settings['location_id'];
        }
        return $this->request( 'POST', $this->path( 'contacts_path', '/contacts' ), $body );
    }

    /**
     * @param array<string,mixed> $payload Must include: to, body. Optional: from, contact_id.
     */
    public function send_message( array $payload ) {
        $body = [
            'to'      => $payload['to'] ?? '',
            'message' => $payload['body'] ?? '',
        ];
        if ( ! empty( $payload['from'] ) ) {
            $body['from'] = $payload['from'];
        }
        if ( ! empty( $payload['contact_id'] ) ) {
            $body['contact_id'] = $payload['contact_id'];
        }
        if ( ! empty( $this->settings['location_id'] ) ) {
            $body['location_id'] = $this->settings['location_id'];
        }
        return $this->request( 'POST', $this->path( 'messages_path', '/messages' ), $body );
    }

    /**
     * Push a campaign payload to OtterText for native broadcast.
     *
     * @param array<string,mixed> $campaign { name, body, list_id?, audience_phones?, schedule_at? }
     */
    public function push_campaign( array $campaign ) {
        $body = [
            'name'    => (string) ( $campaign['name'] ?? '' ),
            'message' => (string) ( $campaign['body'] ?? '' ),
        ];
        if ( ! empty( $campaign['list_id'] ) ) {
            $body['list_id'] = (string) $campaign['list_id'];
        } elseif ( ! empty( $this->settings['default_list_id'] ) ) {
            $body['list_id'] = (string) $this->settings['default_list_id'];
        }
        if ( ! empty( $campaign['audience_phones'] ) ) {
            $body['phones'] = (array) $campaign['audience_phones'];
        }
        if ( ! empty( $campaign['schedule_at'] ) ) {
            $body['schedule_at'] = (string) $campaign['schedule_at'];
        }
        if ( ! empty( $this->settings['location_id'] ) ) {
            $body['location_id'] = (string) $this->settings['location_id'];
        }
        return $this->request( 'POST', $this->path( 'campaigns_path', '/campaigns' ), $body );
    }

    /**
     * Paginated list endpoint helper. OtterText paginates either by `?page=`
     * or by cursor (`?cursor=`); we accept both via $cursor and surface what
     * the response gives us so the sync service can advance.
     *
     * Returns:
     * [
     *   'items'       => array<int,array<string,mixed>>,
     *   'next_page'   => int|null,
     *   'next_cursor' => string|null,
     *   'total'       => int|null,
     *   'raw'         => mixed,
     * ]
     */
    public function list_contacts( int $page = 1, ?string $cursor = null, int $per_page = 100, ?string $list_id = null, ?string $campaign_id = null ): array {
        $query = [ 'per_page' => $per_page ];
        if ( $cursor ) {
            $query['cursor'] = $cursor;
        } else {
            $query['page'] = $page;
        }
        if ( $list_id ) {
            $query['list_id'] = $list_id;
        } elseif ( ! empty( $this->settings['default_list_id'] ) ) {
            $query['list_id'] = (string) $this->settings['default_list_id'];
        }
        if ( $campaign_id ) {
            $query['campaign_id'] = $campaign_id;
        }
        if ( ! empty( $this->settings['location_id'] ) ) {
            $query['location_id'] = (string) $this->settings['location_id'];
        }

        $response = $this->request( 'GET', $this->path( 'contacts_path', '/contacts' ), [], $query );
        if ( is_wp_error( $response ) ) {
            return [ 'items' => [], 'next_page' => null, 'next_cursor' => null, 'total' => null, 'error' => $response, 'raw' => null ];
        }
        return $this->normalize_list( $response, $page );
    }

    public function list_campaigns( int $page = 1, int $per_page = 50 ): array {
        $response = $this->request( 'GET', $this->path( 'campaigns_path', '/campaigns' ), [], [ 'page' => $page, 'per_page' => $per_page ] );
        if ( is_wp_error( $response ) ) {
            return [ 'items' => [], 'next_page' => null, 'next_cursor' => null, 'total' => null, 'error' => $response, 'raw' => null ];
        }
        return $this->normalize_list( $response, $page );
    }

    public function list_lists(): array {
        $response = $this->request( 'GET', $this->path( 'lists_path', '/lists' ) );
        if ( is_wp_error( $response ) ) {
            return [ 'items' => [], 'error' => $response ];
        }
        $items = $response['data'] ?? $response['items'] ?? $response['lists'] ?? ( is_array( $response ) ? $response : [] );
        return [ 'items' => is_array( $items ) ? $items : [], 'raw' => $response ];
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    private function request( string $method, string $path, array $body = [], array $query = [] ) {
        $url = trailingslashit( (string) $this->settings['api_base_url'] ) . ltrim( $path, '/' );
        if ( ! empty( $query ) ) {
            $url = add_query_arg( array_filter( $query, static fn ( $v ) => null !== $v && '' !== $v ), $url );
        }

        $args = [
            'method'  => $method,
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . (string) ( $this->settings['api_key'] ?? '' ),
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ],
        ];

        if ( ! empty( $this->settings['account_id'] ) ) {
            $args['headers']['X-Account-Id'] = (string) $this->settings['account_id'];
        }

        if ( ! empty( $body ) && in_array( $method, [ 'POST', 'PUT', 'PATCH' ], true ) ) {
            $args['body'] = wp_json_encode( $body );
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
                : sprintf( 'OtterText returned HTTP %d', $code );
            return new WP_Error( 'messageistic_ottertext_http_' . $code, $message, [ 'status' => $code, 'body' => $raw ] );
        }

        return is_array( $data ) ? $data : [ 'raw' => $raw ];
    }

    private function path( string $setting_key, string $default ): string {
        $value = $this->settings[ $setting_key ] ?? '';
        return $value !== '' ? (string) $value : $default;
    }

    /**
     * Pull `items` / `next_page` / `next_cursor` / `total` out of common
     * OtterText response envelopes.
     *
     * @param array<string,mixed> $response
     * @return array{items:array<int,array<string,mixed>>,next_page:int|null,next_cursor:string|null,total:int|null,raw:array<string,mixed>}
     */
    private function normalize_list( array $response, int $current_page ): array {
        $items       = $response['data'] ?? $response['items'] ?? $response['contacts'] ?? $response['results'] ?? [];
        $items       = is_array( $items ) ? array_values( $items ) : [];
        $meta        = $response['meta'] ?? $response['pagination'] ?? [];
        $next_cursor = $response['next_cursor'] ?? $meta['next_cursor'] ?? null;
        $current     = (int) ( $meta['current_page'] ?? $current_page );
        $last        = isset( $meta['last_page'] ) ? (int) $meta['last_page'] : null;
        $next_page   = $next_cursor ? null : ( $last && $current < $last ? $current + 1 : ( count( $items ) > 0 && null === $last ? $current + 1 : null ) );
        $total       = isset( $meta['total'] ) ? (int) $meta['total'] : ( isset( $response['total'] ) ? (int) $response['total'] : null );

        return [
            'items'       => $items,
            'next_page'   => $next_page,
            'next_cursor' => $next_cursor ? (string) $next_cursor : null,
            'total'       => $total,
            'raw'         => $response,
        ];
    }
}
