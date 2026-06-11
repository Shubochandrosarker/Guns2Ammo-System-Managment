<?php

namespace G2A\POS\Catalog;

/**
 * Confidence-scored matching between a fuzzy item candidate (vendor row,
 * used-firearm intake, manual entry) and an existing canonical identity.
 *
 * Signals stack additively into a 0.0–1.0 confidence. The strongest
 * single signal sets the floor; weaker signals push it up. A score of
 * 1.0 means provably the same item; a score below {@see THRESHOLD}
 * means the caller should create a new canonical identity instead.
 *
 * Signal weights:
 *   - exact UPC                       1.000  (provably the same item)
 *   - exact mfg_part match            0.950
 *   - manufacturer + model match      0.700
 *   - + caliber matches               +0.150
 *   - + firearm_type matches          +0.050
 *   - manufacturer-only match         0.300  (floor — needs more signals)
 *   - fuzzy(model) >= 0.80            +0.100
 */
final class IdentityMatcher {

	public const THRESHOLD = 0.85;

	public static function score( array $candidate, array $existing ): float {
		$cand = self::normalize( $candidate );
		$hit  = self::normalize( $existing );

		// UPC exact match dominates.
		if ( $cand['upc'] !== '' && $cand['upc'] === $hit['upc'] ) {
			return 1.0;
		}

		// Manufacturer part number exact match.
		if ( $cand['mfg_part'] !== '' && $cand['mfg_part'] === $hit['mfg_part'] ) {
			return 0.95;
		}

		$score     = 0.0;
		$mfg_match = $cand['manufacturer'] !== '' && $cand['manufacturer'] === $hit['manufacturer'];

		if ( $mfg_match ) {
			$score = 0.30; // manufacturer floor
			if ( $cand['model'] !== '' && $cand['model'] === $hit['model'] ) {
				$score = 0.70;
			} elseif ( $cand['model'] !== '' && $hit['model'] !== '' && self::sim( $cand['model'], $hit['model'] ) >= 0.80 ) {
				$score += 0.10;
			}
			if ( $score >= 0.70 || $score >= 0.40 ) {
				if ( $cand['caliber'] !== '' && $cand['caliber'] === $hit['caliber'] ) {
					$score += 0.15;
				}
				if ( $cand['firearm_type'] !== '' && $cand['firearm_type'] === $hit['firearm_type'] ) {
					$score += 0.05;
				}
			}
		}

		return min( 1.0, round( $score, 3 ) );
	}

	public static function is_match( array $candidate, array $existing ): bool {
		return self::score( $candidate, $existing ) >= self::THRESHOLD;
	}

	private static function normalize( array $row ): array {
		return array(
			'upc'          => self::clean_upc( (string) ( $row['upc'] ?? '' ) ),
			'mfg_part'     => self::norm( (string) ( $row['mfg_part'] ?? '' ) ),
			'manufacturer' => self::norm( (string) ( $row['manufacturer'] ?? '' ) ),
			'model'        => self::norm( (string) ( $row['model'] ?? '' ) ),
			'caliber'      => self::norm_caliber( (string) ( $row['caliber'] ?? '' ) ),
			'firearm_type' => self::norm( (string) ( $row['firearm_type'] ?? '' ) ),
		);
	}

	/** Strip punctuation, collapse whitespace, lowercase. */
	public static function norm( string $s ): string {
		$s = strtolower( $s );
		$s = preg_replace( '/[^a-z0-9]+/i', ' ', $s ) ?? '';
		return trim( preg_replace( '/\s+/', ' ', $s ) ?? '' );
	}

	/** UPC: digits only. */
	public static function clean_upc( string $s ): string {
		return preg_replace( '/[^0-9]/', '', $s ) ?? '';
	}

	/** Caliber canonicalization — common aliases. */
	public static function norm_caliber( string $s ): string {
		$s       = self::norm( $s );
		$aliases = array(
			'9mm luger'      => '9mm',
			'9x19'           => '9mm',
			'9mm parabellum' => '9mm',
			'5 56 nato'      => '5.56',
			'5 56'           => '5.56',
			'5 56x45'        => '5.56',
			'7 62 nato'      => '7.62',
			'7 62x51'        => '7.62',
			'223 rem'        => '223',
			'223 remington'  => '223',
			'12 ga'          => '12 gauge',
			'12ga'           => '12 gauge',
			'45 acp'         => '45acp',
			'45 auto'        => '45acp',
			'380 acp'        => '380',
			'380 auto'       => '380',
		);
		return $aliases[ $s ] ?? $s;
	}

	/**
	 * Cheap string-similarity (0.0–1.0). PHP's `similar_text` returns
	 * a percentage; we normalize it to a ratio.
	 */
	public static function sim( string $a, string $b ): float {
		$a = self::norm( $a );
		$b = self::norm( $b );
		if ( $a === '' || $b === '' ) {
			return 0.0;
		}
		if ( $a === $b ) {
			return 1.0;
		}
		$pct = 0.0;
		similar_text( $a, $b, $pct );
		return round( $pct / 100, 3 );
	}
}
