<?php

namespace G2A\POS\Support;

final class Cron {

	public static function register_intervals(): void {
		add_filter(
			'cron_schedules',
			static function ( array $schedules ): array {
				$schedules['minute'] = array(
					'interval' => MINUTE_IN_SECONDS,
					'display'  => __( 'Every Minute', 'g2a-pos-core' ),
				);
				if ( ! isset( $schedules['weekly'] ) ) {
					$schedules['weekly'] = array(
						'interval' => 7 * DAY_IN_SECONDS,
						'display'  => __( 'Once Weekly', 'g2a-pos-core' ),
					);
				}

				return $schedules;
			}
		);
	}
}
