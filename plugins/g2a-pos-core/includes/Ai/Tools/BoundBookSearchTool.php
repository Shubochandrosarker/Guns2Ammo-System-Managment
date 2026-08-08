<?php

namespace G2A\POS\Ai\Tools;

use G2A\POS\Ai\ActionPolicy;
use G2A\POS\Ai\ToolRegistry;
use G2A\POS\Database\BoundBookRepository;

final class BoundBookSearchTool {

	public static function register(): void {
		ToolRegistry::register(
			'boundbook.search',
			array(
				'description' => 'Search the ATF acquisition & disposition (bound) book. Filter by serial number, date range, or entry type. Read-only.',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'serial'     => array( 'type' => 'string' ),
						'date_from'  => array(
							'type'        => 'string',
							'description' => 'YYYY-MM-DD',
						),
						'date_to'    => array(
							'type'        => 'string',
							'description' => 'YYYY-MM-DD',
						),
						'entry_type' => array(
							'type' => 'string',
							'enum' => array( 'acquisition', 'disposition', 'any' ),
						),
						'limit'      => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
						),
					),
				),
			),
			array( self::class, 'run' ),
			'g2a_pos_view_bound_book',
			ActionPolicy::SILENT
		);
	}

	public static function run( array $args ): array {
		$repo = new BoundBookRepository();
		$rows = $repo->paginate(
			array(
				'serial'     => sanitize_text_field( (string) ( $args['serial'] ?? '' ) ),
				'entry_type' => $args['entry_type'] === 'any' ? '' : sanitize_key( (string) ( $args['entry_type'] ?? '' ) ),
				'date_from'  => sanitize_text_field( (string) ( $args['date_from'] ?? '' ) ),
				'date_to'    => sanitize_text_field( (string) ( $args['date_to'] ?? '' ) ),
				'per_page'   => max( 1, min( 50, (int) ( $args['limit'] ?? 20 ) ) ),
				'page'       => 1,
			)
		);
		return array(
			'ok'      => true,
			'count'   => count( $rows ),
			'entries' => $rows,
		);
	}
}
