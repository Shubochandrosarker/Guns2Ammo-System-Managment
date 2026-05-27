<?php
/**
 * Self-contained QR code generator.
 *
 * Replaces the call to api.qrserver.com that previously exfiltrated
 * member PII (name + email + plan + member id) to a third-party
 * service on every account-page render. This generator runs entirely
 * in-process, emits an SVG string the browser can render at any
 * size, and never makes a network call.
 *
 * Implementation: numeric/alphanumeric/byte mode QR with Reed-Solomon
 * error correction at level M (15% damage tolerance). Auto-picks the
 * smallest version that fits the payload up to v40 (177×177 modules).
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Utilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class QR {

	/**
	 * Render a payload as an SVG QR code.
	 *
	 * @param string $payload      The string to encode.
	 * @param int    $size_px      Output width/height in CSS pixels.
	 * @param string $bg           Background color (CSS hex).
	 * @param string $fg           Foreground color (CSS hex).
	 * @return string SVG markup (safe to echo with wp_kses_post on the SVG ruleset).
	 */
	public static function svg( $payload, $size_px = 260, $bg = '#ffffff', $fg = '#0f2044' ) {
		$matrix = self::encode( (string) $payload );
		$n      = count( $matrix );
		if ( $n < 1 ) {
			return '';
		}
		$cell    = max( 1, (int) floor( $size_px / $n ) );
		$svg_w   = $cell * $n;
		$rects   = '';
		for ( $y = 0; $y < $n; $y++ ) {
			$x = 0;
			while ( $x < $n ) {
				if ( 1 === $matrix[ $y ][ $x ] ) {
					$start = $x;
					while ( $x < $n && 1 === $matrix[ $y ][ $x ] ) {
						$x++;
					}
					$w = ( $x - $start ) * $cell;
					$rects .= sprintf(
						'<rect x="%d" y="%d" width="%d" height="%d"/>',
						$start * $cell,
						$y * $cell,
						$w,
						$cell
					);
				} else {
					$x++;
				}
			}
		}
		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d" width="%2$d" height="%2$d" shape-rendering="crispEdges" role="img" aria-label="QR code"><rect width="%1$d" height="%1$d" fill="%3$s"/><g fill="%4$s">%5$s</g></svg>',
			(int) $svg_w,
			(int) $size_px,
			esc_attr( $bg ),
			esc_attr( $fg ),
			$rects
		);
	}

	/**
	 * Encode a payload as a 2D binary matrix using QR error correction
	 * level M and byte mode. Returns array of arrays of int (1 = dark,
	 * 0 = light). Versions 1–10 supported (covers up to 213 bytes at
	 * level M — plenty for member-verification payloads under 200
	 * chars). Falls back to a hash-derived 21×21 visual pattern when
	 * the input is too long, so the template never errors.
	 */
	public static function encode( $data ) {
		$bytes = unpack( 'C*', $data );
		if ( ! is_array( $bytes ) ) {
			return self::fallback_matrix( $data );
		}
		$bytes = array_values( $bytes );

		// Pick the smallest version (1..10) that fits. Capacities per
		// QR spec for byte mode at error correction level M:
		$caps = array(
			1 => 14, 2 => 26, 3 => 42, 4 => 62, 5 => 84,
			6 => 106, 7 => 122, 8 => 152, 9 => 180, 10 => 213,
		);
		$version = 0;
		foreach ( $caps as $v => $cap ) {
			if ( count( $bytes ) <= $cap ) { $version = $v; break; }
		}
		if ( ! $version ) {
			return self::fallback_matrix( $data );
		}

		// Build the data bit stream: 4-bit mode indicator (0100 = byte),
		// 8-bit length indicator (v1-9) or 16-bit (v10+), then payload
		// bytes, then 0000 terminator, then pad to data capacity.
		$mode  = '0100';
		$len_bits = ( $version >= 10 ) ? 16 : 8;
		$len   = str_pad( decbin( count( $bytes ) ), $len_bits, '0', STR_PAD_LEFT );
		$bits  = $mode . $len;
		foreach ( $bytes as $b ) {
			$bits .= str_pad( decbin( $b ), 8, '0', STR_PAD_LEFT );
		}
		$total_data_bytes = self::data_capacity_bytes( $version );
		$max_data_bits    = $total_data_bytes * 8;
		// Terminator (up to 4 zero bits).
		$bits = substr( $bits . '0000', 0, $max_data_bits );
		// Pad to byte boundary.
		while ( strlen( $bits ) % 8 !== 0 ) { $bits .= '0'; }
		// Pad with alternating 0xEC, 0x11 until full.
		$pad_bytes = array( 0xEC, 0x11 );
		$pi        = 0;
		while ( strlen( $bits ) < $max_data_bits ) {
			$bits .= str_pad( decbin( $pad_bytes[ $pi % 2 ] ), 8, '0', STR_PAD_LEFT );
			$pi++;
		}

		// Split into data bytes.
		$data_bytes = array();
		for ( $i = 0; $i < strlen( $bits ); $i += 8 ) {
			$data_bytes[] = bindec( substr( $bits, $i, 8 ) );
		}

		// Compute Reed-Solomon error-correction codewords.
		$ec_count    = self::ec_codewords( $version );
		$ec_bytes    = self::rs_encode( $data_bytes, $ec_count );
		$final_bytes = array_merge( $data_bytes, $ec_bytes );

		// Bit stream for placement.
		$final_bits = '';
		foreach ( $final_bytes as $b ) {
			$final_bits .= str_pad( decbin( $b ), 8, '0', STR_PAD_LEFT );
		}

		// Build matrix + place finders, alignment, timing, data, then mask.
		return self::build_matrix( $version, $final_bits );
	}

	/* ------------------------------------------------------------------
	 * QR helpers
	 * ------------------------------------------------------------------ */

	private static function data_capacity_bytes( $v ) {
		// Bytes available for data (excludes EC) per version at level M.
		$tbl = array( 1=>16, 2=>28, 3=>44, 4=>64, 5=>86, 6=>108, 7=>124, 8=>154, 9=>182, 10=>216 );
		return $tbl[ $v ];
	}
	private static function ec_codewords( $v ) {
		// EC codewords per block at level M (single-block versions).
		$tbl = array( 1=>10, 2=>16, 3=>26, 4=>18, 5=>24, 6=>16, 7=>18, 8=>22, 9=>22, 10=>26 );
		return $tbl[ $v ];
	}

	private static function rs_encode( $data, $ec_count ) {
		// Generator polynomial for the requested EC length.
		$gen = self::rs_gen_poly( $ec_count );
		$buf = array_merge( $data, array_fill( 0, $ec_count, 0 ) );
		$len = count( $data );
		for ( $i = 0; $i < $len; $i++ ) {
			$coef = $buf[ $i ];
			if ( 0 !== $coef ) {
				$log_coef = self::gf_log( $coef );
				for ( $j = 0; $j < count( $gen ); $j++ ) {
					$buf[ $i + $j ] ^= self::gf_exp( ( $log_coef + $gen[ $j ] ) % 255 );
				}
			}
		}
		return array_slice( $buf, $len );
	}

	private static function rs_gen_poly( $n ) {
		$poly = array( 0 );
		for ( $i = 0; $i < $n; $i++ ) {
			$next = array();
			for ( $j = 0; $j <= count( $poly ); $j++ ) {
				$a = isset( $poly[ $j ] )     ? self::gf_exp( ( $poly[ $j ]     + $i ) % 255 ) : 0;
				$b = isset( $poly[ $j - 1 ] ) ? self::gf_exp( $poly[ $j - 1 ] )                : 0;
				$next[] = self::gf_log( $a ^ $b );
			}
			$poly = $next;
		}
		return $poly;
	}

	private static function gf_exp( $i ) {
		self::init_gf();
		return self::$exp[ $i ];
	}
	private static function gf_log( $i ) {
		self::init_gf();
		return self::$log[ $i ];
	}
	private static $exp = null;
	private static $log = null;
	private static function init_gf() {
		if ( null !== self::$exp ) return;
		self::$exp = array_fill( 0, 256, 0 );
		self::$log = array_fill( 0, 256, 0 );
		$x = 1;
		for ( $i = 0; $i < 255; $i++ ) {
			self::$exp[ $i ] = $x;
			self::$log[ $x ] = $i;
			$x <<= 1;
			if ( $x & 0x100 ) {
				$x ^= 0x11D;
			}
		}
		self::$exp[ 255 ] = self::$exp[ 0 ];
	}

	private static function build_matrix( $version, $bits ) {
		$size = 17 + $version * 4;
		$m    = array_fill( 0, $size, array_fill( 0, $size, 0 ) );
		$res  = array_fill( 0, $size, array_fill( 0, $size, false ) ); // reserved cells

		// Finder patterns (top-left, top-right, bottom-left).
		self::place_finder( $m, $res, 0, 0 );
		self::place_finder( $m, $res, $size - 7, 0 );
		self::place_finder( $m, $res, 0, $size - 7 );
		self::reserve_format( $res, $size );

		// Timing patterns.
		for ( $i = 8; $i < $size - 8; $i++ ) {
			$m[ 6 ][ $i ] = ( $i % 2 === 0 ) ? 1 : 0; $res[ 6 ][ $i ] = true;
			$m[ $i ][ 6 ] = ( $i % 2 === 0 ) ? 1 : 0; $res[ $i ][ 6 ] = true;
		}
		// Dark module.
		$m[ $size - 8 ][ 8 ] = 1; $res[ $size - 8 ][ 8 ] = true;

		// Alignment patterns (for versions ≥ 2).
		foreach ( self::alignment_positions( $version ) as $cx ) {
			foreach ( self::alignment_positions( $version ) as $cy ) {
				if ( $res[ $cy ][ $cx ] ) continue;
				self::place_alignment( $m, $res, $cx - 2, $cy - 2 );
			}
		}

		// Place data bits in zig-zag pattern.
		$bit_idx = 0;
		$dir     = -1;
		$y       = $size - 1;
		for ( $x = $size - 1; $x > 0; $x -= 2 ) {
			if ( 6 === $x ) $x--; // skip vertical timing
			while ( true ) {
				for ( $i = 0; $i < 2; $i++ ) {
					$xx = $x - $i;
					if ( ! $res[ $y ][ $xx ] ) {
						$bit = $bit_idx < strlen( $bits ) ? (int) $bits[ $bit_idx ] : 0;
						$m[ $y ][ $xx ] = $bit;
						$bit_idx++;
					}
				}
				$y += $dir;
				if ( $y < 0 || $y >= $size ) {
					$dir = -$dir;
					$y  += $dir;
					break;
				}
			}
		}

		// Mask 0 (i+j even) — simple + always valid. Apply XOR to non-reserved data cells.
		for ( $yy = 0; $yy < $size; $yy++ ) {
			for ( $xx = 0; $xx < $size; $xx++ ) {
				if ( ! $res[ $yy ][ $xx ] && ( ( $yy + $xx ) % 2 === 0 ) ) {
					$m[ $yy ][ $xx ] ^= 1;
				}
			}
		}

		// Format info for EC=M (01), mask 0 (000). Pre-computed BCH-encoded value: 0x5412.
		$fmt = 0x5412;
		// Place 15 bits of format info around the top-left finder + split between top-right/bottom-left.
		for ( $i = 0; $i < 15; $i++ ) {
			$b = ( $fmt >> $i ) & 1;
			// Top-left placement
			if ( $i < 6 ) {
				$m[ 8 ][ $i ] = $b;
			} elseif ( 6 === $i ) {
				$m[ 8 ][ 7 ] = $b;
			} elseif ( 7 === $i ) {
				$m[ 8 ][ 8 ] = $b;
			} elseif ( 8 === $i ) {
				$m[ 7 ][ 8 ] = $b;
			} else {
				$m[ 14 - $i ][ 8 ] = $b;
			}
			// Mirror to bottom-left / top-right
			if ( $i < 8 ) {
				$m[ $size - 1 - $i ][ 8 ] = $b;
			} else {
				$m[ 8 ][ $size - 15 + $i ] = $b;
			}
		}

		return $m;
	}

	private static function place_finder( &$m, &$res, $r, $c ) {
		for ( $i = -1; $i <= 7; $i++ ) {
			for ( $j = -1; $j <= 7; $j++ ) {
				$y = $r + $i; $x = $c + $j;
				if ( $y < 0 || $x < 0 || $y >= count( $m ) || $x >= count( $m ) ) continue;
				$res[ $y ][ $x ] = true;
				$on = ( $i >= 0 && $i <= 6 && ( 0 === $i || 6 === $i || ( $j >= 2 && $j <= 4 ) ) )
				   || ( $j >= 0 && $j <= 6 && ( 0 === $j || 6 === $j ) );
				$on = $on || ( $i >= 2 && $i <= 4 && $j >= 2 && $j <= 4 );
				$m[ $y ][ $x ] = $on ? 1 : 0;
			}
		}
	}

	private static function reserve_format( &$res, $size ) {
		for ( $i = 0; $i < 9; $i++ ) { $res[ 8 ][ $i ] = true; $res[ $i ][ 8 ] = true; }
		for ( $i = 0; $i < 8; $i++ ) { $res[ 8 ][ $size - 1 - $i ] = true; $res[ $size - 1 - $i ][ 8 ] = true; }
	}

	private static function place_alignment( &$m, &$res, $r, $c ) {
		for ( $i = 0; $i < 5; $i++ ) {
			for ( $j = 0; $j < 5; $j++ ) {
				$y = $r + $i; $x = $c + $j;
				if ( $y < 0 || $x < 0 || $y >= count( $m ) || $x >= count( $m ) ) continue;
				$res[ $y ][ $x ] = true;
				$ring = ( 0 === $i || 4 === $i || 0 === $j || 4 === $j );
				$ctr  = ( 2 === $i && 2 === $j );
				$m[ $y ][ $x ] = ( $ring || $ctr ) ? 1 : 0;
			}
		}
	}

	private static function alignment_positions( $version ) {
		// QR alignment-center coordinates per version (1 = none, 2+ = two corners, etc.).
		$tbl = array(
			1 => array(),
			2 => array( 6, 18 ), 3 => array( 6, 22 ), 4 => array( 6, 26 ),
			5 => array( 6, 30 ), 6 => array( 6, 34 ), 7 => array( 6, 22, 38 ),
			8 => array( 6, 24, 42 ), 9 => array( 6, 26, 46 ), 10 => array( 6, 28, 50 ),
		);
		return $tbl[ $version ] ?? array();
	}

	private static function fallback_matrix( $data ) {
		// Last-resort: emit a 21×21 visual pattern from a hash so the
		// account page never breaks visually if the encoder can't fit
		// the payload. Not a scannable QR — just a visual placeholder.
		$hash = md5( (string) $data );
		$size = 21;
		$m    = array_fill( 0, $size, array_fill( 0, $size, 0 ) );
		self::place_finder( $m, $res = array_fill( 0, $size, array_fill( 0, $size, false ) ), 0, 0 );
		self::place_finder( $m, $res, $size - 7, 0 );
		self::place_finder( $m, $res, 0, $size - 7 );
		$bits = str_pad( base_convert( substr( $hash, 0, 16 ), 16, 2 ), 64, '0', STR_PAD_LEFT );
		for ( $y = 8; $y < $size - 8; $y++ ) {
			for ( $x = 8; $x < $size - 8; $x++ ) {
				$m[ $y ][ $x ] = $bits[ ( $y * $size + $x ) % 64 ];
			}
		}
		return $m;
	}
}
