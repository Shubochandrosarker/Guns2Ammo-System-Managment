<?php
/**
 * Minimal, self-contained QR encoder and PNG writer.
 *
 * Byte mode, error-correction level M, versions 1-10 — comfortably more
 * than a referral URL needs. Written in-plugin rather than pulled from a
 * QR web service on purpose: a member's referral link is customer data and
 * has no business being sent to a third party to be drawn, and this site's
 * outbound calls are proxied anyway.
 *
 * PNG is emitted directly (zlib + CRC32) rather than through GD, so the
 * download works on a host without the GD extension.
 *
 * @package G2AR
 */

namespace WordPressistic\G2AReferrals;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class QR {

	/**
	 * Data codewords per version at EC level M.
	 */
	private const DATA_CODEWORDS = array( 1 => 16, 2 => 28, 3 => 44, 4 => 64, 5 => 86, 6 => 108, 7 => 124, 8 => 154, 9 => 182, 10 => 216 );

	/**
	 * EC codewords per block, per version, at level M.
	 */
	private const EC_PER_BLOCK = array( 1 => 10, 2 => 16, 3 => 26, 4 => 18, 5 => 24, 6 => 16, 7 => 18, 8 => 22, 9 => 22, 10 => 26 );

	/**
	 * Block layout per version at level M: [ [count, data codewords], ... ].
	 */
	private const BLOCKS = array(
		1  => array( array( 1, 16 ) ),
		2  => array( array( 1, 28 ) ),
		3  => array( array( 1, 44 ) ),
		4  => array( array( 2, 32 ) ),
		5  => array( array( 2, 43 ) ),
		6  => array( array( 4, 27 ) ),
		7  => array( array( 4, 31 ) ),
		8  => array( array( 2, 38 ), array( 2, 39 ) ),
		9  => array( array( 3, 36 ), array( 2, 37 ) ),
		10 => array( array( 4, 43 ), array( 1, 44 ) ),
	);

	/**
	 * Alignment pattern centre coordinates per version.
	 */
	private const ALIGNMENT = array(
		1  => array(),
		2  => array( 6, 18 ),
		3  => array( 6, 22 ),
		4  => array( 6, 26 ),
		5  => array( 6, 30 ),
		6  => array( 6, 34 ),
		7  => array( 6, 22, 38 ),
		8  => array( 6, 24, 42 ),
		9  => array( 6, 26, 46 ),
		10 => array( 6, 28, 50 ),
	);

	/**
	 * GF(256) exponent table.
	 *
	 * @var int[]
	 */
	private static $exp = array();

	/**
	 * GF(256) log table.
	 *
	 * @var int[]
	 */
	private static $log = array();

