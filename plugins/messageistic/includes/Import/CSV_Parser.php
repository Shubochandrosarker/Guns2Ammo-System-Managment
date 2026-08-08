<?php
/**
 * Stateful CSV reader with header detection and chunked reads.
 *
 * @package Messageistic\Import
 */

namespace Messageistic\Import;

defined( 'ABSPATH' ) || exit;

final class CSV_Parser {

    /** @var resource|null */
    private $handle;

    /** @var array<int,string> */
    private array $headers = [];

    private string $delimiter = ',';

    public function __construct( private string $file_path ) {
        $this->handle = fopen( $file_path, 'r' );
        if ( ! $this->handle ) {
            throw new \RuntimeException( 'CSV file could not be opened.' );
        }
        $this->detect_delimiter();
        $first = $this->read_row();
        if ( null === $first ) {
            $this->headers = [];
            return;
        }
        $this->headers = array_map( static fn ( $h ) => trim( strtolower( (string) $h ) ), $first );
    }

    public function __destruct() {
        if ( is_resource( $this->handle ) ) {
            fclose( $this->handle );
        }
    }

    /** @return array<int,string> */
    public function headers(): array {
        return $this->headers;
    }

    public function tell(): int {
        return is_resource( $this->handle ) ? (int) ftell( $this->handle ) : 0;
    }

    public function seek( int $offset ): void {
        if ( is_resource( $this->handle ) ) {
            fseek( $this->handle, $offset );
        }
    }

    /**
     * Read up to $limit rows, each row keyed by header.
     *
     * @return array<int,array<string,string>>
     */
    public function read( int $limit ): array {
        $out = [];
        if ( ! is_resource( $this->handle ) ) {
            return $out;
        }
        while ( count( $out ) < $limit ) {
            $row = $this->read_row();
            if ( null === $row ) {
                break;
            }
            if ( count( array_filter( $row, static fn ( $v ) => '' !== trim( (string) $v ) ) ) === 0 ) {
                continue; // blank line
            }
            $assoc = [];
            foreach ( $this->headers as $i => $header ) {
                $assoc[ $header ] = isset( $row[ $i ] ) ? (string) $row[ $i ] : '';
            }
            $out[] = $assoc;
        }
        return $out;
    }

    /** @return array<int,string>|null */
    private function read_row(): ?array {
        $row = fgetcsv( $this->handle, 0, $this->delimiter );
        return is_array( $row ) ? $row : null;
    }

    private function detect_delimiter(): void {
        $sample = (string) fread( $this->handle, 4096 );
        rewind( $this->handle );
        $counts = [
            ',' => substr_count( $sample, ',' ),
            ';' => substr_count( $sample, ';' ),
            "\t" => substr_count( $sample, "\t" ),
            '|' => substr_count( $sample, '|' ),
        ];
        arsort( $counts );
        $this->delimiter = (string) array_key_first( $counts );
    }
}
