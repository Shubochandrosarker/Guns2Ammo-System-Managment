<?php

namespace G2A\POS\Ai;

use G2A\POS\Database\AiBrainRepository;
use G2A\POS\Support\Logger;
use G2A\POS\Support\SafeHttp;

defined( 'ABSPATH' ) || exit;

/**
 * Default website knowledge for the on-prem AI agent.
 *
 * Ships a curated knowledge pack about Guns 2 Ammo (business facts, services
 * & pricing, page URLs, range policies, POS how-tos) and ingests it through
 * BrainService::ingest_text, so the agent can answer store questions
 * immediately — including in stub/substring-retrieval mode with no embedding
 * endpoint configured. When a live embed endpoint exists, chunks are embedded
 * automatically on ingest (BrainService handles that), so re-running the
 * refresh after configuring a local model re-embeds everything.
 *
 * - Seeded once per SEED_VERSION on activation/upgrade
 *   (option g2a_pos_brain_seeded_version).
 * - POST /ai/brain/refresh-site re-ingests, preferring live site content
 *   from home_url('/llms-full.txt') (the theme publishes it), chunked by
 *   section; the curated pack is always (re)ingested as the baseline.
 * - A full-site crawl (every published page/post/product) runs on each
 *   refresh so the brain reflects the real website.
 * - Nightly cron (g2a_pos_brain_site_refresh) runs the same refresh.
 */
final class WebsiteKnowledgeSeeder {

	public const CRON_HOOK    = 'g2a_pos_brain_site_refresh';
	public const SEED_VERSION = '2026-06-29.1';

	private const OPTION_SEEDED = 'g2a_pos_brain_seeded_version';
	private const TAG_DEFAULT   = 'g2a-website-default';
	private const TAG_LIVE      = 'g2a-website-live';

	public static function boot(): void {
		add_action( self::CRON_HOOK, array( self::class, 'cron_refresh' ) );
	}

	/** Seed once per SEED_VERSION (activation / upgrade safe). */
	public static function maybe_seed(): void {
		if ( get_option( self::OPTION_SEEDED ) === self::SEED_VERSION ) {
			return;
		}
		try {
			self::seed_default_pack();
			update_option( self::OPTION_SEEDED, self::SEED_VERSION, false );
		} catch ( \Throwable $e ) {
			Logger::exception( 'Website knowledge seeding failed', $e );
		}
	}

	public static function cron_refresh(): void {
		try {
			self::refresh();
		} catch ( \Throwable $e ) {
			Logger::exception( 'Website knowledge refresh failed', $e );
		}
	}

	/**
	 * Re-ingest everything: curated pack + live /llms-full.txt when reachable.
	 * Old website documents are removed first so the brain never accumulates
	 * stale duplicates.
	 *
	 * @return array{ok:bool,default_sections:int,live_sections:int,chunks:int,live_source:?string}
	 */
	public static function refresh(): array {
		$result = array(
			'ok'               => true,
			'default_sections' => 0,
			'live_sections'    => 0,
			'crawled_pages'    => 0,
			'chunks'           => 0,
			'live_source'      => null,
		);

		$result['default_sections'] = self::seed_default_pack( $result['chunks'] );

		// Refresh all live-tagged docs in one pass: purge once, then re-ingest
		// the DB snapshot and the website copy so neither deletes the other.
		self::purge_tag( self::TAG_LIVE );

		// Authoritative live snapshot straight from the database: the real
		// Memberistic plan names/prices and the current WooCommerce catalog
		// size. This overrides any plan names guessed in the static pack.
		$snapshot = self::live_data_doc();
		if ( $snapshot !== '' ) {
			$r = BrainService::ingest_text(
				'Guns 2 Ammo — current membership plans and catalog (live)',
				$snapshot,
				array(
					'source_type' => 'seed',
					'tags'        => self::TAG_LIVE,
					'scope'       => 'public',
				)
			);
			if ( ! empty( $r['ok'] ) ) {
				++$result['live_sections'];
				$result['chunks'] += (int) ( $r['chunks'] ?? 0 );
			}
		}

		// Live site content — the theme serves /llms-full.txt with current copy.
		$url  = function_exists( 'home_url' ) ? home_url( '/llms-full.txt' ) : '';
		$body = $url ? self::fetch( $url ) : null;
		if ( $body !== null && $body !== '' ) {
			$sections = self::split_sections( $body );
			foreach ( $sections as $i => $section ) {
				$r = BrainService::ingest_text(
					'Website: ' . $section['title'],
					$section['body'],
					array(
						'source_type' => 'url',
						'source_uri'  => $url . '#' . ( $i + 1 ),
						'tags'        => self::TAG_LIVE,
						'scope'       => 'public',
					)
				);
				if ( ! empty( $r['ok'] ) ) {
					++$result['live_sections'];
					$result['chunks'] += (int) ( $r['chunks'] ?? 0 );
				}
			}
			$result['live_source'] = $url;
		}

		// Full-site crawl: index every published page/post/product so the brain
		// reflects the actual website, not only the curated pack and the theme's
		// /llms-full.txt. Tagged live, so it refreshes (replaces) on each run.
		self::crawl_published_content( $result );

		update_option( self::OPTION_SEEDED, self::SEED_VERSION, false );
		update_option(
			'g2a_pos_brain_site_refreshed_at',
			array(
				'at'     => current_time( 'mysql' ),
				'result' => $result,
			),
			false
		);
		return $result;
	}

