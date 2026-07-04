<?php

namespace G2A\POS\Wholesalers\Lipseys;

/**
 * Thin client for the Lipsey's Cloud API (https://api.lipseys.com).
 *
 * Auth flow: POST /api/Integration/Authentication/Login { Email, Password } -> token.
 * Lipsey's wraps responses in an envelope ({ token|data, success, authorized,
 * errors }) and returns HTTP 200 even when auth is rejected, so the token is
 * extracted defensively and failures are reported from the body (see
 * extractToken()/describeAuthFailure()). The token is then sent as the `Token`
 * request header for subsequent calls.
 *
 * Endpoints used (verified against Lipsey's Postman collection):
 *   POST /api/Integration/Authentication/Login    -> auth, returns { Token }
 *   POST /api/Integration/Items/ValidateItem      -> body = "<sku>" (JSON-encoded string)
 *   GET  /api/Integration/Items/CatalogFeed        -> full catalog (JSON)
 *   POST /api/Integration/Items/CatalogFeed/Item   -> body = "<upc>" (JSON-encoded string) -> single item
 *   GET  /api/Integration/Items/PricingQuantityFeed-> live pricing + quantity for all SKUs
 *   POST /api/Integration/Order/APIOrder           -> ship-to-dealer order (uses dealer FFL on file)
 *   POST /api/Integration/Order/DropShip           -> drop-ship with explicit billing + shipping
 *   POST /api/Integration/Shipping/OneDay          -> body = "<MM/DD/YYYY>", returns availability
 *   GET  /api/Integration/Order/OrderStatus/{po}   -> status for a previously submitted PO
 *
 * All calls retry transient 5xx and transparently re-auth on 401.
 */
final class LipseysApiClient {

	private const DEFAULT_BASE    = 'https://api.lipseys.com';
	private const TOKEN_TRANSIENT = 'g2a_pos_lipseys_token_%d';
	private const TOKEN_TTL       = 14 * MINUTE_IN_SECONDS;

	public function __construct(
		private int $wholesalerId,
		private string $email,
		private string $password,
		private string $baseUrl = self::DEFAULT_BASE
	) {
		$this->baseUrl = rtrim( $this->baseUrl ?: self::DEFAULT_BASE, '/' );
	}

	public function authenticate( bool $force = false ): string {
		$key = sprintf( self::TOKEN_TRANSIENT, $this->wholesalerId );
		if ( ! $force ) {
			$cached = get_transient( $key );
			if ( is_string( $cached ) && $cached !== '' ) {
				return $cached;
			}
		}

		$res = wp_remote_post(
			$this->baseUrl . '/api/Integration/Authentication/Login',
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						// Trim defensively: a stray space pasted into the admin
						// form must not turn into a hard "Login Failed".
						'Email'    => trim( $this->email ),
						'Password' => trim( $this->password ),
					)
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			throw new \RuntimeException( 'lipseys_auth_http_error: ' . $res->get_error_message() );
		}
		$code    = (int) wp_remote_retrieve_response_code( $res );
		$rawBody = (string) wp_remote_retrieve_body( $res );
		$body    = json_decode( $rawBody, true );
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		$token = self::extractToken( $body );
		if ( $token !== '' ) {
			set_transient( $key, $token, self::TOKEN_TTL );
			return $token;
		}

