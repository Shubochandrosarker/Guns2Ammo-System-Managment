<?php
/**
 * Deactivation: intentionally minimal — preserves data by design.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Deactivator {
	public static function deactivate(): void {
		// No-op. Full cleanup happens in uninstall.php only.
	}
}