	/** Ingest the curated pack (replacing any previous copy). Returns section count. */
	private static function seed_default_pack( int &$chunks = 0 ): int {
		self::purge_tag( self::TAG_DEFAULT );
		$count = 0;
		foreach ( self::default_pack() as $label => $body ) {
			$r = BrainService::ingest_text(
				$label,
				$body,
				array(
					'source_type' => 'seed',
					'tags'        => self::TAG_DEFAULT,
					'scope'       => 'public',
				)
			);
			if ( ! empty( $r['ok'] ) ) {
				++$count;
				$chunks += (int) ( $r['chunks'] ?? 0 );
			}
		}
		return $count;
	}

	/**
	 * The curated knowledge pack. Business facts come from the theme's
	 * g2a_biz() (single source of truth) when the theme is active, with
	 * baked-in fallbacks so the pack is complete on any install.
	 *
	 * @return array<string,string> label => body
	 */
	public static function default_pack(): array {
		$biz = self::business_facts();

		$home = function_exists( 'home_url' ) ? rtrim( home_url( '/' ), '/' ) : 'https://guns2ammo.com';

		return array(
			'Guns 2 Ammo — business facts'   =>
				"Guns 2 Ammo is {$biz['city']}'s indoor shooting range, FFL firearm store, and NRA-certified training facility, serving the East Valley since {$biz['founded_year']}.\n"
				. "Address: {$biz['address']}.\n"
				. "Phone: {$biz['phone']}. Email: {$biz['email']}.\n"
				. "The range has 6 climate-controlled shooting lanes (25 yards, rated up to .50 BMG rifle).\n"
				. "Hours: {$biz['hours']}.\n"
				. 'Walk-ins are welcome; lanes can also be booked online.',

			'Guns 2 Ammo — services and pricing' =>
				"Range lane rental: \$20 per hour per lane. Extra shooter on the same lane: \$15. Eye and ear protection: \$3 combined.\n"
				. "Gun rentals: from \$15 (handguns and rifles available; rental guns must use range-purchased ammunition).\n"
				. "Training: Arizona CCW classroom class \$85 (4 hours, Saturdays, includes fingerprinting guidance). Arizona CCW + Live Fire \$149.99 (5 hours, Monday evenings). Basic handgun class \$95. Private one-on-one instruction \$140 per hour with an NRA-certified instructor.\n"
				. "FFL transfers: \$35 per firearm. NFA transfers: \$95 suppressor/SBR, \$295 full-auto.\n"
				. "Machine gun experience packages: \$249 (Basic), \$449 (Premium), \$749 (Elite) — full-auto rentals (MP5 9×19mm, M16 5.56×45mm, AK-47 7.62×39mm) with one-on-one range officer supervision; ammo and targets included.\n"
				. "Memberships (as published on the storefront): Defender, Patriot, and Guardian tiers. Members get free or discounted lane time, guest passes, rental discounts, and store discounts depending on tier. NOTE: the live 'Guns 2 Ammo — current membership plans' document below reflects the exact plan names and prices configured in Memberistic and takes precedence over this summary.\n"
				. "Ladies Tuesday: women shoot a free 1-hour lane every Tuesday, with 25% off rentals; no membership required.",

			'Guns 2 Ammo — website pages'    =>
				"Key pages on {$home}:\n"
				. "- {$home}/book-a-lane/ — book a shooting lane online\n"
				. "- {$home}/training/ — classes (CCW, basic handgun, private instruction)\n"
				. "- {$home}/machine-gun/ — machine gun experience packages\n"
				. "- {$home}/memberships/ — membership tiers and sign-up\n"
				. "- {$home}/transfers/ — FFL transfer instructions and status\n"
				. "- {$home}/shop/ — online store (firearms, ammo, accessories)\n"
				. "- {$home}/contact/ — contact form, map, and hours\n"
				. 'Customers asking where to book, sign up, or check transfer status should be pointed at these URLs.',

			'Guns 2 Ammo — range policies'   =>
				"Age policy: shooters 8 years and older may shoot when directly supervised by a parent or legal guardian; you must be 18+ to shoot solo and 21+ to rent a handgun alone.\n"
				. "Safety equipment: eye and ear protection are required on the range at all times (available to rent or buy at the counter).\n"
				. "Ammunition policy: only factory new ammunition is allowed; no steel-core, tracer, or incendiary rounds. Rental guns must use ammunition purchased at the range.\n"
				. "All shooters must have a signed waiver on file before entering the range. Waivers can be signed in store or online before arrival.\n"
				. 'Range safety officers (RSOs) are on duty during all open hours and their instructions must be followed.',

			'G2A POS — how to ring a sale'   =>
				"To ring a sale in the G2A POS: open Registers and start/resume a register session, then create the order in Sales (or scan items with the barcode scanner). Add the customer from CRM if loyalty points, membership discounts, or store credit should apply. Take payment via the Split Tender screen for cash/card/gift-card combinations. Firearm sales automatically open the compliance flow (4473, NICS check, bound book entry) before the order can complete.\n"
				. 'Trade-ins: use the Trade-Ins workspace to appraise and accept a used firearm; accepted trade-ins create a used-firearm intake and bound-book acquisition entry, and the credit can be applied to the customer order.',

			'G2A POS — layaway, waivers, lanes' =>
				"Layaway: create a layaway from the Layaway workspace with a deposit; the system tracks the balance, sends reminder messages before expiry, and releases inventory when the final payment posts.\n"
				. "Waivers: the Waivers workspace stores signed range waivers (OtterWaiver imports plus Verifyistic and Memberistic sources). Use the search box to confirm a customer has a waiver on file and the Check-in button to record a range visit.\n"
				. "Lane reservations: the Lane Reservations workspace shows POS reservations and online bookings from the booking engine for any date. POS reservations are conflict-checked per lane and time window; use Check in / Check out / No-show to manage the day's roster.",
		);
	}

