<?php

namespace G2A\POS\Shipping;

interface CarrierInterface {

	public function code(): string;

	public function create_label( array $shipment ): array;

	public function void_label( string $tracking_number ): array;
}
