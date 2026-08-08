<?php
namespace WordPressistic\G2ABA\Tests\Unit;

/**
 * Minimal `$wpdb` double for the Phase-C analytics-provider tests.
 *
 * Behaviour is configured with substring patterns:
 *   $fake->tables      = ['wp_g2ab_bookings', ...]     // SHOW TABLES hits
 *   $fake->var_map     = ['SELECT COUNT(*)' => 3]      // get_var answers
 *   $fake->results_map = ['GROUP BY status' => [...]]  // get_results answers
 *   $fake->row_map     = ['FROM wp_x' => [...]]        // get_row answers
 *
 * prepare() inlines %s/%d/%f sequentially so pattern matching still works
 * on the final SQL. Not autoloadable on purpose (mixed-case path) — test
 * files require_once it by path, mirroring SessionsWpdbFake.
 */
class Analytics_Fake_WPDB {
	public string $prefix = 'wp_';

	/** @var array<int, string> Full table names that "exist". */
	public array $tables = array();

	/** @var array<string, mixed> substring => get_var() return. */
	public array $var_map = array();

	/** @var array<string, array> substring => get_results() return. */
	public array $results_map = array();

	/** @var array<string, array> substring => get_row() return. */
	public array $row_map = array();

	/** @var array<int, string> Every executed query, for assertions. */
	public array $queries = array();

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		foreach ( $args as $arg ) {
			$replacement = ( is_int( $arg ) || is_float( $arg ) )
				? (string) $arg
				: "'" . str_replace( array( '\\', '$' ), array( '\\\\', '\\$' ), (string) $arg ) . "'";
			$query       = preg_replace( '/%[sdf]/', $replacement, (string) $query, 1 );
		}
		return $query;
	}

	public function get_var( $query ) {
		$query           = (string) $query;
		$this->queries[] = $query;

		if ( false !== stripos( $query, 'SHOW TABLES LIKE' ) ) {
			foreach ( $this->tables as $table ) {
				if ( false !== strpos( $query, $table ) ) {
					return $table;
				}
			}
			return null;
		}

		foreach ( $this->var_map as $pattern => $value ) {
			if ( false !== stripos( $query, $pattern ) ) {
				return $value;
			}
		}
		return null;
	}

	public function get_results( $query, $output = null ) {
		$query           = (string) $query;
		$this->queries[] = $query;
		foreach ( $this->results_map as $pattern => $rows ) {
			if ( false !== stripos( $query, $pattern ) ) {
				return $rows;
			}
		}
		return array();
	}

	public function get_row( $query, $output = null ) {
		$query           = (string) $query;
		$this->queries[] = $query;
		foreach ( $this->row_map as $pattern => $row ) {
			if ( false !== stripos( $query, $pattern ) ) {
				return $row;
			}
		}
		return null;
	}
}
