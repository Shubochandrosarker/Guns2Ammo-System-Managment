<?php

namespace G2A\POS\Domain;

/**
 * Server-side sales-tax calculator for POS totals.
 *
 * Fully config-driven via the g2a_pos_tax option:
 *   enabled        bool   — master switch (default true)
 *   default_rate   float  — percent applied to every taxable line
 *                           (default 8.3 — Mesa AZ combined TPT)
 *   category_rates array  — per-category/tax-class percent overrides keyed
 *                           by slug, e.g. array( 'ammo' => 9.1, 'labor' => 0 )
 *
 * Lines opt out with 'taxable' => false; a line may name its own bucket via
 * 'tax_class' or 'category' (slug matched against category_rates). Tax is
 * never trusted from the client — controllers/engines call this instead.
 */
final class TaxService {

	public const OPTION = 'g2a_pos_tax';

	/** Option value merged over defaults so partial saves stay safe. */
	public static function config(): array {
		$defaults = array(
			'enabled'        => true,
			'default_rate'   => 8.3,
			'category_rates' => array(),
		);
		$saved    = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$config = array_merge( $defaults, $saved );

		$config['enabled']        = (bool) $config['enabled'];
		$config['default_rate']   = max( 0.0, (float) $config['default_rate'] );
		$config['category_rates'] = is_array( $config['category_rates'] ) ? $config['category_rates'] : array();

		return $config;
	}

	/** Sanitize + persist a config payload; returns the effective config. */
	public static function save_config( array $input ): array {
		$config = self::config();
		if ( array_key_exists( 'enabled', $input ) ) {
			$config['enabled'] = (bool) $input['enabled'];
		}
		if ( array_key_exists( 'default_rate', $input ) ) {
			$config['default_rate'] = max( 0.0, (float) $input['default_rate'] );
		}
		if ( isset( $input['category_rates'] ) && is_array( $input['category_rates'] ) ) {
			$rates = array();
			foreach ( $input['category_rates'] as $slug => $rate ) {
				$slug = sanitize_key( (string) $slug );
				if ( $slug === '' ) {
					continue;
				}
				$rates[ $slug ] = max( 0.0, (float) $rate );
			}
			$config['category_rates'] = $rates;
		}
		update_option( self::OPTION, $config );

		return $config;
	}

	/** Percent rate for one line item, honoring per-category overrides. */
	public static function rate_for_line( array $line, ?array $config = null ): float {
		$config = $config ?? self::config();
		if ( empty( $config['enabled'] ) ) {
			return 0.0;
		}
		foreach ( array( 'tax_class', 'category' ) as $key ) {
			if ( empty( $line[ $key ] ) ) {
				continue;
			}
			$slug = sanitize_key( (string) $line[ $key ] );
			if ( $slug !== '' && array_key_exists( $slug, $config['category_rates'] ) ) {
				return max( 0.0, (float) $config['category_rates'][ $slug ] );
			}
		}

		return (float) $config['default_rate'];
	}

	/**
	 * Tax total (dollars, rounded to cents) for a set of line items.
	 *
	 * Lines default to taxable unless they carry 'taxable' => false. An
	 * order-level discount is prorated across all lines before tax so a
	 * discounted sale is taxed on what the customer actually pays.
	 */
	public static function tax_for_lines( array $lines, float $discount_total = 0.0 ): float {
		$config = self::config();
		if ( empty( $config['enabled'] ) ) {
			return 0.0;
		}

		$subtotal = 0.0;
		foreach ( $lines as $line ) {
			$subtotal += max( 0.0, (float) ( $line['line_total'] ?? 0 ) );
		}
		if ( $subtotal <= 0 ) {
			return 0.0;
		}
		$discount_total = min( $subtotal, max( 0.0, $discount_total ) );
		$factor         = ( $subtotal - $discount_total ) / $subtotal;

		$tax = 0.0;
		foreach ( $lines as $line ) {
			if ( isset( $line['taxable'] ) && ! $line['taxable'] ) {
				continue;
			}
			$base = max( 0.0, (float) ( $line['line_total'] ?? 0 ) ) * $factor;
			$tax += $base * ( self::rate_for_line( $line, $config ) / 100 );
		}

		return round( $tax, 2 );
	}
}
