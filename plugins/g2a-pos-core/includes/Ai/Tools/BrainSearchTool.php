<?php

namespace G2A\POS\Ai\Tools;

use G2A\POS\Ai\ActionPolicy;
use G2A\POS\Ai\BrainService;
use G2A\POS\Ai\ToolRegistry;

final class BrainSearchTool {

	public static function register(): void {
		ToolRegistry::register(
			'brain.search',
			array(
				'description' => 'Search the ingested knowledge base (PDFs, URLs, training material, store policies, ATF guides) for relevant passages. Use this for "how do I…" or "what does our policy say…" questions.',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'q' => array( 'type' => 'string' ),
						'k' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 10,
						),
					),
					'required'   => array( 'q' ),
				),
			),
			array( self::class, 'run' ),
			'g2a_pos_access',
			ActionPolicy::SILENT
		);
	}

	public static function run( array $args ): array {
		$q = trim( (string) ( $args['q'] ?? '' ) );
		if ( $q === '' ) {
			return array(
				'ok'    => false,
				'error' => 'query_required',
			);
		}
		$hits = BrainService::retrieve( $q, (int) ( $args['k'] ?? 5 ) );
		return array(
			'ok'       => true,
			'count'    => count( $hits ),
			'passages' => $hits,
		);
	}
}
