<?php

namespace WpisticFFL\Tests\Unit;

use Brain\Monkey\Functions;
use WpisticFFL\G2A_Gateway_Credova;

require_once dirname( __DIR__, 2 ) . '/includes/class-wpistic-ffl-g2a-gateway-credova.php';

/**
 * Credova's create-application request contract (storeCode/firstName/
 * lastName/mobilePhone/email/referenceNumber/redirectUrl/products) is
 * confirmed verbatim from Credova's own open-source Ruby SDK -- see
 * G2A_Gateway_Credova's own docblock. The CREATE response's field names
 * and the application-status enum were never independently confirmed, so
 * extract_field() defends against several plausible spellings rather than
 * assuming one. These tests exercise both pure, instance-independent
 * static methods directly -- no live account, no WC_Payment_Gateway
 * instantiation.
 */
final class CredovaGatewayTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	public function test_build_application_payload_maps_the_confirmed_fields(): void {
		$order_data = [
			'first_name'   => 'Jane',
			'last_name'    => 'Doe',
			'phone'        => '(555) 123-4567',
			'email'        => 'jane@example.com',
			'order_number' => 'G2A-1001',
			'items'        => [
				[ 'name' => 'Test Rifle', 'quantity' => 1, 'price' => 899.00 ],
				[ 'name' => 'Ammo Box', 'quantity' => 2, 'price' => 29.99 ],
			],
		];

		$payload = G2A_Gateway_Credova::build_application_payload( $order_data, 'STORE-42', 'https://example.com/thank-you' );

		$this->assertSame( 'STORE-42', $payload['storeCode'] );
		$this->assertSame( 'Jane', $payload['firstName'] );
		$this->assertSame( 'Doe', $payload['lastName'] );
		$this->assertSame( '5551234567', $payload['mobilePhone'], 'Phone must be digits-only for the wire format.' );
		$this->assertSame( 'jane@example.com', $payload['email'] );
		$this->assertSame( 'G2A-1001', $payload['referenceNumber'] );
		$this->assertSame( 'https://example.com/thank-you', $payload['redirectUrl'] );
		$this->assertCount( 2, $payload['products'] );
		$this->assertSame( 'Test Rifle', $payload['products'][0]['name'] );
		$this->assertSame( 1, $payload['products'][0]['quantity'] );
		$this->assertSame( 899.00, $payload['products'][0]['price'] );
		$this->assertSame( 2, $payload['products'][1]['quantity'] );
	}

	public function test_build_application_payload_has_no_amount_or_cart_total_field(): void {
		// Confirmed absent from the real create-application contract -- the
		// financed amount is derived from `products`, not sent explicitly.
		$order_data = [
			'first_name' => 'A', 'last_name' => 'B', 'phone' => '', 'email' => '',
			'order_number' => 'X', 'items' => [],
		];

		$payload = G2A_Gateway_Credova::build_application_payload( $order_data, 'S', 'https://example.com' );

		$this->assertArrayNotHasKey( 'amount', $payload );
		$this->assertArrayNotHasKey( 'cartTotal', $payload );
		$this->assertArrayNotHasKey( 'cart_total', $payload );
	}

	public function test_a_store_supplied_filter_can_correct_the_products_payload_shape(): void {
		Functions\when( 'apply_filters' )->alias( function ( $tag, $value ) {
			if ( 'wpistic_ffl_credova_products_payload' === $tag ) {
				$value['amount'] = 899.00; // A store discovers Credova actually wants an explicit total.
				return $value;
			}
			return $value;
		} );
		$order_data = [
			'first_name' => 'A', 'last_name' => 'B', 'phone' => '', 'email' => '',
			'order_number' => 'X', 'items' => [],
		];

		$payload = G2A_Gateway_Credova::build_application_payload( $order_data, 'S', 'https://example.com' );

		$this->assertSame( 899.00, $payload['amount'] );
	}

	public function test_extract_field_tries_keys_in_priority_order(): void {
		$payload = [ 'application_id' => 'B', 'id' => 'A' ];

		$this->assertSame( 'A', G2A_Gateway_Credova::extract_field( $payload, [ 'id', 'application_id' ] ) );
		$this->assertSame( 'B', G2A_Gateway_Credova::extract_field( $payload, [ 'application_id', 'id' ] ) );
	}

	public function test_extract_field_falls_back_through_plausible_spellings(): void {
		$payload = [ 'redirectUrl' => 'https://credova.example/app/123' ];

		$this->assertSame(
			'https://credova.example/app/123',
			G2A_Gateway_Credova::extract_field( $payload, [ 'redirect_url', 'redirectUrl', 'url' ] )
		);
	}

	public function test_extract_field_returns_empty_string_when_nothing_matches(): void {
		$this->assertSame( '', G2A_Gateway_Credova::extract_field( [ 'foo' => 'bar' ], [ 'id', 'applicationId' ] ) );
	}

	public function test_extract_field_ignores_a_blank_value_and_keeps_looking(): void {
		$payload = [ 'id' => '', 'applicationId' => 'REAL-ID' ];

		$this->assertSame( 'REAL-ID', G2A_Gateway_Credova::extract_field( $payload, [ 'id', 'applicationId' ] ) );
	}
}
