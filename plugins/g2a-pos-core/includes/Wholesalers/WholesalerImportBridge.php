<?php

namespace G2A\POS\Wholesalers;

use G2A\POS\Database\WholesalerRepository;
use G2A\POS\Support\Logger;

/**
 * Bridges the v0.6 distributor CSV pipeline (Inventory\SyncService +
 * Inventory\Importer) to the Phase 3 wholesaler-side ingestion
 * (wholesaler_products / map_rules / wholesaler_categories /
 * wholesaler_sync_runs).
 *
 * Both pipelines run off the same uploaded CSV: v0.6 creates WooCommerce
 * products + g2a_inventory_external_refs; Phase 3 populates the vendor
 * catalog tables and discovers MAP rules. Auto-creates a wholesaler
 * record when a distributor uses a provider for the first time, so the
 * merchant doesn't have to configure both halves separately.
 */
final class WholesalerImportBridge {

	public static function mirror_csv( string $adapterSlug, string $csvPath, ?int $distributorId = null, array $context = array() ): array {
		$adapterSlug = sanitize_key( $adapterSlug );
		$provider    = WholesalerRegistry::get( $adapterSlug );
		if ( ! $provider || ! $provider->supportsCatalogCsv() ) {
			return array(
				'ok'       => false,
				'mirrored' => false,
				'reason'   => 'no_matching_provider',
			);
		}
		if ( $csvPath === '' || ! is_readable( $csvPath ) ) {
			return array(
				'ok'       => false,
				'mirrored' => false,
				'reason'   => 'csv_not_readable',
			);
		}

		try {
			$wholesalerId = self::resolve_wholesaler_id( $provider, $context );
		} catch ( AmbiguousWholesalerAccountException $e ) {
			return array(
				'ok'       => false,
				'mirrored' => false,
				'reason'   => 'ambiguous_wholesaler_account',
				'error'    => $e->getMessage(),
			);
		} catch ( \Throwable $e ) {
			return array(
				'ok'       => false,
				'mirrored' => false,
				'reason'   => 'resolve_failed',
				'error'    => $e->getMessage(),
			);
		}

		try {
			$result = $provider->importCatalogCsv(
				$wholesalerId,
				$csvPath,
				array(
					'file_label'     => (string) ( $context['file_label'] ?? basename( $csvPath ) ),
					'source'         => 'distributor_pipeline',
					'distributor_id' => $distributorId,
				)
			);
		} catch ( \Throwable $e ) {
			return array(
				'ok'            => false,
				'mirrored'      => false,
				'wholesaler_id' => $wholesalerId,
				'error'         => $e->getMessage(),
			);
		}

		if ( ! empty( $result['ok'] ) ) {
			( new WholesalerRepository() )->markSyncedNow( $wholesalerId );
		}

		return array(
			'ok'            => ! empty( $result['ok'] ),
			'mirrored'      => true,
			'wholesaler_id' => $wholesalerId,
			'sync_run_id'   => $result['sync_run_id'] ?? null,
			'stats'         => $result['stats'] ?? null,
			'error'         => $result['error'] ?? null,
		);
	}

	/**
	 * Resolve the wholesaler account this feed belongs to.
	 *
	 * When the distributor row carries an account number, resolution is
	 * exact on (provider_code, account_number) — creating the row with that
	 * account number if it doesn't exist yet. Without an account number,
	 * code-only resolution is allowed only while exactly one account exists
	 * for the provider; with several accounts the item aborts loudly instead
	 * of mirroring the feed into whichever row happens to have the lowest id.
	 *
	 * @throws AmbiguousWholesalerAccountException When multiple accounts exist and no account number was supplied.
	 */
	private static function resolve_wholesaler_id( WholesalerProviderInterface $provider, array $context ): int {
		$repo    = new WholesalerRepository();
		$code    = $provider->code();
		$account = trim( (string) ( $context['account_number'] ?? '' ) );

		if ( $account !== '' ) {
			$existing = $repo->findByCodeAndAccount( $code, $account );
			if ( $existing ) {
				WholesalerIntegrityChecker::clearSyncAmbiguity( $code );
				return (int) $existing['id'];
			}
			$id = $repo->upsert(
				array(
					'provider_code'  => $code,
					'display_name'   => (string) ( $context['display_name'] ?? $provider->displayName() ),
					'account_number' => $account,
					'status'         => 'active',
					'settings'       => array( 'auto_created_by' => 'distributor_pipeline' ),
				)
			);
			WholesalerIntegrityChecker::clearSyncAmbiguity( $code );
			return $id;
		}

		$rows = $repo->findAllByCode( $code );
		if ( count( $rows ) === 1 ) {
			WholesalerIntegrityChecker::clearSyncAmbiguity( $code );
			return (int) $rows[0]['id'];
		}
		if ( count( $rows ) > 1 ) {
			$message = sprintf(
				'%d wholesaler accounts exist for provider "%s" but this distributor feed carries no account number; refusing to guess which account the feed belongs to. Set the account number on the distributor row (and on each wholesaler account) so feeds resolve exactly.',
				count( $rows ),
				$code
			);
			Logger::error(
				'Wholesaler mirror aborted: ambiguous account resolution',
				array(
					'provider_code' => $code,
					'row_ids'       => array_map( static fn ( array $r ): int => (int) $r['id'], $rows ),
					'accounts'      => array_map( static fn ( array $r ): string => (string) ( $r['account_number'] ?? '' ), $rows ),
					'hint'          => 'Set account_number on the distributor feed and on each wholesaler row.',
				)
			);
			WholesalerIntegrityChecker::recordSyncAmbiguity( $code, $message );
			throw new AmbiguousWholesalerAccountException( $message );
		}

		// First time this provider is seen and the feed has no account
		// number: create the provider's first (and only) row.
		return $repo->upsert(
			array(
				'provider_code'  => $code,
				'display_name'   => (string) ( $context['display_name'] ?? $provider->displayName() ),
				'account_number' => '',
				'status'         => 'active',
				'settings'       => array( 'auto_created_by' => 'distributor_pipeline' ),
			)
		);
	}
}
