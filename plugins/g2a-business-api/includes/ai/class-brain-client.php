<?php
/**
 * In-process client onto g2a-pos-core's shared AI Brain (RAG store).
 *
 * g2a-pos-core and g2a-business-api run as separate plugins on the same
 * WordPress install. Rather than duplicating the chunk/embedding store (or
 * calling out over HTTP), this class talks straight to g2a-pos-core's
 * `\G2A\POS\Ai\BrainFacade` in-process — the same pattern
 * `Email_Overview_Provider` already uses for Messageistic's Dashboard_API.
 *
 * Every method is guarded with `class_exists()` and a try/catch so a
 * deactivated (or absent) g2a-pos-core plugin degrades the dashboard's
 * future agents (Email Manager, Sales Agent, Store Manager, Booking Agent)
 * to an inactive brain instead of a fatal error — callers can always trust
 * the returned array shape.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Ai;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brain_Client {
	public function is_available(): bool {
		return class_exists( '\G2A\POS\Ai\BrainFacade' );
	}

	/**
	 * @return array{ok:bool, results:array<int,array<string,mixed>>, reason?:string}
	 */
	public function retrieve( string $query, int $k = 5, ?string $scope = null ): array {
		if ( ! $this->is_available() ) {
			return self::unavailable();
		}
		try {
			$results = \G2A\POS\Ai\BrainFacade::retrieve( $query, $k, $scope );
			return array(
				'ok'      => true,
				'results' => $results,
			);
		} catch ( \Throwable $e ) {
			return self::error( $e );
		}
	}

	/**
	 * @param array<string, mixed> $opts
	 * @return array{ok:bool, results:array<int,array<string,mixed>>, reason?:string}
	 */
	public function ingest( string $label, string $body, array $opts = array() ): array {
		if ( ! $this->is_available() ) {
			return self::unavailable();
		}
		try {
			$results = \G2A\POS\Ai\BrainFacade::ingest( $label, $body, $opts );
			return array(
				'ok'      => true,
				'results' => $results,
			);
		} catch ( \Throwable $e ) {
			return self::error( $e );
		}
	}

	/**
	 * @return array{ok:bool, results:array<string,mixed>, reason?:string}
	 */
	public function stats( ?string $scope = null ): array {
		if ( ! $this->is_available() ) {
			return self::unavailable();
		}
		try {
			$results = \G2A\POS\Ai\BrainFacade::stats( $scope );
			return array(
				'ok'      => true,
				'results' => $results,
			);
		} catch ( \Throwable $e ) {
			return self::error( $e );
		}
	}

	private static function unavailable(): array {
		return array(
			'ok'      => false,
			'results' => array(),
			'reason'  => 'pos_brain_inactive',
		);
	}

	private static function error( \Throwable $e ): array {
		return array(
			'ok'      => false,
			'results' => array(),
			'reason'  => 'error: ' . $e->getMessage(),
		);
	}
}
