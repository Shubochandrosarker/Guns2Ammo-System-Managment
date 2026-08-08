<?php
/**
 * GET /system/site-health summary.
 *
 * Prefers WP core's own Site Health tests — invoked in-process via
 * `rest_do_request( '/wp-site-health/v1/tests/{test}' )`, the same REST
 * controller (`WP_REST_Site_Health_Controller`) WP-Admin's own Site Health
 * screen calls — for a handful of "direct" tests (cheap, synchronous, no
 * outbound HTTP of their own). This never makes an HTTP call itself.
 *
 * Degrades to a constants-only summary (WP/PHP version, active plugin
 * count, debug flags, disk free space) whenever `WP_Site_Health` /
 * `rest_do_request` aren't available or a direct test throws — this
 * endpoint must never fatal the request just because Site Health internals
 * are missing or behave unexpectedly in a given environment.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Providers;

use WordPressistic\G2ABA\System\Site_Health_Summary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Health_Provider {
	// WP core's "direct" Site Health tests: synchronous and cheap, unlike
	// the dotorg/plugin-update checks the browser runs async against
	// wordpress.org. Kept short so this endpoint stays fast on page load.
	private const DIRECT_TESTS = array(
		'background-updates',
		'loopback-requests',
		'https-status',
		'authorization-header',
	);

	public function summary(): array {
		$base = $this->base_facts();

		$tests = $this->run_direct_tests();
		if ( null !== $tests ) {
			$tally = Site_Health_Summary::tally( $tests );
			return array_merge(
				$base,
				array(
					'criticalIssues'          => $tally['criticalIssues'],
					'recommendedImprovements' => $tally['recommendedImprovements'],
					'testsRun'                => self::DIRECT_TESTS,
					'degraded'                => false,
				)
			);
		}

		$tally = Site_Health_Summary::fallback_tally(
			array(
				'debugDisplay' => $base['displayErrors'],
				'hasAuthKey'   => defined( 'AUTH_KEY' ) && strlen( (string) AUTH_KEY ) >= 32,
			)
		);

		return array_merge(
			$base,
			array(
				'criticalIssues'          => $tally['criticalIssues'],
				'recommendedImprovements' => $tally['recommendedImprovements'],
				'testsRun'                => array(),
				'degraded'                => true,
			)
		);
	}

	/**
	 * @return array{wpVersion:string, phpVersion:string, activePluginCount:int, debugMode:bool, displayErrors:bool, diskFreeBytes:int|null}
	 */
	private function base_facts(): array {
		global $wp_version;

		$active_plugins = get_option( 'active_plugins', array() );

		$disk_free = null;
		if ( function_exists( 'disk_free_space' ) && defined( 'ABSPATH' ) ) {
			// Silenced: open_basedir / disabled_functions environments can
			// warn here; a null reading is a perfectly fine degrade.
			$val       = @disk_free_space( ABSPATH ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$disk_free = false === $val ? null : (int) $val;
		}

		return array(
			'wpVersion'         => isset( $wp_version ) ? (string) $wp_version : 'unknown',
			'phpVersion'        => PHP_VERSION,
			'activePluginCount' => is_array( $active_plugins ) ? count( $active_plugins ) : 0,
			'debugMode'         => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'displayErrors'     => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY,
			'diskFreeBytes'     => $disk_free,
		);
	}

	/**
	 * Attempts to run WP core's own Site Health direct tests in-process.
	 * Never throws — returns null if Site Health internals are unavailable
	 * or anything goes wrong, so the caller can fall back gracefully.
	 *
	 * @return array<int, array{status?: string}>|null
	 */
	private function run_direct_tests(): ?array {
		if ( ! class_exists( 'WP_Site_Health' )
			|| ! function_exists( 'rest_do_request' )
			|| ! class_exists( 'WP_REST_Request' )
		) {
			return null;
		}

		try {
			$results = array();
			foreach ( self::DIRECT_TESTS as $test ) {
				$request  = new \WP_REST_Request( 'GET', '/wp-site-health/v1/tests/' . $test );
				$response = rest_do_request( $request );
				if ( $response instanceof \WP_Error ) {
					continue;
				}
				$data = $response->get_data();
				if ( is_array( $data ) ) {
					$results[] = $data;
				}
			}
			return $results;
		} catch ( \Throwable $e ) {
			return null;
		}
	}
}
