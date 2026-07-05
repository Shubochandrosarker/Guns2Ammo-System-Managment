<?php
/**
 * Webhooks — fans out a JSON POST to every configured URL whenever a
 * submission is captured. Compatible with Zapier, Make, n8n, and custom
 * endpoints. Optional HMAC-SHA256 signature for verification.
 *
 * @package Wpistic_Formistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Webhook dispatcher.
 */
class Wpistic_Formistic_Webhooks {

	/** Capability required for the test-send endpoint. */
	const CAP = 'manage_options';

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'wpistic_formistic_submission_captured', [ $this, 'dispatch' ], 30, 3 );
		add_action( 'admin_post_wpistic_formistic_webhook_test', [ $this, 'test_send' ] );
	}

	/**
	 * Whether the dispatcher is enabled & has at least one URL.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return '1' === get_option( 'wpistic_formistic_webhook_enabled', '0' ) && self::urls();
	}

	/**
	 * Parsed list of configured URLs.
	 *
	 * @return string[]
	 */
	public static function urls() {
		$raw   = (string) get_option( 'wpistic_formistic_webhook_urls', '' );
		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		$out   = [];
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$url = esc_url_raw( $line );
			if ( $url ) {
				$out[] = $url;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Fire-on-capture: build payload and fan out.
	 *
	 * @param int    $id        Submission ID.
	 * @param string $form_name Form name.
	 * @param array  $fields    Captured fields.
	 */
	public function dispatch( $id, $form_name, $fields ) {
		if ( ! self::enabled() ) {
			return;
		}
		self::dispatch_submission( (int) $id, (string) $form_name, (array) $fields );
	}

	/**
	 * Dispatch webhooks for a given submission payload.
	 *
	 * @param int    $id        Submission ID.
	 * @param string $form_name Form name.
	 * @param array  $fields    Captured fields.
	 */
	public static function dispatch_submission( $id, $form_name, $fields ) {
		if ( ! self::enabled() ) {
			return;
		}
		$payload = self::build_payload( (int) $id, (string) $form_name, (array) $fields );
		foreach ( self::urls() as $url ) {
			self::send( $url, $payload );
		}
	}

	/**
	 * Build the JSON payload for a submission.
	 *
	 * @param int    $id        Submission ID.
	 * @param string $form_name Form name.
	 * @param array  $fields    Captured fields.
	 * @return array
	 */
	public static function build_payload( $id, $form_name, array $fields ) {
		$row = Wpistic_Formistic_Database::get_submission( $id );
		if ( ! $row ) {
			return [
				'event'     => 'submission.created',
				'id'        => $id,
				'form'      => $form_name,
				'fields'    => $fields,
				'site_url'  => home_url( '/' ),
			];
		}
		$attachment_counts = Wpistic_Formistic_Database::attachment_counts( [ $id ] );
		return [
			'event'           => 'submission.created',
			'id'              => (int) $row->id,
			'form'            => (string) $row->form_name,
			'status'          => (string) $row->status,
			'created_at'      => (string) $row->created_at,
			'sender'          => [
				'name'  => (string) $row->sender_name,
				'email' => (string) $row->sender_email,
				'phone' => (string) $row->sender_phone,
				'ip'    => (string) $row->ip_address,
			],
			'subject'         => (string) $row->subject,
			'message'         => (string) $row->message,
			'fields'          => $fields,
			'source_url'      => (string) $row->source_url,
			'attachments'     => (int) ( $attachment_counts[ $id ] ?? 0 ),
			'site_url'        => home_url( '/' ),
			'dashboard_url'   => admin_url( 'admin.php?page=formistic&view=' . (int) $row->id ),
		];
	}

	/**
	 * POST a payload to one URL with optional HMAC signature.
	 *
	 * @param string $url     Target URL.
	 * @param array  $payload Payload.
	 * @return array|WP_Error wp_remote_post response.
	 */
	public static function send( $url, array $payload ) {
		$body    = wp_json_encode( $payload );
		$headers = [
			'Content-Type' => 'application/json',
			'User-Agent'   => 'Formistic/' . WPISTIC_FORMISTIC_VERSION,
		];
		$secret = (string) get_option( 'wpistic_formistic_webhook_secret', '' );
		if ( '' !== $secret ) {
			$headers['X-wpistic-formistic-Signature'] = 'sha256=' . hash_hmac( 'sha256', $body, $secret );
		}
		return wp_remote_post( $url, [
			'timeout'  => 5,
			'blocking' => false, // fire-and-forget so capture pipeline stays fast.
			'headers'  => $headers,
			'body'     => $body,
		] );
	}

	/**
	 * Blocking send used by manual test dispatch.
	 *
	 * @param string $url     Target URL.
	 * @param array  $payload Payload.
	 * @return array|WP_Error
	 */
	protected static function send_blocking( $url, array $payload ) {
		$body    = wp_json_encode( $payload );
		$headers = [
			'Content-Type' => 'application/json',
			'User-Agent'   => 'Formistic/' . WPISTIC_FORMISTIC_VERSION,
		];
		$secret = (string) get_option( 'wpistic_formistic_webhook_secret', '' );
		if ( '' !== $secret ) {
			$headers['X-wpistic-formistic-Signature'] = 'sha256=' . hash_hmac( 'sha256', $body, $secret );
		}
		return wp_remote_post( $url, [
			'timeout'  => 5,
			'blocking' => true,
			'headers'  => $headers,
			'body'     => $body,
		] );
	}

	/**
	 * Admin test-send endpoint — fires a sample payload to every URL and
	 * returns to Settings → Webhooks with a notice.
	 */
	public function test_send() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'formistic' ), 403 );
		}
		check_admin_referer( 'wpistic_formistic_webhook_test' );

		$urls = self::urls();
		$n    = 0;
		if ( $urls ) {
			$sample = [
				'event'         => 'submission.test',
				'id'            => 0,
				'form'          => __( 'Webhook test', 'formistic' ),
				'status'        => 'test',
				'created_at'    => current_time( 'mysql' ),
				'sender'        => [ 'name' => 'Test User', 'email' => 'test@example.com', 'phone' => '', 'ip' => '' ],
				'subject'       => __( 'Formistic webhook test', 'formistic' ),
				'message'       => __( 'If you received this payload, your webhook integration is working.', 'formistic' ),
				'fields'        => [ __( 'Test', 'formistic' ) => __( 'Hello from Formistic.', 'formistic' ) ],
				'source_url'    => home_url( '/' ),
				'attachments'   => 0,
				'site_url'      => home_url( '/' ),
				'dashboard_url' => admin_url( 'admin.php?page=formistic' ),
			];
			foreach ( $urls as $url ) {
				$res = self::send_blocking( $url, $sample );
				if ( ! is_wp_error( $res ) ) {
					$code = (int) wp_remote_retrieve_response_code( $res );
					if ( $code >= 200 && $code < 400 ) {
						$n++;
					}
				}
			}
		}

		$back = add_query_arg( [
			'page'        => 'formistic-settings',
			'tab'         => 'webhooks',
			'wpistic_formistic_notice' => 'webhook_test',
			'n'           => $n,
		], admin_url( 'admin.php' ) );
		wp_safe_redirect( $back );
		exit;
	}
}
