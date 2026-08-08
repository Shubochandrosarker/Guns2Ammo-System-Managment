<?php
/**
 * Persistent tasks store.
 *
 * Tasks are the concrete artefacts BridGistic action approvals + AI-insight
 * approvals + Business-Gap "create task" clicks all produce. A task carries
 * enough context to be actionable without loading its source record.
 *
 * Storage shape: `{ id => task }` in the `g2aba_tasks` option, capped at
 * 500 entries with resolved/dismissed entries evicted first so open tasks
 * are never silently displaced.
 *
 * Backward compat: Phase-6 stored ad-hoc "BridGistic: <query>" tasks in
 * `g2aba_bridgistic_tasks`. `all_raw()` migrates on read.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Tasks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Tasks_Store {
	private const OPTION      = 'g2aba_tasks';
	private const LEGACY_OPT  = 'g2aba_bridgistic_tasks';
	private const MAX_ENTRIES = 500;

	public const STATUS_OPEN      = 'open';
	public const STATUS_DONE      = 'done';
	public const STATUS_DISMISSED = 'dismissed';

	public const SOURCE_AI_INSIGHT   = 'ai_insight';
	public const SOURCE_BUSINESS_GAP = 'business_gap';
	public const SOURCE_BRIDGISTIC   = 'bridgistic';
	public const SOURCE_MANUAL       = 'manual';

	/**
	 * @return array<int, array>
	 */
	public function all(): array {
		$raw = $this->raw();
		return array_values( $raw );
	}

	/**
	 * @return array<int, array>
	 */
	public function open(): array {
		return array_values(
			array_filter(
				$this->all(),
				static fn( $t ) => ( $t['status'] ?? '' ) === self::STATUS_OPEN
			)
		);
	}

	public function find( string $id ): ?array {
		$raw = $this->raw();
		return $raw[ $id ] ?? null;
	}

	public function create(
		string $title,
		string $body,
		string $source,
		string $source_id = '',
		string $owner = ''
	): array {
		$id   = 'task_' . bin2hex( random_bytes( 6 ) );
		$task = array(
			'id'         => $id,
			'title'      => $title,
			'body'       => $body,
			'source'     => self::normalize_source( $source ),
			'sourceId'   => $source_id,
			'owner'      => $owner,
			'status'     => self::STATUS_OPEN,
			'createdAt'  => gmdate( 'c' ),
			'resolvedAt' => null,
		);
		$raw        = $this->raw();
		$raw[ $id ] = $task;
		if ( count( $raw ) > self::MAX_ENTRIES ) {
			$raw = $this->trim_resolved( $raw, self::MAX_ENTRIES );
		}
		update_option( self::OPTION, $raw, false );
		return $task;
	}

	public function resolve( string $id ): ?array {
		return $this->transition( $id, self::STATUS_DONE );
	}

	public function dismiss( string $id ): ?array {
		return $this->transition( $id, self::STATUS_DISMISSED );
	}

	/**
	 * @return array<string,int> Count per source.
	 */
	public function counts_by_source(): array {
		$out = array();
		foreach ( $this->all() as $t ) {
			$s          = (string) ( $t['source'] ?? self::SOURCE_MANUAL );
			$out[ $s ]  = ( $out[ $s ] ?? 0 ) + 1;
		}
		return $out;
	}

	private function transition( string $id, string $status ): ?array {
		if ( ! in_array( $status, array( self::STATUS_DONE, self::STATUS_DISMISSED ), true ) ) {
			return null;
		}
		$raw = $this->raw();
		if ( ! isset( $raw[ $id ] ) ) {
			return null;
		}
		if ( ( $raw[ $id ]['status'] ?? '' ) !== self::STATUS_OPEN ) {
			return null; // Terminal — refuse double-resolve.
		}
		$raw[ $id ]['status']     = $status;
		$raw[ $id ]['resolvedAt'] = gmdate( 'c' );
		update_option( self::OPTION, $raw, false );
		return $raw[ $id ];
	}

	/**
	 * @return array<string, array>
	 */
	private function raw(): array {
		$raw = get_option( self::OPTION, null );
		if ( null === $raw || false === $raw ) {
			$raw = $this->maybe_migrate_legacy();
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		return $raw;
	}

	/**
	 * @return array<string, array>
	 */
	private function maybe_migrate_legacy(): array {
		$legacy = get_option( self::LEGACY_OPT, array() );
		if ( ! is_array( $legacy ) || empty( $legacy ) ) {
			update_option( self::OPTION, array(), false );
			return array();
		}
		$out = array();
		foreach ( $legacy as $row ) {
			$row  = is_array( $row ) ? $row : array();
			$id   = 'task_' . bin2hex( random_bytes( 6 ) );
			$out[ $id ] = array(
				'id'         => $id,
				'title'      => (string) ( $row['title'] ?? 'Legacy BridGistic task' ),
				'body'       => (string) ( $row['body'] ?? '' ),
				'source'     => self::SOURCE_BRIDGISTIC,
				'sourceId'   => '',
				'owner'      => '',
				'status'     => 'open' === (string) ( $row['status'] ?? 'open' ) ? self::STATUS_OPEN : self::STATUS_DONE,
				'createdAt'  => (string) ( $row['createdAt'] ?? gmdate( 'c' ) ),
				'resolvedAt' => null,
			);
		}
		update_option( self::OPTION, $out, false );
		delete_option( self::LEGACY_OPT );
		return $out;
	}

	/**
	 * @param array<string, array> $raw
	 * @return array<string, array>
	 */
	private function trim_resolved( array $raw, int $max ): array {
		$resolved_ids = array();
		foreach ( $raw as $id => $t ) {
			if ( ( $t['status'] ?? '' ) !== self::STATUS_OPEN ) {
				$resolved_ids[] = (string) $id;
			}
		}
		while ( count( $raw ) > $max && count( $resolved_ids ) > 0 ) {
			$drop_id = array_shift( $resolved_ids );
			unset( $raw[ $drop_id ] );
		}
		return $raw;
	}

	public static function normalize_source( string $source ): string {
		$source = strtolower( trim( $source ) );
		$known  = array(
			self::SOURCE_AI_INSIGHT,
			self::SOURCE_BUSINESS_GAP,
			self::SOURCE_BRIDGISTIC,
			self::SOURCE_MANUAL,
		);
		return in_array( $source, $known, true ) ? $source : self::SOURCE_MANUAL;
	}
}
