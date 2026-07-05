<?php
/**
 * GET /emails/overview
 *
 * Real email traffic for the dashboard's Email Management page: Formistic
 * inbox counts + recent enquiries, newsletter subscriber totals, and
 * Messageistic SMS stats. Sections degrade to `active:false` when the
 * source plugin is absent.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\REST;

use WordPressistic\G2ABA\Cache;
use WordPressistic\G2ABA\Providers\Email_Overview_Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Email_Overview_Controller extends REST_Controller {
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/emails/overview',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'overview' ),
				'permission_callback' => array( $this, 'read_permissions_check' ),
			)
		);
	}

	public function overview() {
		$data = Cache::remember(
			'emails_overview',
			60,
			static function () {
				return ( new Email_Overview_Provider() )->overview();
			}
		);
		return $this->ok( $data );
	}
}
