<?php

namespace G2A\POS\Ai\Tools;

use G2A\POS\Ai\ActionPolicy;
use G2A\POS\Ai\ToolRegistry;
use G2A\POS\Database\CustomerProfileRepository;
use G2A\POS\Database\CustomerRepository;
use G2A\POS\Database\LoyaltyRepository;

final class CustomerLookupTool {

	public static function register(): void {
		ToolRegistry::register(
			'customer.lookup',
			array(
				'description' => 'Look up a customer by name, email, or phone. Returns profile, loyalty balance, lifetime spend, denied-buyer flag, waiver status.',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'q' => array(
							'type'        => 'string',
							'description' => 'Search term (name, email, or phone fragment)',
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
		$matches      = ( new CustomerRepository() )->search( $q, 10 );
		$profile_repo = new CustomerProfileRepository();
		$loyalty_repo = new LoyaltyRepository();
		$enriched     = array();
		foreach ( $matches as $m ) {
			$cid        = (int) $m['id'];
			$enriched[] = array_merge(
				$m,
				array(
					'profile' => $profile_repo->find( $cid ),
					'loyalty' => $loyalty_repo->balance( $cid ),
				)
			);
		}
		return array(
			'ok'      => true,
			'count'   => count( $enriched ),
			'matches' => $enriched,
		);
	}
}
