<?php

namespace G2A\POS\Compliance\State\Connectors;

/**
 * No-op connector for states without an automated submission path.
 * Operator enters the state transaction id and response manually.
 */
final class NullConnector implements Connector {

	public function __construct( private string $state_code, private string $label = '' ) {}
	public function state_code(): string {
		return $this->state_code; }
	public function label(): string {
		return $this->label !== '' ? $this->label : 'Manual entry'; }

	public function submit( array $transferee, array $firearms, array $context = array() ): array {
		return array(
			'status'               => 'pending',
			'state_transaction_id' => null,
			'message'              => 'Manual submission required.',
		);
	}

	public function poll( string $state_transaction_id ): array {
		return array(
			'status'               => 'pending',
			'state_transaction_id' => $state_transaction_id,
		);
	}
}
