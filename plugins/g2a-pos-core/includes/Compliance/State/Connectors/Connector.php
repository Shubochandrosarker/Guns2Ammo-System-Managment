<?php

namespace G2A\POS\Compliance\State\Connectors;

/**
 * State-conducted background check connector.
 * Each state agency (CA DOJ/DROS, IL PICS, NY NICS-IS, PA PICS, etc.) speaks
 * a different API; this interface lets the NICS service swap them in.
 *
 * Implementations should be thin HTTP clients. No state is persisted here;
 * the caller persists results into the g2a_nics_transactions table.
 */
interface Connector {

	public function state_code(): string;

	public function label(): string;

	/**
	 * Submit transferee + firearms to the state agency.
	 * Returns:
	 *   - state_transaction_id (string|null)
	 *   - status: 'proceed'|'denied'|'delayed'|'pending'
	 *   - delayed_until?: ISO-8601 string
	 *   - message?: string
	 *   - raw_response?: array
	 */
	public function submit( array $transferee, array $firearms, array $context = array() ): array;

	/**
	 * Re-query a previously submitted transaction.
	 */
	public function poll( string $state_transaction_id ): array;
}
