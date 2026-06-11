<?php

namespace G2A\POS\Domain;

use G2A\POS\Database\AuditLogRepository;
use G2A\POS\Database\InventoryRepository;
use G2A\POS\Database\QueueRepository;

final class InventoryEngine {

	public static function adjust( array $payload ): array {
		$repo  = new InventoryRepository();
		$queue = new QueueRepository();
		$audit = new AuditLogRepository();

		$before = (float) ( $payload['before_qty'] ?? 0 );
		$delta  = (float) ( $payload['quantity_delta'] ?? 0 );
		$after  = $before + $delta;

		$log_id = $repo->log(
			array(
				'product_id'     => (int) $payload['product_id'],
				'variation_id'   => $payload['variation_id'] ?? null,
				'serial_number'  => $payload['serial_number'] ?? null,
				'location_id'    => (int) $payload['location_id'],
				'event_type'     => $payload['event_type'] ?? 'manual_adjustment',
				'quantity_delta' => $delta,
				'before_qty'     => $before,
				'after_qty'      => $after,
				'source_ref'     => $payload['source_ref'] ?? null,
				'metadata'       => $payload['metadata'] ?? array(),
			)
		);

		$queue->enqueue(
			'inventory_sync',
			array(
				'inventory_log_id' => $log_id,
				'product_id'       => (int) $payload['product_id'],
				'location_id'      => (int) $payload['location_id'],
				'after_qty'        => $after,
			)
		);

		$audit->add( 'inventory.adjust', 'inventory_log', (string) $log_id, array( 'before_qty' => $before ), array( 'after_qty' => $after ) );

		return array(
			'inventory_log_id' => $log_id,
			'after_qty'        => $after,
		);
	}
}
