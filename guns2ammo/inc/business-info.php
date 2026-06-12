<?php
/**
 * Business Info — single source of truth.
 *
 * Every customer-visible NAP (Name / Address / Phone), opening
 * hour, founding year, and review count on the public site reads
 * through `g2a_biz()` from here. All values come from Customizer
 * theme mods (Appearance → Customize → G2A Business Info), with
 * sensible defaults baked in so a fresh install renders correctly
 * even before the client has touched the Customizer.
 *
 * Earlier the site shipped with two conflicting founding years
 * (2014 vs 2015), two review counts (449 vs 500), and a
 * "Today Tue" string hard-coded into the live-status pill. This
 * module is the fix: change a value here (or in the Customizer)
 * and every page, footer, schema block, and email picks it up.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return the full business info array. Cached per-request.
 *
 * @return array{
 *   name:string, legal_name:string, slogan:string,
 *   addr1:string, addr2:string, city:string, region:string,
 *   postal:string, country:string,
 *   phone:string, phone_tel:string, email:string,
 *   founded:string, founded_year:int,
 *   review_count:int, review_rating:float, review_source:string,
 *   hours:array<int,array{open:int,close:int}|null>,
 *   maps_url:string, directions_url:string,
 *   lat:float, lng:float,
 *   social:array{fb:string,ig:string,x:string,yt:string},
 *   timezone:string
 * }
 */
function g2a_biz() {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}

	$founded_year = (int) get_theme_mod( 'g2a_founded_year', 2014 );

	// Hours by weekday index (0=Sun ... 6=Sat). null = closed.
	// Stored as { open, close } in minutes-from-midnight so the
	// JS live-status pill, the schema JSON-LD, the footer, and the
	// contact page all draw from the same numbers.
	$hours = array(
		0 => array( 'open' => (int) get_theme_mod( 'g2a_hours_sun_open',   720 ), 'close' => (int) get_theme_mod( 'g2a_hours_sun_close',   1080 ) ), // 12-18
		1 => array( 'open' => (int) get_theme_mod( 'g2a_hours_mon_open',   600 ), 'close' => (int) get_theme_mod( 'g2a_hours_mon_close',   1080 ) ), // 10-18
		2 => array( 'open' => (int) get_theme_mod( 'g2a_hours_tue_open',   600 ), 'close' => (int) get_theme_mod( 'g2a_hours_tue_close',   1080 ) ),
		3 => array( 'open' => (int) get_theme_mod( 'g2a_hours_wed_open',   600 ), 'close' => (int) get_theme_mod( 'g2a_hours_wed_close',   1080 ) ),
		4 => array( 'open' => (int) get_theme_mod( 'g2a_hours_thu_open',   600 ), 'close' => (int) get_theme_mod( 'g2a_hours_thu_close',   1080 ) ),
		5 => array( 'open' => (int) get_theme_mod( 'g2a_hours_fri_open',   600 ), 'close' => (int) get_theme_mod( 'g2a_hours_fri_close',   1140 ) ), // 10-19
		6 => array( 'open' => (int) get_theme_mod( 'g2a_hours_sat_open',   600 ), 'close' => (int) get_theme_mod( 'g2a_hours_sat_close',   1140 ) ),
	);
	// A day with open >= close (or both 0) is treated as closed.
	foreach ( $hours as $i => $h ) {
		if ( $h['open'] >= $h['close'] || $h['close'] <= 0 ) {
			$hours[ $i ] = null;
		}
	}

	$phone     = trim( (string) get_theme_mod( 'g2a_phone', '(602) 715-2677' ) );
	$phone_tel = '+1' . preg_replace( '/[^0-9]/', '', $phone );

	$g2a_live_reviews = g2a_google_live_reviews();

	$cache = array(
		'name'         => trim( (string) get_theme_mod( 'g2a_business_name', 'Guns 2 Ammo' ) ),
		'legal_name'   => trim( (string) get_theme_mod( 'g2a_legal_name',    'Guns 2 Ammo — Arizona Shooting Range' ) ),
		'slogan'       => trim( (string) get_theme_mod( 'g2a_slogan',        "Mesa's most-trusted indoor shooting range, FFL firearm store, and NRA-certified training facility." ) ),

		'addr1'        => trim( (string) get_theme_mod( 'g2a_addr1', '6030 E Main St, Suite 103' ) ),
		'addr2'        => trim( (string) get_theme_mod( 'g2a_addr2', 'Mesa, AZ 85205' ) ),
		'city'         => trim( (string) get_theme_mod( 'g2a_city',   'Mesa' ) ),
		'region'       => trim( (string) get_theme_mod( 'g2a_region', 'AZ' ) ),
		'postal'       => trim( (string) get_theme_mod( 'g2a_postal', '85205' ) ),
		'country'      => trim( (string) get_theme_mod( 'g2a_country','US' ) ),

		'phone'        => $phone,
		'phone_tel'    => $phone_tel,
		'email'        => trim( (string) get_theme_mod( 'g2a_email', 'sales@guns2ammo.com' ) ),

		'founded_year' => $founded_year,
		'founded'      => trim( (string) get_theme_mod( 'g2a_founded_string', 'Since ' . $founded_year ) ),

		// Reviews — live from the Google Places API when an API key +
		// Place ID are configured in the Customizer (12h cache,
		// stale-on-error), otherwise the manually maintained numbers.
		// NEVER auto-increment.
		'review_count'  => (int)   ( $g2a_live_reviews['count'] ?? get_theme_mod( 'g2a_review_count', 449 ) ),
		'review_rating' => (float) ( $g2a_live_reviews['rating'] ?? get_theme_mod( 'g2a_review_rating', 4.7 ) ),
		'review_source' => trim( (string) get_theme_mod( 'g2a_review_source', 'Google' ) ),

		'hours'         => $hours,
		'timezone'      => 'America/Phoenix',

		'maps_url'      => esc_url_raw( get_theme_mod( 'g2a_maps_url', 'https://www.google.com/maps?q=Guns+2+Ammo+6030+E+Main+St+Mesa+AZ+85205' ) ),
		'directions_url'=> esc_url_raw( get_theme_mod( 'g2a_directions_url', 'https://www.google.com/maps/dir/?api=1&destination=Guns+2+Ammo%2C+6030+E+Main+St+%23103%2C+Mesa%2C+AZ+85205%2C+United+States' ) ),
		'lat'           => (float) get_theme_mod( 'g2a_lat',  33.4152 ),
		'lng'           => (float) get_theme_mod( 'g2a_lng', -111.7066 ),

		'social'        => array(
			'fb' => esc_url_raw( get_theme_mod( 'g2a_social_fb', '' ) ),
			'ig' => esc_url_raw( get_theme_mod( 'g2a_social_ig', '' ) ),
			'x'  => esc_url_raw( get_theme_mod( 'g2a_social_x',  '' ) ),
			'yt' => esc_url_raw( get_theme_mod( 'g2a_social_yt', '' ) ),
		),
	);

	return $cache;
}

