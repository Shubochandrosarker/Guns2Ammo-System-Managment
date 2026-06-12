<?php

namespace G2A\POS\Ai;

use G2A\POS\Database\AiBrainRepository;
use G2A\POS\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Idempotent seeder for the AI RAG brain. Inserts/updates a set of
 * site-default knowledge documents (business facts, services + prices,
 * policies, staff, page map) plus dynamic snapshots (Woo catalog,
 * Memberistic plans, upcoming bookings) so a fresh install can answer
 * questions about the business with ANY configured model.
 *
 * Documents are stored through BrainService::ingest_text, which embeds
 * chunks when a live embedding endpoint is configured and otherwise stores
 * plain text the retriever's substring fallback (search_text) already
 * serves. Adding a local model later just means re-running refresh().
 *
 * Idempotency: each seeded document is keyed by its source_label and
 * tagged `site-default`. Unchanged content (same SHA-256 of the normalized
 * body) is skipped entirely — re-ingesting an identical document would
 * duplicate its chunks. Changed content deletes the stale document first,
 * and labels no longer in the set are pruned.
 */
final class DefaultKnowledgeSeeder {

	public const TAG       = 'site-default';
	public const CRON_HOOK = 'g2a_pos_ai_knowledge_refresh';

	/**
	 * Sync the site-default knowledge set. Safe to run repeatedly.
	 *
	 * @return array{ok:bool,seeded:int,updated:int,skipped:int,pruned:int,errors:array<int,string>}
	 */
	public static function refresh(): array {
		$summary = array(
			'ok'      => true,
			'seeded'  => 0,
			'updated' => 0,
			'skipped' => 0,
			'pruned'  => 0,
			'errors'  => array(),
		);

		try {
			$existing = self::existing_default_documents();
			$repo     = new AiBrainRepository();
			$docs     = array_merge( self::static_documents(), self::dynamic_documents() );
			$labels   = array();

			foreach ( $docs as $doc ) {
				$label    = (string) $doc['label'];
				$labels[] = $label;
				$body     = BrainService::normalize( (string) $doc['body'] );
				if ( $body === '' ) {
					continue;
				}
				$hash = hash( 'sha256', $body );
				$prev = $existing[ $label ] ?? null;

				if ( $prev && $prev['content_hash'] === $hash ) {
					++$summary['skipped'];
					continue;
				}
				if ( $prev ) {
					// Content changed: drop the stale document (and chunks)
					// before re-ingesting, so retrieval never sees old facts.
					$repo->delete_document( (int) $prev['id'] );
				}
				$res = BrainService::ingest_text(
					$label,
					$body,
					array(
						'source_type' => self::TAG,
						'tags'        => self::TAG . ',' . (string) ( $doc['tags'] ?? '' ),
						'scope'       => (string) ( $doc['scope'] ?? 'public' ),
						'metadata'    => array( 'seeder' => self::TAG ),
					)
				);
				if ( empty( $res['ok'] ) ) {
					$summary['errors'][] = $label . ': ' . (string) ( $res['error'] ?? 'ingest_failed' );
					continue;
				}
				$prev ? ++$summary['updated'] : ++$summary['seeded'];
			}

			// Prune site-default documents whose label left the set.
			foreach ( $existing as $label => $row ) {
				if ( ! in_array( $label, $labels, true ) ) {
					$repo->delete_document( (int) $row['id'] );
					++$summary['pruned'];
				}
			}
		} catch ( \Throwable $e ) {
			$summary['ok']       = false;
			$summary['errors'][] = $e->getMessage();
			Logger::exception( 'Default knowledge refresh failed', $e );
		}

		return $summary;
	}

	/** Cron entry point. */
	public static function cron_refresh(): void {
		self::refresh();
	}

