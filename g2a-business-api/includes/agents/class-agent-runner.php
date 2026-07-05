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
 * Every department except `email` builds one context bundle and makes one
 * `Anthropic_Client::complete()` call, exactly like before. `email` is
 * special-cased: it drafts a reply for each of up to 5 open enquiries, one
 * model call per lead (see `run_email_department()`).
 *
 * Every data source this runner touches (Leads_Repository, Brain_Client,
 * the analytics Providers) is wrapped in `safe_call()` so a single missing
 * table or unavailable integration degrades that one slice of context to an
 * empty default rather than aborting the whole run — Leads_Repository and
 * Brain_Client already degrade internally per their own contracts, this is
 * an extra defensive layer at the call site.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Agents;

use WordPressistic\G2ABA\Ai\Brain_Client;
use WordPressistic\G2ABA\Integrations\Anthropic_Client;
use WordPressistic\G2ABA\Leads\Leads_Repository;
use WordPressistic\G2ABA\Ops\Email_Draft_Store;
use WordPressistic\G2ABA\Ops\Opt_Out_Store;
use WordPressistic\G2ABA\Providers\Booking_Provider;
use WordPressistic\G2ABA\Providers\Membership_Provider;
use WordPressistic\G2ABA\Providers\Revenue_Provider;
use WordPressistic\G2ABA\Providers\SEO_Provider;
use WordPressistic\G2ABA\Providers\Store_Provider;
use WordPressistic\G2ABA\Range;
use WordPressistic\G2ABA\Routing\Model_Routing_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Agent_Runner {
	public const CRON_HOOK = 'g2aba_run_agent';

	/** How many days back the department snapshots cover. */
	private const SNAPSHOT_DAYS = 29;

	/** Max enquiries drafted per `email` department run. */
	private const EMAIL_BATCH_SIZE = 5;

	public static function register(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ), 10, 1 );
	}

	public static function run( string $agent_id ): void {
		$store = new Agent_Store();
		$agent = $store->find( $agent_id );
		if ( null === $agent ) {
			return;
		}

		// Short-circuit BEFORE gathering any context — context gathering
		// touches $wpdb + WooCommerce (and, for `email`, the leads table +
		// the AI brain), which is a lot of work to throw away if there's no
		// API key configured.
		//
		// Connection selection: the dashboard's model-routing map wins when a
		// route exists for this agent's purpose; otherwise the agent's own
		// `model` connection id is used, exactly as before.
		$default_connection = (string) ( $agent['model'] ?? 'anthropic-primary' );
		$department         = (string) ( $agent['department'] ?? '' );
		$purpose            = self::purpose_for_department( $department );
		$connection_id      = ( new Model_Routing_Store() )->connection_for( $purpose, $default_connection );

		$client = new Anthropic_Client( $connection_id );
		if ( ! $client->is_configured() ) {
			$store->record_run( $agent_id, 'Anthropic connection not configured. Set an API key under Settings → G2A Business API.', 0.0 );
			return;
		}

		if ( 'email' === $department ) {
			self::run_email_department( $agent_id, $agent, $client, $store );
			return;
		}

		$context = self::context_for( $department );
		$prompt  = self::render_prompt( (string) ( $agent['promptTemplate'] ?? '' ), $context );

		try {
			$text = $client->complete( $prompt );
			$store->record_run( $agent_id, self::truncate( $text ), 0.9 );
		} catch ( \Throwable $e ) {
			$store->record_run( $agent_id, 'Run failed: ' . $e->getMessage(), 0.0 );
		}
	}

	/**
	 * Map an agent department onto a model-routing purpose. Departments
	 * without a dedicated purpose fall into `business_analysis` — the
	 * catch-all analytical route. `membership` has never had its own
	 * purpose and still doesn't; it intentionally falls through to that
	 * same default.
	 *
	 * @internal Exposed for tests.
	 */
	public static function purpose_for_department( string $department ): string {
		$map = array(
			'seo'     => 'seo_analysis',
			'analyst' => 'business_analysis',
			'booking' => 'booking_suggest',
			'support' => 'support_classify',
			'email'   => 'email_drafts',
			'reports' => 'daily_summaries',
			'store'   => 'private_inventory',
			'sales'   => 'sales_pipeline',
		);
		return $map[ $department ] ?? 'business_analysis';
	}

	/**
	 * Substitute `{{snapshot}}`, `{{leads}}`, `{{knowledge}}`, `{{lead}}`, and
	 * `{{agents}}` from the given context. A key that is absent from $context
	 * leaves its placeholder literally in place — harmless, ignored by the
	 * model — so every department can share one template-rendering call
	 * regardless of which slices of context it actually has.
	 *
	 * @internal Exposed for tests.
	 *
	 * @param array{snapshot?:mixed, leads?:mixed, knowledge?:mixed, lead?:mixed, agents?:mixed} $context
	 */
	public static function render_prompt( string $template, array $context = array() ): string {
		$placeholders = array(
			'snapshot'  => '{{snapshot}}',
			'leads'     => '{{leads}}',
			'knowledge' => '{{knowledge}}',
			'lead'      => '{{lead}}',
			'agents'    => '{{agents}}',
		);

		foreach ( $placeholders as $key => $placeholder ) {
			if ( ! array_key_exists( $key, $context ) ) {
				continue;
			}
			$json     = wp_json_encode( $context[ $key ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$template = str_replace( $placeholder, (string) $json, $template );
		}

		return $template;
	}

	/**
	 * Assemble the per-department context bundle. Every department gets
	 * only the data its prompt actually asks for — `analyst` and `reports`
	 * are the two exceptions that still get the full cross-cutting
	 * snapshot, exactly as before.
	 *
	 * @internal Exposed for tests.
	 */
	public static function context_for( string $department ): array {
		$range = new Range( gmdate( 'Y-m-d', strtotime( '-' . self::SNAPSHOT_DAYS . ' days' ) ), gmdate( 'Y-m-d' ) );

		switch ( $department ) {
			case 'seo':
				return array(
					'snapshot' => array(
						'range' => $range->to_array(),
						'seo'   => self::safe_call( static fn() => ( new SEO_Provider() )->analytics( $range ) ),
					),
				);

			case 'booking':
				$leads = self::safe_call(
					static fn() => self::merge_lead_queries(
						self::per_value_variants(
							'category',
							array( 'lane_booking', 'ccw_class', 'range_enquiry', 'event_booking' ),
							array( 'status' => 'new', 'per_page' => 10 )
						),
						10
					)
				);
				return array(
					'snapshot'  => array(
						'range'    => $range->to_array(),
						'bookings' => self::safe_call( static fn() => ( new Booking_Provider() )->analytics( $range ) ),
					),
					'leads'     => $leads,
					'knowledge' => self::safe_knowledge( 'lane pricing, CCW course pricing, membership guest pricing policies', 5 ),
				);

			case 'membership':
				$leads = self::safe_call(
					static fn() => self::merge_lead_queries(
						self::per_value_variants(
							'category',
							array( 'membership', 'new_member' ),
							array( 'status' => 'new', 'per_page' => 10 )
						),
						10
					)
				);
				return array(
					'snapshot'  => array(
						'range'       => $range->to_array(),
						'memberships' => self::safe_call( static fn() => ( new Membership_Provider() )->analytics( $range ) ),
					),
					'leads'     => $leads,
					'knowledge' => self::safe_knowledge( 'membership plans pricing benefits guest pricing', 5 ),
				);

			case 'store':
				return array(
					'snapshot'  => array(
						'range' => $range->to_array(),
						'store' => self::safe_call( static fn() => ( new Store_Provider() )->analytics( $range ) ),
					),
					'knowledge' => self::safe_knowledge( 'product pricing policies return exchange', 5 ),
				);

			case 'sales':
				return self::sales_context();

			case 'reports':
				return array(
					'snapshot' => self::full_snapshot( $range ),
					'agents'   => self::agent_findings(),
				);

			case 'analyst':
			default:
				return array( 'snapshot' => self::full_snapshot( $range ) );
		}
	}

	/**
	 * The full cross-cutting snapshot — unchanged shape from before this
	 * phase. Still used by `analyst` (its whole point) and `reports`, which
	 * additionally gets an `agents` context key alongside this snapshot (see
	 * `agent_findings()`).
	 */
	private static function full_snapshot( Range $range ): array {
		return array(
			'range'       => $range->to_array(),
			'overview'    => self::safe_call( static fn() => ( new Revenue_Provider() )->overview( $range ) ),
			'bookings'    => self::safe_call( static fn() => ( new Booking_Provider() )->analytics( $range ) ),
			'memberships' => self::safe_call( static fn() => ( new Membership_Provider() )->analytics( $range ) ),
			'store'       => self::safe_call( static fn() => ( new Store_Provider() )->analytics( $range ) ),
			'seo'         => self::safe_call( static fn() => ( new SEO_Provider() )->analytics( $range ) ),
		);
	}

	/**
	 * The `{{agents}}` context for the `reports` department — the latest
	 * finding from every OTHER department agent (ag-reports itself is
	 * excluded; it wouldn't make sense for the reporter to cite its own
	 * prior output), shaped down to just what a narration prompt needs.
	 * `promptTemplate` and any other internal field never leaves this
	 * method — only the fields a human reading the weekly report would
	 * actually care about.
	 *
	 * @internal Exposed for tests.
	 *
	 * @return array<int, array{id:string, name:string, department:string, lastOutput:string, confidence:float, lastRun:?string}>
	 */
	public static function agent_findings(): array {
		$out = array();
		foreach ( ( new Agent_Store() )->all() as $agent ) {
			$id = (string) ( $agent['id'] ?? '' );
			if ( 'ag-reports' === $id ) {
				continue;
			}
			$out[] = array(
				'id'         => $id,
				'name'       => (string) ( $agent['name'] ?? '' ),
				'department' => (string) ( $agent['department'] ?? '' ),
				'lastOutput' => (string) ( $agent['lastOutput'] ?? '' ),
				'confidence' => (float) ( $agent['confidence'] ?? 0.0 ),
				'lastRun'    => $agent['lastRun'] ?? null,
			);
		}
		return $out;
	}

	/**
	 * The `sales` department's context: a funnel-stats `snapshot` plus the
	 * actual stale/actionable `leads` list, oldest first. Knowledge is
	 * intentionally omitted — stale-lead triage doesn't lean on FAQ/pricing
	 * grounding the way booking, membership, and email replies do, and
	 * skipping it saves a brain query on every run. (Documented choice —
	 * swap in `self::safe_knowledge('sales follow-up best practices', 3)`
	 * under a `knowledge` key here if that changes.)
	 */
	private static function sales_context(): array {
		$stats = self::safe_call(
			static fn() => ( new Leads_Repository() )->stats(),
			array( 'byCategory' => array(), 'byStatus' => array() )
		);

		$items = self::safe_call(
			static fn() => self::merge_lead_queries(
				self::per_value_variants( 'status', array( 'new', 'in_progress', 'contacted' ), array( 'per_page' => 15 ) ),
				15
			)
		);
		// Leads_Repository::query() only ever orders by created_at DESC and
		// has no `order` param to flip that, so staleness ordering (oldest
		// first) happens here instead.
		usort( $items, static fn( $a, $b ) => strcmp( (string) ( $a['createdAt'] ?? '' ), (string) ( $b['createdAt'] ?? '' ) ) );
		$items = array_slice( $items, 0, 15 );

		return array(
			'snapshot' => self::sales_snapshot( $stats ),
			'leads'    => $items,
		);
	}

	/**
	 * Derive the `{{snapshot}}` figures the ag-sales prompt asks for
	 * (conversion rate, stale-lead count) from `Leads_Repository::stats()`.
	 * `staleLeadCount` is a proxy — the count of leads currently sitting in
	 * an open status (new/in_progress/contacted) — since stats() doesn't
	 * track per-lead age; the actual candidates are the `leads` context key.
	 *
	 * @internal Exposed for tests.
	 */
	public static function sales_snapshot( array $stats ): array {
		$by_status = (array) ( $stats['byStatus'] ?? array() );
		$total     = array_sum( $by_status );
		$won       = (int) ( $by_status['won'] ?? 0 );

		$open = 0;
		foreach ( array( 'new', 'in_progress', 'contacted' ) as $status ) {
			$open += (int) ( $by_status[ $status ] ?? 0 );
		}

		return array(
			'byCategory'     => $stats['byCategory'] ?? array(),
			'byStatus'       => $by_status,
			'conversionRate' => $total > 0 ? round( ( $won / $total ) * 100, 1 ) : 0.0,
			'staleLeadCount' => $open,
		);
	}

	/**
	 * Fetch + draft-reply for up to EMAIL_BATCH_SIZE open Formistic
	 * enquiries, one Anthropic call per lead (bounded, so acceptable on a
	 * cron schedule). Each success enqueues a pending-approval draft via
	 * Email_Draft_Store and flips the lead to `in_progress` so it isn't
	 * redrafted next run; a per-lead failure is logged and skipped, it does
	 * not abort the batch.
	 */
	private static function run_email_department( string $agent_id, array $agent, Anthropic_Client $client, Agent_Store $store ): void {
		$leads = self::safe_call(
			static fn() => ( new Leads_Repository() )->query(
				array(
					'source'   => 'formistic',
					'status'   => 'new',
					'per_page' => self::EMAIL_BATCH_SIZE,
				)
			)['items'] ?? array()
		);

		if ( empty( $leads ) ) {
			$store->record_run( $agent_id, 'No new enquiries to draft.', 0.0 );
			return;
		}

		$template = (string) ( $agent['promptTemplate'] ?? '' );
		$repo     = new Leads_Repository();
		$drafted  = 0;

		foreach ( $leads as $lead ) {
			try {
				$query     = self::email_knowledge_query( $lead );
				$knowledge = self::safe_knowledge( $query, 3 );
				$prompt    = self::render_prompt( $template, array( 'lead' => $lead, 'knowledge' => $knowledge ) );
				$body      = trim( $client->complete( $prompt ) );

				$fields = self::email_draft_fields( $lead );
				( new Email_Draft_Store() )->enqueue(
					$query,
					$fields['to'],
					$fields['subject'],
					$body,
					Opt_Out_Store::CATEGORY_TRANSACTIONAL
				);

				$repo->update_status( (int) ( $lead['id'] ?? 0 ), 'in_progress' );
				$drafted++;
			} catch ( \Throwable $e ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional: surfaces a per-lead draft failure without aborting the batch.
				error_log( sprintf( '[G2ABA] ag-email failed for lead #%d: %s', (int) ( $lead['id'] ?? 0 ), $e->getMessage() ) );
				continue;
			}
		}

		$store->record_run(
			$agent_id,
			sprintf( 'Drafted %d replies for %d new enquiries.', $drafted, count( $leads ) ),
			$drafted > 0 ? 0.9 : 0.0
		);
	}

	/**
	 * The Brain_Client query for one lead's per-enquiry knowledge lookup —
	 * its category plus subject line.
	 *
	 * @internal Exposed for tests.
	 *
	 * @param array<string, mixed> $lead Shaped Leads_Repository row.
	 */
	public static function email_knowledge_query( array $lead ): string {
		$category = (string) ( $lead['category'] ?? '' );
		$subject  = (string) ( $lead['subject'] ?? '' );
		return trim( $category . ' ' . $subject );
	}

	/**
	 * The `to`/`subject` fields Email_Draft_Store::enqueue() needs for one
	 * lead's reply. Uses Leads_Repository's actual shaped-row keys
	 * (`contactEmail`, `subject`) — the repository never returns
	 * snake_case field names to callers.
	 *
	 * @internal Exposed for tests.
	 *
	 * @param array<string, mixed> $lead Shaped Leads_Repository row.
	 * @return array{to:string, subject:string}
	 */
	public static function email_draft_fields( array $lead ): array {
		$subject = trim( (string) ( $lead['subject'] ?? '' ) );
		return array(
			'to'      => (string) ( $lead['contactEmail'] ?? '' ),
			'subject' => 'Re: ' . ( '' !== $subject ? $subject : 'Your enquiry to Guns 2 Ammo' ),
		);
	}

	/**
	 * Build one `Leads_Repository::query()` filter array per value of a
	 * single-valued filter key (e.g. `category` or `status`) — the
	 * repository's `query()` only accepts a scalar for either of those
	 * filters, not an array, so a "match any of these categories/statuses"
	 * lookup has to be expressed as several single-value queries merged by
	 * the caller instead of one multi-value query.
	 *
	 * @internal Exposed for tests.
	 *
	 * @param array<int, string>   $values Scalars to fan out over.
	 * @param array<string, mixed> $common Shared filter args merged into each variant.
	 * @return array<int, array<string, mixed>>
	 */
	public static function per_value_variants( string $key, array $values, array $common = array() ): array {
		$variants = array();
		foreach ( $values as $value ) {
			$variants[] = array_merge( $common, array( $key => $value ) );
		}
		return $variants;
	}

	/**
	 * Run several Leads_Repository::query() filter variants and merge the
	 * results, de-duplicating by lead id and capping at $limit. Order of the
	 * merged list is whatever Leads_Repository::query() returns (created_at
	 * DESC) — callers that need a different order (e.g. staleness, oldest
	 * first) re-sort after calling this.
	 *
	 * @internal Exposed for tests.
	 *
	 * @param array<int, array<string, mixed>> $filters_variants
	 */
	public static function merge_lead_queries( array $filters_variants, int $limit ): array {
		$repo = new Leads_Repository();
		$seen = array();
		$out  = array();

		foreach ( $filters_variants as $filters ) {
			try {
				$result = $repo->query( $filters );
			} catch ( \Throwable $e ) {
				continue;
			}
			foreach ( (array) ( $result['items'] ?? array() ) as $item ) {
				$id = (int) ( $item['id'] ?? 0 );
				if ( $id > 0 ) {
					if ( isset( $seen[ $id ] ) ) {
						continue;
					}
					$seen[ $id ] = true;
				}
				$out[] = $item;
			}
		}

		return array_slice( $out, 0, $limit );
	}

	/**
	 * `Brain_Client::retrieve()` never throws (see its own contract), but
	 * this call site wraps it anyway per the defensive-degradation rule for
	 * this runner, and normalises the return to a bare results array.
	 */
	private static function safe_knowledge( string $query, int $k ): array {
		$out = self::safe_call( static fn() => ( new Brain_Client() )->retrieve( $query, $k ), array( 'results' => array() ) );
		return (array) ( $out['results'] ?? array() );
	}

	/**
	 * Run $fn, returning $default if it throws. Every provider/repository
	 * call in this class goes through here so one missing table or
	 * unavailable integration degrades only its own slice of context
	 * instead of aborting the whole agent run.
	 */
	private static function safe_call( callable $fn, $default = array() ) {
		try {
			return $fn();
		} catch ( \Throwable $e ) {
			return $default;
		}
	}

	private static function truncate( string $text, int $max = 4000 ): string {
		$text = trim( $text );
		return strlen( $text ) > $max ? substr( $text, 0, $max ) . '…' : $text;
	}
}
