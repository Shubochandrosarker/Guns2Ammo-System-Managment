<?php

namespace G2A\POS\Hardware;

/**
 * Minimal ESC/POS receipt builder. Emits the binary byte string for an
 * 80mm thermal printer (Epson TM-T20/T88, Star TSP). The byte stream can
 * be POSTed to a network-attached printer (TCP:9100) or a print-server
 * URL — both are configured per-register in g2a_hardware_profiles.
 *
 * We don't open sockets from PHP-FPM here. The plugin exposes a /hardware/
 * print endpoint that returns the bytes; the in-store register driver
 * (JS in the SPA or a desktop helper) pipes them to the device.
 */
final class EscPosPrinter {

	private const ESC = "\x1B";
	private const GS  = "\x1D";
	private const LF  = "\x0A";

	private string $buf = '';

	public function init(): self {
		$this->buf .= self::ESC . '@';
		return $this; }
	public function center(): self {
		$this->buf .= self::ESC . 'a' . "\x01";
		return $this; }
	public function left(): self {
		$this->buf .= self::ESC . 'a' . "\x00";
		return $this; }
	public function bold( bool $on ): self {
		$this->buf .= self::ESC . 'E' . ( $on ? "\x01" : "\x00" );
		return $this; }
	public function double( bool $on ): self {
		$this->buf .= self::GS . '!' . ( $on ? "\x11" : "\x00" );
		return $this; }
	public function feed( int $n = 1 ): self {
		$this->buf .= str_repeat( self::LF, $n );
		return $this; }
	public function line( string $s = '' ): self {
		$this->buf .= $s . self::LF;
		return $this; }
	public function rule( int $cols = 42 ): self {
		$this->buf .= str_repeat( '-', $cols ) . self::LF;
		return $this; }
	public function cut(): self {
		$this->buf .= self::GS . 'V' . "\x42" . "\x00";
		return $this; }

	/** Kick the cash drawer wired to the printer's RJ-11 drawer port. */
	public function kick_drawer(): self {
		$this->buf .= self::ESC . 'p' . "\x00" . "\x19" . "\xFA";
		return $this;
	}

	public function barcode_code128( string $code ): self {
		$this->buf .= self::GS . 'h' . "\x50";    // height = 80
		$this->buf .= self::GS . 'w' . "\x02";    // width module
		$this->buf .= self::GS . 'H' . "\x02";    // HRI below
		$this->buf .= self::GS . 'k' . "\x49" . chr( strlen( $code ) ) . $code;
		return $this;
	}

	public function bytes(): string {
		return $this->buf; }

	public static function render_receipt( array $order, array $store ): string {
		$p = new self();
		$p->init()->center()->bold( true )->double( true )
			->line( $store['name'] ?? 'G2A Firearms' )->double( false )
			->bold( false )
			->line( $store['address'] ?? '' )
			->line( ( $store['phone'] ?? '' ) . ' · FFL ' . ( $store['ffl_number'] ?? '' ) )
			->feed()
			->left()->bold( true )->line( 'Order #' . ( $order['id'] ?? '' ) )->bold( false )
			->line( 'Date: ' . ( $order['created_at'] ?? date( 'Y-m-d H:i' ) ) )
			->line( 'Register: ' . ( $order['register_code'] ?? '' ) )
			->line( 'Cashier: ' . ( $order['cashier_name'] ?? '' ) )
			->rule();
		foreach ( ( $order['items'] ?? array() ) as $it ) {
			$qty   = (string) ( $it['quantity'] ?? 1 );
			$name  = substr( (string) ( $it['name'] ?? $it['sku'] ?? '' ), 0, 28 );
			$price = number_format( (float) ( $it['line_total'] ?? 0 ), 2 );
			$p->line( sprintf( '%-28s %3sx %8s', $name, $qty, $price ) );
			if ( ! empty( $it['serial_number'] ) ) {
				$p->line( '  SN: ' . $it['serial_number'] );
			}
		}
		$p->rule()
			->line( sprintf( '%-32s %9s', 'Subtotal', '$' . number_format( (float) ( $order['subtotal'] ?? 0 ), 2 ) ) )
			->line( sprintf( '%-32s %9s', 'Tax', '$' . number_format( (float) ( $order['tax_total'] ?? 0 ), 2 ) ) )
			->bold( true )
			->line( sprintf( '%-32s %9s', 'TOTAL', '$' . number_format( (float) ( $order['grand_total'] ?? 0 ), 2 ) ) )
			->bold( false )
			->feed()
			->center()
			->line( 'Thank you for shopping with us!' )
			->feed( 2 );
		if ( ! empty( $order['id'] ) ) {
			$p->barcode_code128( (string) $order['id'] );
		}
		$p->feed( 3 )->cut();
		return $p->bytes();
	}
}
