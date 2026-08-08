<?php
/**
 * Shared service-account credential handling for GSC + GA4.
 *
 * Both integrations rely on the same JSON key (a single service-account can
 * be given access to both a GSC property and a GA4 property), but they each
 * need a differently-scoped OAuth token. This class owns the shared plumbing
 * — parsing the JSON key, calling oauth2.googleapis.com/token, caching the
 * response — so the clients above only own their API-specific shaping.
 *
 * The plaintext JSON key lives ONLY in Secrets. Callers never see it.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Integrations;

use WordPressistic\G2ABA\Secrets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Google_Service_Account {
	private const TOKEN_URL          = 'https://oauth2.googleapis.com/token';
	private const CACHED_TOKEN_KEY   = 'g2aba_google_access_token_';
	public  const SECRET_ID          = 'google:service-account';

	/**
	 * Legacy per-integration secret id — used by the GSC client written in
	 * Phase 3 before this shared path existed. We look it up as a fallback
	 * for zero-migration operators.
	 */
	public  const LEGACY_GSC_SECRET  = 'gsc:service-account';

	public function is_configured(): bool {
		return Secrets::has( self::SECRET_ID ) || Secrets::has( self::LEGACY_GSC_SECRET );
	}

	/**
	 * @return array{client_email:string,private_key:string}
	 * @throws \RuntimeException When no key is stored or the JSON is invalid.
	 */
	public function credentials(): array {
		$raw = Secrets::get( self::SECRET_ID );
		if ( null === $raw ) {
			$raw = Secrets::get( self::LEGACY_GSC_SECRET );
		}
		if ( null === $raw ) {
			throw new \RuntimeException( 'Google service-account key is not stored' );
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) || empty( $decoded['client_email'] ) || empty( $decoded['private_key'] ) ) {
			throw new \RuntimeException( 'Google service-account key is not valid JSON' );
		}
		return array(
			'client_email' => (string) $decoded['client_email'],
			'private_key'  => (string) $decoded['private_key'],
		);
	}

	/**
	 * Mint (or reuse a cached) OAuth access token for the given API scope.
	 *
	 * The transient key is scoped-per-scope so a GSC token doesn't leak to
	 * GA4 code that asked for a different scope.
	 *
	 * @throws \RuntimeException On JWT signing or transport failure.
	 */
	public function access_token( string $scope ): string {
		$transient = self::CACHED_TOKEN_KEY . md5( $scope );
		$cached    = get_transient( $transient );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$creds  = $this->credentials();
		$signer = new Google_JWT_Signer();
		$jwt    = $signer->sign(
			$signer->claims_for( $creds['client_email'], $scope, time() ),
			$creds['private_key']
		);

		$resp = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 8,
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				),
			)
		);
		if ( is_wp_error( $resp ) ) {
			throw new \RuntimeException( 'Google token exchange: ' . $resp->get_error_message() );
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $json ) || empty( $json['access_token'] ) ) {
			throw new \RuntimeException( 'Google token exchange: unexpected response' );
		}

		$token = (string) $json['access_token'];
		$ttl   = (int) ( $json['expires_in'] ?? 3600 );
		set_transient( $transient, $token, max( 60, $ttl - 60 ) );
		return $token;
	}
}
