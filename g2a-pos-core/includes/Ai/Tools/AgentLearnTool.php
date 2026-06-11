<?php

namespace G2A\POS\Ai\Tools;

use G2A\POS\Ai\ActionPolicy;
use G2A\POS\Ai\BrainService;
use G2A\POS\Ai\ToolRegistry;

final class AgentLearnTool {

	public static function register(): void {
		ToolRegistry::register(
			'agent.learn',
			array(
				'description' => 'Add a fact or store policy to the brain so the agent remembers it in future conversations. Pass a short label and the fact text. Idempotent on content.',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'label' => array( 'type' => 'string' ),
						'fact'  => array( 'type' => 'string' ),
						'scope' => array(
							'type' => 'string',
							'enum' => array( 'public', 'staff', 'admin' ),
						),
					),
					'required'   => array( 'label', 'fact' ),
				),
			),
			array( self::class, 'run' ),
			'g2a_pos_access',
			ActionPolicy::CONFIRM
		);
	}

	public static function run( array $args ): array {
		$label = trim( (string) ( $args['label'] ?? '' ) );
		$fact  = trim( (string) ( $args['fact'] ?? '' ) );
		if ( ! $label || ! $fact ) {
			return array(
				'ok'    => false,
				'error' => 'label_and_fact_required',
			);
		}
		$res = BrainService::ingest_text(
			$label,
			$fact,
			array(
				'source_type' => 'agent_learned',
				'scope'       => $args['scope'] ?? 'staff',
				'tags'        => 'agent_learned',
			)
		);
		return $res;
	}
}
