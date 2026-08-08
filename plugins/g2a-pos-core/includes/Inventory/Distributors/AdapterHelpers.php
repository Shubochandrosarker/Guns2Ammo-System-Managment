<?php

namespace G2A\POS\Inventory\Distributors;

/**
 * Shared field-coercion helpers used by every adapter.
 */
final class AdapterHelpers {

	public static function pick( array $row, array $keys ): ?string {
		foreach ( $keys as $k ) {
			foreach ( $row as $rk => $rv ) {
				if ( strcasecmp( $k, $rk ) === 0 ) {
					$val = trim( (string) $rv );
					if ( $val !== '' ) {
						return $val;
					}
				}
			}
		}
		return null;
	}

	public static function money( array $row, array $keys ): ?float {
		$raw = self::pick( $row, $keys );
		if ( $raw === null ) {
			return null;
		}
		$clean = preg_replace( '/[^0-9.\-]/', '', $raw );
		return $clean === '' ? null : (float) $clean;
	}

	public static function int( array $row, array $keys ): ?int {
		$raw = self::pick( $row, $keys );
		if ( $raw === null ) {
			return null;
		}
		$clean = preg_replace( '/[^0-9\-]/', '', $raw );
		return $clean === '' ? null : (int) $clean;
	}

	public static function bool( array $row, array $keys, array $truthy = array( 'Y', 'YES', 'TRUE', '1', 'T' ) ): ?bool {
		$raw = self::pick( $row, $keys );
		if ( $raw === null ) {
			return null;
		}
		return in_array( strtoupper( $raw ), $truthy, true );
	}

	public static function upc( ?string $raw ): ?string {
		if ( ! $raw ) {
			return null;
		}
		$digits = preg_replace( '/\D/', '', $raw );
		$len    = strlen( (string) $digits );
		if ( $len < 8 || $len > 14 ) {
			return null;
		}
		return $digits;
	}

	public static function classify_type( ?string $hint, ?string $description = null ): array {
		$h            = strtolower( trim( (string) $hint ) );
		$d            = strtolower( (string) $description );
		$is_firearm   = false;
		$firearm_type = null;
		$blob         = $h . ' ' . $d;
		if ( preg_match( '/\b(pistol|handgun|revolver)\b/', $blob ) ) {
			$is_firearm   = true;
			$firearm_type = 'handgun';
		} elseif ( preg_match( '/\b(rifle|carbine|ar-?15|ak-?47)\b/', $blob ) ) {
			$is_firearm   = true;
			$firearm_type = 'rifle';
		} elseif ( preg_match( '/\b(shotgun)\b/', $blob ) ) {
			$is_firearm   = true;
			$firearm_type = 'shotgun';
		} elseif ( preg_match( '/\b(long\s*gun)\b/', $blob ) ) {
			$is_firearm   = true;
			$firearm_type = 'long_gun';
		}
		$item_type = 'accessory';
		if ( $is_firearm ) {
			$item_type = 'firearm';
		} elseif ( preg_match( '/\b(ammo|ammunition|cartridge|shells?)\b/', $blob ) ) {
			$item_type = 'ammunition';
		}

		return array(
			'item_type'    => $item_type,
			'firearm_type' => $firearm_type,
		);
	}
}