	/**
	 * Encode a string and return a base64 PNG data URI.
	 *
	 * @param string $text  Payload.
	 * @param int    $scale Pixels per module.
	 * @param int    $quiet Quiet-zone width in modules.
	 * @return string Data URI, or '' when the payload will not fit.
	 */
	public static function png_data_uri( $text, $scale = 8, $quiet = 4 ) {
		$matrix = self::matrix( (string) $text );

		if ( ! $matrix ) {
			return '';
		}

		$png = self::png( $matrix, max( 1, (int) $scale ), max( 0, (int) $quiet ) );

		return $png ? 'data:image/png;base64,' . base64_encode( $png ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- building a data URI, not obfuscating.
	}

	/**
	 * Build the module matrix for a payload.
	 *
	 * @param string $text Payload.
	 * @return array[]|null Matrix of 0/1 ints, or null when too long.
	 */
	public static function matrix( $text ) {
		$bytes  = array_values( unpack( 'C*', $text ) );
		$length = count( $bytes );

		$version = 0;
		foreach ( self::DATA_CODEWORDS as $candidate => $capacity ) {
			// 4 bits mode + 8 bits length (versions 1-9) + payload.
			$needed = 4 + ( $candidate < 10 ? 8 : 16 ) + ( $length * 8 );
			if ( $needed <= $capacity * 8 ) {
				$version = $candidate;
				break;
			}
		}

		if ( ! $version ) {
			return null;
		}

		$codewords = self::encode_data( $bytes, $version );
		$final     = self::interleave( $codewords, $version );
		$size      = 17 + ( $version * 4 );

		$modules  = array_fill( 0, $size, array_fill( 0, $size, 0 ) );
		$reserved = array_fill( 0, $size, array_fill( 0, $size, false ) );

		self::place_function_patterns( $modules, $reserved, $version, $size );
		self::place_data( $modules, $reserved, $final, $size );

		// Try every mask, keep the least visually penalised.
		$best      = null;
		$best_cost = PHP_INT_MAX;

		for ( $mask = 0; $mask < 8; $mask++ ) {
			$candidate = self::apply_mask( $modules, $reserved, $mask, $size );
			self::place_format( $candidate, $mask, $size );

			$cost = self::penalty( $candidate, $size );

			if ( $cost < $best_cost ) {
				$best_cost = $cost;
				$best      = $candidate;
			}
		}

		return $best;
	}

	/**
	 * Mode + length + payload + padding, as data codewords.
	 *
	 * @param int[] $bytes   Payload bytes.
	 * @param int   $version QR version.
	 * @return int[]
	 */
	private static function encode_data( array $bytes, $version ) {
		$capacity  = self::DATA_CODEWORDS[ $version ];
		$len_bits  = $version < 10 ? 8 : 16;
		$bitstring = '0100' . str_pad( decbin( count( $bytes ) ), $len_bits, '0', STR_PAD_LEFT );

		foreach ( $bytes as $byte ) {
			$bitstring .= str_pad( decbin( $byte ), 8, '0', STR_PAD_LEFT );
		}

		// Terminator, up to four zero bits.
		$remaining  = ( $capacity * 8 ) - strlen( $bitstring );
		$bitstring .= str_repeat( '0', min( 4, max( 0, $remaining ) ) );

		// Pad to a byte boundary.
		if ( strlen( $bitstring ) % 8 ) {
			$bitstring .= str_repeat( '0', 8 - ( strlen( $bitstring ) % 8 ) );
		}

		$codewords = array();
		foreach ( str_split( $bitstring, 8 ) as $chunk ) {
			$codewords[] = bindec( $chunk );
		}

		// Alternating pad codewords, per the spec.
		$pad = array( 0xEC, 0x11 );
		$i   = 0;
		while ( count( $codewords ) < $capacity ) {
			$codewords[] = $pad[ $i % 2 ];
			++$i;
		}

		return $codewords;
	}

	/**
	 * Split into blocks, add Reed-Solomon ECC, interleave.
	 *
	 * @param int[] $codewords Data codewords.
	 * @param int   $version   QR version.
	 * @return int[]
	 */
	private static function interleave( array $codewords, $version ) {
		self::init_galois();

		$ec_len = self::EC_PER_BLOCK[ $version ];
		$blocks = array();
		$offset = 0;

		foreach ( self::BLOCKS[ $version ] as $group ) {
			list( $count, $data_len ) = $group;

			for ( $b = 0; $b < $count; $b++ ) {
				$data     = array_slice( $codewords, $offset, $data_len );
				$offset  += $data_len;
				$blocks[] = array(
					'data' => $data,
					'ec'   => self::reed_solomon( $data, $ec_len ),
				);
			}
		}

		$out      = array();
		$max_data = 0;

		foreach ( $blocks as $block ) {
			$max_data = max( $max_data, count( $block['data'] ) );
		}

		for ( $i = 0; $i < $max_data; $i++ ) {
			foreach ( $blocks as $block ) {
				if ( isset( $block['data'][ $i ] ) ) {
					$out[] = $block['data'][ $i ];
				}
			}
		}

		for ( $i = 0; $i < $ec_len; $i++ ) {
			foreach ( $blocks as $block ) {
				if ( isset( $block['ec'][ $i ] ) ) {
					$out[] = $block['ec'][ $i ];
				}
			}
		}

		return $out;
	}

	/**
	 * Build the GF(256) log/exp tables once.
	 *
	 * @return void
	 */
	private static function init_galois() {
		if ( self::$exp ) {
			return;
		}

		$x = 1;

		for ( $i = 0; $i < 256; $i++ ) {
			self::$exp[ $i ] = $x;
			self::$log[ $x ] = $i;
			$x <<= 1;
			if ( $x & 0x100 ) {
				$x ^= 0x11D; // QR's primitive polynomial.
			}
		}

		for ( $i = 256; $i < 512; $i++ ) {
			self::$exp[ $i ] = self::$exp[ $i - 255 ];
		}
	}

	/**
	 * Reed-Solomon error-correction codewords.
	 *
	 * @param int[] $data   Data codewords.
	 * @param int   $ec_len Number of EC codewords.
	 * @return int[]
	 */
	private static function reed_solomon( array $data, $ec_len ) {
		// Generator polynomial for $ec_len.
		$generator = array( 1 );

		for ( $i = 0; $i < $ec_len; $i++ ) {
			$next = array_fill( 0, count( $generator ) + 1, 0 );

			foreach ( $generator as $index => $coefficient ) {
				$next[ $index ]     ^= $coefficient;
				$next[ $index + 1 ] ^= ( 0 === $coefficient ) ? 0 : self::$exp[ ( self::$log[ $coefficient ] + $i ) % 255 ];
			}

			$generator = $next;
		}

		$remainder = array_merge( $data, array_fill( 0, $ec_len, 0 ) );

		for ( $i = 0; $i < count( $data ); $i++ ) {
			$factor = $remainder[ $i ];

			if ( 0 === $factor ) {
				continue;
			}

			$log_factor = self::$log[ $factor ];

			foreach ( $generator as $index => $coefficient ) {
				if ( 0 === $coefficient ) {
					continue;
				}
				$remainder[ $i + $index ] ^= self::$exp[ ( self::$log[ $coefficient ] + $log_factor ) % 255 ];
			}
		}

		return array_slice( $remainder, count( $data ), $ec_len );
	}

	/**
	 * Finders, separators, timing, alignment, dark module and the reserved
	 * format/version areas.
	 *
	 * @param array[] $modules  Module matrix, by reference.
	 * @param array[] $reserved Reserved map, by reference.
	 * @param int     $version  QR version.
	 * @param int     $size     Matrix size.
	 * @return void
	 */
	private static function place_function_patterns( array &$modules, array &$reserved, $version, $size ) {
		// Finder patterns + separators.
		foreach ( array( array( 0, 0 ), array( $size - 7, 0 ), array( 0, $size - 7 ) ) as $origin ) {
			list( $ox, $oy ) = $origin;

			for ( $y = -1; $y <= 7; $y++ ) {
				for ( $x = -1; $x <= 7; $x++ ) {
					$px = $ox + $x;
					$py = $oy + $y;

					if ( $px < 0 || $py < 0 || $px >= $size || $py >= $size ) {
						continue;
					}

					$on = ( $x >= 0 && $x <= 6 && ( 0 === $y || 6 === $y ) )
						|| ( $y >= 0 && $y <= 6 && ( 0 === $x || 6 === $x ) )
						|| ( $x >= 2 && $x <= 4 && $y >= 2 && $y <= 4 );

					$modules[ $py ][ $px ]  = $on ? 1 : 0;
					$reserved[ $py ][ $px ] = true;
				}
			}
		}

		// Timing patterns.
		for ( $i = 8; $i < $size - 8; $i++ ) {
			$bit = ( 0 === $i % 2 ) ? 1 : 0;

			$modules[ 6 ][ $i ]  = $bit;
			$reserved[ 6 ][ $i ] = true;
			$modules[ $i ][ 6 ]  = $bit;
			$reserved[ $i ][ 6 ] = true;
		}

		// Alignment patterns, skipping the three finder corners.
		$centres = self::ALIGNMENT[ $version ];

		foreach ( $centres as $cy ) {
			foreach ( $centres as $cx ) {
				$in_finder = ( $cx <= 8 && $cy <= 8 )
					|| ( $cx >= $size - 9 && $cy <= 8 )
					|| ( $cx <= 8 && $cy >= $size - 9 );

				if ( $in_finder ) {
					continue;
				}

				for ( $y = -2; $y <= 2; $y++ ) {
					for ( $x = -2; $x <= 2; $x++ ) {
						$on = ( 2 === max( abs( $x ), abs( $y ) ) ) || ( 0 === $x && 0 === $y );

						$modules[ $cy + $y ][ $cx + $x ]  = $on ? 1 : 0;
						$reserved[ $cy + $y ][ $cx + $x ] = true;
					}
				}
			}
		}

		// Dark module.
		$modules[ ( 4 * $version ) + 9 ][ 8 ]  = 1;
		$reserved[ ( 4 * $version ) + 9 ][ 8 ] = true;

		// Reserve the format-information areas.
		for ( $i = 0; $i < 9; $i++ ) {
			if ( 6 !== $i ) {
				$reserved[ 8 ][ $i ] = true;
				$reserved[ $i ][ 8 ] = true;
			}
		}

		for ( $i = 0; $i < 8; $i++ ) {
			$reserved[ 8 ][ $size - 1 - $i ] = true;
			$reserved[ $size - 1 - $i ][ 8 ] = true;
		}

		// Version information, versions 7 and up.
		if ( $version >= 7 ) {
			$bits = self::version_bits( $version );

			for ( $i = 0; $i < 18; $i++ ) {
				$bit = ( $bits >> $i ) & 1;
				$row = intdiv( $i, 3 );
				$col = $i % 3;

				$modules[ $row ][ $size - 11 + $col ]  = $bit;
				$reserved[ $row ][ $size - 11 + $col ] = true;
				$modules[ $size - 11 + $col ][ $row ]  = $bit;
				$reserved[ $size - 11 + $col ][ $row ] = true;
			}
		}
	}

	/**
	 * Zigzag data placement.
	 *
	 * @param array[] $modules   Module matrix, by reference.
	 * @param array[] $reserved  Reserved map.
	 * @param int[]   $codewords Final codewords.
	 * @param int     $size      Matrix size.
	 * @return void
	 */
	private static function place_data( array &$modules, array $reserved, array $codewords, $size ) {
		$bits = '';

		foreach ( $codewords as $codeword ) {
			$bits .= str_pad( decbin( $codeword ), 8, '0', STR_PAD_LEFT );
		}

		$index = 0;
		$up    = true;

		for ( $col = $size - 1; $col > 0; $col -= 2 ) {
			// Skip the vertical timing column.
			if ( 6 === $col ) {
				--$col;
			}

			for ( $step = 0; $step < $size; $step++ ) {
				$row = $up ? ( $size - 1 - $step ) : $step;

				for ( $offset = 0; $offset < 2; $offset++ ) {
					$c = $col - $offset;

					if ( ! empty( $reserved[ $row ][ $c ] ) ) {
						continue;
					}

					$modules[ $row ][ $c ] = isset( $bits[ $index ] ) ? (int) $bits[ $index ] : 0;
					++$index;
				}
			}

			$up = ! $up;
		}
	}

	/**
	 * Apply one of the eight masks to the data modules.
	 *
	 * @param array[] $modules  Module matrix.
	 * @param array[] $reserved Reserved map.
	 * @param int     $mask     Mask index 0-7.
	 * @param int     $size     Matrix size.
	 * @return array[]
	 */
	private static function apply_mask( array $modules, array $reserved, $mask, $size ) {
		for ( $y = 0; $y < $size; $y++ ) {
			for ( $x = 0; $x < $size; $x++ ) {
				if ( ! empty( $reserved[ $y ][ $x ] ) ) {
					continue;
				}

				switch ( $mask ) {
					case 0:
						$flip = 0 === ( ( $y + $x ) % 2 );
						break;
					case 1:
						$flip = 0 === ( $y % 2 );
						break;
					case 2:
						$flip = 0 === ( $x % 3 );
						break;
					case 3:
						$flip = 0 === ( ( $y + $x ) % 3 );
						break;
					case 4:
						$flip = 0 === ( ( intdiv( $y, 2 ) + intdiv( $x, 3 ) ) % 2 );
						break;
					case 5:
						$flip = 0 === ( ( ( $y * $x ) % 2 ) + ( ( $y * $x ) % 3 ) );
						break;
					case 6:
						$flip = 0 === ( ( ( ( $y * $x ) % 2 ) + ( ( $y * $x ) % 3 ) ) % 2 );
						break;
					default:
						$flip = 0 === ( ( ( ( $y + $x ) % 2 ) + ( ( $y * $x ) % 3 ) ) % 2 );
						break;
				}

				if ( $flip ) {
					$modules[ $y ][ $x ] ^= 1;
				}
			}
		}

		return $modules;
	}

	/**
	 * Write the 15-bit format information for EC level M and a mask.
	 *
	 * @param array[] $modules Module matrix, by reference.
	 * @param int     $mask    Mask index.
	 * @param int     $size    Matrix size.
	 * @return void
	 */
	private static function place_format( array &$modules, $mask, $size ) {
		// EC level M is 0b00; the five data bits are 00 followed by the mask.
		$data = ( 0x00 << 3 ) | $mask;
		$bch  = $data << 10;

		for ( $i = 4; $i >= 0; $i-- ) {
			if ( $bch & ( 1 << ( $i + 10 ) ) ) {
				$bch ^= 0x537 << $i;
			}
		}

		$bits = ( ( $data << 10 ) | $bch ) ^ 0x5412;

		for ( $i = 0; $i < 15; $i++ ) {
			$bit = ( $bits >> $i ) & 1;

			// Around the top-left finder.
			if ( $i < 6 ) {
				$modules[ $i ][ 8 ] = $bit;
			} elseif ( 6 === $i ) {
				$modules[7][8] = $bit;
			} elseif ( 7 === $i ) {
				$modules[8][8] = $bit;
			} elseif ( 8 === $i ) {
				$modules[8][7] = $bit;
			} else {
				$modules[8][ 14 - $i ] = $bit;
			}

			// Mirrored copy along the other two finders.
			if ( $i < 8 ) {
				$modules[8][ $size - 1 - $i ] = $bit;
			} else {
				$modules[ $size - 15 + $i ][8] = $bit;
			}
		}

		// The dark module is always set.
		$modules[ $size - 8 ][8] = 1;
	}

	/**
	 * The 18-bit version information word for versions 7-40.
	 *
	 * @param int $version QR version.
	 * @return int
	 */
	private static function version_bits( $version ) {
		$bch = $version << 12;

		for ( $i = 5; $i >= 0; $i-- ) {
			if ( $bch & ( 1 << ( $i + 12 ) ) ) {
				$bch ^= 0x1F25 << $i;
			}
		}

		return ( $version << 12 ) | ( $bch & 0xFFF );
	}

	/**
	 * The four standard mask penalty rules. Lower is better.
	 *
	 * @param array[] $modules Module matrix.
	 * @param int     $size    Matrix size.
	 * @return int
	 */
	private static function penalty( array $modules, $size ) {
		$score = 0;

		// Rule 1: runs of five or more same-coloured modules.
		for ( $pass = 0; $pass < 2; $pass++ ) {
			for ( $a = 0; $a < $size; $a++ ) {
				$run  = 1;
				$last = null;

				for ( $b = 0; $b < $size; $b++ ) {
					$value = $pass ? $modules[ $b ][ $a ] : $modules[ $a ][ $b ];

					if ( $value === $last ) {
						++$run;
					} else {
						if ( $run >= 5 ) {
							$score += 3 + ( $run - 5 );
						}
						$run  = 1;
						$last = $value;
					}
				}

				if ( $run >= 5 ) {
					$score += 3 + ( $run - 5 );
				}
			}
		}

		// Rule 2: 2x2 blocks of one colour.
		for ( $y = 0; $y < $size - 1; $y++ ) {
			for ( $x = 0; $x < $size - 1; $x++ ) {
				$v = $modules[ $y ][ $x ];

				if ( $v === $modules[ $y ][ $x + 1 ]
					&& $v === $modules[ $y + 1 ][ $x ]
					&& $v === $modules[ $y + 1 ][ $x + 1 ] ) {
					$score += 3;
				}
			}
		}

		// Rule 3: finder-like 1:1:3:1:1 patterns.
		$needles = array(
			array( 1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0 ),
			array( 0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1 ),
		);

		for ( $y = 0; $y < $size; $y++ ) {
			for ( $x = 0; $x < $size; $x++ ) {
				foreach ( $needles as $needle ) {
					if ( $x + 11 <= $size ) {
						$match = true;
						for ( $i = 0; $i < 11; $i++ ) {
							if ( $modules[ $y ][ $x + $i ] !== $needle[ $i ] ) {
								$match = false;
								break;
							}
						}
						if ( $match ) {
							$score += 40;
						}
					}

					if ( $y + 11 <= $size ) {
						$match = true;
						for ( $i = 0; $i < 11; $i++ ) {
							if ( $modules[ $y + $i ][ $x ] !== $needle[ $i ] ) {
								$match = false;
								break;
							}
						}
						if ( $match ) {
							$score += 40;
						}
					}
				}
			}
		}

		// Rule 4: deviation from a 50/50 light/dark balance.
		$dark = 0;

		foreach ( $modules as $row ) {
			$dark += array_sum( $row );
		}

		$percent = ( $dark * 100 ) / ( $size * $size );
		$score  += ( (int) ( abs( $percent - 50 ) / 5 ) ) * 10;

		return $score;
	}

	/**
	 * Write an 8-bit greyscale PNG by hand.
	 *
	 * @param array[] $modules Module matrix.
	 * @param int     $scale   Pixels per module.
	 * @param int     $quiet   Quiet-zone modules.
	 * @return string Raw PNG bytes, or '' when zlib is unavailable.
	 */
	private static function png( array $modules, $scale, $quiet ) {
		if ( ! function_exists( 'gzcompress' ) ) {
			return '';
		}

		$size  = count( $modules );
		$total = ( $size + ( $quiet * 2 ) ) * $scale;

		$raw = '';

		for ( $py = 0; $py < $total; $py++ ) {
			$raw .= chr( 0 ); // Filter type 0 (None).

			$my  = intdiv( $py, $scale ) - $quiet;
			$row = '';

			for ( $px = 0; $px < $total; $px++ ) {
				$mx = intdiv( $px, $scale ) - $quiet;

				$dark = ( $my >= 0 && $my < $size && $mx >= 0 && $mx < $size )
					? (int) $modules[ $my ][ $mx ]
					: 0;

				$row .= chr( $dark ? 0x00 : 0xFF );
			}

			$raw .= $row;
		}

		$ihdr = pack( 'NN', $total, $total ) . chr( 8 ) . chr( 0 ) . chr( 0 ) . chr( 0 ) . chr( 0 );

		return "\x89PNG\r\n\x1a\n"
			. self::png_chunk( 'IHDR', $ihdr )
			. self::png_chunk( 'IDAT', gzcompress( $raw, 9 ) )
			. self::png_chunk( 'IEND', '' );
	}

	/**
	 * One length-prefixed, CRC-suffixed PNG chunk.
	 *
	 * @param string $type Four-character chunk type.
	 * @param string $data Chunk payload.
	 * @return string
	 */
	private static function png_chunk( $type, $data ) {
		return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
	}
}
