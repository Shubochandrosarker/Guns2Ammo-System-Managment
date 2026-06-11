<?php

namespace G2A\POS\Support;

use G2A\POS\Wholesalers\Crypto\CredentialCipher;

final class SecretStore {

	public static function seal( string $value ): string {
		return $value === '' ? '' : CredentialCipher::encrypt( array( 'value' => $value ) );
	}

	public static function open( string $value ): string {
		if ( $value === '' ) {
			return '';
		}
		if ( ! str_starts_with( $value, 'enc1:' ) && ! str_starts_with( $value, 'enc2:' ) ) {
			return $value; // Plaintext legacy option; migrated on the next save.
		}
		$decoded = CredentialCipher::decrypt( $value );
		return (string) ( $decoded['value'] ?? '' );
	}
}
