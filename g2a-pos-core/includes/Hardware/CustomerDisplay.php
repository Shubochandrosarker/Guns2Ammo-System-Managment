<?php

namespace G2A\POS\Hardware;

/**
 * Customer-facing display protocol — a tiny JSON envelope that drivers
 * push to a serial VFD pole display, an Android tablet, or a browser
 * window on a second monitor. The hardware profile points at the
 * endpoint; the POS SPA POSTs an envelope here every time the cart
 * changes.
 */
final class CustomerDisplay {

	public static function build( array $cart, ?string $welcome = null ): array {
		$lines   = array();
		$running = 0.0;
		foreach ( ( $cart['items'] ?? array() ) as $it ) {
			$name       = substr( (string) ( $it['name'] ?? $it['sku'] ?? '' ), 0, 20 );
			$line_total = (float) ( $it['line_total'] ?? 0 );
			$running   += $line_total;
			$lines[]    = array(
				'name'  => $name,
				'qty'   => (float) ( $it['quantity'] ?? 1 ),
				'price' => $line_total,
			);
		}
		return array(
			'mode'       => $cart ? 'cart' : 'idle',
			'welcome'    => $welcome ?? 'Welcome to G2A Firearms',
			'lines'      => $lines,
			'subtotal'   => $running,
			'tax'        => (float) ( $cart['tax_total'] ?? 0 ),
			'total'      => (float) ( $cart['grand_total'] ?? $running ),
			'currency'   => 'USD',
			'updated_at' => gmdate( 'c' ),
		);
	}
}
