<?php

namespace G2A\POS\Compliance\State;

use G2A\POS\Database\StateRulesRepository;

final class StateRules {

	private static array $cache = array();

	public static function for( string $state_code ): ?array {
		$code = strtoupper( substr( $state_code, 0, 2 ) );
		if ( isset( self::$cache[ $code ] ) ) {
			return self::$cache[ $code ];
		}
		$repo  = new StateRulesRepository();
		$rules = $repo->find( $code );
		if ( $rules && ! empty( $rules['rules_payload'] ) ) {
			$decoded                        = json_decode( (string) $rules['rules_payload'], true );
			$rules['rules_payload_decoded'] = is_array( $decoded ) ? $decoded : array();
		}
		self::$cache[ $code ] = $rules;
		return $rules;
	}

	public static function all(): array {
		$repo = new StateRulesRepository();
		return $repo->all();
	}

	public static function invalidate(): void {
		self::$cache = array();
	}
}
