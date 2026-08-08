<?php
/**
 * GET /content/{posts|pages|media|categories|tags}
 *
 * Thin in-process proxy onto WordPress core's own `wp/v2` endpoints, so the
 * dashboard can read Posts/Pages/Media/Categories/Tags through the same
 * `g2a/v1` auth + CORS surface every other page already uses, instead of
 * the frontend needing a second cross-origin auth story against `wp/v2`
 * directly. Each route delegates to the shared `proxy()` helper — mirrors
 * the dispatch-table idiom in Analytics_Controller — which forwards a
 * whitelisted set of query args into `rest_do_request()` and relays the
 * `wp/v2` response body plus its pagination headers.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Content_Controller extends REST_Controller {
	/** Dashboard route slug => wp/v2 sub-path (same string for every one today). */
	private const ROUTES = array(
		'posts'      => 'posts',
		'pages'      => 'pages',
		'media'      => 'media',
		'categories' => 'categories',
		'tags'       => 'tags',
	);

	public function register_routes(): void {
		foreach ( self::ROUTES as $slug => $method ) {
			register_rest_route(
				$this->namespace,
				'/content/' . $slug,
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, $method ),
					'permission_callback' => array( $this, 'read_permissions_check' ),
				)
			);
		}
	}

	public function posts( \WP_REST_Request $req ) {
		return $this->proxy( 'posts', $req );
	}

	public function pages( \WP_REST_Request $req ) {
		return $this->proxy( 'pages', $req );
	}

	public function media( \WP_REST_Request $req ) {
		return $this->proxy( 'media', $req );
	}

	public function categories( \WP_REST_Request $req ) {
		return $this->proxy( 'categories', $req );
	}

	public function tags( \WP_REST_Request $req ) {
		return $this->proxy( 'tags', $req );
	}

	/**
	 * Which `wp/v2` query args each sub-resource forwards. Pure + static so
	 * it is unit-testable without a real WP_REST_Request. `status` is only
	 * meaningful (and only permitted by wp/v2 for non-`publish` values with
	 * edit capabilities) on posts/pages.
	 *
	 * @return array<int, string>
	 */
	public static function allowed_params( string $type ): array {
		$common = array( 'per_page', 'page', 'search' );
		if ( 'posts' === $type || 'pages' === $type ) {
			return array_merge( $common, array( 'status' ) );
		}
		return $common;
	}

	/**
	 * Filters a raw params array down to the whitelisted, non-empty keys
	 * for $type. Pure + static — no WP dependency — so it is unit-testable
	 * directly.
	 *
	 * @param array<string, mixed> $raw
	 * @return array<string, mixed>
	 */
	public static function build_query_args( string $type, array $raw ): array {
		$out = array();
		foreach ( self::allowed_params( $type ) as $key ) {
			if ( isset( $raw[ $key ] ) && '' !== $raw[ $key ] ) {
				$out[ $key ] = $raw[ $key ];
			}
		}
		return $out;
	}

	/**
	 * Invokes `GET wp/v2/{type}` in-process and relays its body + pagination
	 * headers.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function proxy( string $type, \WP_REST_Request $req ) {
		if ( ! function_exists( 'rest_do_request' ) || ! class_exists( '\WP_REST_Request' ) ) {
			return new \WP_Error(
				'g2aba_content_unavailable',
				__( 'WordPress core REST server is unavailable.', 'g2a-business-api' ),
				array( 'status' => 500 )
			);
		}

		$params = method_exists( $req, 'get_params' ) ? (array) $req->get_params() : array();
		$args   = self::build_query_args( $type, $params );

		$internal = new \WP_REST_Request( 'GET', '/wp/v2/' . $type );
		foreach ( $args as $key => $value ) {
			$internal->set_param( $key, $value );
		}

		$response = rest_do_request( $internal );
		if ( $response instanceof \WP_Error ) {
			return $response;
		}

		$res = $this->ok( $response->get_data() );

		$headers = $response->get_headers();
		if ( isset( $headers['X-WP-Total'] ) ) {
			$res->header( 'X-WP-Total', $headers['X-WP-Total'] );
		}
		if ( isset( $headers['X-WP-TotalPages'] ) ) {
			$res->header( 'X-WP-TotalPages', $headers['X-WP-TotalPages'] );
		}

		return $res;
	}
}
