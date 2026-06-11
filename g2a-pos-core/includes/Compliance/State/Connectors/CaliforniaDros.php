<?php

namespace G2A\POS\Compliance\State\Connectors;

/**
 * California DROS (Dealer Record Of Sale) connector.
 *
 * STATUS: scaffolded; requires CA DOJ DES (Dealer Entry System) credentials
 * to go live. Without credentials, falls back to a queued-pending response
 * so the operator submits via DES web portal manually.
 */
final class CaliforniaDros implements Connector {

	public function state_code(): string {
		return 'CA'; }
	public function label(): string {
		return 'CA DROS / DOJ'; }

	public function submit( array $transferee, array $firearms, array $context = array() ): array {
		$creds = $this->credentials();
		if ( ! $creds ) {
			return array(
				'status'               => 'pending',
				'state_transaction_id' => null,
				'message'              => 'CA DROS credentials not configured; submit via DES portal.',
			);
		}
		$endpoint = (string) $creds['endpoint'];
		$body     = wp_json_encode(
			array(
				'dealer_id'  => $creds['dealer_id'],
				'transferee' => $transferee,
				'firearms'   => $firearms,
				'context'    => $context,
			)
		);
		$resp     = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $creds['api_key'],
				),
				'body'    => $body,
			)
		);
		if ( is_wp_error( $resp ) ) {
			return array(
				'status'  => 'pending',
				'message' => $resp->get_error_message(),
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( (string) wp_remote_retrieve_body( $resp ), true ) ?: array();
		if ( $code >= 200 && $code < 300 ) {
			return array(
				'status'               => $data['status'] ?? 'pending',
				'state_transaction_id' => $data['dros_number'] ?? null,
				'delayed_until'        => $data['delivery_eligible_at'] ?? null,
				'raw_response'         => $data,
			);
		}
		return array(
			'status'       => 'pending',
			'message'      => 'DROS submission failed: HTTP ' . $code,
			'raw_response' => $data,
		);
	}

	public function poll( string $state_transaction_id ): array {
		$creds = $this->credentials();
		if ( ! $creds ) {
			return array(
				'status'               => 'pending',
				'state_transaction_id' => $state_transaction_id,
			);
		}
		$url  = trim( (string) $creds['endpoint'], '/' ) . '/' . rawurlencode( $state_transaction_id );
		$resp = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . $creds['api_key'] ),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return array(
				'status'  => 'pending',
				'message' => $resp->get_error_message(),
			);
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $resp ), true ) ?: array();
		return array(
			'status'               => $data['status'] ?? 'pending',
			'state_transaction_id' => $state_transaction_id,
			'delayed_until'        => $data['delivery_eligible_at'] ?? null,
			'raw_response'         => $data,
		);
	}

	private function credentials(): ?array {
		$opt = (array) get_option( 'g2a_pos_dros_credentials', array() );
		if ( empty( $opt['endpoint'] ) || empty( $opt['api_key'] ) || empty( $opt['dealer_id'] ) ) {
			return null;
		}
		return $opt;
	}
}
