<?php

namespace G2A\POS\Shipping;

/**
 * FedEx label creator (REST/OAuth). Behaves identically to UpsCarrier when
 * credentials are missing — returns requires_credentials. Defaults to
 * SIGNATURE_OPTION = ADULT (FedEx's 21+-acceptable option).
 */
final class FedExCarrier implements CarrierInterface {

	public function code(): string {
		return 'fedex';
	}

	public function create_label( array $shipment ): array {
		$cfg     = (array) get_option( 'g2a_pos_shipping_fedex', array() );
		$token   = $cfg['oauth_token'] ?? '';
		$account = $cfg['account_number'] ?? '';
		if ( ! $token || ! $account ) {
			return array(
				'ok'      => false,
				'error'   => 'requires_credentials',
				'message' => 'Configure FedEx OAuth token + account number under Settings → Shipping.',
			);
		}

		$payload = array(
			'labelResponseOptions' => 'URL_ONLY',
			'accountNumber'        => array( 'value' => $account ),
			'requestedShipment'    => array(
				'shipper'                   => $this->party( $shipment['from'] ?? array() ),
				'recipients'                => array( $this->party( $shipment['to'] ?? array() ) ),
				'serviceType'               => $shipment['service_code'] ?? 'FEDEX_GROUND',
				'packagingType'             => 'YOUR_PACKAGING',
				'pickupType'                => 'USE_SCHEDULED_PICKUP',
				'shippingChargesPayment'    => array(
					'paymentType' => 'SENDER',
					'payor'       => array( 'responsibleParty' => array( 'accountNumber' => array( 'value' => $account ) ) ),
				),
				'requestedPackageLineItems' => array(
					array(
						'weight'                 => array(
							'units' => 'LB',
							'value' => max( 1, round( ( (float) ( $shipment['weight_oz'] ?? 16 ) ) / 16, 2 ) ),
						),
						'dimensions'             => array(
							'length' => (int) ( $shipment['length_in'] ?? 12 ),
							'width'  => (int) ( $shipment['width_in'] ?? 8 ),
							'height' => (int) ( $shipment['height_in'] ?? 4 ),
							'units'  => 'IN',
						),
						'packageSpecialServices' => array(
							'specialServiceTypes'   => array( 'SIGNATURE_OPTION' ),
							'signatureOptionDetail' => array( 'optionType' => 'ADULT' ),
						),
					),
				),
			),
		);

		$res = wp_remote_post(
			'https://apis.fedex.com/ship/v1/shipments',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
					'X-locale'      => 'en_US',
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 30,
			)
		);
		if ( is_wp_error( $res ) ) {
			return array(
				'ok'      => false,
				'error'   => 'http_error',
				'message' => $res->get_error_message(),
			);
		}
		$body          = json_decode( (string) wp_remote_retrieve_body( $res ), true ) ?: array();
		$shipment_resp = $body['output']['transactionShipments'][0] ?? null;
		if ( ! $shipment_resp ) {
			return array(
				'ok'       => false,
				'error'    => 'fedex_error',
				'response' => $body,
			);
		}
		$piece = $shipment_resp['pieceResponses'][0] ?? array();
		return array(
			'ok'               => true,
			'tracking_number'  => $piece['trackingNumber'] ?? ( $shipment_resp['masterTrackingNumber'] ?? '' ),
			'label_format'     => 'pdf',
			'label_url'        => $piece['packageDocuments'][0]['url'] ?? '',
			'cost_amount'      => (float) ( $shipment_resp['shipmentRating']['shipmentRateDetails'][0]['totalNetCharge'] ?? 0 ),
			'response_payload' => $body,
			'request_payload'  => $payload,
		);
	}

	public function void_label( string $tracking_number ): array {
		$cfg     = (array) get_option( 'g2a_pos_shipping_fedex', array() );
		$token   = $cfg['oauth_token'] ?? '';
		$account = $cfg['account_number'] ?? '';
		if ( ! $token || ! $account ) {
			return array(
				'ok'    => false,
				'error' => 'requires_credentials',
			);
		}
		$payload = array(
			'accountNumber'  => array( 'value' => $account ),
			'trackingNumber' => $tracking_number,
		);
		$res     = wp_remote_request(
			'https://apis.fedex.com/ship/v1/shipments/cancel',
			array(
				'method'  => 'PUT',
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 20,
			)
		);
		if ( is_wp_error( $res ) ) {
			return array(
				'ok'    => false,
				'error' => $res->get_error_message(),
			);
		}
		return array( 'ok' => (int) wp_remote_retrieve_response_code( $res ) < 300 );
	}

	private function party( array $a ): array {
		return array(
			'contact' => array(
				'personName'  => $a['name'] ?? '',
				'phoneNumber' => $a['phone'] ?? '',
				'companyName' => $a['company'] ?? '',
			),
			'address' => array(
				'streetLines'         => array( $a['address'] ?? '' ),
				'city'                => $a['city'] ?? '',
				'stateOrProvinceCode' => $a['state'] ?? '',
				'postalCode'          => $a['zip'] ?? '',
				'countryCode'         => $a['country'] ?? 'US',
			),
		);
	}
}
