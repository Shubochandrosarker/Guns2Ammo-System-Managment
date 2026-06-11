<?php

namespace G2A\POS\Inventory;

use G2A\POS\Database\DistributorRepository;
use G2A\POS\Database\DistributorSyncRunRepository;
use G2A\POS\Inventory\Distributors\AdapterRegistry;
use G2A\POS\Webhooks\WebhookBus;
use G2A\POS\Wholesalers\WholesalerImportBridge;

/**
 * Pulls a distributor feed via HTTP and pipes it through the Importer.
 * Cron-driven (daily / hourly schedules) or triggered manually.
 *
 * Source types supported:
 *   - http:        endpoint_url (optionally with basic auth in credentials)
 *   - local_file:  endpoint_url is a server path (test/dev only)
 *
 * FTP/SFTP feeds (RSR, traditional Davidson's) are out of scope here — pull
 * them with a separate cron + drop the CSV into uploads/ then use local_file.
 */
final class SyncService {

	public static function run_distributor( int $distributor_id ): array {
		$repo     = new DistributorRepository();
		$runs     = new DistributorSyncRunRepository();
		$importer = new Importer();

		$dist = $repo->find( $distributor_id );
		if ( ! $dist ) {
			return array( 'error' => 'Distributor not found.' );
		}
		if ( empty( $dist['enabled'] ) ) {
			return array( 'error' => 'Distributor is disabled.' );
		}
		$adapter = AdapterRegistry::get( (string) $dist['adapter'] );
		if ( ! $adapter ) {
			return array( 'error' => 'Unknown adapter: ' . $dist['adapter'] );
		}

		$run_id = $runs->open( $distributor_id );
		try {
			$csv_path = self::fetch( $dist );
			if ( ! $csv_path ) {
				$runs->close( $run_id, array(), 'Failed to fetch feed.' );
				return array(
					'error'  => 'Failed to fetch feed.',
					'run_id' => $run_id,
				);
			}
			$result = $importer->import_from_adapter(
				$adapter,
				CsvReader::rows( $csv_path ),
				array(
					'auto_publish' => ! empty( $dist['auto_publish'] ),
					'markup_pct'   => (float) $dist['default_markup_pct'],
				)
			);
			$runs->close( $run_id, $result['stats'] ?? array(), null );
			$repo->update( $distributor_id, array( 'last_synced_at' => current_time( 'mysql' ) ) );

			// Mirror the same CSV into the wholesaler-side tables (vendor catalog,
			// MAP rules, categories, sync runs) when the adapter slug matches a
			// registered WholesalerProvider. No-op for adapters with no provider.
			$mirror = WholesalerImportBridge::mirror_csv(
				(string) $dist['adapter'],
				(string) $csv_path,
				$distributor_id,
				array(
					'display_name'   => (string) ( $dist['name'] ?? '' ),
					'account_number' => (string) ( $dist['account_number'] ?? '' ),
					'file_label'     => 'distributor:' . $distributor_id,
				)
			);

			WebhookBus::emit(
				'order.created',
				array(
					'distributor_id' => $distributor_id,
					'stats'          => $result['stats'] ?? array(),
				)
			);
			return array(
				'run_id'            => $run_id,
				'result'            => $result,
				'wholesaler_mirror' => $mirror,
			);
		} catch ( \Throwable $e ) {
			$runs->close( $run_id, array(), $e->getMessage() );
			return array(
				'error'  => $e->getMessage(),
				'run_id' => $run_id,
			);
		}
	}

	public static function run_due( string $schedule = 'daily' ): array {
		$repo = new DistributorRepository();
		$ran  = array();
		foreach ( $repo->enabled_due( $schedule ) as $dist ) {
			$ran[] = array(
				'distributor_id' => (int) $dist['id'],
				'result'         => self::run_distributor( (int) $dist['id'] ),
			);
		}
		return array( 'ran' => $ran );
	}

	private static function fetch( array $dist ): ?string {
		$source_type = (string) ( $dist['source_type'] ?? 'http' );
		$url         = (string) ( $dist['endpoint_url'] ?? '' );
		if ( $url === '' ) {
			return null;
		}

		if ( $source_type === 'local_file' ) {
			return is_readable( $url ) ? $url : null;
		}

		$args = array(
			'timeout' => 120,
			'stream'  => true,
		);
		$tmp  = wp_tempnam( 'g2a_dist_' );
		if ( ! $tmp ) {
			return null;
		}
		$args['filename'] = $tmp;
		$creds            = (array) ( $dist['credentials'] ?? array() );
		if ( ! empty( $creds['basic_user'] ) ) {
			$args['headers']['Authorization'] = 'Basic ' . base64_encode( $creds['basic_user'] . ':' . ( $creds['basic_pass'] ?? '' ) );
		}
		if ( ! empty( $creds['bearer'] ) ) {
			$args['headers']['Authorization'] = 'Bearer ' . $creds['bearer'];
		}
		$resp = wp_remote_get( $url, $args );
		if ( is_wp_error( $resp ) ) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		return ( $code >= 200 && $code < 300 ) ? $tmp : null;
	}
}