		// Lipsey's returns HTTP 200 even when authentication is rejected — e.g.
		// wrong email/password, or API access not yet enabled on the dealer
		// account. The real reason lives in the response envelope, not the
		// status line, so surface it instead of a bare "HTTP <code>".
		throw new \RuntimeException( 'lipseys_auth_failed: ' . self::describeAuthFailure( $code, $body, $rawBody ) );
	}

	/**
	 * Pull the session token out of a Lipsey's login response. The token has
	 * been observed under several casings/nestings across environments:
	 * top-level `token`/`Token`, nested under `data`, or `data` returned as a
	 * bare token string. Check them all rather than assume one shape.
	 *
	 * @param array<string,mixed> $body Decoded JSON response body.
	 */
	private static function extractToken( array $body ): string {
		$candidates = array(
			$body['token'] ?? null,
			$body['Token'] ?? null,
		);

		$data = $body['data'] ?? $body['Data'] ?? null;
		if ( is_array( $data ) ) {
			$candidates[] = $data['token'] ?? null;
			$candidates[] = $data['Token'] ?? null;
		} elseif ( is_string( $data ) ) {
			$candidates[] = $data;
		}

		foreach ( $candidates as $candidate ) {
			if ( is_string( $candidate ) && trim( $candidate ) !== '' ) {
				return trim( $candidate );
			}
		}
		return '';
	}

	/**
	 * Compose an actionable failure reason from the Lipsey's response envelope
	 * (`errors[]`, `authorized`, `success`), falling back to the HTTP status and
	 * a short raw-body snippet when the body carries no structured detail.
	 *
	 * @param array<string,mixed> $body Decoded JSON response body.
	 */
	private static function describeAuthFailure( int $code, array $body, string $rawBody ): string {
		$parts = array( 'HTTP ' . $code );

		if ( array_key_exists( 'authorized', $body ) && ! $body['authorized'] ) {
			$parts[] = 'authorized=false';
		}
		if ( array_key_exists( 'success', $body ) && ! $body['success'] ) {
			$parts[] = 'success=false';
		}

		$errors = $body['errors'] ?? $body['Errors'] ?? array();
		if ( is_string( $errors ) && $errors !== '' ) {
			$errors = array( $errors );
		}
		if ( is_array( $errors ) && array() !== $errors ) {
			$messages = array();
			foreach ( $errors as $error ) {
				if ( is_string( $error ) ) {
					$messages[] = $error;
				} elseif ( is_array( $error ) ) {
					$messages[] = (string) ( $error['message'] ?? $error['Message'] ?? wp_json_encode( $error ) );
				}
			}
			$messages = array_filter( array_map( 'trim', $messages ) );
			if ( array() !== $messages ) {
				$parts[] = implode( '; ', $messages );
			}
		}

		// Nothing structured to report — attach a trimmed snippet of the raw body.
		if ( 1 === count( $parts ) ) {
			$snippet = trim( (string) preg_replace( '/\s+/', ' ', $rawBody ) );
			$parts[] = $snippet === ''
				? 'empty response body'
				: 'body: ' . ( strlen( $snippet ) > 200 ? substr( $snippet, 0, 200 ) . '…' : $snippet );
		}

		return implode( ' — ', $parts );
	}

	public function validateItem( string $sku ): array {
		return $this->post( '/api/Integration/Items/ValidateItem', $sku );
	}

	public function fetchPricingQuantityFeed(): array {
		return $this->get( '/api/Integration/Items/PricingQuantityFeed' );
	}

	public function fetchCatalog(): array {
		// The full catalog feed is a single large JSON document (Lipsey's has
		// no pagination); give it more room than the default request timeout.
		return $this->get( '/api/Integration/Items/CatalogFeed', 120 );
	}

	public function catalogFeedItem( string $upc ): array {
		return $this->post( '/api/Integration/Items/CatalogFeed/Item', $upc );
	}

	/**
	 * Ship-to-dealer order (Lipsey's ships to the dealer's FFL on file).
	 * Payload: { PONumber, EmailConfirmation, Items: [{ ItemNo, Quantity, Note }] }
	 */
	public function submitWarehouseOrder( array $orderPayload ): array {
		return $this->post( '/api/Integration/Order/APIOrder', $orderPayload );
	}

	/**
	 * Drop-ship order to a third party (typically the end-customer's transferring FFL).
	 * Payload: { Warehouse, PoNumber, Billing*, Shipping*, MessageForSalesExec, Items[], Overnight }
	 */
	public function submitDropShipOrder( array $orderPayload ): array {
		return $this->post( '/api/Integration/Order/DropShip', $orderPayload );
	}

	/**
	 * Check if one-day shipping is available for a given date.
	 * Body is a JSON-encoded date string (e.g. "7/15/2019" or "2026-06-04").
	 */
	public function checkOneDayShipping( string $date ): array {
		return $this->post( '/api/Integration/Shipping/OneDay', $date );
	}

	public function orderStatus( string $poNumber ): array {
		return $this->get( '/api/Integration/Order/OrderStatus/' . rawurlencode( $poNumber ) );
	}

	private function get( string $path ): array {
		return $this->request( 'GET', $path, null );
	}

	private function post( string $path, $payload ): array {
		return $this->request( 'POST', $path, $payload );
	}

	private function request( string $method, string $path, $payload, int $attempt = 1 ): array {
		$token = $this->authenticate();
		$args  = array(
			'method'  => $method,
			'timeout' => 60,
			'headers' => array(
				'Token'        => $token,
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
		);
		if ( $payload !== null ) {
			$args['body'] = wp_json_encode( $payload );
		}
		$res = wp_remote_request( $this->baseUrl . $path, $args );
		if ( is_wp_error( $res ) ) {
			if ( $attempt < 3 ) {
				usleep( ( $attempt * 750 ) * 1000 );
				return $this->request( $method, $path, $payload, $attempt + 1 );
			}
			throw new \RuntimeException( 'lipseys_http_error: ' . $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code === 401 && $attempt === 1 ) {
			$this->authenticate( true );
			return $this->request( $method, $path, $payload, $attempt + 1 );
		}
		if ( $code >= 500 && $attempt < 3 ) {
			usleep( ( $attempt * 1000 ) * 1000 );
			return $this->request( $method, $path, $payload, $attempt + 1 );
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $body ) ) {
			$body = array( 'raw' => (string) wp_remote_retrieve_body( $res ) );
		}
		$body['_status'] = $code;
		return $body;
	}
}
