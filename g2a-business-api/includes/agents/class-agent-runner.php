<?php
/**
 * Runs an agent's prompt against the connected Anthropic model.
 *
 * Wired to the `g2aba_run_agent` cron hook already scheduled by
 * Agents_Controller::run(). Never runs on the REST hot path — the
 * controller schedules a single event and the actual model call happens
 * later, inside WP-Cron.
 *
 * If Anthropic isn't configured OR the call fails, we still record a
 * meaningful `lastOutput` so the dashboard shows what went wrong.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Agents;

use WordPressistic\G2ABA\Integrations\Anthropic_Client;
use WordPressistic\G2ABA\Providers\Booking_Provider;
use WordPressistic\G2ABA\Providers\Membership_Provider;
use WordPressistic\G2ABA\Providers\Revenue_Provider;
use WordPressistic\G2ABA\Providers\SEO_Provider;
use WordPressistic\G2ABA\Providers\Store_Provider;
use WordPressistic\G2ABA\Range;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Agent_Runner {
	public const CRON_HOOK = 'g2aba_run_agent';

	public static function register(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ), 10, 1 );
	}

	public static function run( string $agent_id ): void {
		$store = new Agent_Store();
		$agent = $store->find( $agent_id );
		if ( null === $agent ) {
			return;
		}

		// Short-circuit BEFORE gathering the snapshot — snapshot queries touch
		// $wpdb + WooCommerce, which is a lot of work to throw away if there's
		// no API key configured.
		$client = new Anthropic_Client( (string) ( $agent['model'] ?? 'anthropic-primary' ) );
		if ( ! $client->is_configured() ) {
			$store->record_run( $agent_id, 'Anthropic connection not configured. Set an API key under Settings → G2A Business API.', 0.0 );
			return;
		}

		$snapshot = self::snapshot();
		$prompt   = self::render_prompt( (string) ( $agent['promptTemplate'] ?? '' ), $snapshot );

		try {
			$text = $client->complete( $prompt );
			$store->record_run( $agent_id, self::truncate( $text ), 0.9 );
		} catch ( \Throwable $e ) {
			$store->record_run( $agent_id, 'Run failed: ' . $e->getMessage(), 0.0 );
		}
	}

	/**
	 * @internal Exposed for tests.
	 */
	public static function render_prompt( string $template, array $snapshot ): string {
		$json = wp_json_encode( $snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return str_replace( '{{snapshot}}', (string) $json, $template );
	}

	private static function snapshot(): array {
		$range = new Range( gmdate( 'Y-m-d', strtotime( '-29 days' ) ), gmdate( 'Y-m-d' ) );
		return array(
			'range'       => $range->to_array(),
			'overview'    => ( new Revenue_Provider() )->overview( $range ),
			'bookings'    => ( new Booking_Provider() )->analytics( $range ),
			'memberships' => ( new Membership_Provider() )->analytics( $range ),
			'store'       => ( new Store_Provider() )->analytics( $range ),
			'seo'         => ( new SEO_Provider() )->analytics( $range ),
		);
	}

	private static function truncate( string $text, int $max = 4000 ): string {
		$text = trim( $text );
		return strlen( $text ) > $max ? substr( $text, 0, $max ) . '…' : $text;
	}
}
