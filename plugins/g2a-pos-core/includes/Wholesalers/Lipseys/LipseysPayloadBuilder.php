<?php

namespace G2A\POS\Wholesalers\Lipseys;

/**
 * Builds JSON payloads for the two distinct Lipsey's order endpoints.
 * Pure functions — no I/O, no globals — so they can be unit-tested.
 *
 * Schemas verified against the LipseysAPI Public Postman collection.
 */
final class LipseysPayloadBuilder {

	/**
	 * /api/Integration/Order/APIOrder — ship to the dealer's FFL on file.
	 * Minimal schema: PONumber, EmailConfirmation, Items[].
	 */
	public static function warehouseOrder( array $order ): array {
		return array(
			'PONumber'          => (string) ( $order['po_number'] ?? 'G2A-' . ( $order['pos_order_id'] ?? 0 ) ),
			'EmailConfirmation' => (bool) ( $order['email_confirmation'] ?? true ),
			'Items'             => self::items( $order['items'] ?? array() ),
		);
	}

	/**
	 * /api/Integration/Order/DropShip — third-party drop-ship with explicit
	 * billing + shipping addresses + Overnight flag + MessageForSalesExec.
	 */
	public static function dropShip( array $order ): array {
		$billing = (array) ( $order['billing'] ?? array() );
		$ship    = (array) ( $order['ship_to'] ?? array() );

		return array(
			'Warehouse'            => (string) ( $order['warehouse'] ?? '' ),
			'PoNumber'             => (string) ( $order['po_number'] ?? 'G2A-' . ( $order['pos_order_id'] ?? 0 ) ),
			'BillingName'          => (string) ( $billing['name'] ?? '' ),
			'BillingAddressLine1'  => (string) ( $billing['address'] ?? $billing['address_line_1'] ?? '' ),
			'BillingAddressLine2'  => (string) ( $billing['address_line_2'] ?? '' ),
			'BillingAddressCity'   => (string) ( $billing['city'] ?? '' ),
			'BillingAddressState'  => (string) ( $billing['state'] ?? '' ),
			'BillingAddressZip'    => (string) ( $billing['zip'] ?? '' ),
			'ShippingName'         => (string) ( $ship['name'] ?? '' ),
			'ShippingAddressLine1' => (string) ( $ship['address'] ?? $ship['address_line_1'] ?? '' ),
			'ShippingAddressLine2' => (string) ( $ship['address_line_2'] ?? '' ),
			'ShippingAddressCity'  => (string) ( $ship['city'] ?? '' ),
			'ShippingAddressState' => (string) ( $ship['state'] ?? '' ),
			'ShippingAddressZip'   => (string) ( $ship['zip'] ?? '' ),
			'MessageForSalesExec'  => (string) ( $order['message_for_sales_exec'] ?? ( ! empty( $ship['ffl'] ) ? 'Transfer FFL: ' . $ship['ffl'] : '' ) ),
			'Items'                => self::items( $order['items'] ?? array() ),
			'Overnight'            => (bool) ( $order['overnight'] ?? false ),
		);
	}

	private static function items( array $items ): array {
		$out = array();
		foreach ( $items as $item ) {
			$out[] = array(
				'ItemNo'   => (string) ( $item['vendor_sku'] ?? $item['ItemNo'] ?? $item['itemNo'] ?? '' ),
				'Quantity' => (int) ( $item['quantity'] ?? 1 ),
				'Note'     => (string) ( $item['note'] ?? '' ),
			);
		}
		return $out;
	}
}