	/**
	 * Build a live snapshot document from the database: the exact Memberistic
	 * plan names + prices and the current WooCommerce catalog size. Empty
	 * string when neither data source is present.
	 */
	private static function live_data_doc(): string {
		global $wpdb;
		$parts = array();

		if ( isset( $wpdb ) ) {
			$plans_table = $wpdb->prefix . 'memberistic_plans';
			$has_plans   = (bool) $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $plans_table ) )
			);
			if ( $has_plans ) {
				$rows = $wpdb->get_results(
					"SELECT name, monthly_price, annual_price, status FROM {$plans_table} WHERE status = 'active' ORDER BY monthly_price ASC",
					ARRAY_A
				);
				if ( $rows ) {
					$lines = array();
					foreach ( $rows as $row ) {
						$name = (string) ( $row['name'] ?? '' );
						$mo   = isset( $row['monthly_price'] ) ? number_format( (float) $row['monthly_price'], 2 ) : '';
						$yr   = isset( $row['annual_price'] ) && (float) $row['annual_price'] > 0 ? number_format( (float) $row['annual_price'], 2 ) : '';
						$line = '- ' . $name;
						if ( '' !== $mo ) {
							$line .= ": \${$mo}/month";
						}
						if ( '' !== $yr ) {
							$line .= " (or \${$yr}/year)";
						}
						$lines[] = $line;
					}
					$parts[] = "Current Memberistic membership plans (authoritative — these are the real plan names and prices configured on the site):\n" . implode( "\n", $lines );
				}
			}
		}

		if ( function_exists( 'wp_count_posts' ) ) {
			$counts = wp_count_posts( 'product' );
			$pub    = isset( $counts->publish ) ? (int) $counts->publish : 0;
			if ( $pub > 0 ) {
				$cats = function_exists( 'get_terms' ) ? get_terms(
					array(
						'taxonomy'   => 'product_cat',
						'hide_empty' => true,
						'number'     => 12,
						'orderby'    => 'count',
						'order'      => 'DESC',
					)
				) : array();
				$cat_names = array();
				if ( is_array( $cats ) ) {
					foreach ( $cats as $cat ) {
						if ( is_object( $cat ) && isset( $cat->name ) ) {
							$cat_names[] = $cat->name;
						}
					}
				}
				$line = "The online store currently has {$pub} published product(s).";
				if ( $cat_names ) {
					$line .= ' Top categories: ' . implode( ', ', $cat_names ) . '.';
				}
				$parts[] = $line;
			}
		}

		return $parts ? implode( "\n\n", $parts ) : '';
	}

	/**
	 * Crawl the whole site: index every published page, post and WooCommerce
	 * product so the agent answers from real website content. Tagged TAG_LIVE
	 * (purged at the start of refresh) so each run replaces the prior crawl.
	 * Capped so a single cron run stays bounded.
	 *
	 * @param array<string,mixed> $result Mutated in place with counts.
	 */
	private static function crawl_published_content( array &$result ): void {
		if ( ! class_exists( '\WP_Query' ) || ! function_exists( 'get_permalink' ) ) {
			return;
		}
		$types = array();
		foreach ( array( 'page', 'post', 'product' ) as $pt ) {
			if ( ! function_exists( 'post_type_exists' ) || post_type_exists( $pt ) ) {
				$types[] = $pt;
			}
		}
		if ( ! $types ) {
			return;
		}

		$query = new \WP_Query(
			array(
				'post_type'              => $types,
				'post_status'            => 'publish',
				'posts_per_page'         => 500,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'suppress_filters'       => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		foreach ( $query->posts as $post ) {
			$title   = trim( (string) get_the_title( $post ) );
			$raw     = (string) ( $post->post_content ?? '' );
			$content = trim( wp_strip_all_tags( strip_shortcodes( $raw ) ) );
			$excerpt = trim( wp_strip_all_tags( (string) ( $post->post_excerpt ?? '' ) ) );
			$text    = trim( $title . "\n\n" . $excerpt . "\n\n" . $content );
			if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text ) ) < 40 ) {
				continue; // Skip near-empty pages (e.g. page-builder layouts with no server-rendered copy).
			}
			$url = (string) get_permalink( $post );
			$r   = BrainService::ingest_text(
				'Page: ' . ( $title !== '' ? $title : $url ),
				$text,
				array(
					'source_type' => 'url',
					'source_uri'  => $url,
					'tags'        => self::TAG_LIVE,
					'scope'       => 'public',
				)
			);
			if ( ! empty( $r['ok'] ) ) {
				++$result['crawled_pages'];
				$result['chunks'] += (int) ( $r['chunks'] ?? 0 );
			}
		}

		if ( function_exists( 'wp_reset_postdata' ) ) {
			wp_reset_postdata();
		}
	}

	// ------------------------------------------------------------------ helpers

	/** @return array{city:string,founded_year:string,address:string,phone:string,email:string,hours:string} */
	private static function business_facts(): array {
		$fallback = array(
			'city'         => 'Mesa',
			'founded_year' => '2014',
			'address'      => '6030 E Main St, Suite 103, Mesa, AZ 85205',
			'phone'        => '(602) 715-2677',
			'email'        => 'sales@guns2ammo.com',
			'hours'        => 'Monday–Saturday 10am–6pm, Sunday 12pm–6pm',
		);
		if ( ! function_exists( 'g2a_biz' ) ) {
			return $fallback;
		}
		try {
			$biz = g2a_biz();
			if ( ! is_array( $biz ) ) {
				return $fallback;
			}
			$addr = trim( implode( ', ', array_filter( array( $biz['addr1'] ?? '', $biz['addr2'] ?? '' ) ) ) );
			return array(
				'city'         => (string) ( $biz['city'] ?: $fallback['city'] ),
				'founded_year' => (string) ( $biz['founded_year'] ?: $fallback['founded_year'] ),
				'address'      => $addr !== '' ? $addr : $fallback['address'],
				'phone'        => (string) ( $biz['phone'] ?: $fallback['phone'] ),
				'email'        => (string) ( $biz['email'] ?: $fallback['email'] ),
				'hours'        => self::format_hours( $biz['hours'] ?? null ) ?: $fallback['hours'],
			);
		} catch ( \Throwable ) {
			return $fallback;
		}
	}

	/** Render g2a_biz()'s minutes-from-midnight hours map as readable text. */
	private static function format_hours( $hours ): string {
		if ( ! is_array( $hours ) ) {
			return '';
		}
		$days = array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' );
		$out  = array();
		foreach ( $days as $i => $day ) {
			$h = $hours[ $i ] ?? null;
			if ( ! is_array( $h ) ) {
				$out[] = "{$day}: closed";
				continue;
			}
			$out[] = sprintf( '%s: %s–%s', $day, self::clock( (int) $h['open'] ), self::clock( (int) $h['close'] ) );
		}
		return implode( ', ', $out );
	}

	private static function clock( int $minutes ): string {
		$h    = intdiv( $minutes, 60 ) % 24;
		$m    = $minutes % 60;
		$ampm = $h >= 12 ? 'pm' : 'am';
		$h12  = $h % 12 ?: 12;
		return $m ? sprintf( '%d:%02d%s', $h12, $m, $ampm ) : sprintf( '%d%s', $h12, $ampm );
	}

	private static function fetch( string $url ): ?string {
		if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
			return null;
		}
		$res = class_exists( SafeHttp::class )
			? SafeHttp::get(
				$url,
				array(
					'timeout'             => 15,
					'user-agent'          => 'G2A-POS-Brain/3.0',
					'limit_response_size' => 2 * MB_IN_BYTES,
				)
			)
			: wp_remote_get( $url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $res ) || (int) wp_remote_retrieve_response_code( $res ) >= 400 ) {
			return null;
		}
		$body = (string) wp_remote_retrieve_body( $res );
		return trim( $body ) !== '' ? $body : null;
	}

	/**
	 * Split an llms-full.txt document into titled sections on markdown-style
	 * headings; fall back to one big section.
	 *
	 * @return array<int,array{title:string,body:string}>
	 */
	public static function split_sections( string $text ): array {
		$lines    = preg_split( "/\r\n|\r|\n/", $text ) ?: array();
		$sections = array();
		$title    = 'Site overview';
		$buf      = array();
		$flush    = static function () use ( &$sections, &$title, &$buf ): void {
			$body = trim( implode( "\n", $buf ) );
			if ( $body !== '' ) {
				$sections[] = array(
					'title' => $title,
					'body'  => $body,
				);
			}
			$buf = array();
		};
		foreach ( $lines as $line ) {
			if ( preg_match( '/^#{1,3}\s+(.+)$/', $line, $m ) ) {
				$flush();
				$title = trim( $m[1] );
				continue;
			}
			$buf[] = $line;
		}
		$flush();
		return $sections ?: array(
			array(
				'title' => 'Site content',
				'body'  => trim( $text ),
			),
		);
	}

	private static function purge_tag( string $tag ): void {
		$repo = new AiBrainRepository();
		foreach ( $repo->document_ids_by_tag( $tag ) as $id ) {
			$repo->delete_document( $id );
		}
	}
}
