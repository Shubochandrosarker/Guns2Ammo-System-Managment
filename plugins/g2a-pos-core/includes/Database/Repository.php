<?php

namespace G2A\POS\Database;

class Repository {

	protected function table( string $table ): string {
		global $wpdb;
		return $wpdb->prefix . $table;
	}

	protected function now(): string {
		return current_time( 'mysql' );
	}
}
