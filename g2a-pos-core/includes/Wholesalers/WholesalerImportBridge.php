<?php

namespace G2A\POS\Wholesalers;

use G2A\POS\Database\WholesalerRepository;

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

	private static function resolve_wholesaler_id( WholesalerProviderInterface $provider, array $context ): int {
		$repo     = new WholesalerRepository();
		$existing = $repo->findByCode( $provider->code() );
		if ( $existing ) {
			return (int) $existing['id'];
		}
		return $repo->upsert(
			array(
				'provider_code'  => $provider->code(),
				'display_name'   => (string) ( $context['display_name'] ?? $provider->displayName() ),
				'account_number' => (string) ( $context['account_number'] ?? '' ),
				'status'         => 'active',
				'settings'       => array( 'auto_created_by' => 'distributor_pipeline' ),
			)
		);
	}
}