/**
 * Convenience accessors.
 */
function g2a_biz_phone()    { $b = g2a_biz(); return $b['phone']; }
function g2a_biz_phone_tel(){ $b = g2a_biz(); return $b['phone_tel']; }
function g2a_biz_email()    { $b = g2a_biz(); return $b['email']; }
function g2a_biz_addr_line(){ $b = g2a_biz(); return $b['addr1'] . ', ' . $b['addr2']; }

/**
 * Live status helper — answers "are we open RIGHT NOW in Mesa?"
 * Used by schema JSON-LD and any server-side template that needs
 * to render an Open/Closed badge. The JS pill in chrome.js does
 * the same calculation on the client so the badge updates without
 * a page reload, but starting from this single source of truth
 * means the server-rendered SEO content and the JS-rendered UX
 * can never drift apart.
 */
function g2a_biz_is_open_now() {
	$b = g2a_biz();
	try {
		$now = new DateTime( 'now', new DateTimeZone( $b['timezone'] ) );
	} catch ( \Exception $e ) {
		return false;
	}
	$day  = (int) $now->format( 'w' ); // 0 = Sun ... 6 = Sat
	$mins = ( (int) $now->format( 'G' ) ) * 60 + (int) $now->format( 'i' );
	$h    = $b['hours'][ $day ] ?? null;
	if ( ! $h ) {
		return false;
	}
	return $mins >= $h['open'] && $mins < $h['close'];
}

/**
 * Output a tel: href for the configured phone. Safe to echo.
 */
function g2a_biz_tel_href() {
	return 'tel:' . g2a_biz_phone_tel();
}

/**
 * Localize hour ranges as a single string for hour-list display,
 * e.g. "Mon–Thu 10am–6pm · Fri 10am–7pm · Sat 10am–7pm · Sun 12pm–6pm".
 * Empty for any day flagged closed.
 */
