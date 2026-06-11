<?php

namespace G2A\POS\Compliance\State;

final class WaitingPeriod {

	public static function compute_release( string $state_code, array $firearms, ?\DateTimeImmutable $start = null ): ?\DateTimeImmutable {
		$rules = StateRules::for( strtoupper( $state_code ) );
		if ( ! $rules ) {
			return null;
		}

		$hours = 0;
		foreach ( $firearms as $firearm ) {
			$type = strtolower( (string) ( $firearm['firearm_type'] ?? '' ) );
			if ( $type === 'handgun' ) {
				$hours = max( $hours, (int) $rules['waiting_period_hours_handgun'] );
			} else {
				$hours = max( $hours, (int) $rules['waiting_period_hours_long_gun'] );
			}
		}

		if ( $hours <= 0 ) {
			return null;
		}

		$start ??= new \DateTimeImmutable( 'now', wp_timezone() );
		return $start->modify( "+{$hours} hours" );
	}
}
