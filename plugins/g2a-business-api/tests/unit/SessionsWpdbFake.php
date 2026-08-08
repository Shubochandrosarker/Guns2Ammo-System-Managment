<?php
namespace WordPressistic\G2ABA\Tests\Unit;

/**
 * Minimal `$wpdb` double for the Session_Store tests (and the controllers
 * that ride on it). Records every insert/update/query so tests can assert
 * exactly what would hit the database; `get_row` answers with a canned row.
 * Also answers the Rate_Limiter's GET_LOCK/RELEASE_LOCK probes so the
 * login controller can be exercised with this same fake installed.
 *
 * Not autoloadable on purpose (mixed-case path) — test files require_once
 * it by path, mirroring how RateLimiterTest keeps its fake file-local.
 */
class Sessions_Fake_WPDB {
	public string $prefix    = 'wp_';
	public int    $insert_id = 0;

	/** @var array<int, array{table:string, data:array}> */
	public array $inserted = array();

	/** @var array<int, array{table:string, data:array, where:array}> */
	public array $updates = array();

	/** @var array<int, string> Raw SQL passed to query()/get_row(). */
	public array $queries = array();

	/** Row returned by get_row(), or null. */
	public ?array $row = null;

	/** Return value for query() (affected-row count). */
	public int $query_return = 1;

	public function insert( string $table, array $data ): int {
		$this->insert_id  = count( $this->inserted ) + 1;
		$this->inserted[] = array( 'table' => $table, 'data' => $data );
		return 1;
	}

	public function update( string $table, array $data, array $where ): int {
		$this->updates[] = array( 'table' => $table, 'data' => $data, 'where' => $where );
		return 1;
	}

	public function get_row( string $query, $output = null ) {
		$this->queries[] = $query;
		return $this->row;
	}

	public function query( string $query ): int {
		$this->queries[] = $query;
		return $this->query_return;
	}

	public function get_var( string $query ) {
		// Keep the Rate_Limiter's advisory lock happy.
		if ( false !== strpos( $query, 'GET_LOCK' ) || false !== strpos( $query, 'RELEASE_LOCK' ) ) {
			return 1;
		}
		return null;
	}

	public function get_charset_collate(): string {
		return '';
	}

	public function prepare( string $query, ...$args ): string {
		if ( count( $args ) === 1 && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		foreach ( $args as $arg ) {
			$pos = strpos( $query, is_int( $arg ) ? '%d' : '%s' );
			if ( false !== $pos ) {
				$query = substr_replace( $query, (string) $arg, $pos, 2 );
			}
		}
		return $query;
	}
}
