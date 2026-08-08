<?php

namespace G2A\POS\Ai\Tools;

use G2A\POS\Ai\ActionPolicy;
use G2A\POS\Ai\ToolRegistry;
use G2A\POS\Reporting\KpiService;

final class ReportsSalesTool {

	public static function register(): void {
		ToolRegistry::register(
			'reports.sales',
			array(
				'description' => 'Returns the current KPI snapshot — today\'s revenue + order count, 30-day revenue, gross margin %, attach rate, open NICS, dead stock, layaway balance. Use this for any "how are we doing" / "what are sales like" question.',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => new \stdClass(),
				),
			),
			array( self::class, 'run' ),
			'g2a_pos_access',
			ActionPolicy::SILENT
		);
	}

	public static function run( array $args ): array {
		$snap = ( new KpiService() )->snapshot();
		return array(
			'ok'  => true,
			'kpi' => $snap,
		);
	}
}
