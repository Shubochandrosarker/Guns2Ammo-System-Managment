<?php

namespace WpisticFFL\Tests\Unit\Support;

/**
 * A minimal $wpdb stand-in for exercising business logic that reads/writes
 * through $wpdb without a real database connection. Not a SQL parser --
 * `when()` matches a query by a static substring drawn from the source
 * (e.g. a distinctive WHERE clause fragment), so it's stable across the
 * dynamic values `prepare()` would normally interpolate. `get_var()`/
 * `get_row()`/`get_results()` all resolve through the same matcher since
 * the calling code already knows which shape it asked for.
 */
final class FakeWpdb {

	public string $prefix = 'wp_';

	/** @var array<int, array{0:string,1:mixed}> */
	private array $expectations = [];

	/** @var array<int, array{table:string,data:array}> */
	public array $inserts = [];

	/** @var array<int, array{table:string,data:array,where:array}> */
	public array $updates = [];

	/** @var array<int, array{sql:string,args:array}> Raw SQL (+ prepare() args, if any) passed to query(). */
	public array $queries = [];

	/**
	 * Register a canned response for the next query whose raw SQL (before
	 * interpolation) contains $needle. Consumed in registration order on
	 * first match, so register once per expected call.
	 */
	public function when( string $needle, $value ): void {
		$this->expectations[] = [ $needle, $value ];
	}

	/**
	 * Mimics wpdb::prepare() just enough to carry the raw query text
	 * through to get_var()/get_row()/get_results() -- args are accepted
	 * but not interpolated, since matching happens on the static SQL.
	 */
	public function prepare( string $query, ...$args ) {
		return (object) [ 'query' => $query, 'args' => $args ];
	}

	private function resolve( $query ) {
		$text = is_object( $query ) ? $query->query : (string) $query;
		foreach ( $this->expectations as $i => [ $needle, $value ] ) {
			if ( false !== strpos( $text, $needle ) ) {
				unset( $this->expectations[ $i ] );
				return $value;
			}
		}
		throw new \RuntimeException( "FakeWpdb: no matching expectation registered for query:\n" . $text );
	}

	public function get_var( $query ) {
		return $this->resolve( $query );
	}

	public function get_row( $query ) {
		return $this->resolve( $query );
	}

	public function get_results( $query ) {
		return $this->resolve( $query );
	}

	public function insert( string $table, array $data ): int {
		$this->inserts[] = [ 'table' => $table, 'data' => $data ];
		return 1;
	}

	public function update( string $table, array $data, array $where ): int {
		$this->updates[] = [ 'table' => $table, 'data' => $data, 'where' => $where ];
		return 1;
	}

	/** Records a raw query() call (e.g. INSERT ... ON DUPLICATE KEY UPDATE) -- does not resolve against when(). */
	public function query( $sql ) {
		$this->queries[] = is_object( $sql )
			? [ 'sql' => $sql->query, 'args' => $sql->args ]
			: [ 'sql' => (string) $sql, 'args' => [] ];
		return 1;
	}
}
