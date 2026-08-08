<?php

namespace G2A\POS\API;

use G2A\POS\Database\ShippingLabelRepository;
use G2A\POS\Shipping\CarrierRegistry;
use WP_REST_Request;

final class ShippingController {

	public static function carriers( WP_REST_Request $request ) {
		$out = array();
		foreach ( CarrierRegistry::all() as $c ) {
			$out[] = array( 'code' => $c->code() );
		}
		return array( 'carriers' => $out );
	}

	public static function create_label( WP_REST_Request $request ) {
		$body         = $request->get_json_params() ?: array();
		$carrier_code = sanitize_key( (string) ( $body['carrier'] ?? 'ups' ) );
		$carrier      = CarrierRegistry::get( $carrier_code );
		if ( ! $carrier ) {
			return new \WP_Error( 'unknown_carrier', 'Unknown carrier ' . $carrier_code, array( 'status' => 400 ) );
		}
		$shipment = array(
			'description'  => $body['description'] ?? 'Firearm shipment (Adult Signature, 21+)',
			'service_code' => $body['service_code'] ?? null,
			'from'         => $body['from'] ?? array(),
			'to'           => $body['to'] ?? array(),
			'weight_oz'    => $body['weight_oz'] ?? 16,
			'length_in'    => $body['length_in'] ?? 12,
			'width_in'     => $body['width_in'] ?? 8,
			'height_in'    => $body['height_in'] ?? 4,
		);
		$result   = $carrier->create_label( $shipment );
		if ( empty( $result['ok'] ) ) {
			return new \WP_Error(
				$result['error'] ?? 'carrier_error',
				$result['message'] ?? 'Carrier error',
				array(
					'status' => 422,
					'detail' => $result,
				)
			);
		}

		$id = ( new ShippingLabelRepository() )->record(
			array(
				'carrier'                  => $carrier_code,
				'service'                  => $body['service_code'] ?? '',
				'tracking_number'          => $result['tracking_number'] ?? '',
				'label_url'                => $result['label_url'] ?? '',
				'label_format'             => $result['label_format'] ?? 'pdf',
				'from_name'                => $body['from']['name'] ?? '',
				'from_address'             => $body['from']['address'] ?? '',
				'from_city'                => $body['from']['city'] ?? '',
				'from_state'               => $body['from']['state'] ?? '',
				'from_zip'                 => $body['from']['zip'] ?? '',
				'to_name'                  => $body['to']['name'] ?? '',
				'to_address'               => $body['to']['address'] ?? '',
				'to_city'                  => $body['to']['city'] ?? '',
				'to_state'                 => $body['to']['state'] ?? '',
				'to_zip'                   => $body['to']['zip'] ?? '',
				'weight_oz'                => $body['weight_oz'] ?? null,
				'length_in'                => $body['length_in'] ?? null,
				'width_in'                 => $body['width_in'] ?? null,
				'height_in'                => $body['height_in'] ?? null,
				'insured_value'            => $body['insured_value'] ?? null,
				'adult_signature_required' => true,
				'requires_21'              => true,
				'cost_amount'              => $result['cost_amount'] ?? null,
				'related_order_id'         => $body['related_order_id'] ?? null,
				'related_transfer_id'      => $body['related_transfer_id'] ?? null,
				'request_payload'          => $result['request_payload'] ?? null,
				'response_payload'         => $result['response_payload'] ?? null,
			)
		);
		return array(
			'label_id'        => $id,
			'tracking_number' => $result['tracking_number'] ?? '',
			'label_url'       => $result['label_url'] ?? '',
			'cost'            => $result['cost_amount'] ?? null,
		);
	}

	public static function void( WP_REST_Request $request ) {
		$id    = (int) $request['id'];
		$label = ( new ShippingLabelRepository() )->find( $id );
		if ( ! $label ) {
			return new \WP_Error( 'not_found', 'Label not found', array( 'status' => 404 ) );
		}
		$carrier = CarrierRegistry::get( (string) $label['carrier'] );
		if ( ! $carrier ) {
			return new \WP_Error( 'unknown_carrier', 'Carrier missing', array( 'status' => 400 ) );
		}
		$res = $carrier->void_label( (string) $label['tracking_number'] );
		if ( ! empty( $res['ok'] ) ) {
			( new ShippingLabelRepository() )->void( $id );
		}
		return $res;
	}

	public static function index( WP_REST_Request $request ) {
		return array( 'items' => ( new ShippingLabelRepository() )->list( (int) ( $request->get_param( 'limit' ) ?: 100 ) ) );
	}
}
