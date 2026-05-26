<?php
/**
 * Deactivation handler.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Deactivator {
	/**
	 * Run deactivation tasks.
	 */
	public static function deactivate() {
		require_once MEMBERISTIC_PATH . 'includes/class-scheduler.php';
		Scheduler::clear_scheduled();

		flush_rewrite_rules();
	}
}
