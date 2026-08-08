<?php

namespace G2A\POS\Ai\Tools;

use G2A\POS\Ai\ActionPolicy;
use G2A\POS\Ai\ToolRegistry;
use G2A\POS\Database\RangeWaiverRepository;

final class RangeWaiverLookupTool {

	public static function register(): void {
		ToolRegistry::register(
			'range.waiver_lookup',
			array(
				'description' => 'Check whether a customer has a valid range waiver on file. Provide email and/or phone.',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'email' => array( 'type' => 'string' ),
						'phone' => array( 'type' => 'string' ),
					),
				),
			),
			array( self::class, 'run' ),
			'g2a_pos_access',
			ActionPolicy::SILENT
		);
	}

	public static function run( array $args ): array {
		$email = sanitize_email( (string) ( $args['email'] ?? '' ) );
		$phone = sanitize_text_field( (string) ( $args['phone'] ?? '' ) );
		if ( ! $email && ! $phone ) {
			return array(
				'ok'    => false,
				'error' => 'email_or_phone_required',
			);
		}
		$waiver = ( new RangeWaiverRepository() )->findLatestForCustomer( $email, $phone );
		if ( ! $waiver ) {
			return array(
				'ok'      => true,
				'on_file' => false,
			);
		}
		return array(
			'ok'             => true,
			'on_file'        => true,
			'waiver_id'      => (int) $waiver['id'],
			'first_name'     => $waiver['first_name'] ?? '',
			'last_name'      => $waiver['last_name'] ?? '',
			'waiver_date'    => $waiver['waiver_date'] ?? '',
			'valid_until'    => $waiver['valid_until'] ?? null,
			'check_in_count' => (int) ( $waiver['check_in_count'] ?? 0 ),
		);
	}
}
