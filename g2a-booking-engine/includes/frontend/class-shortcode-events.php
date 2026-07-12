<?php
/**
 * [g2a_upcoming_events] — clean, premium event listing (Amelia-style).
 *
 * Two layouts:
 *   layout="list"  a tidy vertical list — date badge · name + status · price · button
 *                  (one row per upcoming date), with an optional search box.
 *   layout="card"  premium event cards (one per event, showing the next date).
 *
 * Scope it to a page:
 *   category="ccw-class"   only CCW classes
 *   category="ladies"      only Ladies night events
 *   event="az-ccw-classroom"  a single event's upcoming dates
 *   (no scope)             every upcoming event
 *
 * Self-registers; assets emitted in wp_footer so the_content can't mangle them.
 *
 * @package G2AB
 * @since   1.0.0 (rebuilt 1.9.9)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Frontend_Shortcode_Events {

	private static $instance = null;
	private static $css_done = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'g2a_upcoming_events', array( $this, 'render' ) );
	}

	public function render( $atts = array() ) {
		if ( ! class_exists( 'G2AB_Events' ) ) {
			return '';
		}
		$atts = shortcode_atts( array(
			'layout'           => 'list',     // 'list' | 'card'
			'category'         => '',
			'event'            => '',          // single event id or slug
			'limit'            => 12,
			'theme'            => 'dark',      // 'dark' | 'light'
			'members_only'     => '',
			'show_book_button' => 'yes',
			'heading'          => '',
			'search'           => '',          // 'yes' to show a search box (list only)
		), $atts, 'g2a_upcoming_events' );

		// Resolve the candidate events.
		if ( '' !== $atts['event'] ) {
			$ev = G2AB_Events::get_event( is_numeric( $atts['event'] ) ? (int) $atts['event'] : sanitize_title( $atts['event'] ) );
			$events = ( $ev && 'publish' === $ev->status ) ? array( $ev ) : array();
		} else {
			$events = G2AB_Events::get_events( array(
				'status'   => 'publish',
				'category' => $atts['category'] ? sanitize_key( $atts['category'] ) : '',
				'limit'    => 200,
			) );
		}

		$layout = ( 'card' === $atts['layout'] ) ? 'card' : 'list';
		$theme  = ( 'light' === $atts['theme'] ) ? 'light' : 'dark';
		$limit  = max( 1, (int) $atts['limit'] );

		if ( 'card' === $layout ) {
			$rows = $this->collect_cards( $events, $atts, $limit );
		} else {
			$rows = $this->collect_occurrences( $events, $atts, $limit );
		}

		if ( empty( $rows ) ) {
			$this->enqueue_css();
			return '<div class="g2ab-evt g2ab-evt--' . esc_attr( $theme ) . ' g2ab-evt--empty"><p>' . esc_html__( 'No upcoming events right now — check back soon.', 'g2a-booking' ) . '</p></div>';
		}

		$this->enqueue_css();
		ob_start();
		if ( 'card' === $layout ) {
			$this->render_cards( $rows, $atts, $theme );
		} else {
			$this->render_list( $rows, $atts, $theme );
		}
		return ob_get_clean();
	}

	/* ───────────────────────── data ───────────────────────── */

	/** One row per upcoming occurrence, across all matching events, soonest first. */
	private function collect_occurrences( $events, $atts, $limit ) {
		$is_member = $this->viewer_is_member();
		$rows = array();
		foreach ( $events as $ev ) {
			if ( ! $this->passes_members_filter( $ev, $atts ) ) {
				continue;
			}
			$occs = G2AB_Events::get_occurrences( $ev->id, array( 'upcoming_only' => true, 'limit' => 60 ) );
			foreach ( $occs as $o ) {
				$price = G2AB_Events::price_for( $ev, $o, $is_member );
				$free  = ( (int) $ev->is_free === 1 || (float) $price <= 0 );
				$rows[] = array(
					'ev'      => $ev,
					'ts'      => G2AB_Events::timestamp( $o->start_at ),
					'start'   => $o->start_at,
					'left'    => (int) $o->seats_left,
					'total'   => (int) $o->seats_total,
					'soldout' => (int) $o->seats_left <= 0,
					'free'    => $free,
					'price'   => $free ? 0.0 : (float) $price,
				);
			}
		}
		usort( $rows, static function ( $a, $b ) { return $a['ts'] <=> $b['ts']; } );
		return array_slice( $rows, 0, $limit );
	}

	/** One card per event (its next upcoming occurrence). */
	private function collect_cards( $events, $atts, $limit ) {
		$is_member = $this->viewer_is_member();
		$rows = array();
		foreach ( $events as $ev ) {
			if ( ! $this->passes_members_filter( $ev, $atts ) ) {
				continue;
			}
			$next = G2AB_Events::next_occurrence( $ev->id );
			if ( ! $next ) {
				continue;
			}
			$price = G2AB_Events::price_for( $ev, $next, $is_member );
			$free  = ( (int) $ev->is_free === 1 || (float) $price <= 0 );
			$rows[] = array(
				'ev'      => $ev,
				'ts'      => G2AB_Events::timestamp( $next->start_at ),
				'start'   => $next->start_at,
				'left'    => (int) $next->seats_left,
				'total'   => (int) $next->seats_total,
				'soldout' => (int) $next->seats_left <= 0,
				'free'    => $free,
				'price'   => $free ? 0.0 : (float) $price,
				'dates'   => count( G2AB_Events::get_occurrences( $ev->id, array( 'upcoming_only' => true, 'with_seats' => false, 'limit' => 60 ) ) ),
			);
			if ( count( $rows ) >= $limit ) {
				break;
			}
		}
		usort( $rows, static function ( $a, $b ) { return $a['ts'] <=> $b['ts']; } );
		return $rows;
	}

	private function viewer_is_member() {
		$uid = get_current_user_id();
		$base = $uid ? ( function_exists( 'memberistic_user_has_active_membership' ) ? (bool) memberistic_user_has_active_membership( $uid ) : current_user_can( 'manage_g2ab_bookings' ) ) : false;
		return (bool) apply_filters( 'g2ab_user_is_member', $base, $uid, (object) array( 'members_only' => 1 ), '' );
	}

	private function passes_members_filter( $ev, $atts ) {
		if ( 'yes' === $atts['members_only'] && (int) $ev->members_only !== 1 ) {
			return false;
		}
		if ( 'no' === $atts['members_only'] && (int) $ev->members_only === 1 ) {
			return false;
		}
		return true;
	}

	private function book_url( $event ) {
		return home_url( '/event/' . $event->slug . '/' );
	}

	/* ───────────────────────── list layout ───────────────────────── */

	private function render_list( $rows, $atts, $theme ) {
		$show_search = ( 'yes' === $atts['search'] || count( $rows ) >= 6 );
		?>
		<div class="g2ab-evl g2ab-evl--<?php echo esc_attr( $theme ); ?>" data-g2ab-evl>
			<div class="g2ab-evl__head">
				<div class="g2ab-evl__count"><strong><?php echo (int) count( $rows ); ?></strong> <?php echo esc_html( _n( 'event available', 'events available', count( $rows ), 'g2a-booking' ) ); ?></div>
				<?php if ( $atts['heading'] ) : ?><h2 class="g2ab-evl__heading"><?php echo esc_html( $atts['heading'] ); ?></h2><?php endif; ?>
				<?php if ( $show_search ) : ?>
					<div class="g2ab-evl__search"><span class="g2ab-evl__search-i">⌕</span><input type="search" data-evl-search placeholder="<?php esc_attr_e( 'Search events…', 'g2a-booking' ); ?>" /></div>
				<?php endif; ?>
			</div>
			<div class="g2ab-evl__rows">
				<?php foreach ( $rows as $r ) : $ev = $r['ev']; $accent = $ev->color ?: '#E8802F'; ?>
					<a class="g2ab-evl__row<?php echo $r['soldout'] ? ' is-out' : ''; ?>" href="<?php echo esc_url( $this->book_url( $ev ) ); ?>" style="--accent:<?php echo esc_attr( $accent ); ?>;" data-evl-name="<?php echo esc_attr( strtolower( $ev->title . ' ' . G2AB_Events::category_label( $ev->category ) ) ); ?>">
						<div class="g2ab-evl__date">
							<span class="g2ab-evl__d"><?php echo esc_html( G2AB_Events::format_local( $r['start'], 'd' ) ); ?></span>
							<span class="g2ab-evl__mo"><?php echo esc_html( strtoupper( G2AB_Events::format_local( $r['start'], 'M' ) ) ); ?></span>
							<span class="g2ab-evl__ti"><?php echo esc_html( G2AB_Events::format_local( $r['start'], 'g:i A' ) ); ?></span>
						</div>
						<div class="g2ab-evl__main">
							<span class="g2ab-evl__tag"><?php echo esc_html( strtoupper( G2AB_Events::category_label( $ev->category ) ) ); ?></span>
							<span class="g2ab-evl__name"><?php echo esc_html( $ev->title ); ?></span>
							<span class="g2ab-evl__sub">
								<?php if ( $r['soldout'] ) : ?>
									<span class="g2ab-evl__status is-out"><?php esc_html_e( 'Sold out', 'g2a-booking' ); ?></span>
								<?php else : ?>
									<span class="g2ab-evl__status is-open"><?php esc_html_e( 'Open', 'g2a-booking' ); ?></span>
									<span class="g2ab-evl__slots"><?php echo (int) $r['left']; ?> <?php esc_html_e( 'seats left', 'g2a-booking' ); ?></span>
								<?php endif; ?>
								<?php if ( $ev->location ) : ?><span class="g2ab-evl__loc">· <?php echo esc_html( $ev->location ); ?></span><?php endif; ?>
								<?php if ( (int) $ev->requires_waiver ) : ?><span class="g2ab-evl__loc">· <?php esc_html_e( 'waiver', 'g2a-booking' ); ?></span><?php endif; ?>
							</span>
						</div>
						<div class="g2ab-evl__right">
							<span class="g2ab-evl__price"><?php echo $r['free'] ? esc_html__( 'FREE', 'g2a-booking' ) : '$' . esc_html( number_format( $r['price'], 2 ) ); ?></span>
							<?php if ( 'yes' === $atts['show_book_button'] ) : ?>
								<span class="g2ab-evl__btn"><?php echo $r['soldout'] ? esc_html__( 'Details', 'g2a-booking' ) : esc_html__( 'Reserve', 'g2a-booking' ); ?> →</span>
							<?php endif; ?>
						</div>
					</a>
				<?php endforeach; ?>
				<div class="g2ab-evl__noresults" data-evl-none hidden><?php esc_html_e( 'No events match your search.', 'g2a-booking' ); ?></div>
			</div>
		</div>
		<?php
		$this->enqueue_search_js();
	}

	/* ───────────────────────── card layout ───────────────────────── */

	private function render_cards( $rows, $atts, $theme ) {
		?>
		<div class="g2ab-evc g2ab-evc--<?php echo esc_attr( $theme ); ?>">
			<?php if ( $atts['heading'] ) : ?><h2 class="g2ab-evc__heading"><?php echo esc_html( $atts['heading'] ); ?></h2><?php endif; ?>
			<div class="g2ab-evc__grid">
				<?php foreach ( $rows as $r ) : $ev = $r['ev']; $accent = $ev->color ?: '#E8802F'; $pct = $r['total'] > 0 ? min( 100, (int) round( ( ( $r['total'] - $r['left'] ) / $r['total'] ) * 100 ) ) : 0; ?>
					<article class="g2ab-evc__card<?php echo $r['soldout'] ? ' is-out' : ''; ?>" style="--accent:<?php echo esc_attr( $accent ); ?>;">
						<div class="g2ab-evc__top">
							<span class="g2ab-evc__tag"><?php echo esc_html( strtoupper( G2AB_Events::category_label( $ev->category ) ) ); ?></span>
							<?php if ( (int) $ev->members_only ) : ?><span class="g2ab-evc__members">★ <?php esc_html_e( 'MEMBERS', 'g2a-booking' ); ?></span><?php endif; ?>
						</div>
						<h3 class="g2ab-evc__name"><?php echo esc_html( $ev->title ); ?></h3>
						<?php if ( $ev->summary ) : ?><p class="g2ab-evc__sum"><?php echo esc_html( $ev->summary ); ?></p><?php endif; ?>
						<div class="g2ab-evc__when">
							<span class="g2ab-evc__when-d"><?php echo esc_html( G2AB_Events::format_local( $r['start'], 'D, M j' ) ); ?></span>
							<span class="g2ab-evc__when-t"><?php echo esc_html( G2AB_Events::format_local( $r['start'], 'g:i A' ) ); ?></span>
							<?php if ( (int) $r['dates'] > 1 ) : ?><span class="g2ab-evc__more">+<?php echo (int) $r['dates'] - 1; ?> <?php esc_html_e( 'more dates', 'g2a-booking' ); ?></span><?php endif; ?>
						</div>
						<div class="g2ab-evc__meta">
							<span>⏱ <?php echo (int) $ev->duration_min; ?> <?php esc_html_e( 'min', 'g2a-booking' ); ?></span>
							<?php if ( $ev->location ) : ?><span>⌖ <?php echo esc_html( $ev->location ); ?></span><?php endif; ?>
							<?php if ( (int) $ev->requires_waiver ) : ?><span>⚠ <?php esc_html_e( 'Waiver', 'g2a-booking' ); ?></span><?php endif; ?>
						</div>
						<div class="g2ab-evc__seats">
							<div class="g2ab-evc__seats-row">
								<span><?php echo $r['soldout'] ? esc_html__( 'Sold out', 'g2a-booking' ) : ( (int) $r['left'] . ' ' . esc_html__( 'of', 'g2a-booking' ) . ' ' . (int) $r['total'] . ' ' . esc_html__( 'open', 'g2a-booking' ) ); ?></span>
								<span class="g2ab-evc__price"><?php echo $r['free'] ? esc_html__( 'FREE', 'g2a-booking' ) : '$' . esc_html( number_format( $r['price'], 2 ) ); ?></span>
							</div>
							<div class="g2ab-evc__bar"><div class="g2ab-evc__fill" style="width:<?php echo (int) $pct; ?>%;"></div></div>
						</div>
						<?php if ( 'yes' === $atts['show_book_button'] ) : ?>
							<a class="g2ab-evc__cta" href="<?php echo esc_url( $this->book_url( $ev ) ); ?>"><?php echo $r['soldout'] ? esc_html__( 'View details', 'g2a-booking' ) : esc_html__( 'Reserve a seat', 'g2a-booking' ); ?> <span class="g2ab-evc__arrow">→</span></a>
						<?php endif; ?>
					</article>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/* ───────────────────────── assets ───────────────────────── */

	private function enqueue_css() {
		if ( self::$css_done ) {
			return;
		}
		self::$css_done = true;
		$hook = is_admin() ? 'admin_footer' : 'wp_footer';
		add_action( $hook, array( __CLASS__, 'print_css' ) );
		if ( did_action( $hook ) ) {
			self::print_css();
		}
	}

	private function enqueue_search_js() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		$hook = is_admin() ? 'admin_footer' : 'wp_footer';
		add_action( $hook, array( __CLASS__, 'print_search_js' ) );
		if ( did_action( $hook ) ) {
			self::print_search_js();
		}
	}

	public static function print_search_js() {
		?>
		<script>
		(function(){
			document.querySelectorAll('[data-g2ab-evl]').forEach(function(root){
				if (root.dataset.evlBooted) return; root.dataset.evlBooted='1';
				var s = root.querySelector('[data-evl-search]'); if (!s) return;
				var rows = Array.prototype.slice.call(root.querySelectorAll('.g2ab-evl__row'));
				var none = root.querySelector('[data-evl-none]');
				s.addEventListener('input', function(){
					var q = s.value.trim().toLowerCase(), shown = 0;
					rows.forEach(function(r){ var hit = !q || (r.getAttribute('data-evl-name')||'').indexOf(q) !== -1; r.style.display = hit ? '' : 'none'; if (hit) shown++; });
					if (none) none.hidden = shown !== 0;
				});
			});
		})();
		</script>
		<?php
	}

	public static function print_css() {
		?>
		<style id="g2ab-evt-styles">
		.g2ab-evl,.g2ab-evc{--bg:#1A191E;--surface:#15191F;--surface2:#1C232C;--border:#2A323D;--text:#EDEFF2;--muted:#9AA4B2;--orange:#E8802F;--green:#36C26B;--red:#E5484D;font-family:'Inter',system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;margin:26px 0;}
		.g2ab-evl--light,.g2ab-evc--light{--bg:#FFFFFF;--surface:#FFFFFF;--surface2:#F4F6FA;--border:#E5E9F0;--text:#1C2533;--muted:#6B7686;}
		.g2ab-evl *,.g2ab-evc *{box-sizing:border-box;}
		/* ── list ── */
		.g2ab-evl__head{display:flex;flex-wrap:wrap;align-items:center;gap:12px 18px;margin-bottom:16px;}
		/* Header sits on the page (not a card) — inherit the page's text colour so
		   it stays readable on light OR dark pages regardless of the list theme. */
		.g2ab-evl__count{font-size:14px;color:inherit;opacity:.7;}
		.g2ab-evl__count strong{color:var(--orange);opacity:1;font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:18px;}
		.g2ab-evl__heading{margin:0;font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:20px;letter-spacing:.03em;text-transform:uppercase;color:inherit;}
		.g2ab-evl__search{position:relative;margin-left:auto;}
		.g2ab-evl__search-i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:16px;}
		.g2ab-evl__search input{background:var(--surface);border:1px solid var(--border);border-radius:10px;color:var(--text);padding:10px 14px 10px 34px;font-size:14px;min-width:240px;}
		.g2ab-evl__search input:focus{outline:none;border-color:var(--orange);}
		.g2ab-evl__rows{display:flex;flex-direction:column;gap:10px;}
		.g2ab-evl__row{display:grid;grid-template-columns:84px 1fr auto;gap:18px;align-items:center;background:var(--surface);border:1px solid var(--border);border-left:4px solid var(--accent,#E8802F);border-radius:12px;padding:16px 20px;text-decoration:none;transition:transform .14s ease,box-shadow .14s ease,border-color .14s ease;animation:g2ab-evl-in .35s ease both;}
		.g2ab-evl__row:hover{transform:translateX(3px);box-shadow:0 10px 26px rgba(0,0,0,.22);}
		.g2ab-evl__row.is-out{opacity:.62;}
		@keyframes g2ab-evl-in{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:translateY(0);}}
		.g2ab-evl__date{text-align:center;border-right:1px solid var(--border);padding-right:14px;}
		.g2ab-evl__d{display:block;font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:30px;font-weight:700;color:var(--text);line-height:.95;}
		.g2ab-evl__mo{display:block;font-size:11px;font-weight:700;letter-spacing:.12em;color:var(--accent,#E8802F);}
		.g2ab-evl__ti{display:block;font-size:11px;color:var(--muted);margin-top:3px;}
		.g2ab-evl__main{min-width:0;}
		.g2ab-evl__tag{display:inline-block;font-size:9px;font-weight:700;letter-spacing:.12em;color:var(--accent,#E8802F);margin-bottom:3px;}
		.g2ab-evl__name{display:block;font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:20px;font-weight:700;color:var(--text);letter-spacing:.01em;text-transform:uppercase;line-height:1.1;}
		.g2ab-evl__sub{display:flex;flex-wrap:wrap;align-items:center;gap:7px;font-size:13px;color:var(--muted);margin-top:4px;}
		.g2ab-evl__status{font-weight:700;}
		.g2ab-evl__status.is-open{color:var(--green);}
		.g2ab-evl__status.is-out{color:var(--red);}
		.g2ab-evl__slots{color:var(--muted);}
		.g2ab-evl__right{display:flex;flex-direction:column;align-items:flex-end;gap:8px;}
		.g2ab-evl__price{font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:24px;font-weight:700;color:var(--accent,#E8802F);line-height:1;}
		.g2ab-evl__btn{display:inline-block;background:var(--accent,#E8802F);color:#fff;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;padding:8px 16px;border-radius:8px;white-space:nowrap;transition:filter .14s ease;}
		.g2ab-evl__row:hover .g2ab-evl__btn{filter:brightness(1.1);}
		.g2ab-evl__noresults{color:var(--muted);text-align:center;padding:24px;}
		@media (max-width:620px){.g2ab-evl__row{grid-template-columns:64px 1fr;}.g2ab-evl__right{grid-column:1/-1;flex-direction:row;justify-content:space-between;align-items:center;border-top:1px solid var(--border);padding-top:10px;}.g2ab-evl__search input{min-width:0;width:100%;}.g2ab-evl__search{width:100%;margin-left:0;}}
		/* ── cards ── */
		.g2ab-evc__heading{font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:22px;letter-spacing:.03em;text-transform:uppercase;color:inherit;margin:0 0 16px;}
		.g2ab-evc__grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:18px;}
		.g2ab-evc__card{display:flex;flex-direction:column;gap:9px;background:var(--surface);border:1px solid var(--border);border-top:4px solid var(--accent,#E8802F);border-radius:14px;padding:20px;transition:transform .14s ease,box-shadow .14s ease;animation:g2ab-evl-in .35s ease both;}
		.g2ab-evc__card:hover{transform:translateY(-4px);box-shadow:0 16px 36px rgba(0,0,0,.22);}
		.g2ab-evc__card.is-out{opacity:.66;}
		.g2ab-evc__top{display:flex;justify-content:space-between;align-items:center;}
		.g2ab-evc__tag{display:inline-block;background:var(--accent,#E8802F);color:#fff;font-size:9px;font-weight:700;letter-spacing:.1em;padding:4px 9px;border-radius:3px;}
		.g2ab-evc__members{font-size:9px;letter-spacing:.08em;color:#F2B33D;font-weight:700;}
		.g2ab-evc__name{margin:2px 0 0;font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:21px;font-weight:700;color:var(--text);text-transform:uppercase;letter-spacing:.01em;line-height:1.12;}
		.g2ab-evc__sum{margin:0;color:var(--muted);font-size:13px;line-height:1.45;}
		.g2ab-evc__when{display:flex;flex-wrap:wrap;align-items:baseline;gap:10px;padding:8px 0;border-top:1px solid var(--border);border-bottom:1px solid var(--border);}
		.g2ab-evc__when-d{font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:17px;font-weight:700;color:var(--text);}
		.g2ab-evc__when-t{font-size:13px;color:var(--muted);}
		.g2ab-evc__more{margin-left:auto;font-size:11px;color:var(--accent,#E8802F);font-weight:700;}
		.g2ab-evc__meta{display:flex;flex-wrap:wrap;gap:6px 14px;font-size:12px;color:var(--muted);}
		.g2ab-evc__seats{margin-top:2px;}
		.g2ab-evc__seats-row{display:flex;justify-content:space-between;align-items:baseline;font-size:12px;color:var(--muted);font-weight:600;margin-bottom:6px;}
		.g2ab-evc__price{font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:22px;font-weight:700;color:var(--accent,#E8802F);}
		.g2ab-evc__bar{height:6px;background:var(--surface2);border-radius:999px;overflow:hidden;}
		.g2ab-evc__fill{height:100%;background:linear-gradient(90deg,var(--green),var(--accent,#E8802F));transition:width .6s ease;}
		.g2ab-evc__cta{display:flex;justify-content:center;align-items:center;gap:10px;margin-top:auto;background:var(--accent,#E8802F);color:#fff !important;text-decoration:none;font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:14px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:13px;border-radius:9px;border:2px solid var(--accent,#E8802F);transition:all .15s ease;}
		.g2ab-evc__cta:hover{background:transparent;color:var(--accent,#E8802F) !important;}
		.g2ab-evc__arrow{transition:transform .15s ease;}
		.g2ab-evc__cta:hover .g2ab-evc__arrow{transform:translateX(4px);}
		.g2ab-evl--empty,.g2ab-evc--empty,.g2ab-evt--empty{padding:30px;background:var(--surface,#15191F);border:1px dashed var(--border,#2A323D);border-radius:12px;color:var(--muted,#9AA4B2);text-align:center;}
		.g2ab-evt--light.g2ab-evt--empty{--surface:#fff;--border:#E5E9F0;--muted:#6B7686;}
		</style>
		<?php
	}
}
