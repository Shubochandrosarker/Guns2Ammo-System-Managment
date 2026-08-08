<?php

namespace G2A\POS\Ai;

use G2A\POS\Database\AmmoRentalRepository;
use G2A\POS\Database\FirearmRentalRepository;
use G2A\POS\Database\MembershipRepository;
use G2A\POS\Database\PurchaseOrderRepository;
use G2A\POS\Database\RangeIncidentRepository;
use G2A\POS\Reporting\KpiService;
use G2A\POS\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Feeds the brain with the state of the business, not just the website.
 *
 * WebsiteKnowledgeSeeder already ingests pages, products and a curated pack —
 * everything a customer could read. That makes the assistant good at "what are
 * your range hours" and useless at "what needs my attention today", because
 * nothing operational was ever in the index. This collector closes that gap by
 * writing a rolling operational picture into the same brain, so the assistant
 * can monitor the business rather than describe it.
 *
 * WHAT IS DELIBERATELY NOT INGESTED
 * ---------------------------------
 * No customer PII. No names, emails, phone numbers, addresses, ID numbers or
 * firearm serial numbers. Three reasons, in order of weight:
 *
 *   1. g2a-rag-worker stores a `scope` on every chunk but its /query does not
 *      filter on it, so anything ingested is retrievable by any caller holding
 *      the token. Treat the index as readable by everyone who can chat.
 *   2. A vector store is the wrong tool for individual lookups anyway. "What
 *      did customer 412 buy" should be an indexed SQL query through the
 *      dashboard, which is exact; semantic search over it would be
 *      approximate, which is worse than useless for a purchase record.
 *   3. Serial numbers tie to bound-book entries. Those belong in the A&D
 *      record and the 4473, not in an embedding index.
 *
 * So every collector below emits aggregates, counts and operational state.
 * That is what makes the assistant useful for monitoring, and it happens to be
 * the only shape that is safe to put here.
 *
 * Each collector is isolated: one that throws is logged and skipped, and the
 * rest of the snapshot still lands. A half-refreshed brain beats none.
 *
 * BrainService::ingest_text hashes the body and no-ops when it is unchanged,
 * so running this on a schedule does not re-embed a quiet day.
 */
final class BusinessKnowledgeCollector {

	public const CRON_HOOK = 'g2a_pos_brain_business_refresh';

	private const TAG    = 'g2a-business-state';
	private const SOURCE = 'business_state';

	/** Currency formatting only; the brain reads prose, not floats. */
	private static function money( $value ): string {
		return '$' . number_format( (float) $value, 2 );
	}

	private static function pct( $value ): string {
		return number_format( (float) $value, 1 ) . '%';
	}

