<?php

namespace G2A\POS\Inventory;

/**
 * Streaming CSV reader that yields associative arrays keyed by the header row.
 * Auto-detects delimiter (comma / tab / pipe / semicolon).
 */
final class CsvReader {

	public static function rows( string $path_or_csv, bool $is_path = true ): \Generator {
		$fh = $is_path ? fopen( $path_or_csv, 'r' ) : self::stream_from_string( $path_or_csv );
		if ( ! $fh ) {
			return;
		}
		try {
			$first = fgets( $fh );
			if ( $first === false ) {
				return;
			}
			$first = self::strip_bom( $first );
			$delim = self::detect_delim( $first );
			rewind( $fh );
			if ( $is_path ) {
				$headers = fgetcsv( $fh, 0, $delim, '"', '\\' );
			} else {
				$headers = str_getcsv( rtrim( (string) fgets( $fh ), "\r\n" ), $delim, '"', '\\' );
			}
			if ( ! $headers ) {
				return;
			}
			$headers = array_map( static fn( $h ) => self::strip_bom( trim( (string) $h ) ), $headers );
			$i       = 0;
			while ( ( $row = fgetcsv( $fh, 0, $delim, '"', '\\' ) ) !== false ) {
				if ( $row === array( null ) || ( count( $row ) === 1 && $row[0] === null ) ) {
					continue;
				}
				$assoc = array();
				foreach ( $headers as $k => $name ) {
					$assoc[ $name ] = $row[ $k ] ?? null;
				}
				yield $i++ => $assoc;
			}
		} finally {
			fclose( $fh );
		}
	}

	/** Read just the headers (lower-cased, trimmed). */
	public static function peek_headers( string $path_or_csv, bool $is_path = true ): array {
		$fh = $is_path ? fopen( $path_or_csv, 'r' ) : self::stream_from_string( $path_or_csv );
		if ( ! $fh ) {
			return array();
		}
		try {
			$line = fgets( $fh );
			if ( $line === false ) {
				return array();
			}
			$line  = self::strip_bom( rtrim( $line, "\r\n" ) );
			$delim = self::detect_delim( $line );
			return array_map( static fn( $s ) => trim( (string) $s ), str_getcsv( $line, $delim, '"', '\\' ) );
		} finally {
			fclose( $fh );
		}
	}

	private static function detect_delim( string $line ): string {
		$counts = array(
			','  => substr_count( $line, ',' ),
			"\t" => substr_count( $line, "\t" ),
			'|'  => substr_count( $line, '|' ),
			';'  => substr_count( $line, ';' ),
		);
		arsort( $counts );
		return (string) array_key_first( $counts );
	}

	private static function strip_bom( string $s ): string {
		return preg_replace( '/^\xEF\xBB\xBF/', '', $s ) ?? $s;
	}

	/** @return resource|false */
	private static function stream_from_string( string $csv ) {
		$fh = fopen( 'php://temp', 'r+' );
		if ( ! $fh ) {
			return false;
		}
		fwrite( $fh, $csv );
		rewind( $fh );
		return $fh;
	}
}
