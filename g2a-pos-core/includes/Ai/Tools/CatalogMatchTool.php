<?php

namespace G2A\POS\Ai\Tools;

use G2A\POS\Ai\ActionPolicy;
use G2A\POS\Ai\ToolRegistry;
use G2A\POS\Catalog\IdentityMatcher;
use G2A\POS\Database\ItemIdentityRepository;

final class CatalogMatchTool {

	public static function register(): void {
		ToolRegistry::register(
			'catalog.match_identity',
			array(
				'description' => 'Find the canonical item identity for a fuzzy item description (used by AI when ringing up or receiving an item). Returns the top scored candidates from the catalog truth.',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'manufacturer' => array( 'type' => 'string' ),
						'model'        => array( 'type' => 'string' ),
						'caliber'      => array( 'type' => 'string' ),
						'upc'          => array( 'type' => 'string' ),
						'mfg_part'     => array( 'type' => 'string' ),
					),
					'required'   => array( 'manufacturer' ),
				),
			),
			array( self::class, 'run' ),
			'g2a_pos_access',
			ActionPolicy::SILENT
		);
	}

	public static function run( array $args ): array {
		if ( empty( $args['manufacturer'] ) ) {
			return array(
				'ok'    => false,
				'error' => 'manufacturer required',
			);
		}
		$repo       = new ItemIdentityRepository();
		$candidates = $repo->candidates( $args, 100 );
		$scored     = array();
		foreach ( $candidates as $c ) {
			$s = IdentityMatcher::score( $args, $c );
			if ( $s > 0.10 ) {
				$scored[] = array(
					'identity_id'    => (int) $c['id'],
					'canonical_code' => $c['canonical_code'],
					'manufacturer'   => $c['manufacturer'],
					'model'          => $c['model'],
					'caliber'        => $c['caliber'],
					'score'          => $s,
				);
			}
		}
		usort( $scored, static fn( $a, $b ) => $b['score'] <=> $a['score'] );
		return array(
			'ok'        => true,
			'threshold' => IdentityMatcher::THRESHOLD,
			'matches'   => array_slice( $scored, 0, 5 ),
		);
	}
}
