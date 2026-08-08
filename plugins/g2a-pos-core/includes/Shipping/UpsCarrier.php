<?php

namespace G2A\POS\Shipping;

/**
 * UPS label creator. Calls the UPS REST API when an OAuth token is
 * configured under the `g2a_pos_shipping_ups` option; otherwise returns a
 * `requires_credentials` response so the merchant gets a clear next step.
 *
 * Firearm-shipping defaults are pre-set: Adult-Signature-Required and the
 * 21+ signature flag (UPS service code 010 = adult signature required).
 */
final class UpsCarrier implements CarrierInterface {

	public function code(): string {
		return 'ups';
	}

	public function create_label( array $shipment ): array {
		$cfg            = (array) get_option( 'g2a_pos_shipping_ups', array() );
		$token          = $cfg['oauth_token'] ?? '';
		$shipper_number = $cfg['account_number'] ?? '';
		if ( ! $token || ! $shipper_number ) {
			return array(
				'ok'      => false,
				'error'   => 'requires_credentials',
				'message' => 'Configure UPS OAuth token + shipper number under Settings → Shipping.',
			);
		}

		$payload = array(
			'ShipmentRequest' => array(
				'Shipment'           => array(
					'Description'        => $shipment['description'] ?? 'Firearm shipment',
					'Shipper'            => $this->party( $shipment['from'] ?? array(), $shipper_number ),
					'ShipTo'             => $this->party( $shipment['to'] ?? array(), null ),
					'ShipFrom'           => $this->party( $shipment['from'] ?? array(), null ),
					'PaymentInformation' => array(
						'ShipmentCharge' => array(
							'Type'        => '01',
							'BillShipper' => array( 'AccountNumber' => $shipper_number ),
						),
					),
					'Service'            => array( 'Code' => $shipment['service_code'] ?? '03' ),
					'Package'            => array(
						array(
							'PackagingType'         => array( 'Code' => '02' ),
							'Dimensions'            => array(
								'UnitOfMeasurement' => array( 'Code' => 'IN' ),
								'Length'            => (string) ( $shipment['length_in'] ?? '12' ),
								'Width'             => (string) ( $shipment['width_in'] ?? '8' ),
								'Height'            => (string) ( $shipment['height_in'] ?? '4' ),
							),
							'PackageWeight'         => array(
								'UnitOfMeasurement' => array( 'Code' => 'LBS' ),
								'Weight'            => (string) max( 1, round( ( (float) ( $shipment['weight_oz'] ?? 16 ) ) / 16, 2 ) ),
							),
							'PackageServiceOptions' => array(
								'DeliveryConfirmation' => array( 'DCISType' => '3' ), // 3 = adult signature required
							),
						),
					),
				),
				'LabelSpecification' => array(
					'LabelImageFormat' => array( 'Code' => 'GIF' ),
					'LabelStockSize'   => array(
						'Height' => '6',
						'Width'  => '4',
					),
				),
			),
		);

		$res = wp_remote_post(
			'https://onlinetools.ups.com/api/shipments/v1/ship',
			array(
				'headers' => array(
					'Authorization'  => 'Bearer ' . $token,
					'Content-Type'   => 'application/json',
					'transId'        => wp_generate_uuid4(),
					'transactionSrc' => 'G2A_POS',
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
		$body   = json_decode( (string) wp_remote_retrieve_body( $res ), true ) ?: array();
		$result = $body['ShipmentResponse']['ShipmentResults'] ?? null;
		if ( ! $result ) {
			return array(
				'ok'       => false,
				'error'    => 'ups_error',
				'response' => $body,
			);
		}
		$package = $result['PackageResults'] ?? array();
		$package = isset( $package[0] ) ? $package[0] : $package;
		return array(
			'ok'               => true,
			'tracking_number'  => $package['TrackingNumber'] ?? '',
			'label_format'     => 'gif',
			'label_data'       => $package['ShippingLabel']['GraphicImage'] ?? '',
			'cost_amount'      => (float) ( $result['ShipmentCharges']['TotalCharges']['MonetaryValue'] ?? 0 ),
			'response_payload' => $body,
			'request_payload'  => $payload,
		);
	}

	public function void_label( string $tracking_number ): array {
		$cfg   = (array) get_option( 'g2a_pos_shipping_ups', array() );
		$token = $cfg['oauth_token'] ?? '';
		if ( ! $token ) {
			return array(
				'ok'    => false,
				'error' => 'requires_credentials',
			);
		}
		$res = wp_remote_request(
			'https://onlinetools.ups.com/api/shipments/v1/void/cancel/' . rawurlencode( $tracking_number ),
			array(
				'method'  => 'DELETE',
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
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

	private function party( array $a, ?string $shipper_number ): array {
		$out = array(
			'Name'          => $a['name'] ?? '',
			'AttentionName' => $a['name'] ?? '',
			'Phone'         => array( 'Number' => $a['phone'] ?? '' ),
			'Address'       => array(
				'AddressLine'       => array( $a['address'] ?? '' ),
				'City'              => $a['city'] ?? '',
				'StateProvinceCode' => $a['state'] ?? '',
				'PostalCode'        => $a['zip'] ?? '',
				'CountryCode'       => $a['country'] ?? 'US',
			),
		);
		if ( $shipper_number ) {
			$out['ShipperNumber'] = $shipper_number;
		}
		return $out;
	}
}
