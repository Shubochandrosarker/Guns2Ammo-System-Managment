<?php
/**
 * G2A — Shared plumbing for distributor drop-ship integrations.
 *
 * Every distributor client (Lipsey's, Sports South, RSR, Bill Hicks & Co.)
 * needs the same three things regardless of its own wire protocol
 * (REST/JSON, SOAP-ish ASMX form-POST, or FTP/FTPS file exchange):
 * encrypted-at-rest credentials, an upsert into the shared
 * `distributor_products` catalog cache, and a recorded row in
 * `distributor_orders` + an `events` entry for whatever it just did. This
 * class holds only that shared plumbing — no distributor ever talks to
 * another distributor's wire protocol through here.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Distributor_Support {

	/** Marker for encrypted values stored in wp_options -- same convention every distributor's settings use. */
	const SECRET_PREFIX = 'enc:v1:';

	/**
	 * AES-256-GCM key derived from the site's AUTH_KEY (wp_salt fallback),
	 * namespaced per distributor via $context so a leaked key for one
	 * distributor's secret can't be replayed against another's ciphertext.
	 *
	 * $context must never change for an already-deployed distributor --
	 * doing so permanently orphans any already-encrypted value at rest
	 * (see G2A_Lipseys, which keeps its original 'wpistic-ffl-lipseys'
	 * context from before this class existed, for exactly that reason).
	 */
	public static function secret_key( string $context ): string {
		$material = defined( 'AUTH_KEY' ) && '' !== AUTH_KEY ? AUTH_KEY : wp_salt( 'auth' );
		return hash( 'sha256', 'wpistic-ffl-' . $context . '|' . $material, true );
	}

	public static function encrypt_secret( string $plain, string $context ): string {
		if ( '' === $plain || ! function_exists( 'openssl_encrypt' ) ) {
			return $plain;
		}
		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( $plain, 'aes-256-gcm', self::secret_key( $context ), OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $cipher ) {
			return $plain;
		}
		return self::SECRET_PREFIX . base64_encode( $iv . $tag . $cipher );
	}

	public static function decrypt_secret( string $stored, string $context ): string {
		if ( 0 !== strpos( $stored, self::SECRET_PREFIX ) || ! function_exists( 'openssl_decrypt' ) ) {
			return $stored;
		}
		$raw = base64_decode( substr( $stored, strlen( self::SECRET_PREFIX ) ), true );
		if ( false === $raw || strlen( $raw ) <= 28 ) {
			return '';
		}
		$iv     = substr( $raw, 0, 12 );
		$tag    = substr( $raw, 12, 16 );
		$cipher = substr( $raw, 28 );
		$plain  = openssl_decrypt( $cipher, 'aes-256-gcm', self::secret_key( $context ), OPENSSL_RAW_DATA, $iv, $tag );
		return false === $plain ? '' : $plain;
	}

	/**
	 * Decrypts a settings array's secret field(s) on read, transparently
	 * re-encrypting a legacy plaintext value exactly once. Mirrors
	 * G2A_Lipseys::settings() so every distributor gets the same
	 * upgrade-in-place behavior for however many secret fields it has
	 * (Sports South has one password; RSR/Bill Hicks each have one FTP
	 * password -- some may have more than one secret field eventually).
	 *
	 * @param array<string,mixed> $settings   Raw settings as stored (get_option()'d already).
	 * @param string[]            $secret_keys Which array keys hold a secret.
	 * @param string              $context    Passed through to encrypt/decrypt_secret().
	 * @param string              $option_key  wp_options key to re-save to if an upgrade happened.
	 */
	public static function decrypt_settings_secrets( array $settings, array $secret_keys, string $context, string $option_key ): array {
		$dirty = false;
		$stored_for_save = $settings;

		foreach ( $secret_keys as $key ) {
			$value = (string) ( $settings[ $key ] ?? '' );
			if ( '' === $value ) {
				continue;
			}
			if ( 0 === strpos( $value, self::SECRET_PREFIX ) ) {
				$settings[ $key ] = self::decrypt_secret( $value, $context );
				continue;
			}
			// Legacy plaintext at rest -- re-store encrypted once; the
			// decrypted (here: original plaintext) value keeps flowing
			// through unchanged for this read.
			$encrypted = self::encrypt_secret( $value, $context );
			if ( 0 === strpos( $encrypted, self::SECRET_PREFIX ) ) {
				$stored_for_save[ $key ] = $encrypted;
				$dirty = true;
			}
		}

		if ( $dirty ) {
			update_option( $option_key, $stored_for_save );
		}

		return $settings;
	}

	// ── Catalog cache ─────────────────────────────────────────────────────

	/**
	 * Upserts one normalized catalog row into the shared
	 * `distributor_products` table. Every distributor client is
	 * responsible for mapping its own wire format into this shape before
	 * calling here -- this method has no idea whether the source was JSON,
	 * XML, or a semicolon-delimited flat file.
	 *
	 * @param string $distributor Registry slug, e.g. 'sports_south'.
	 * @param array{item_no:string,description?:string,manufacturer?:string,model?:string,upc?:string,price?:float,quantity?:int,is_firearm?:bool} $item
	 */
	public static function upsert_catalog_item( string $distributor, array $item ): bool {
		$item_no = trim( (string) ( $item['item_no'] ?? '' ) );
		if ( '' === $item_no ) {
			return false;
		}

		global $wpdb;
		$table = DB::table( 'distributor_products' );
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"INSERT INTO {$table}
			 ( distributor, item_no, description, manufacturer, model, upc, price, quantity, is_firearm, last_synced )
			 VALUES ( %s, %s, %s, %s, %s, %s, %f, %d, %d, %s )
			 ON DUPLICATE KEY UPDATE
			   description = VALUES(description), manufacturer = VALUES(manufacturer),
			   model = VALUES(model), upc = VALUES(upc), price = VALUES(price),
			   quantity = VALUES(quantity), is_firearm = VALUES(is_firearm), last_synced = VALUES(last_synced)",
			$distributor,
			$item_no,
			(string) ( $item['description'] ?? '' ),
			(string) ( $item['manufacturer'] ?? '' ),
			(string) ( $item['model'] ?? '' ),
			(string) ( $item['upc'] ?? '' ),
			(float) ( $item['price'] ?? 0 ),
			(int) ( $item['quantity'] ?? 0 ),
			! empty( $item['is_firearm'] ) ? 1 : 0,
			current_time( 'mysql' )
		) );

		return true;
	}

	// ── Order bookkeeping ────────────────────────────────────────────────

	/**
	 * Records a distributor order attempt (of whatever status) into
	 * `distributor_orders` plus a matching `events` row on the transfer.
	 * Called after the distributor-specific client has already done
	 * whatever real network/file work it needed to do -- this is pure
	 * bookkeeping, never the thing that decides success/failure.
	 *
	 * @param array{transfer_id:int,po_number:string,item_no:string,quantity:int,ship_to_ffl:string,status:string,distributor_order_ref?:string,response_json?:string,note?:string} $data
	 */
	public static function record_distributor_order( string $distributor, array $data ): void {
		global $wpdb;

		$wpdb->insert( DB::table( 'distributor_orders' ), [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'distributor'           => $distributor,
			'transfer_id'           => (int) $data['transfer_id'],
			'po_number'             => (string) ( $data['po_number'] ?? '' ),
			'item_no'               => (string) ( $data['item_no'] ?? '' ),
			'quantity'              => (int) ( $data['quantity'] ?? 1 ),
			'ship_to_ffl'           => (string) ( $data['ship_to_ffl'] ?? '' ),
			'status'                => (string) ( $data['status'] ?? 'submitted' ),
			'distributor_order_ref' => (string) ( $data['distributor_order_ref'] ?? '' ),
			'response_json'         => $data['response_json'] ?? null,
			'submitted_by'          => get_current_user_id() ?: null,
		] );

		$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'transfer_id' => (int) $data['transfer_id'],
			'event_type'  => 'distributor_order_' . (string) ( $data['status'] ?? 'submitted' ),
			'notes'       => (string) ( $data['note'] ?? '' ),
			'actor'       => function_exists( 'wp_get_current_user' ) ? ( wp_get_current_user()->user_login ?: 'staff' ) : 'staff',
			'actor_ip'    => class_exists( __NAMESPACE__ . '\\Token' ) ? Token::client_ip() : '',
		] );
	}

	/**
	 * Looks up a transfer + its dealer's FFL/name/phone -- every
	 * distributor order needs this same join before it can build a
	 * ship-to-FFL request.
	 */
	public static function transfer_with_dealer( int $transfer_id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT t.*, d.license_number, d.business_name, d.phone AS dealer_phone,
			        d.premise_street, d.premise_city, d.premise_state, d.premise_zip
			 FROM ' . DB::table( 'transfers' ) . ' t
			 LEFT JOIN ' . DB::table( 'dealers' ) . ' d ON d.id = t.dealer_id
			 WHERE t.id = %d', $transfer_id
		) );
		return $row ?: null;
	}
}
