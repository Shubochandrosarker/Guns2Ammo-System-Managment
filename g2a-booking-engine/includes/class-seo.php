<?php
/**
 * G2AB SEO Engine — per-booking-type landing pages with full schema.
 *
 * - Rewrite rule: /event/{slug}/ → renders selected template
 * - 6 firearms-industry landing page templates
 * - JSON-LD schema: Event + Course + LocalBusiness + FAQPage + Speakable
 * - OG/Twitter meta + canonical
 * - Auto sitemap at /g2a-events-sitemap.xml
 * - All templates render unique tactical designs
 *
 * @package G2AB
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class G2AB_Seo {

	private static $instance = null;
	private $current_type = null;
	private $current_event = null;

	const TEMPLATES = array(
		'tactical_lane'      => array( 'label' => 'Tactical Lane Range',     'category' => 'lane',       'icon' => '◎', 'color' => '#E8802F' ),
		'ccw_class'          => array( 'label' => 'CCW Class',                'category' => 'class',      'icon' => '⊕', 'color' => '#E8802F' ),
		'hunter_safety'      => array( 'label' => 'Hunter Safety Course',     'category' => 'class',      'icon' => '🌲', 'color' => '#3a4a30' ),
		'private_lesson'     => array( 'label' => 'Private Instructor Lesson','category' => 'class',      'icon' => '✪', 'color' => '#0F4C75' ),
		'range_membership'   => array( 'label' => 'Range Membership',         'category' => 'membership', 'icon' => '★', 'color' => '#E8802F' ),
		'tactical_training'  => array( 'label' => 'Tactical Training Day',    'category' => 'class',      'icon' => '⚔', 'color' => '#1F3864' ),
	);

	public static function instance() {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		// Flush rewrite rules once per plugin version so /event/{slug}/ (and the
		// sitemap) actually resolve after an update — otherwise the landing pages
		// 404 until someone manually re-saves Permalinks.
		add_action( 'init', array( $this, 'maybe_flush_rewrites' ), 11 );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		// Real events (g2ab_events) get a clean, responsive landing page. This
		// runs before the booking-type template fallback below.
		add_action( 'template_redirect', array( $this, 'maybe_render_event_landing' ), 5 );
		add_filter( 'template_include', array( $this, 'maybe_load_template' ), 99 );
		add_action( 'wp_head', array( $this, 'inject_head' ), 1 );
		add_action( 'init', array( $this, 'add_sitemap_endpoint' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve_sitemap' ) );

		// Flush rewrites on activation hook from main plugin.
		add_action( 'g2ab_activated', array( $this, 'flush_rewrites' ) );

		// Append landing-page link to Booking Type cards.
		add_filter( 'g2ab_booking_type_extra_meta', array( $this, 'booking_type_landing_link' ), 10, 2 );
	}

	public function flush_rewrites() {
		$this->add_rewrite_rules();
		flush_rewrite_rules();
	}

	/**
	 * Keep the /event/{slug}/ rewrite alive — self-healing.
	 *
	 * Flushes when the plugin version changes OR when our rule has gone
	 * missing from the live rewrite table (e.g. another plugin or a permalink
	 * re-save wiped it). Without the missing-rule check, a one-time version
	 * gate would leave every event landing page 404'ing until someone manually
	 * re-saved Permalinks. Runs at most once per page load, then no-ops.
	 */
	public function maybe_flush_rewrites() {
		$rules     = get_option( 'rewrite_rules' );
		$has_rule  = is_array( $rules ) && array_key_exists( '^event/([^/]+)/?$', $rules );
		$ver_match = ( get_option( 'g2ab_seo_rewrites_version' ) === G2AB_VERSION );
		if ( $has_rule && $ver_match ) {
			return;
		}
		$this->add_rewrite_rules();
		$this->add_sitemap_endpoint();
		flush_rewrite_rules( false );
		update_option( 'g2ab_seo_rewrites_version', G2AB_VERSION );
	}

	public function add_rewrite_rules() {
		add_rewrite_rule( '^event/([^/]+)/?$', 'index.php?g2ab_event=$matches[1]', 'top' );
	}

	public function add_query_vars( $vars ) {
		$vars[] = 'g2ab_event';
		return $vars;
	}

	public function add_sitemap_endpoint() {
		add_rewrite_rule( '^g2a-events-sitemap\.xml$', 'index.php?g2ab_sitemap=1', 'top' );
		add_rewrite_tag( '%g2ab_sitemap%', '1' );
	}

	public function maybe_serve_sitemap() {
		if ( '1' !== get_query_var( 'g2ab_sitemap' ) ) return;
		global $wpdb;
		$bt = $wpdb->prefix . 'g2ab_booking_types';
		$rows = $wpdb->get_results( "SELECT slug, updated_at FROM {$bt} WHERE is_active = 1 ORDER BY id ASC" );
		header( 'Content-Type: application/xml; charset=utf-8' );
		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		foreach ( $rows as $r ) {
			$loc = esc_url( home_url( '/event/' . $r->slug . '/' ) );
			$lastmod = gmdate( 'c', strtotime( $r->updated_at ) );
			echo "  <url><loc>{$loc}</loc><lastmod>{$lastmod}</lastmod><changefreq>weekly</changefreq><priority>0.8</priority></url>\n";
		}
		echo '</urlset>';
		exit;
	}

	/**
	 * If the /event/{slug}/ slug matches a real Event, render a clean,
	 * responsive landing page (hero + description + booking widget) and exit
	 * before the legacy booking-type template machinery runs.
	 */
	public function maybe_render_event_landing() {
		$slug = get_query_var( 'g2ab_event' );
		if ( empty( $slug ) || ! class_exists( 'G2AB_Events' ) ) {
			return;
		}
		$event = G2AB_Events::get_event( sanitize_title( $slug ) );
		if ( ! $event || 'publish' !== $event->status ) {
			return; // fall through to booking-type fallback / 404.
		}
		$this->current_event = $event;
		add_filter( 'document_title_parts', array( $this, 'override_event_title' ) );
		add_action( 'wp_head', array( $this, 'inject_event_head' ), 1 );

		status_header( 200 );
		nocache_headers();
		get_header();
		$this->render_event_landing_page( $event );
		get_footer();
		exit;
	}

	public function override_event_title( $parts ) {
		if ( $this->current_event ) {
			$parts['title'] = $this->current_event->title;
		}
		return $parts;
	}

	public function inject_event_head() {
		$ev = $this->current_event;
		if ( ! $ev ) {
			return;
		}
		$url  = home_url( '/event/' . $ev->slug . '/' );
		$desc = $ev->summary ? $ev->summary : wp_trim_words( wp_strip_all_tags( (string) $ev->description ), 30 );
		$next = G2AB_Events::next_occurrence( $ev->id );

		echo '<link rel="canonical" href="' . esc_url( $url ) . '" />' . "\n";
		echo '<meta name="description" content="' . esc_attr( $desc ) . '" />' . "\n";
		echo '<meta property="og:type" content="event" />' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( $ev->title ) . '" />' . "\n";
		echo '<meta property="og:description" content="' . esc_attr( $desc ) . '" />' . "\n";
		echo '<meta property="og:url" content="' . esc_url( $url ) . '" />' . "\n";
		if ( $ev->image_url ) {
			echo '<meta property="og:image" content="' . esc_url( $ev->image_url ) . '" />' . "\n";
		}

		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => 'Event',
			'name'     => $ev->title,
			'description' => $desc,
			'eventStatus' => 'https://schema.org/EventScheduled',
			'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
			'url'      => $url,
		);
		if ( $next ) {
			$schema['startDate'] = gmdate( 'c', strtotime( $next->start_at ) );
			$schema['endDate']   = gmdate( 'c', strtotime( $next->end_at ) );
		}
		if ( $ev->location ) {
			$schema['location'] = array( '@type' => 'Place', 'name' => $ev->location );
		}
		if ( (int) $ev->is_free === 1 || (float) $ev->price <= 0 ) {
			$schema['offers'] = array( '@type' => 'Offer', 'price' => '0', 'priceCurrency' => get_option( 'g2ab_currency', 'USD' ), 'availability' => 'https://schema.org/InStock' );
		} else {
			$schema['offers'] = array( '@type' => 'Offer', 'price' => (string) number_format( (float) $ev->price, 2, '.', '' ), 'priceCurrency' => get_option( 'g2ab_currency', 'USD' ), 'availability' => 'https://schema.org/InStock', 'url' => $url );
		}
		echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
	}

	/**
	 * The actual landing markup. Fully responsive, Guns2Ammo dark/orange.
	 */
	public function render_event_landing_page( $ev ) {
		$next     = G2AB_Events::next_occurrence( $ev->id );
		$accent   = $ev->color ? $ev->color : '#E8802F';
		$is_free  = ( (int) $ev->is_free === 1 || (float) $ev->price <= 0 );
		$cat      = G2AB_Events::category_label( $ev->category );
		$has_img  = ! empty( $ev->image_url );
		$theme    = apply_filters( 'g2ab_event_landing_theme', get_option( 'g2ab_event_landing_theme', 'light' ), $ev );
		$theme    = ( 'dark' === $theme ) ? 'dark' : 'light';

		// Solid, theme-driven palette — no transparent layers, so a theme's own
		// page-title hero can never bleed through and muddy the text.
		if ( 'light' === $theme ) {
			$bg = '#F6F7FA'; $surface = '#FFFFFF'; $surface2 = '#F0F3F8'; $border = '#E3E8F0';
			$text = '#161B22'; $muted = '#5B6678'; $heroBg = 'linear-gradient(145deg,#090B0F 0%,#171B22 62%,#0E1117 100%)';
			$heroText = '#FFFFFF'; $heroSub = '#D6DEE8'; $statBg = '#15191F'; $green = '#1F9E54';
		} else {
			$bg = '#0B0D11'; $surface = '#15191F'; $surface2 = '#1C2229'; $border = '#2A323D';
			$text = '#EDEFF2'; $muted = '#9AA4B2'; $heroBg = 'linear-gradient(160deg,#0B0D11 0%,#161B22 60%,#0B0D11 100%)';
			$heroText = '#FFFFFF'; $heroSub = '#C7D0DC'; $statBg = '#1C2229'; $green = '#36C26B';
		}
		?>
		<style>
		.g2ab-elp{--bg:<?php echo esc_attr( $bg ); ?>;--surface:<?php echo esc_attr( $surface ); ?>;--surface2:<?php echo esc_attr( $surface2 ); ?>;--border:<?php echo esc_attr( $border ); ?>;--text:<?php echo esc_attr( $text ); ?>;--muted:<?php echo esc_attr( $muted ); ?>;--accent:<?php echo esc_attr( $accent ); ?>;--green:<?php echo esc_attr( $green ); ?>;background:var(--bg);color:var(--text);font-family:'Inter',system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;margin:0;position:relative;z-index:1;}
		.g2ab-elp *{box-sizing:border-box;}
		.g2ab-elp__hero{position:relative;background:<?php echo $has_img ? "url('" . esc_url( $ev->image_url ) . "') center/cover" : $heroBg; // phpcs:ignore ?>;padding:0;border-bottom:3px solid var(--accent);overflow:hidden;}
		.g2ab-elp__hero-scrim{position:relative;<?php if ( $has_img ) : ?>background:linear-gradient(180deg,rgba(8,10,14,.62),rgba(8,10,14,.82));<?php endif; ?>padding:74px 20px 64px;text-align:center;}
		.g2ab-elp__hero .g2ab-elp__title{color:#fff;}.g2ab-elp__hero .g2ab-elp__summary{color:#E6EAF0;}.g2ab-elp__hero .g2ab-elp__stat{background:rgba(21,25,31,.94);border-color:rgba(255,255,255,.18);box-shadow:0 18px 44px rgba(0,0,0,.26);}.g2ab-elp__hero .g2ab-elp__stat-k{color:#C9D2DE;}.g2ab-elp__hero .g2ab-elp__stat-v{color:#fff;}.g2ab-elp__hero .g2ab-elp__flag{background:rgba(21,25,31,.82);color:#E6EAF0;border-color:rgba(255,255,255,.24);}
		.g2ab-elp__hero-inner{position:relative;max-width:900px;margin:0 auto;}
		.g2ab-elp__eyebrow{display:inline-flex;align-items:center;gap:8px;color:var(--accent);font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:13px;font-weight:700;letter-spacing:.26em;text-transform:uppercase;margin-bottom:14px;opacity:0;animation:g2ab-elp-up .6s ease .05s forwards;}
		.g2ab-elp__eyebrow::before,.g2ab-elp__eyebrow::after{content:"";width:26px;height:2px;background:var(--accent);opacity:.7;}
		.g2ab-elp__title{margin:0 auto 14px;max-width:18ch;font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:clamp(32px,6vw,58px);line-height:1.03;font-weight:700;letter-spacing:.01em;text-transform:uppercase;color:<?php echo esc_attr( $heroText ); ?>;opacity:0;animation:g2ab-elp-up .6s ease .12s forwards;}
		.g2ab-elp__summary{margin:0 auto;max-width:620px;color:<?php echo esc_attr( $heroSub ); ?>;font-size:clamp(15px,2.1vw,18px);line-height:1.55;opacity:0;animation:g2ab-elp-up .6s ease .2s forwards;}
		.g2ab-elp__strip{display:flex;flex-wrap:wrap;justify-content:center;gap:12px;margin:28px auto 0;max-width:760px;opacity:0;animation:g2ab-elp-up .6s ease .3s forwards;}
		.g2ab-elp__stat{min-width:118px;background:<?php echo esc_attr( $statBg ); ?>;border:1px solid var(--border);border-radius:12px;padding:13px 18px;text-align:left;box-shadow:0 6px 18px rgba(20,28,46,.06);}
		.g2ab-elp__stat-k{display:block;font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);font-weight:700;margin-bottom:4px;}
		.g2ab-elp__stat-v{display:block;font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:21px;font-weight:700;color:var(--text);line-height:1.1;}
		.g2ab-elp__stat-v.is-accent{color:var(--accent);}
		.g2ab-elp__stat-v.is-green{color:var(--green);}
		.g2ab-elp__flags{display:flex;flex-wrap:wrap;justify-content:center;gap:8px;margin:18px auto 0;opacity:0;animation:g2ab-elp-up .6s ease .38s forwards;}
		.g2ab-elp__flag{font-size:11px;letter-spacing:.05em;color:var(--muted);border:1px solid var(--border);border-radius:999px;padding:5px 12px;background:var(--surface);}
		.g2ab-elp__flag--gold{color:#C98A1B;border-color:rgba(201,138,27,.4);}
		@keyframes g2ab-elp-up{from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:translateY(0);}}
		.g2ab-elp__wrap{max-width:1080px;margin:0 auto;padding:42px 20px 72px;display:grid;grid-template-columns:1.2fr .92fr;gap:36px;align-items:start;}
		@media (max-width:880px){.g2ab-elp__wrap{grid-template-columns:1fr;gap:24px;padding-top:30px;}}
		.g2ab-elp__back{display:inline-flex;align-items:center;gap:6px;margin:0 0 22px;color:var(--muted);text-decoration:none;font-size:13px;font-weight:600;}
		.g2ab-elp__back:hover{color:var(--accent);}
		.g2ab-elp__body h2{font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:22px;letter-spacing:.04em;text-transform:uppercase;color:var(--text);margin:0 0 14px;display:flex;align-items:center;gap:12px;}
		.g2ab-elp__body h2::before{content:"";width:26px;height:3px;background:var(--accent);border-radius:2px;}
		.g2ab-elp__desc{color:var(--muted);font-size:15.5px;line-height:1.78;}
		.g2ab-elp__desc p{margin:0 0 14px;}
		.g2ab-elp__detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin:24px 0 26px;}
		@media (max-width:620px){.g2ab-elp__detail-grid{grid-template-columns:1fr;}}
		.g2ab-elp__card{background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:18px 18px 17px;box-shadow:0 14px 36px rgba(20,28,46,.08);animation:g2ab-elp-card .65s cubic-bezier(.16,.8,.3,1) both;transition:transform .18s ease,border-color .18s ease,box-shadow .18s ease;}
		.g2ab-elp__card:nth-child(2){animation-delay:.05s}.g2ab-elp__card:nth-child(3){animation-delay:.1s}.g2ab-elp__card:nth-child(4){animation-delay:.15s}
		.g2ab-elp__card:hover{transform:translateY(-3px);border-color:color-mix(in srgb,var(--accent) 48%,var(--border));box-shadow:0 18px 44px rgba(20,28,46,.12);}
		.g2ab-elp__card-k{display:block;color:var(--accent);font-size:11px;font-weight:800;letter-spacing:.14em;text-transform:uppercase;margin-bottom:7px;}
		.g2ab-elp__card-v{display:block;color:var(--text);font-size:18px;font-weight:800;line-height:1.22;}
		.g2ab-elp__card p{margin:8px 0 0;color:var(--muted);font-size:13.5px;line-height:1.55;}
		.g2ab-elp__notice{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:0 0 26px;}
		@media (max-width:760px){.g2ab-elp__notice{grid-template-columns:1fr;}}
		.g2ab-elp__notice span{display:flex;align-items:center;gap:9px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:12px 14px;color:var(--text);font-size:13px;font-weight:700;}
		.g2ab-elp__notice b{color:var(--accent);font-size:16px;}
		@keyframes g2ab-elp-card{from{opacity:0;transform:translateY(14px);}to{opacity:1;transform:translateY(0);}}
		.g2ab-elp__dates{margin-top:26px;}
		.g2ab-elp__date-pill{display:inline-flex;align-items:baseline;gap:8px;background:var(--surface);border:1px solid var(--border);border-left:3px solid var(--accent);border-radius:8px;padding:8px 14px;margin:0 8px 8px 0;font-size:13px;color:var(--text);}
		.g2ab-elp__date-pill b{font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;}
		.g2ab-elp__date-pill small{color:var(--green);font-weight:700;}
		.g2ab-elp__book{position:sticky;top:20px;}
		@media (max-width:880px){.g2ab-elp__book{position:static;}}
		.g2ab-elp .g2ab-evb{margin-top:0;max-width:none;}
		</style>
		<div class="g2ab-elp g2ab-elp--<?php echo esc_attr( $theme ); ?>">
			<header class="g2ab-elp__hero">
				<div class="g2ab-elp__hero-scrim">
					<div class="g2ab-elp__hero-inner">
						<span class="g2ab-elp__eyebrow"><?php echo esc_html( strtoupper( $cat ) ); ?></span>
						<h1 class="g2ab-elp__title"><?php echo esc_html( $ev->title ); ?></h1>
						<?php if ( $ev->summary ) : ?><p class="g2ab-elp__summary"><?php echo esc_html( $ev->summary ); ?></p><?php endif; ?>
						<div class="g2ab-elp__strip">
							<?php if ( $next ) : ?>
							<div class="g2ab-elp__stat"><span class="g2ab-elp__stat-k"><?php esc_html_e( 'Next date', 'g2a-booking' ); ?></span><span class="g2ab-elp__stat-v"><?php echo esc_html( G2AB_Events::format_local( $next->start_at, 'D, M j' ) ); ?></span></div>
							<div class="g2ab-elp__stat"><span class="g2ab-elp__stat-k"><?php esc_html_e( 'Time', 'g2a-booking' ); ?></span><span class="g2ab-elp__stat-v"><?php echo esc_html( G2AB_Events::format_local( $next->start_at, 'g:i A' ) ); ?></span></div>
							<?php endif; ?>
							<div class="g2ab-elp__stat"><span class="g2ab-elp__stat-k"><?php esc_html_e( 'Price', 'g2a-booking' ); ?></span><span class="g2ab-elp__stat-v is-accent"><?php echo $is_free ? esc_html__( 'FREE', 'g2a-booking' ) : '$' . esc_html( number_format( (float) $ev->price, 2 ) ); ?></span></div>
							<?php if ( $next ) : ?>
							<div class="g2ab-elp__stat"><span class="g2ab-elp__stat-k"><?php esc_html_e( 'Seats left', 'g2a-booking' ); ?></span><span class="g2ab-elp__stat-v <?php echo (int) $next->seats_left > 0 ? 'is-green' : 'is-accent'; ?>"><?php echo (int) $next->seats_left > 0 ? (int) $next->seats_left : esc_html__( 'Full', 'g2a-booking' ); ?></span></div>
							<?php endif; ?>
						</div>
						<div class="g2ab-elp__flags">
							<span class="g2ab-elp__flag">⏱ <?php echo (int) $ev->duration_min; ?> <?php esc_html_e( 'min', 'g2a-booking' ); ?></span>
							<?php if ( $ev->location ) : ?><span class="g2ab-elp__flag">⌖ <?php echo esc_html( $ev->location ); ?></span><?php endif; ?>
							<?php if ( (int) $ev->requires_waiver ) : ?><span class="g2ab-elp__flag">⚠ <?php esc_html_e( 'Waiver required', 'g2a-booking' ); ?></span><?php endif; ?>
							<?php if ( (int) $ev->members_only ) : ?><span class="g2ab-elp__flag g2ab-elp__flag--gold">★ <?php esc_html_e( 'Members only', 'g2a-booking' ); ?></span><?php endif; ?>
						</div>
					</div>
				</div>
			</header>

			<div class="g2ab-elp__wrap">
				<div class="g2ab-elp__body">
					<a class="g2ab-elp__back" href="<?php echo esc_url( home_url( '/' ) ); ?>">← <?php esc_html_e( 'Back to site', 'g2a-booking' ); ?></a>
					<?php if ( trim( (string) $ev->description ) !== '' ) : ?>
						<h2><?php esc_html_e( 'About this event', 'g2a-booking' ); ?></h2>
						<div class="g2ab-elp__desc"><?php echo wp_kses_post( wpautop( $ev->description ) ); ?></div>
					<?php endif; ?>
					<div class="g2ab-elp__detail-grid">
						<article class="g2ab-elp__card">
							<span class="g2ab-elp__card-k"><?php esc_html_e( 'Training format', 'g2a-booking' ); ?></span>
							<span class="g2ab-elp__card-v"><?php echo esc_html( $cat ); ?></span>
							<p><?php esc_html_e( 'Instructor-led session with clear safety expectations, practical guidance, and time for questions before you reserve.', 'g2a-booking' ); ?></p>
						</article>
						<article class="g2ab-elp__card">
							<span class="g2ab-elp__card-k"><?php esc_html_e( 'Session length', 'g2a-booking' ); ?></span>
							<span class="g2ab-elp__card-v"><?php echo (int) $ev->duration_min; ?> <?php esc_html_e( 'minutes', 'g2a-booking' ); ?></span>
							<p><?php esc_html_e( 'Plan to arrive early enough for check-in, waiver review, and any range safety briefing.', 'g2a-booking' ); ?></p>
						</article>
						<article class="g2ab-elp__card">
							<span class="g2ab-elp__card-k"><?php esc_html_e( 'Reservation cost', 'g2a-booking' ); ?></span>
							<span class="g2ab-elp__card-v"><?php echo $is_free ? esc_html__( 'Free seat', 'g2a-booking' ) : '$' . esc_html( number_format( (float) $ev->price, 2 ) ) . ' / ' . esc_html__( 'seat', 'g2a-booking' ); ?></span>
							<p><?php esc_html_e( 'Secure your spot online when checkout is enabled, or reserve for in-store handling when available.', 'g2a-booking' ); ?></p>
						</article>
						<article class="g2ab-elp__card">
							<span class="g2ab-elp__card-k"><?php esc_html_e( 'Availability', 'g2a-booking' ); ?></span>
							<span class="g2ab-elp__card-v"><?php echo $next ? esc_html( (int) $next->seats_left . ' ' . __( 'seats left', 'g2a-booking' ) ) : esc_html__( 'Dates coming soon', 'g2a-booking' ); ?></span>
							<p><?php esc_html_e( 'Seat counts update live before checkout so the reservation form reflects current capacity.', 'g2a-booking' ); ?></p>
						</article>
					</div>
					<div class="g2ab-elp__notice">
						<span><b>1</b><?php esc_html_e( 'Choose a date and seat count.', 'g2a-booking' ); ?></span>
						<span><b>2</b><?php echo (int) $ev->requires_waiver ? esc_html__( 'Accept the required waiver.', 'g2a-booking' ) : esc_html__( 'Enter your attendee details.', 'g2a-booking' ); ?></span>
						<span><b>3</b><?php esc_html_e( 'Receive booking confirmation by email.', 'g2a-booking' ); ?></span>
					</div>
					<?php
					$upcoming = G2AB_Events::get_occurrences( $ev->id, array( 'upcoming_only' => true, 'limit' => 8 ) );
					if ( count( $upcoming ) > 1 ) : ?>
						<div class="g2ab-elp__dates">
							<h2><?php esc_html_e( 'Upcoming dates', 'g2a-booking' ); ?></h2>
							<?php foreach ( $upcoming as $o ) : ?>
								<span class="g2ab-elp__date-pill"><b><?php echo esc_html( G2AB_Events::format_local( $o->start_at, 'D, M j · g:i A' ) ); ?></b> <small><?php echo (int) $o->seats_left > 0 ? (int) $o->seats_left . ' ' . esc_html__( 'left', 'g2a-booking' ) : esc_html__( 'full', 'g2a-booking' ); ?></small></span>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
				<div class="g2ab-elp__book">
					<?php echo do_shortcode( '[g2a_event_booking event="' . (int) $ev->id . '" theme="' . esc_attr( $theme ) . '" heading="' . esc_attr( $ev->title ) . '"]' ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	public function maybe_load_template( $template ) {
		$slug = get_query_var( 'g2ab_event' );
		if ( empty( $slug ) ) return $template;

		$type = $this->fetch_type( sanitize_title( $slug ) );
		if ( ! $type ) {
			status_header( 404 );
			return $template;
		}
		$this->current_type = $type;

		// Allow theme override.
		$theme_template = locate_template( array( 'g2a-booking/event-landing.php', 'g2a-event-landing.php' ) );
		if ( $theme_template ) return $theme_template;

		// Render plugin template inline.
		add_filter( 'document_title_parts', array( $this, 'override_title' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_landing_assets' ) );
		add_action( 'g2ab_render_event_landing', array( $this, 'render_landing' ) );

		// Use a generic template loader that calls our action.
		$plugin_template = G2AB_PATH . 'templates/event-landing.php';
		if ( file_exists( $plugin_template ) ) return $plugin_template;
		// Fallback: render inline within the active theme's structure.
		add_action( 'loop_start', array( $this, 'render_inline_landing' ), 1 );
		return $template;
	}

	public function render_inline_landing() {
		if ( $this->current_type ) {
			remove_action( 'loop_start', array( $this, 'render_inline_landing' ), 1 );
			get_header();
			$this->render_landing();
			get_footer();
			exit;
		}
	}

	public function render_landing() {
		if ( ! $this->current_type ) return;
		$type = $this->current_type;
		$settings = json_decode( (string) $type->settings, true );
		$tpl_id = $settings['landing_template'] ?? $this->default_template_for( $type->category );
		if ( ! isset( self::TEMPLATES[ $tpl_id ] ) ) $tpl_id = 'tactical_lane';

		$copy = $this->copy_for_template( $tpl_id, $type );
		$tpl = self::TEMPLATES[ $tpl_id ];

		echo '<style>' . $this->landing_styles() . '</style>';
		echo '<main class="g2ab-lp g2ab-lp--' . esc_attr( $tpl_id ) . '">';
		$method = 'render_' . $tpl_id;
		if ( method_exists( $this, $method ) ) {
			$this->$method( $type, $copy, $tpl );
		}
		echo '</main>';
	}

	public function override_title( $parts ) {
		if ( ! $this->current_type ) return $parts;
		$copy = $this->copy_for_template( $this->get_template_id( $this->current_type ), $this->current_type );
		$parts['title'] = $copy['meta_title'] ?? $this->current_type->name;
		return $parts;
	}

	public function inject_head() {
		if ( ! $this->current_type ) return;
		$type = $this->current_type;
		$tpl_id = $this->get_template_id( $type );
		$copy = $this->copy_for_template( $tpl_id, $type );
		$url = home_url( '/event/' . $type->slug . '/' );

		// OG / Twitter / canonical.
		echo '<link rel="canonical" href="' . esc_url( $url ) . '" />' . "\n";
		echo '<meta name="description" content="' . esc_attr( $copy['meta_desc'] ) . '" />' . "\n";
		echo '<meta property="og:type" content="event" />' . "\n";
		echo '<meta property="og:url" content="' . esc_url( $url ) . '" />' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( $copy['meta_title'] ) . '" />' . "\n";
		echo '<meta property="og:description" content="' . esc_attr( $copy['meta_desc'] ) . '" />' . "\n";
		echo '<meta property="og:site_name" content="' . esc_attr( g2ab_business_name() ) . '" />' . "\n";
		echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
		echo '<meta name="twitter:title" content="' . esc_attr( $copy['meta_title'] ) . '" />' . "\n";
		echo '<meta name="twitter:description" content="' . esc_attr( $copy['meta_desc'] ) . '" />' . "\n";

		// JSON-LD.
		$schema = $this->build_schema( $type, $copy, $tpl_id, $url );
		echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . '</script>' . "\n";
	}

	private function build_schema( $type, $copy, $tpl_id, $url ) {
		$biz_name = g2ab_business_name();
		$biz_addr = g2ab_business_address();
		$biz_phone = g2ab_business_phone();
		$next_start = $this->next_session( $type->id );
		$next_end = $next_start ? gmdate( 'c', strtotime( $next_start ) + ( (int) $type->duration_min * 60 ) ) : '';

		$graph = array();

		// LocalBusiness.
		$graph[] = array(
			'@type' => 'LocalBusiness',
			'@id' => home_url( '/' ) . '#localbusiness',
			'name' => $biz_name,
			'url' => home_url( '/' ),
			'telephone' => $biz_phone,
			'address' => array(
				'@type' => 'PostalAddress',
				'streetAddress' => $biz_addr,
				'addressCountry' => 'US',
			),
		);

		// Event (always — applies to all categories).
		$event = array(
			'@type' => 'Event',
			'@id' => $url . '#event',
			'name' => $copy['meta_title'],
			'description' => $copy['meta_desc'],
			'eventStatus' => 'https://schema.org/EventScheduled',
			'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
			'organizer' => array( '@id' => home_url( '/' ) . '#localbusiness' ),
			'location' => array( '@id' => home_url( '/' ) . '#localbusiness' ),
			'offers' => array(
				'@type' => 'Offer',
				'url' => $url,
				'price' => number_format( (float) $type->base_price, 2, '.', '' ),
				'priceCurrency' => get_option( 'g2ab_currency', 'USD' ),
				'availability' => 'https://schema.org/InStock',
				'validFrom' => gmdate( 'c' ),
			),
		);
		if ( $next_start ) {
			$event['startDate'] = gmdate( 'c', strtotime( $next_start ) );
			$event['endDate'] = $next_end;
		}
		$graph[] = $event;

		// Course schema for class/training categories.
		if ( in_array( $type->category, array( 'class' ), true ) ) {
			$graph[] = array(
				'@type' => 'Course',
				'@id' => $url . '#course',
				'name' => $copy['meta_title'],
				'description' => $copy['meta_desc'],
				'provider' => array( '@id' => home_url( '/' ) . '#localbusiness' ),
				'hasCourseInstance' => array(
					'@type' => 'CourseInstance',
					'courseMode' => 'in-person',
					'duration' => 'PT' . (int) $type->duration_min . 'M',
					'location' => array( '@id' => home_url( '/' ) . '#localbusiness' ),
				),
			);
		}

		// FAQPage with Speakable for AEO.
		if ( ! empty( $copy['faqs'] ) ) {
			$mainEntity = array();
			foreach ( $copy['faqs'] as $f ) {
				$mainEntity[] = array(
					'@type' => 'Question',
					'name' => $f[0],
					'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $f[1] ),
				);
			}
			$graph[] = array(
				'@type' => 'FAQPage',
				'@id' => $url . '#faq',
				'mainEntity' => $mainEntity,
				'speakable' => array(
					'@type' => 'SpeakableSpecification',
					'cssSelector' => array( '.g2ab-lp__hero-sub', '.g2ab-lp__quick-facts' ),
				),
			);
		}

		// BreadcrumbList.
		$graph[] = array(
			'@type' => 'BreadcrumbList',
			'@id' => $url . '#breadcrumb',
			'itemListElement' => array(
				array( '@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => home_url( '/' ) ),
				array( '@type' => 'ListItem', 'position' => 2, 'name' => 'Events', 'item' => home_url( '/event/' ) ),
				array( '@type' => 'ListItem', 'position' => 3, 'name' => $type->name ),
			),
		);

		return array( '@context' => 'https://schema.org', '@graph' => $graph );
	}

	private function next_session( $type_id ) {
		global $wpdb;
		$bt = $wpdb->prefix . 'g2ab_bookings';
		return $wpdb->get_var( $wpdb->prepare( "SELECT MIN(start_at) FROM {$bt} WHERE booking_type_id = %d AND start_at > %s AND status IN ('pending','reserved','confirmed','paid')", $type_id, current_time( 'mysql' ) ) );
	}

	private function get_template_id( $type ) {
		$settings = json_decode( (string) $type->settings, true );
		$id = $settings['landing_template'] ?? $this->default_template_for( $type->category );
		return isset( self::TEMPLATES[ $id ] ) ? $id : 'tactical_lane';
	}

	private function default_template_for( $category ) {
		switch ( $category ) {
			case 'lane':       return 'tactical_lane';
			case 'membership': return 'range_membership';
			case 'class':      return 'ccw_class';
		}
		return 'tactical_lane';
	}

	private function fetch_type( $slug ) {
		global $wpdb;
		$bt = $wpdb->prefix . 'g2ab_booking_types';
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$bt} WHERE slug = %s AND is_active = 1", $slug ) );
	}

	public function enqueue_landing_assets() { /* inline styles via wp_head; nothing to do */ }

	/* ============================================================ */
	/*  COPY GENERATORS — defaults per template, overridable later  */
	/* ============================================================ */
	private function copy_for_template( $tpl_id, $type ) {
		$biz = g2ab_business_name();
		$city_state = g2ab_business_address();
		$price = number_format( (float) $type->base_price, 2 );
		$mins = (int) $type->duration_min;

		$base = array(
			'meta_title' => sprintf( '%s — %s | %s', $type->name, $biz, $city_state ),
			'meta_desc'  => '',
			'hero_kicker' => '',
			'hero_title' => $type->name,
			'hero_sub' => '',
			'cta' => 'BOOK YOUR SPOT',
			'features' => array(),
			'faqs' => array(),
		);

		switch ( $tpl_id ) {
			case 'tactical_lane':
				$base['meta_desc'] = sprintf( 'Book your %s indoor shooting range lane at %s. %d-minute sessions from $%s. Real-time availability, military-green tactical experience.', strtolower( $type->name ), $biz, $mins, $price );
				$base['hero_kicker'] = 'INDOOR RANGE · LIVE AVAILABILITY';
				$base['hero_sub'] = sprintf( 'Lock and load. %d minutes of trigger time. $%s per shooter. All calibers welcome.', $mins, $price );
				$base['features'] = array(
					array( '◎', 'Climate-Controlled Bays', 'Filtered air, 25-yard target line, USPSA-grade backstop.' ),
					array( '⚡', 'Real-Time Booking', 'Live lane availability — no double bookings, no waiting.' ),
					array( '🎯', 'All Calibers', 'Pistol, rifle, shotgun. Magnum-rated. Bring your own or rent.' ),
					array( '🛡', 'Safety First', 'RSO on duty. Mandatory waiver. Eye/ear protection provided.' ),
				);
				$base['faqs'] = array(
					array( "Do I need a reservation to shoot?", "Reservations recommended during peak hours. Walk-ins accepted when lanes are open. Book online to lock your time slot." ),
					array( "What calibers are allowed?", "Pistol, rifle, shotgun, magnum cartridges. No tracer or armor-piercing rounds. Steel-core ammunition prohibited." ),
					array( "Can I bring my own firearm?", "Yes. All firearms must be range-legal and in safe condition. Bring your own ammo or buy from our retail counter." ),
					array( "Do I need a license to shoot here?", "No license required to use our range. Must be 18+ unsupervised, or under direct adult supervision if younger." ),
					array( "How much does it cost?", sprintf( "$%s per shooter for a %d-minute lane session. Add-ons (rentals, ammo, targets) priced separately at the front desk.", $price, $mins ) ),
				);
				$base['cta'] = 'RESERVE LANE';
				break;

			case 'ccw_class':
				$base['meta_desc'] = sprintf( '%s concealed carry class at %s — %d-minute curriculum, range qualification, certificate. $%s. Reserve your seat now.', $type->name, $biz, $mins, $price );
				$base['hero_kicker'] = 'CONCEALED CARRY · CERTIFICATION';
				$base['hero_sub'] = sprintf( 'Get your CCW certificate. %d-minute course. Live-fire qualification. Walk out ready to apply. $%s.', $mins, $price );
				$base['features'] = array(
					array( '⊕', 'State-Approved Curriculum', 'Meets all qualification requirements. Documentation provided.' ),
					array( '🎓', 'Certified Instructors', 'NRA + state-licensed. Active LE/Mil backgrounds.' ),
					array( '🎯', 'Live-Fire Qualification', 'Range time included. Range fee + ammo in price.' ),
					array( '📜', 'Certificate Issued', 'Walk out with your completion certificate. Ready to apply.' ),
				);
				$base['faqs'] = array(
					array( "What does the CCW class cover?", "Legal use of force, safe storage, drawing from concealment, situational awareness, live-fire qualification, and your state's specific permit application process." ),
					array( "Do I need my own gun?", "Bring your concealed-carry gun if you have one. Rentals available — common compact pistols stocked." ),
					array( "How long is the certificate valid?", "Most state CCW permits are valid 4-5 years from issue. Renewal classes available." ),
					array( "Is the qualification graded?", "Yes — minimum passing score on the live-fire portion is required for certification. Our instructors coach you to pass." ),
					array( "What should I bring?", "Concealed-carry gun + holster (or rent), 100 rounds of ammo (or buy onsite), eye/ear protection, photo ID, and a notepad." ),
				);
				$base['cta'] = 'RESERVE MY SEAT';
				break;

			case 'hunter_safety':
				$base['meta_desc'] = sprintf( '%s hunter safety course at %s. State-approved %d-minute curriculum, ethics, regulations, hands-on training. $%s.', $type->name, $biz, $mins, $price );
				$base['hero_kicker'] = 'HUNTER ED · STATE CERTIFIED';
				$base['hero_sub'] = sprintf( 'Earn your hunter safety card. %d-minute course covers law, ethics, field skills, and live-fire safety. $%s.', $mins, $price );
				$base['features'] = array(
					array( '🌲', 'State-Approved', 'Meets your state\'s hunter education requirement for license eligibility.' ),
					array( '📖', 'Ethics & Regulations', 'Wildlife law, fair-chase ethics, tag/season knowledge.' ),
					array( '🦌', 'Field Skills', 'Tracking, safe field carry, treestand safety, blood-trail tracking basics.' ),
					array( '✅', 'Hands-on Range', 'Live-fire safety drills with rifle and shotgun.' ),
				);
				$base['faqs'] = array(
					array( "Who needs hunter safety certification?", "Most states require hunter ed for any new hunter born after a certain year. Required to purchase a hunting license in many states." ),
					array( "What's the minimum age?", "Course is open to ages 10+. Under 18 requires parent/guardian consent. Some states issue cards earlier." ),
					array( "Is the certificate accepted in other states?", "Yes — IHEA-USA reciprocity means your certificate is accepted in all 50 states + Canada." ),
					array( "What firearms will I use?", "Standard-caliber rifle and 12-gauge shotgun. Equipment provided — bring eye/ear protection and a notebook." ),
					array( "How long does the certificate last?", "Lifetime. No renewal needed once you've passed." ),
				);
				$base['cta'] = 'ENROLL NOW';
				break;

			case 'private_lesson':
				$base['meta_desc'] = sprintf( 'Private %d-minute %s with a certified instructor at %s. One-on-one coaching, all skill levels. $%s per session.', $mins, strtolower( $type->name ), $biz, $price );
				$base['hero_kicker'] = '1-ON-1 COACHING · CERTIFIED INSTRUCTORS';
				$base['hero_sub'] = sprintf( 'Personalized coaching. %d minutes. Just you and the instructor. $%s.', $mins, $price );
				$base['features'] = array(
					array( '✪', 'One-on-One Focus', 'No group, no distractions. Full instructor attention.' ),
					array( '🎯', 'Customized Drill Plan', 'Tailored to your goals: defensive, competitive, recreational.' ),
					array( '📊', 'Progress Tracking', 'Take-home video review, drill scores, improvement plan.' ),
					array( '⏱', 'Flexible Scheduling', 'Pick your instructor and time slot. Reschedule with 24h notice.' ),
				);
				$base['faqs'] = array(
					array( "Who is this lesson for?", "Anyone — first-time shooters, intermediate practitioners, competitive shooters refining technique. We meet you at your level." ),
					array( "What will we work on?", "Whatever you choose: trigger control, draw stroke, recoil management, target transitions, defensive scenarios, or competitive drills." ),
					array( "Do I need my own gun?", "Bring it if you have it. Rentals available for an additional fee. Most calibers stocked." ),
					array( "Can I take video?", "Yes — we encourage it. Many of our drills benefit from slow-motion review. Instructor will help review with you." ),
					array( "What happens if I miss my appointment?", "24-hour cancellation policy. Less than 24 hours = full charge. Reschedules within 24h: at instructor's discretion." ),
				);
				$base['cta'] = 'BOOK MY COACH';
				break;

			case 'range_membership':
				$base['meta_desc'] = sprintf( '%s membership at %s — unlimited range access, member-only events, priority booking, discounts. From $%s.', $type->name, $biz, $price );
				$base['hero_kicker'] = 'MEMBERSHIP · UNLIMITED ACCESS';
				$base['hero_sub'] = sprintf( 'Unlimited lane time. Member-only nights. Priority booking. Discounts on rentals + retail. From $%s.', $price );
				$base['features'] = array(
					array( '★', 'Unlimited Range Time', 'No per-session fees. Walk in any time, any open lane.' ),
					array( '⏱', 'Priority Booking', 'Members lock slots 14 days ahead. Non-members 7 days.' ),
					array( '🎯', 'Member-Only Nights', 'Reserved hours, discounted ammo, free targets.' ),
					array( '🛒', 'Retail Discount', '10% off ammo, accessories, and rentals every visit.' ),
				);
				$base['faqs'] = array(
					array( "Is membership monthly or annual?", "Both. Choose monthly billing or save 15% with annual prepay. Cancel monthly anytime." ),
					array( "Do I get unlimited range time?", "Yes — unlimited lane time during open hours, subject to availability. Members get priority booking 14 days out." ),
					array( "Are guests included?", "Members can bring up to 1 guest per visit at the standard non-member rate. Family-tier memberships include 3 named family members." ),
					array( "What happens if I cancel?", "Monthly: cancel anytime, no fee. Annual: prorated refund minus a small admin fee. No hidden lock-ins." ),
					array( "Are there member-only events?", "Yes — quarterly member shoots, intro classes free for members, special pricing on advanced courses." ),
				);
				$base['cta'] = 'BECOME A MEMBER';
				break;

			case 'tactical_training':
				$base['meta_desc'] = sprintf( '%s tactical training day at %s. %d-minute intensive curriculum: defensive carry, scenario drills, instructor coaching. $%s.', $type->name, $biz, $mins, $price );
				$base['hero_kicker'] = 'TACTICAL · ADVANCED';
				$base['hero_sub'] = sprintf( 'Defensive scenarios. Live-fire drills. Real-world skills. %d-minute intensive. $%s. Limited seats.', $mins, $price );
				$base['features'] = array(
					array( '⚔', 'Mission-Brief Curriculum', 'Real-world defensive scenarios. No square-range drills.' ),
					array( '🎯', 'Live-Fire Drills', 'Multi-target engagement, movement, cover use, low-light.' ),
					array( '🛡', 'Vetted Instructors', 'Active or former LE / military. NRA + state certified.' ),
					array( '📋', 'Take-Home Brief', 'Drill notes, video review, follow-up training plan.' ),
				);
				$base['faqs'] = array(
					array( "What level is this course?", "Intermediate-to-advanced. Comfortable handling your gun under pressure. Not a beginner class." ),
					array( "What gear do I need?", "Concealed-carry handgun + 3 magazines, holster, mag pouches, eye/ear protection, range bag, 250 rounds of ammo. Optional: low-light gear, tourniquet." ),
					array( "Will I shoot a lot?", "Yes — typically 200-250 rounds in a full-day course. Drills are intensive but coached for safety throughout." ),
					array( "Is there a fitness requirement?", "Moderate — expect movement, kneeling, getting up/down, brief sprints. Communicate any limitations to your instructor before class." ),
					array( "What if weather cancels the class?", "Indoor backup if the course includes outdoor components. Otherwise reschedule with no fee." ),
				);
				$base['cta'] = 'DEPLOY TO TRAINING';
				break;
		}
		return $base;
	}

	/* ============================================================ */
	/*  TEMPLATE RENDERERS                                          */
	/* ============================================================ */

	private function render_tactical_lane( $type, $copy, $tpl ) {
		$this->render_hero( $copy, '#E8802F', 'lane' );
		$this->render_quick_facts( $type, $copy );
		$this->render_features( $copy );
		$this->render_booking_widget( 'lane' );
		$this->render_faqs( $copy );
		$this->render_cta_strip( $copy );
	}

	private function render_ccw_class( $type, $copy, $tpl ) {
		$this->render_hero( $copy, '#E8802F', 'class' );
		$this->render_quick_facts( $type, $copy );
		$this->render_features( $copy );
		$this->render_curriculum( $copy );
		$this->render_booking_widget( 'class' );
		$this->render_faqs( $copy );
		$this->render_cta_strip( $copy );
	}

	private function render_hunter_safety( $type, $copy, $tpl ) {
		$this->render_hero( $copy, '#3a4a30', 'hunter' );
		$this->render_quick_facts( $type, $copy );
		$this->render_features( $copy );
		$this->render_booking_widget( 'class' );
		$this->render_faqs( $copy );
		$this->render_cta_strip( $copy );
	}

	private function render_private_lesson( $type, $copy, $tpl ) {
		$this->render_hero( $copy, '#0F4C75', 'lesson' );
		$this->render_quick_facts( $type, $copy );
		$this->render_features( $copy );
		$this->render_booking_widget( 'lesson' );
		$this->render_faqs( $copy );
		$this->render_cta_strip( $copy );
	}

	private function render_range_membership( $type, $copy, $tpl ) {
		$this->render_hero( $copy, '#E8802F', 'membership' );
		$this->render_tier_grid( $type, $copy );
		$this->render_features( $copy );
		$this->render_booking_widget( 'membership' );
		$this->render_faqs( $copy );
		$this->render_cta_strip( $copy );
	}

	private function render_tactical_training( $type, $copy, $tpl ) {
		$this->render_hero( $copy, '#1F3864', 'tactical' );
		$this->render_quick_facts( $type, $copy );
		$this->render_features( $copy );
		$this->render_booking_widget( 'class' );
		$this->render_faqs( $copy );
		$this->render_cta_strip( $copy );
	}

	/* ============================================================ */
	/*  SHARED RENDER PARTS                                         */
	/* ============================================================ */

	private function render_hero( $copy, $accent, $variant ) {
		?>
		<section class="g2ab-lp__hero" style="--accent:<?php echo esc_attr( $accent ); ?>;" data-variant="<?php echo esc_attr( $variant ); ?>">
			<div class="g2ab-lp__hero-camo"></div>
			<div class="g2ab-lp__hero-grid"></div>
			<div class="g2ab-lp__hero-inner">
				<div class="g2ab-lp__hero-kicker"><?php echo esc_html( $copy['hero_kicker'] ); ?></div>
				<h1 class="g2ab-lp__hero-title"><?php echo esc_html( $copy['hero_title'] ); ?></h1>
				<p class="g2ab-lp__hero-sub"><?php echo esc_html( $copy['hero_sub'] ); ?></p>
				<a class="g2ab-lp__hero-cta" href="#book"><span><?php echo esc_html( $copy['cta'] ); ?></span><span>→</span></a>
			</div>
		</section>
		<?php
	}

	private function render_quick_facts( $type, $copy ) {
		$next = $this->next_session( $type->id );
		$next_label = $next ? wp_date( 'M j · g:i A', strtotime( $next ) ) : 'On demand';
		?>
		<section class="g2ab-lp__facts">
			<div class="g2ab-lp__quick-facts">
				<div><strong>$<?php echo esc_html( number_format( (float) $type->base_price, 2 ) ); ?></strong><span>PRICE</span></div>
				<div><strong><?php echo (int) $type->duration_min; ?> min</strong><span>DURATION</span></div>
				<div><strong><?php echo esc_html( $next_label ); ?></strong><span>NEXT SESSION</span></div>
				<div><strong><?php echo (int) $type->requires_waiver ? 'YES' : 'NO'; ?></strong><span>WAIVER</span></div>
			</div>
		</section>
		<?php
	}

	private function render_features( $copy ) {
		if ( empty( $copy['features'] ) ) return;
		?>
		<section class="g2ab-lp__features">
			<div class="g2ab-lp__features-grid">
				<?php foreach ( $copy['features'] as $f ) : ?>
					<div class="g2ab-lp__feature">
						<div class="g2ab-lp__feature-icon"><?php echo esc_html( $f[0] ); ?></div>
						<h3><?php echo esc_html( $f[1] ); ?></h3>
						<p><?php echo esc_html( $f[2] ); ?></p>
					</div>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	private function render_curriculum( $copy ) {
		?>
		<section class="g2ab-lp__curriculum">
			<h2 class="g2ab-lp__h2"><span class="g2ab-lp__h2-num">02</span> COURSE BRIEFING</h2>
			<ol class="g2ab-lp__curriculum-list">
				<li><strong>Block 1 — Legal &amp; Ethical Foundations.</strong> Use of force, jurisdictional law, post-incident protocol.</li>
				<li><strong>Block 2 — Defensive Mindset.</strong> Situational awareness, OODA, de-escalation, threat ID.</li>
				<li><strong>Block 3 — Marksmanship Refresher.</strong> Stance, grip, sight alignment, trigger control.</li>
				<li><strong>Block 4 — Drawing from Concealment.</strong> Holster work, garment clearance, presentation, retention.</li>
				<li><strong>Block 5 — Live-Fire Qualification.</strong> Timed course of fire. Pass = certificate.</li>
			</ol>
		</section>
		<?php
	}

	private function render_tier_grid( $type, $copy ) {
		$base = (float) $type->base_price;
		$tiers = array(
			array( 'BRONZE', '$' . number_format( $base, 0 ), 'mo', 'Unlimited range time · 7-day priority booking · 5% retail discount', false ),
			array( 'SILVER', '$' . number_format( $base * 1.6, 0 ), 'mo', 'Everything in Bronze · 14-day priority · 10% retail · Member nights', true ),
			array( 'GOLD',   '$' . number_format( $base * 2.4, 0 ), 'mo', 'Everything in Silver · Plus-one guest pass · 15% retail · Free intro classes', false ),
		);
		?>
		<section class="g2ab-lp__tiers">
			<h2 class="g2ab-lp__h2"><span class="g2ab-lp__h2-num">02</span> CHOOSE YOUR TIER</h2>
			<div class="g2ab-lp__tier-grid">
				<?php foreach ( $tiers as $t ) : ?>
					<div class="g2ab-lp__tier <?php echo $t[4] ? 'is-popular' : ''; ?>">
						<?php if ( $t[4] ) : ?><div class="g2ab-lp__tier-flag">MOST POPULAR</div><?php endif; ?>
						<h3><?php echo esc_html( $t[0] ); ?></h3>
						<div class="g2ab-lp__tier-price"><?php echo esc_html( $t[1] ); ?><small>/<?php echo esc_html( $t[2] ); ?></small></div>
						<p><?php echo esc_html( $t[3] ); ?></p>
						<a class="g2ab-lp__btn" href="#book">CHOOSE</a>
					</div>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	private function render_booking_widget( $variant ) {
		?>
		<section id="book" class="g2ab-lp__book">
			<h2 class="g2ab-lp__h2"><span class="g2ab-lp__h2-num">03</span> RESERVE YOUR SPOT</h2>
			<?php echo do_shortcode( '[g2a_lane_booking]' ); ?>
		</section>
		<?php
	}

	private function render_faqs( $copy ) {
		if ( empty( $copy['faqs'] ) ) return;
		?>
		<section class="g2ab-lp__faqs">
			<h2 class="g2ab-lp__h2"><span class="g2ab-lp__h2-num">04</span> COMMON QUESTIONS</h2>
			<div class="g2ab-lp__faq-list">
				<?php foreach ( $copy['faqs'] as $f ) : ?>
					<details class="g2ab-lp__faq">
						<summary><?php echo esc_html( $f[0] ); ?></summary>
						<p><?php echo esc_html( $f[1] ); ?></p>
					</details>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	private function render_cta_strip( $copy ) {
		?>
		<section class="g2ab-lp__cta-strip">
			<h2><?php echo esc_html( $copy['cta'] ); ?></h2>
			<a class="g2ab-lp__hero-cta" href="#book"><?php echo esc_html( $copy['cta'] ); ?> →</a>
		</section>
		<?php
	}

	private function landing_styles() {
		return '
		.g2ab-lp{font-family:"Inter",system-ui,-apple-system,sans-serif;color:#1A191E;}
		.g2ab-lp__hero{position:relative;background:linear-gradient(135deg,#1A191E 0%,#26252C 50%,#1A191E 100%);color:#F7F7F9;padding:96px 24px 80px;overflow:hidden;border-bottom:6px solid var(--accent);}
		.g2ab-lp__hero-camo{position:absolute;inset:0;opacity:.06;background-image:repeating-linear-gradient(45deg,transparent 0,transparent 10px,#E8802F 10px,#E8802F 14px),repeating-linear-gradient(-45deg,transparent 0,transparent 16px,var(--accent) 16px,var(--accent) 19px);pointer-events:none;}
		.g2ab-lp__hero-grid{position:absolute;inset:0;background-image:linear-gradient(rgba(255,255,255,.04) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.04) 1px,transparent 1px);background-size:48px 48px;pointer-events:none;}
		.g2ab-lp__hero-inner{position:relative;max-width:1100px;margin:0 auto;text-align:center;}
		.g2ab-lp__hero-kicker{display:inline-block;background:var(--accent);color:#fff;padding:6px 14px;font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;font-size:11px;letter-spacing:.18em;font-weight:700;margin-bottom:18px;border-radius:2px;}
		.g2ab-lp__hero-title{font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;font-size:clamp(40px,7vw,72px);line-height:1.05;letter-spacing:.04em;text-transform:uppercase;color:#fff;margin:0 0 18px;}
		.g2ab-lp__hero-sub{font-size:clamp(15px,2vw,18px);color:#A7A6AE;max-width:720px;margin:0 auto 28px;line-height:1.6;}
		.g2ab-lp__hero-cta{display:inline-flex;align-items:center;gap:14px;background:var(--accent);color:#fff !important;padding:18px 32px;text-decoration:none;font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;font-size:16px;letter-spacing:.16em;font-weight:700;text-transform:uppercase;border-radius:2px;border:2px solid var(--accent);transition:all .15s ease;}
		.g2ab-lp__hero-cta:hover{background:transparent;color:var(--accent) !important;letter-spacing:.2em;}
		.g2ab-lp__facts{background:#1A191E;color:#F7F7F9;padding:0;}
		.g2ab-lp__quick-facts{max-width:1100px;margin:0 auto;display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));}
		.g2ab-lp__quick-facts>div{padding:24px;text-align:center;border-right:1px solid #2A323D;}
		.g2ab-lp__quick-facts>div:last-child{border-right:none;}
		.g2ab-lp__quick-facts strong{display:block;font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;font-size:28px;font-weight:700;color:#E8802F;line-height:1;}
		.g2ab-lp__quick-facts span{display:block;font-size:10px;color:#A7A6AE;letter-spacing:.16em;margin-top:6px;font-weight:700;}
		.g2ab-lp__h2{font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;font-size:32px;letter-spacing:.06em;text-transform:uppercase;color:#1A191E;margin:0 0 28px;display:flex;align-items:center;gap:12px;}
		.g2ab-lp__h2-num{display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;background:#E8802F;color:#fff;font-size:14px;font-weight:700;border-radius:3px;}
		.g2ab-lp__features{padding:64px 24px;background:#fff;}
		.g2ab-lp__features-grid{max-width:1100px;margin:0 auto;display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:20px;}
		.g2ab-lp__feature{padding:28px 24px;background:#f8f9fa;border-top:4px solid #E8802F;}
		.g2ab-lp__feature-icon{font-size:32px;margin-bottom:12px;}
		.g2ab-lp__feature h3{margin:0 0 8px;font-size:17px;color:#1A191E;}
		.g2ab-lp__feature p{margin:0;color:#3c434a;font-size:14px;line-height:1.6;}
		.g2ab-lp__curriculum{padding:64px 24px;background:#1A191E;color:#F7F7F9;}
		.g2ab-lp__curriculum .g2ab-lp__h2{color:#fff;}
		.g2ab-lp__curriculum-list{max-width:900px;margin:0 auto;list-style:none;padding:0;counter-reset:curr;}
		.g2ab-lp__curriculum-list li{counter-increment:curr;padding:18px 22px 18px 70px;border-left:3px solid #E8802F;background:#26252C;margin-bottom:12px;position:relative;font-size:14px;line-height:1.6;}
		.g2ab-lp__curriculum-list li::before{content:counter(curr,decimal-leading-zero);position:absolute;left:18px;top:18px;font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;font-size:24px;font-weight:700;color:#E8802F;}
		.g2ab-lp__tiers{padding:64px 24px;background:#fff;}
		.g2ab-lp__tier-grid{max-width:1100px;margin:0 auto;display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;}
		.g2ab-lp__tier{position:relative;padding:32px 28px;background:#f8f9fa;border:1px solid #d0d4d9;text-align:center;border-top:4px solid #A7A6AE;}
		.g2ab-lp__tier.is-popular{border-top-color:#E8802F;background:#fffbe6;transform:scale(1.05);box-shadow:0 8px 32px rgba(0,0,0,.08);}
		.g2ab-lp__tier-flag{position:absolute;top:0;right:0;background:#E8802F;color:#fff;padding:5px 14px;font-size:10px;letter-spacing:.1em;font-weight:700;}
		.g2ab-lp__tier h3{margin:0 0 12px;font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;font-size:22px;letter-spacing:.08em;}
		.g2ab-lp__tier-price{font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;font-size:48px;font-weight:700;color:#E8802F;line-height:1;}
		.g2ab-lp__tier-price small{font-size:14px;color:#A7A6AE;font-weight:400;margin-left:4px;}
		.g2ab-lp__tier p{color:#3c434a;font-size:14px;margin:18px 0 24px;line-height:1.6;}
		.g2ab-lp__btn{display:inline-block;background:#1A191E;color:#fff !important;padding:12px 28px;text-decoration:none;font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;font-size:13px;letter-spacing:.12em;font-weight:700;text-transform:uppercase;border-radius:2px;}
		.g2ab-lp__btn:hover{background:#E8802F;}
		.g2ab-lp__book{padding:64px 24px;background:#f0f1f3;}
		.g2ab-lp__book .g2ab-lp__h2{justify-content:center;}
		.g2ab-lp__faqs{padding:64px 24px;background:#fff;}
		.g2ab-lp__faq-list{max-width:880px;margin:0 auto;}
		.g2ab-lp__faq{background:#f8f9fa;margin-bottom:8px;border-left:3px solid #E8802F;padding:0;}
		.g2ab-lp__faq summary{padding:18px 22px;cursor:pointer;font-weight:600;font-size:15px;color:#1A191E;list-style:none;position:relative;}
		.g2ab-lp__faq summary::after{content:"+";position:absolute;right:22px;top:18px;font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;font-size:22px;color:#E8802F;font-weight:700;}
		.g2ab-lp__faq[open] summary::after{content:"−";}
		.g2ab-lp__faq p{padding:0 22px 20px;margin:0;color:#3c434a;line-height:1.7;font-size:14px;}
		.g2ab-lp__cta-strip{padding:64px 24px;background:linear-gradient(135deg,#E8802F 0%,#1A191E 100%);text-align:center;color:#fff;}
		.g2ab-lp__cta-strip h2{font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;font-size:36px;letter-spacing:.08em;text-transform:uppercase;margin:0 0 24px;color:#fff;}
		.g2ab-lp__cta-strip .g2ab-lp__hero-cta{background:#fff;color:#1A191E !important;border-color:#fff;}
		.g2ab-lp__cta-strip .g2ab-lp__hero-cta:hover{background:transparent;color:#fff !important;}
		';
	}

	public function booking_type_landing_link( $meta, $type ) {
		$meta['landing_url'] = home_url( '/event/' . $type->slug . '/' );
		return $meta;
	}
}