	public static function boot(): void {
		add_action( self::CRON_HOOK, array( self::class, 'refresh' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Run every collector and push the results into the brain.
	 *
	 * @return array{ok:bool,ingested:int,skipped:int,failed:array<string,string>}
	 */
	public static function refresh(): array {
		$collectors = array(
			'trading'    => array( self::class, 'collect_trading' ),
			'compliance' => array( self::class, 'collect_compliance' ),
			'range'      => array( self::class, 'collect_range' ),
			'inventory'  => array( self::class, 'collect_inventory' ),
			'membership' => array( self::class, 'collect_membership' ),
			'suppliers'  => array( self::class, 'collect_suppliers' ),
		);

		$ingested = 0;
		$skipped  = 0;
		$failed   = array();

		foreach ( $collectors as $name => $callable ) {
			try {
				$doc = call_user_func( $callable );
				if ( ! $doc || empty( $doc['body'] ) ) {
					++$skipped;
					continue;
				}

				$res = BrainService::ingest_text(
					$doc['label'],
					$doc['body'],
					array(
						'source_type' => self::SOURCE,
						'source_uri'  => 'g2a://business/' . $name,
						'tags'        => self::TAG . ',' . $name,
						'scope'       => 'internal',
					)
				);

				if ( ! empty( $res['ok'] ) ) {
					++$ingested;
				} else {
					$failed[ $name ] = (string) ( $res['error'] ?? 'ingest_failed' );
				}
			} catch ( \Throwable $e ) {
				// One bad collector must not cost the whole refresh.
				$failed[ $name ] = $e->getMessage();
				Logger::error( 'brain business collector failed', array( 'collector' => $name, 'error' => $e->getMessage() ) );
			}
		}

		update_option(
			'g2a_pos_brain_business_last_run',
			array(
				'at'       => current_time( 'mysql' ),
				'ingested' => $ingested,
				'skipped'  => $skipped,
				'failed'   => $failed,
			),
			false
		);

		return array(
			'ok'       => empty( $failed ),
			'ingested' => $ingested,
			'skipped'  => $skipped,
			'failed'   => $failed,
		);
	}

	// ── Collectors ──────────────────────────────────────────────────────────

	/**
	 * Trading position. Written as prose with the figures inline because that
	 * is what retrieves well — a bare JSON blob embeds poorly and reads worse
	 * when the model quotes it back.
	 */
	private static function collect_trading(): array {
		$kpi = ( new KpiService() )->snapshot();

		$today  = $kpi['today'] ?? array();
		$m      = $kpi['last_30_days'] ?? array();
		$ops    = $kpi['operations'] ?? array();
		$as_of  = (string) ( $kpi['as_of'] ?? current_time( 'mysql' ) );

		$lines = array(
			'Guns2Ammo trading position as of ' . $as_of . '.',
			'',
			'Today: ' . (int) ( $today['orders'] ?? 0 ) . ' orders totalling ' . self::money( $today['revenue'] ?? 0 ) . '.',
			'Last 30 days: revenue ' . self::money( $m['revenue'] ?? 0 )
				. ', cost of goods ' . self::money( $m['cogs'] ?? 0 )
				. ', gross margin ' . self::pct( $m['gross_margin_pct'] ?? 0 ) . '.',
			'Attach rate over the last 30 days (firearm sales that also included ammo, an optic, a holster or another accessory): '
				. self::pct( $m['attach_rate_pct'] ?? 0 ) . '.',
			'',
			'Open register sessions right now: ' . (int) ( $ops['open_registers'] ?? 0 ) . '.',
			'Outstanding layaway balance: ' . self::money( $ops['layaway_balance'] ?? 0 ) . '.',
		);

		// Margin is computed against wholesale_price matched on SKU. When the
		// vendor catalog has no match the cost lands at zero and the margin
		// reads as 100%, which is a data gap rather than a great month. Say so
		// here so the assistant repeats the caveat instead of the fiction.
		if ( (float) ( $m['gross_margin_pct'] ?? 0 ) >= 95.0 && (float) ( $m['cogs'] ?? 0 ) <= 0.0 ) {
			$lines[] = '';
			$lines[] = 'CAVEAT: cost of goods is zero for this period, so the gross margin figure is not '
				. 'trustworthy. Cost is matched from the wholesaler catalog on SKU; a zero means those '
				. 'products are not matched, not that they were free. Treat margin as unknown until the '
				. 'vendor catalog is linked.';
		}

		return array(
			'label' => 'Business state — trading and revenue',
			'body'  => implode( "\n", $lines ),
		);
	}

	/** Compliance posture: counts only, never a name or a serial. */
	private static function collect_compliance(): array {
		$kpi = ( new KpiService() )->snapshot();
		$c   = $kpi['compliance'] ?? array();

		$nics = (int) ( $c['nics_open'] ?? 0 );
		$open = (int) ( $c['open_4473'] ?? 0 );

		$lines = array(
			'Guns2Ammo compliance posture as of ' . (string) ( $kpi['as_of'] ?? current_time( 'mysql' ) ) . '.',
			'',
			'Open NICS/background checks awaiting a result: ' . $nics . '.',
			'Form 4473 records still open (started but not completed): ' . $open . '.',
			'',
			'These are counts only. Individual 4473s, transferee details and firearm serial numbers are '
				. 'deliberately not in this knowledge base — they live in the bound book and the 4473 '
				. 'records, and must be read there.',
			'',
			'No figure here authorises a transfer. Whether a sale may proceed is decided by a licensed '
				. 'person against the actual record, never by this assistant.',
		);

		if ( $nics > 0 ) {
			$lines[] = '';
			$lines[] = 'ATTENTION: ' . $nics . ' background check' . ( 1 === $nics ? '' : 's' )
				. ' are open. Each one is a customer waiting on a firearm.';
		}

		return array(
			'label' => 'Business state — compliance posture',
			'body'  => implode( "\n", $lines ),
		);
	}

	/** Range floor: what is out, what is overdue, what is broken. */
	private static function collect_range(): array {
		$firearms = ( new FirearmRentalRepository() )->list_open();
		$ammo     = ( new AmmoRentalRepository() )->list_open();

		$overdue = 0;
		foreach ( $firearms as $rental ) {
			if ( ( $rental['status'] ?? '' ) === FirearmRentalRepository::STATUS_OVERDUE ) {
				++$overdue;
			}
		}

		$incidents = array();
		try {
			$incidents = ( new RangeIncidentRepository() )->list( array( 'status' => 'open' ) );
		} catch ( \Throwable $e ) {
			$incidents = array();
		}

		$lines = array(
			'Guns2Ammo range operations as of ' . current_time( 'mysql' ) . '.',
			'',
			'Firearm rentals currently out: ' . count( $firearms ) . '.',
			'Of those, marked overdue: ' . $overdue . '.',
			'Ammo rentals currently open: ' . count( $ammo ) . '.',
			'Open range incidents: ' . count( $incidents ) . '.',
			'',
			'A rented firearm is a serialised item that has physically left the counter. Every one on '
				. 'that list is expected back. An overdue rental is chased, not written off.',
			'',
			'Rules that do not bend: a firearm is never issued without a verified photo ID and a signed, '
				. 'current range waiver, and the same serial is never issued twice at once.',
		);

		if ( $overdue > 0 ) {
			$lines[] = '';
			$lines[] = 'ATTENTION: ' . $overdue . ' firearm rental' . ( 1 === $overdue ? ' is' : 's are' )
				. ' overdue and should be chased today.';
		}

		return array(
			'label' => 'Business state — range operations',
			'body'  => implode( "\n", $lines ),
		);
	}

	/** Stock health. */
	private static function collect_inventory(): array {
		$kpi = ( new KpiService() )->snapshot();
		$inv = $kpi['inventory'] ?? array();

		$dead = (int) ( $inv['dead_stock_90d'] ?? 0 );

		$lines = array(
			'Guns2Ammo inventory position as of ' . (string) ( $kpi['as_of'] ?? current_time( 'mysql' ) ) . '.',
			'',
			'Serialised firearms in stock: ' . (int) ( $inv['serials_in_stock'] ?? 0 ) . '.',
			'Lines with no sale in the last 90 days (dead stock): ' . $dead . '.',
			'',
			'Dead stock is capital sitting on a shelf. It is a candidate for markdown, a bundle, or a '
				. 'return to the distributor where the terms allow it.',
		);

		if ( $dead > 0 ) {
			$lines[] = '';
			$lines[] = 'ATTENTION: ' . $dead . ' product line' . ( 1 === $dead ? '' : 's' )
				. ' have not sold in 90 days.';
		}

		return array(
			'label' => 'Business state — inventory',
			'body'  => implode( "\n", $lines ),
		);
	}

	/** Membership base, in aggregate. */
	private static function collect_membership(): array {
		$active = array();
		try {
			$active = ( new MembershipRepository() )->list_active( 500 );
		} catch ( \Throwable $e ) {
			$active = array();
		}

		$by_plan = array();
		foreach ( $active as $row ) {
			$plan             = (string) ( $row['plan_name'] ?? $row['plan'] ?? 'unnamed plan' );
			$by_plan[ $plan ] = ( $by_plan[ $plan ] ?? 0 ) + 1;
		}
		arsort( $by_plan );

		$lines = array(
			'Guns2Ammo membership base as of ' . current_time( 'mysql' ) . '.',
			'',
			'Active memberships: ' . count( $active ) . '.',
		);

		if ( $by_plan ) {
			$lines[] = '';
			$lines[] = 'By plan:';
			foreach ( $by_plan as $plan => $count ) {
				$lines[] = '- ' . $plan . ': ' . $count;
			}
		}

		$lines[] = '';
		$lines[] = 'Members and walk-ins are charged differently for lane time. When a lane fee looks '
			. 'wrong, check the member\'s entitlement for that booking type before assuming a pricing bug — '
			. 'a plan that does not include a booking type is charged at the walk-in rate by design.';
		$lines[] = '';
		$lines[] = 'Individual member names and contact details are not in this knowledge base.';

		return array(
			'label' => 'Business state — membership',
			'body'  => implode( "\n", $lines ),
		);
	}

	/** Supplier reliability, from the vendor scorecard. */
	private static function collect_suppliers(): array {
		$rows = array();
		try {
			$rows = ( new KpiService() )->vendor_scorecard( 90 );
		} catch ( \Throwable $e ) {
			$rows = array();
		}

		$open_pos = 0;
		try {
			$open_pos = count( ( new PurchaseOrderRepository() )->list( array( 'status' => 'submitted' ) ) );
		} catch ( \Throwable $e ) {
			$open_pos = 0;
		}

		$lines = array(
			'Guns2Ammo supplier performance over the last 90 days, as of ' . current_time( 'mysql' ) . '.',
			'',
			'Purchase orders submitted and not yet fully received: ' . $open_pos . '.',
		);

		if ( $rows ) {
			$lines[] = '';
			$lines[] = 'By wholesaler:';
			foreach ( array_slice( $rows, 0, 12 ) as $r ) {
				$name  = (string) ( $r['display_name'] ?? $r['provider_code'] ?? 'unknown' );
				$count = (int) ( $r['order_count'] ?? 0 );
				if ( 0 === $count ) {
					$lines[] = '- ' . $name . ': no orders in this period.';
					continue;
				}
				$ship    = null !== ( $r['avg_ship_hours'] ?? null ) ? round( (float) $r['avg_ship_hours'], 1 ) : null;
				$line    = '- ' . $name . ': ' . $count . ' orders, ' . (int) ( $r['fulfilled'] ?? 0 ) . ' fulfilled';
				$lines[] = null === $ship ? $line . '.' : $line . ', average ' . $ship . 'h to ship.';
			}
		}

		return array(
			'label' => 'Business state — suppliers and purchasing',
			'body'  => implode( "\n", $lines ),
		);
	}
}