function g2a_biz_hours_human() {
	$b = g2a_biz();
	$fmt = function ( $mins ) {
		$h  = intdiv( $mins, 60 );
		$am = $h < 12;
		$h12 = $h % 12 ?: 12;
		return $h12 . ( $am ? 'am' : 'pm' );
	};
	$labels = array( 0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat' );
	// Collapse consecutive identical day-ranges into "Mon–Thu" runs.
	$out = array();
	$run_start = null; $run_end = null; $run_h = null;
	$order = array( 1, 2, 3, 4, 5, 6, 0 ); // start week on Mon
	foreach ( $order as $idx ) {
		$h = $b['hours'][ $idx ] ?? null;
		$key = $h ? $h['open'] . '-' . $h['close'] : 'closed';
		if ( $run_h === $key ) {
			$run_end = $idx;
			continue;
		}
		if ( null !== $run_start ) {
			$out[] = g2a_biz_human_run( $labels, $run_start, $run_end, $run_h, $fmt );
		}
		$run_start = $idx; $run_end = $idx; $run_h = $key;
	}
	if ( null !== $run_start ) {
		$out[] = g2a_biz_human_run( $labels, $run_start, $run_end, $run_h, $fmt );
	}
	return implode( ' · ', array_filter( $out ) );
}

function g2a_biz_human_run( $labels, $start, $end, $key, $fmt ) {
	if ( 'closed' === $key ) {
		return ( $start === $end ? $labels[ $start ] : $labels[ $start ] . '–' . $labels[ $end ] ) . ' closed';
	}
	list( $open, $close ) = array_map( 'intval', explode( '-', $key ) );
	$range = ( $start === $end ? $labels[ $start ] : $labels[ $start ] . '–' . $labels[ $end ] );
	return $range . ' ' . $fmt( $open ) . '–' . $fmt( $close );
}

/**
 * Dynamic WooCommerce product-category SKU counts, replacing the
 * old hard-coded "24 / 12 / 38 / 21" numbers that drifted out of
 * sync between the nav and the homepage. Returns an associative
 * map of category slug → published-product count. Cached for an
 * hour to avoid hitting the DB on every nav render.
 */
function g2a_biz_category_counts() {
	$cached = get_transient( 'g2a_category_counts' );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$out = array();
	if ( taxonomy_exists( 'product_cat' ) ) {
		$terms = get_terms( array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'fields'     => 'all',
		) );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				$out[ $t->slug ] = (int) $t->count;
			}
		}
	}
	set_transient( 'g2a_category_counts', $out, HOUR_IN_SECONDS );
	return $out;
}

/**
 * Bust the transient when WC products / categories change.
 */
add_action( 'save_post_product', function () { delete_transient( 'g2a_category_counts' ); } );
add_action( 'deleted_post',      function ( $id ) { if ( 'product' === get_post_type( $id ) ) delete_transient( 'g2a_category_counts' ); } );
add_action( 'edited_product_cat', function () { delete_transient( 'g2a_category_counts' ); } );

/**
 * Localize the centralized business info to the front-end so JS
 * (live-status pill, etc.) reads from the SAME hour table the PHP
 * schema does. No more drift between the JS pill and the JSON-LD.
 */
add_action( 'wp_enqueue_scripts', function () {
	$b = g2a_biz();
	wp_add_inline_script(
		'g2a-chrome',
		'window.g2aBiz = ' . wp_json_encode( array(
			'tz'       => $b['timezone'],
			'hours'    => $b['hours'],
			'phone'    => $b['phone'],
			'phoneTel' => $b['phone_tel'],
		) ) . ';',
		'before'
	);
}, 30 );

/**
 * Live Google rating + review count via the Places Details API.
 *
 * Requires Customizer settings g2a_google_places_api_key and
 * g2a_google_place_id. Cached 12 hours; the last good response is kept
 * in an option so a failed refresh never blanks the numbers (stale
 * beats wrong). Returns array{rating:float,count:int}|null.
 */
function g2a_google_live_reviews() {
	$key      = trim( (string) get_theme_mod( 'g2a_google_places_api_key', '' ) );
	$place_id = trim( (string) get_theme_mod( 'g2a_google_place_id', '' ) );
	if ( '' === $key || '' === $place_id ) {
		return null;
	}

	$cached = get_transient( 'g2a_google_reviews' );
	if ( is_array( $cached ) && isset( $cached['rating'], $cached['count'] ) ) {
		return $cached;
	}

	$url = add_query_arg(
		array(
			'place_id' => rawurlencode( $place_id ),
			'fields'   => 'rating,user_ratings_total',
			'key'      => rawurlencode( $key ),
		),
		'https://maps.googleapis.com/maps/api/place/details/json'
	);
	$res  = wp_remote_get( $url, array( 'timeout' => 8 ) );
	$body = ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) )
		? json_decode( wp_remote_retrieve_body( $res ), true )
		: null;

	if ( isset( $body['status'], $body['result']['rating'], $body['result']['user_ratings_total'] )
		&& 'OK' === $body['status'] ) {
		$fresh = array(
			'rating' => round( (float) $body['result']['rating'], 1 ),
			'count'  => (int) $body['result']['user_ratings_total'],
		);
		set_transient( 'g2a_google_reviews', $fresh, 12 * HOUR_IN_SECONDS );
		update_option( 'g2a_google_reviews_last_good', $fresh, false );
		return $fresh;
	}

	// Fetch failed — serve the last good numbers and retry in an hour.
	$stale = get_option( 'g2a_google_reviews_last_good' );
	if ( is_array( $stale ) && isset( $stale['rating'], $stale['count'] ) ) {
		set_transient( 'g2a_google_reviews', $stale, HOUR_IN_SECONDS );
		return $stale;
	}
	return null;
}
