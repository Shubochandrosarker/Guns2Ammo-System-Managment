<?php

namespace G2A\POS\Database;

use G2A\POS\Wholesalers\Crypto\CredentialCipher;

final class WholesalerRepository extends Repository {

	public function all(): array {
		global $wpdb;
		$t = $this->table( 'g2a_wholesalers' );
		return $wpdb->get_results( "SELECT * FROM {$t} ORDER BY display_name ASC", ARRAY_A ) ?: array();
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$t   = $this->table( 'g2a_wholesalers' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id=%d", $id ), ARRAY_A );
		return $row ?: null;
	}

	public function findByCode( string $code ): ?array {
		global $wpdb;
		$t   = $this->table( 'g2a_wholesalers' );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE provider_code=%s ORDER BY id ASC LIMIT 1",
				$code
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	public function upsert( array $data ): int {
		global $wpdb;
		$t   = $this->table( 'g2a_wholesalers' );
		$now = $this->now();

		$providerCode = sanitize_key( (string) ( $data['provider_code'] ?? '' ) );
		$account      = (string) ( $data['account_number'] ?? '' );

		// Prefer an explicit id when the caller is editing a known row —
		// matching on provider_code+account_number alone means changing the
		// account number silently creates a duplicate row instead of updating.
		$existing = null;
		if ( ! empty( $data['id'] ) && (int) $data['id'] > 0 ) {
			$row      = $this->find( (int) $data['id'] );
			$existing = $row ? (int) $row['id'] : null;
		}
		if ( ! $existing ) {
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$t} WHERE provider_code=%s AND (account_number=%s OR (%s='' AND (account_number IS NULL OR account_number='')))",
					$providerCode,
					$account,
					$account
				)
			);
		}

		$credentials = $data['credentials'] ?? null;
		if ( is_array( $credentials ) ) {
			$credentials = $this->normalizeCredentials( $credentials, $existing ? (int) $existing : 0 );
			$credentials = $this->encryptCredentials( $credentials );
		}

		$settings = $data['settings'] ?? null;
		if ( is_array( $settings ) ) {
			$settings = wp_json_encode( $settings );
		}

		$payload = array(
			'provider_code'  => $providerCode,
			'display_name'   => (string) ( $data['display_name'] ?? $providerCode ),
			'account_number' => $account !== '' ? $account : null,
			'api_endpoint'   => $data['api_endpoint'] ?? null,
			'credentials'    => $credentials,
			'status'         => (string) ( $data['status'] ?? 'active' ),
			'settings'       => $settings,
			'updated_at'     => $now,
		);

		if ( $existing ) {
			$wpdb->update( $t, $payload, array( 'id' => (int) $existing ) );
			return (int) $existing;
		}
		$payload['created_at'] = $now;
		$wpdb->insert( $t, $payload );
		return (int) $wpdb->insert_id;
	}

	public function markSyncedNow( int $id ): void {
		global $wpdb;
		$wpdb->update(
			$this->table( 'g2a_wholesalers' ),
			array(
				'last_sync_at' => $this->now(),
				'updated_at'   => $this->now(),
			),
			array( 'id' => $id )
		);
	}

	public function decodeCredentials( array $row ): array {
		$raw = (string) ( $row['credentials'] ?? '' );
		return $raw === '' ? array() : CredentialCipher::decrypt( $raw );
	}

	/**
	 * Trim credential fields and, when updating an existing row, keep the
	 * stored email/password if the incoming value is blank. The admin forms
	 * always submit a credentials object with an empty password field, so
	 * re-saving a wholesaler must never wipe a previously stored secret.
	 *
	 * @param array<string,mixed> $credentials Incoming credential fields.
	 */
	private function normalizeCredentials( array $credentials, int $existingId ): array {
		foreach ( array( 'email', 'password' ) as $field ) {
			if ( isset( $credentials[ $field ] ) && is_string( $credentials[ $field ] ) ) {
				$credentials[ $field ] = trim( $credentials[ $field ] );
			}
		}

		if ( $existingId > 0 ) {
			$row    = $this->find( $existingId );
			$stored = $row ? $this->decodeCredentials( $row ) : array();
			foreach ( array( 'email', 'password' ) as $field ) {
				$incoming = $credentials[ $field ] ?? '';
				if ( ( ! is_string( $incoming ) || $incoming === '' )
					&& isset( $stored[ $field ] ) && is_string( $stored[ $field ] ) && $stored[ $field ] !== '' ) {
					$credentials[ $field ] = $stored[ $field ];
				}
			}
		}

		return $credentials;
	}

	private function encryptCredentials( array $data ): string {
		return CredentialCipher::encrypt( $data );
	}
}
