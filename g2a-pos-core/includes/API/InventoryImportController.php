<?php

namespace G2A\POS\API;

use G2A\POS\Database\DistributorRepository;
use G2A\POS\Database\DistributorSyncRunRepository;
use G2A\POS\Inventory\Distributors\AdapterRegistry;
use G2A\POS\Inventory\Importer;
use G2A\POS\Inventory\SyncService;
use G2A\POS\Wholesalers\WholesalerImportBridge;
use WP_REST_Request;

final class InventoryImportController {

	public static function adapters( WP_REST_Request $req ) {
		// AdapterRegistry::all() is keyed by slug; array_values() guarantees a
		// JSON array (not an object) so the admin SPA can .map() over it.
		return array(
			'adapters' => array_values(
				array_map(
					static fn( $a ) => array(
						'slug'      => $a->slug(),
						'label'     => $a->label(),
						'signature' => $a->header_signature(),
					),
					AdapterRegistry::all()
				)
			),
		);
	}

	public static function preview( WP_REST_Request $req ) {
		$path = self::accept_csv( $req );
		if ( ! $path ) {
			return new \WP_Error( 'no_csv', 'Provide csv (base64-encoded body) or csv_path (server file).', array( 'status' => 400 ) );
		}
		try {
			$importer = new Importer();
			return $importer->preview( $path, $req->get_param( 'adapter' ) ? sanitize_key( (string) $req->get_param( 'adapter' ) ) : null );
		} finally {
			self::cleanup_temp( $path, $req );
		}
	}

	public static function commit( WP_REST_Request $req ) {
		$path = self::accept_csv( $req );
		if ( ! $path ) {
			return new \WP_Error( 'no_csv', 'Provide csv (base64-encoded body) or csv_path.', array( 'status' => 400 ) );
		}
		try {
			$importer     = new Importer();
			$payload      = $req->get_json_params() ?: array();
			$adapter_slug = $req->get_param( 'adapter' ) ? sanitize_key( (string) $req->get_param( 'adapter' ) ) : null;
			$dry_run      = ! empty( $payload['dry_run'] );
			$result       = $importer->import(
				$path,
				$adapter_slug,
				array(
					'auto_publish' => ! empty( $payload['auto_publish'] ),
					'markup_pct'   => (float) ( $payload['markup_pct'] ?? 0 ),
					'dry_run'      => $dry_run,
				)
			);

			// Mirror to wholesaler-side tables on real (non-dry-run) imports.
			// Uses the detected/explicit adapter slug from the importer result.
			$effective_slug = $adapter_slug ?: ( $result['adapter'] ?? null );
			if ( ! $dry_run && $effective_slug && empty( $result['error'] ) ) {
				$result['wholesaler_mirror'] = WholesalerImportBridge::mirror_csv(
					(string) $effective_slug,
					(string) $path,
					null,
					array( 'file_label' => 'manual-upload:' . current_time( 'mysql' ) )
				);
			}
			return $result;
		} finally {
			self::cleanup_temp( $path, $req );
		}
	}

	public static function distributors( WP_REST_Request $req ) {
		$repo = new DistributorRepository();
		return array( 'distributors' => $repo->all() );
	}

	public static function create_distributor( WP_REST_Request $req ) {
		$payload = $req->get_json_params() ?: array();
		if ( empty( $payload['adapter'] ) || empty( $payload['name'] ) ) {
			return new \WP_Error( 'missing', 'adapter and name are required.', array( 'status' => 400 ) );
		}
		if ( ! AdapterRegistry::get( (string) $payload['adapter'] ) ) {
			return new \WP_Error( 'bad_adapter', 'Unknown adapter slug.', array( 'status' => 422 ) );
		}
		$repo = new DistributorRepository();
		$id   = $repo->create( $payload );
		return array(
			'id'          => $id,
			'distributor' => $repo->find( $id ),
		);
	}

	public static function update_distributor( WP_REST_Request $req ) {
		$id   = (int) $req->get_param( 'id' );
		$repo = new DistributorRepository();
		if ( ! $repo->find( $id ) ) {
			return new \WP_Error( 'not_found', 'Distributor not found.', array( 'status' => 404 ) );
		}
		$repo->update( $id, $req->get_json_params() ?: array() );
		return array( 'distributor' => $repo->find( $id ) );
	}

	public static function delete_distributor( WP_REST_Request $req ) {
		$id   = (int) $req->get_param( 'id' );
		$repo = new DistributorRepository();
		$repo->delete( $id );
		return array( 'ok' => true );
	}

	public static function sync_now( WP_REST_Request $req ) {
		$id = (int) $req->get_param( 'id' );
		return SyncService::run_distributor( $id );
	}

	public static function sync_runs( WP_REST_Request $req ) {
		$id   = (int) $req->get_param( 'id' );
		$runs = new DistributorSyncRunRepository();
		return array( 'runs' => $runs->recent( $id ) );
	}

	private static function accept_csv( WP_REST_Request $req ): ?string {
		$payload = $req->get_json_params() ?: array();
		if ( ! empty( $payload['csv_b64'] ) ) {
			$bin = base64_decode( (string) $payload['csv_b64'], true );
			if ( $bin === false ) {
				return null;
			}
			$tmp = wp_tempnam( 'g2a_csv_' );
			if ( ! $tmp ) {
				return null;
			}
			file_put_contents( $tmp, $bin );
			return $tmp;
		}
		if ( ! empty( $payload['csv_path'] ) && is_readable( (string) $payload['csv_path'] ) ) {
			return (string) $payload['csv_path'];
		}
		$files = $req->get_file_params();
		if ( ! empty( $files['csv']['tmp_name'] ) ) {
			return (string) $files['csv']['tmp_name'];
		}
		return null;
	}

	private static function cleanup_temp( string $path, WP_REST_Request $req ): void {
		$payload = $req->get_json_params() ?: array();
		if ( ! empty( $payload['csv_b64'] ) && str_contains( $path, 'g2a_csv_' ) ) {
			@unlink( $path );
		}
	}
}