	/** @return array<string,array{id:int,content_hash:string}> keyed by source_label */
	private static function existing_default_documents(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, source_label, content_hash
                 FROM {$wpdb->prefix}g2a_ai_brain_documents
                 WHERE source_type = %s OR tags LIKE %s",
				self::TAG,
				'%' . $wpdb->esc_like( self::TAG ) . '%'
			),
			ARRAY_A
		) ?: array();
		$map  = array();
		foreach ( $rows as $row ) {
			$map[ (string) $row['source_label'] ] = array(
				'id'           => (int) $row['id'],
				'content_hash' => (string) $row['content_hash'],
			);
		}
		return $map;
	}

	// ---- Static knowledge --------------------------------------------------

	/** @return array<int,array{label:string,body:string,tags?:string,scope?:string}> */
	private static function static_documents(): array {
		return array(
			array(
				'label' => 'Guns 2 Ammo — Business Facts',
				'tags'  => 'business,location,hours',
				'body'  => <<<'TXT'
Guns 2 Ammo is an indoor shooting range, gun store, and FFL dealer located at
6030 E Main St Ste 103, Mesa, AZ 85205. Phone: (602) 715-2677. Serving the
East Valley since 2014.

The range has 6 shooting lanes. Lanes 1 and 2 are ADA accessible.

Hours of operation (Arizona MST, no daylight saving):
- Monday through Thursday: 10:00 AM – 6:00 PM
- Friday and Saturday: 10:00 AM – 7:00 PM
- Sunday: 12:00 PM – 6:00 PM
TXT,
			),
			array(
				'label' => 'Guns 2 Ammo — Services and Prices',
				'tags'  => 'services,pricing,range,classes,transfers,nfa',
				'body'  => <<<'TXT'
Range lane rental: $20 per hour per lane. Extra shooter on the same lane: $15.
Firearm rentals start at $15. Eye and ear protection rental: $3.

Training classes:
- Arizona CCW classroom course: $85 for a 4-hour class, held on Saturdays.
- Arizona CCW Live Fire course: $149.99 for a 5-hour course, held Monday evenings.
- Private one-on-one instruction: $140 per hour.

FFL transfers: $35 per firearm transfer.
NFA transfers: $95 for suppressors, $295 for full-auto (machine gun) transfers.

Machine gun rental experience packages: $249, $449, and $749 packages.
Available full-auto rentals include the MP5 (9×19mm), M16 (5.56×45mm), and
AK-47 (7.62×39mm).

Memberships (per month): Ranger $29.99, Operator $39.99, Legacy $59.99.

Ladies Tuesday: every Tuesday, women shoot free for 1 hour of lane time and
get 25% off firearm rentals.
TXT,
			),
			array(
				'label' => 'Guns 2 Ammo — Range Policies',
				'tags'  => 'policies,safety,age,ammo',
				'body'  => <<<'TXT'
Range policies at Guns 2 Ammo:
- Shooters age 8 and older may shoot when supervised by a parent or guardian.
  Shooters must be 18 or older to shoot solo.
- Eye and ear protection is required at all times on the range floor.
- Prohibited ammunition: no steel-core ammo, no tracer rounds, and no
  steel-cased ammunition on the range.
TXT,
			),
			array(
				'label' => 'Guns 2 Ammo — Staff and Instructors',
				'tags'  => 'staff,instructors,training',
				'body'  => <<<'TXT'
Guns 2 Ammo staff and instructors:
- Nicholas Steigert, lead firearms instructor. NRA certified instructor and
  a United States Marine Corps combat veteran (3rd Battalion, 7th Marines).
- Alen Olson, firearms instructor. Retired Mesa Police Department firearms
  instructor, NRA certified.
TXT,
			),
			array(
				'label' => 'Guns 2 Ammo — Website Page Map',
				'tags'  => 'website,pages,navigation',
				'body'  => <<<'TXT'
Key pages on the Guns 2 Ammo website (link customers to these):
- /book-a-lane — reserve a shooting lane online.
- /training — CCW classes and private instruction schedule and signup.
- /machine-gun — machine gun rental experience packages.
- /memberships — Ranger, Operator, and Legacy membership signup.
- /transfers — FFL and NFA transfer information and inbound transfer form.
- /sell-your-gun — sell or trade in a used firearm.
- /ladies-tuesday — Ladies Tuesday weekly promotion details.
- /contact — location, hours, phone, and contact form.
TXT,
			),
		);
	}

	// ---- Dynamic snapshots ---------------------------------------------------

	/** @return array<int,array{label:string,body:string,tags?:string,scope?:string}> */
	private static function dynamic_documents(): array {
		$docs = array();

		$catalog = self::woo_catalog_snapshot();
		if ( $catalog !== '' ) {
			$docs[] = array(
				'label' => 'Guns 2 Ammo — Store Catalog Snapshot',
				'tags'  => 'catalog,products,dynamic',
				'body'  => $catalog,
			);
		}

		$plans = self::memberistic_plans_snapshot();
		if ( $plans !== '' ) {
			$docs[] = array(
				'label' => 'Guns 2 Ammo — Active Membership Plans',
				'tags'  => 'memberships,plans,dynamic',
				'body'  => $plans,
			);
		}

		$bookings = self::bookings_snapshot();
		if ( $bookings !== '' ) {
			$docs[] = array(
				'label' => 'Guns 2 Ammo — Upcoming Bookings Snapshot',
				'tags'  => 'bookings,range,dynamic',
				'body'  => $bookings,
			);
		}

		return $docs;
	}

	private static function woo_catalog_snapshot(): string {
		if ( ! function_exists( 'wp_count_posts' ) || ! post_type_exists( 'product' ) ) {
			return '';
		}
		$counts    = wp_count_posts( 'product' );
		$published = (int) ( $counts->publish ?? 0 );
		if ( $published <= 0 ) {
			return '';
		}
		$lines   = array(
			sprintf( 'The online store currently lists %d published products.', $published ),
		);
		$top_cats = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => 8,
				'hide_empty' => true,
			)
		);
		if ( is_array( $top_cats ) && $top_cats ) {
			$bits = array();
			foreach ( $top_cats as $term ) {
				$bits[] = sprintf( '%s (%d)', $term->name, (int) $term->count );
			}
			$lines[] = 'Top product categories: ' . implode( ', ', $bits ) . '.';
		}
		return implode( "\n", $lines );
	}

	private static function memberistic_plans_snapshot(): string {
		global $wpdb;
		$table = $wpdb->prefix . 'memberistic_plans';
		if ( ! self::table_exists( $table ) ) {
			return '';
		}
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$select  = array( 'name', 'slug' );
		foreach ( array( 'monthly_price', 'annual_price', 'price', 'benefits', 'status' ) as $optional ) {
			if ( in_array( $optional, $columns, true ) ) {
				$select[] = $optional;
			}
		}
		$rows = $wpdb->get_results(
			'SELECT ' . implode( ', ', $select ) . " FROM {$table} LIMIT 25", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		) ?: array();
		if ( ! $rows ) {
			return '';
		}
		$lines = array( 'Live membership plans currently configured on this site:' );
		foreach ( $rows as $row ) {
			if ( isset( $row['status'] ) && ! in_array( strtolower( (string) $row['status'] ), array( 'active', 'published', '' ), true ) ) {
				continue;
			}
			$line = '- ' . (string) $row['name'];
			if ( ! empty( $row['monthly_price'] ) ) {
				$line .= sprintf( ': $%.2f/month', (float) $row['monthly_price'] );
			} elseif ( ! empty( $row['price'] ) ) {
				$line .= sprintf( ': $%.2f', (float) $row['price'] );
			}
			if ( ! empty( $row['annual_price'] ) ) {
				$line .= sprintf( ' (or $%.2f/year)', (float) $row['annual_price'] );
			}
			if ( ! empty( $row['benefits'] ) ) {
				$benefits = json_decode( (string) $row['benefits'], true );
				if ( is_array( $benefits ) && $benefits ) {
					$flat = array();
					foreach ( $benefits as $k => $v ) {
						$flat[] = is_scalar( $v ) ? ( is_string( $k ) ? "$k: $v" : (string) $v ) : (string) $k;
					}
					$line .= '. Benefits: ' . implode( '; ', array_slice( $flat, 0, 8 ) );
				}
			}
			$lines[] = $line;
		}
		return count( $lines ) > 1 ? implode( "\n", $lines ) : '';
	}

	private static function bookings_snapshot(): string {
		global $wpdb;
		$table = $wpdb->prefix . 'g2ab_bookings';
		if ( ! self::table_exists( $table ) ) {
			return '';
		}
		$rows = $wpdb->get_results(
			"SELECT status, COUNT(*) AS c, MIN(start_at) AS soonest
             FROM {$table}
             WHERE start_at >= NOW() AND status NOT IN ('cancelled','no_show','expired')
             GROUP BY status", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		) ?: array();
		if ( ! $rows ) {
			return '';
		}
		$total = 0;
		$bits  = array();
		foreach ( $rows as $row ) {
			$total += (int) $row['c'];
			$bits[] = sprintf( '%s: %d (next on %s)', (string) $row['status'], (int) $row['c'], (string) $row['soonest'] );
		}
		return sprintf(
			"The booking engine currently has %d upcoming reservations.\nBy status — %s.",
			$total,
			implode( '; ', $bits )
		);
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}
}
