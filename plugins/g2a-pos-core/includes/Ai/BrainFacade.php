<?php

namespace G2A\POS\Ai;

use G2A\POS\Database\AiBrainRepository;

/**
 * Stable cross-plugin entry point onto the AI Brain (RAG store).
 *
 * Other plugins on this WordPress install may call this class directly via
 * class_exists() — treat method signatures as a public contract. It is a
 * thin, in-process wrapper over BrainService/AiBrainRepository so a
 * dashboard plugin (e.g. g2a-business-api) can read from and write to the
 * same chunk/embedding store the POS in-store assistant uses, without
 * standing up a second vector store or making a network call.
 */
final class BrainFacade {

	/**
	 * Ingest a piece of text into the shared brain. Thin wrapper over
	 * BrainService::ingest_text() — see that method for the `$opts` shape
	 * (source_type, source_uri, tags, scope, metadata, force).
	 */
	public static function ingest( string $label, string $body, array $opts = array() ): array {
		return BrainService::ingest_text( $label, $body, $opts );
	}

	/**
	 * Retrieve the top-$k chunks relevant to $query, optionally restricted to
	 * a single scope (e.g. a dashboard agent ingesting under its own scope
	 * so it never surfaces another agent's private notes).
	 */
	public static function retrieve( string $query, int $k = 5, ?string $scope = null ): array {
		return BrainService::retrieve( $query, $k, $scope );
	}

	/**
	 * Document/chunk counts, optionally restricted to a single scope.
	 */
	public static function stats( ?string $scope = null ): array {
		$stats            = ( new AiBrainRepository() )->stats( $scope );
		$stats['backend'] = BrainService::backend();
		return $stats;
	}

	/**
	 * Always true today (the class is only loaded when g2a-pos-core is
	 * active) — kept for symmetry with the consumer-side
	 * `Brain_Client::is_available()` check in g2a-business-api.
	 */
	public static function is_available(): bool {
		return true;
	}
}
