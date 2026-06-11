<?php

namespace G2A\POS\Queue;

use G2A\POS\Database\QueueRepository;

final class Worker {

	public static function process_pending(): void {
		$repo  = new QueueRepository();
		$batch = $repo->claim_batch( 25 );

		foreach ( $batch as $job ) {
			try {
				self::process_job( $job );
				$repo->mark_done( (int) $job['id'] );
			} catch ( \Throwable $e ) {
				$repo->mark_failed( (int) $job['id'], $e->getMessage() );
			}
		}
	}

	private static function process_job( array $job ): void {
		$type    = sanitize_key( (string) ( $job['queue_type'] ?? '' ) );
		$payload = json_decode( (string) ( $job['payload'] ?? '{}' ), true );
		if ( ! is_array( $payload ) ) {
			$payload = array();
		}

		// Default behavior: fire typed and generic hooks so integrations can attach real processors.
		do_action( 'g2a_pos_queue_before_job', $type, $payload, $job );

		switch ( $type ) {
			case 'inventory_sync':
				do_action( 'g2a_pos_queue_inventory_sync', $payload, $job );
				break;
			case 'crm_sync':
				do_action( 'g2a_pos_queue_crm_sync', $payload, $job );
				break;
			case 'compliance_screening':
				do_action( 'g2a_pos_queue_compliance_screening', $payload, $job );
				break;
			default:
				do_action( 'g2a_pos_queue_unknown_job', $type, $payload, $job );
				break;
		}

		do_action( 'g2a_pos_queue_after_job', $type, $payload, $job );
	}
}
