<?php
/**
 * [g2a_event_banner] — full-width tactical hero for ONE featured event.
 *
 * Live countdown, spots-remaining bar, big CTA. Pure CSS animation + tiny JS
 * for the ticking timer (5 lines, no deps).
 *
 * @package G2AB
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class G2AB_Frontend_Shortcode_Banner {

	private static $instance = null;
	private $printed = false;

	public static function instance() {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'g2a_event_banner', array( $this, 'render' ) );
	}

	public function render( $atts = array() ) {
		$atts = shortcode_atts( array(
			'id'         => 0,            // specific booking_type id
			'type'       => '',           // booking type slug
			'instructor' => '',           // instructor slug (resource)
			'countdown'  => 'yes',        // show ticking countdown to next session
			'cta'        => 'RECRUIT NOW',
			'theme'      => 'dark',
		), $atts, 'g2a_event_banner' );

		global $wpdb;
		$btt = $wpdb->prefix . 'g2ab_booking_types';
		$bt  = $wpdb->prefix . 'g2ab_bookings';
		$rt  = $wpdb->prefix . 'g2ab_resources';

		// Pick the booking type.
		if ( $atts['id'] ) {
			$type = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$btt} WHERE id = %d AND is_active = 1", (int) $atts['id'] ) );
		} elseif ( $atts['type'] ) {
			$type = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$btt} WHERE slug = %s AND is_active = 1", sanitize_title( $atts['type'] ) ) );
		} else {
			// Auto-pick: first active class type.
			$type = $wpdb->get_row( "SELECT * FROM {$btt} WHERE is_active = 1 AND category = 'class' ORDER BY id ASC LIMIT 1" );
		}

		if ( ! $type ) {
			return '<div class="g2ab-banr g2ab-banr--empty"><p>' . esc_html__( 'No featured event configured.', 'g2a-booking' ) . '</p></div>';
		}

		// Find next session start (use next hour as fallback if no scheduled bookings).
		$now = current_time( 'mysql' );
		$next_at = $wpdb->get_var( $wpdb->prepare(
			"SELECT MIN(start_at) FROM {$bt} WHERE booking_type_id = %d AND start_at > %s AND status IN ('pending','reserved','confirmed','paid') GROUP BY DATE(start_at)",
			$type->id, $now
		) );

		// If no scheduled bookings, project next default slot (next business day at 10am).
		if ( ! $next_at ) {
			$next_at = wp_date( 'Y-m-d H:i:s', strtotime( 'tomorrow 10:00:00' ) );
		}

		// Capacity & seats.
		$resource_id = (int) $wpdb->get_var( "SELECT id FROM {$rt} WHERE is_active = 1 AND type IN ('classroom','instructor') ORDER BY sort_order ASC LIMIT 1" );
		$capacity    = $resource_id ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT capacity FROM {$rt} WHERE id = %d", $resource_id ) ) : 12;

		$booked = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$bt} WHERE booking_type_id = %d AND DATE(start_at) = DATE(%s) AND status IN ('pending','reserved','confirmed','paid')",
			$type->id, $next_at
		) );
		$spots_left = max( 0, $capacity - $booked );
		$pct_full   = $capacity > 0 ? min( 100, (int) round( ( $booked / $capacity ) * 100 ) ) : 0;

		$ts        = strtotime( $next_at );
		$book_url  = $this->book_url( $type );

		ob_start();
		$this->print_styles();
		?>
		<section class="g2ab-banr g2ab-banr--theme-<?php echo esc_attr( $atts['theme'] ); ?>">
			<div class="g2ab-banr__camo"></div>
			<div class="g2ab-banr__grid"></div>

			<div class="g2ab-banr__inner">
				<div class="g2ab-banr__left">
					<div class="g2ab-banr__chip"><?php echo esc_html( strtoupper( $type->category ) ); ?> · <?php esc_html_e( 'NEXT MISSION', 'g2a-booking' ); ?></div>
					<h2 class="g2ab-banr__title"><?php echo esc_html( $type->name ); ?></h2>
					<div class="g2ab-banr__date">
						<div class="g2ab-banr__date-day"><?php echo esc_html( wp_date( 'M j', $ts ) ); ?></div>
						<div class="g2ab-banr__date-time"><?php echo esc_html( wp_date( 'l · g:i A', $ts ) ); ?></div>
					</div>
					<div class="g2ab-banr__meta">
						<span><span class="g2ab-banr__icon">⏱</span> <?php echo (int) $type->duration_min; ?> MIN</span>
						<span><span class="g2ab-banr__icon">$</span> <?php echo esc_html( number_format( (float) $type->base_price, 2 ) ); ?></span>
						<?php if ( (int) $type->requires_waiver ) : ?>
							<span><span class="g2ab-banr__icon">⚠</span> WAIVER REQ</span>
						<?php endif; ?>
					</div>
					<a class="g2ab-banr__cta" href="<?php echo esc_url( $book_url ); ?>">
						<span><?php echo esc_html( $atts['cta'] ); ?></span>
						<span class="g2ab-banr__cta-arrow">→</span>
					</a>
				</div>

				<div class="g2ab-banr__right">
					<?php if ( 'yes' === $atts['countdown'] ) : ?>
						<div class="g2ab-banr__count" data-g2ab-countdown="<?php echo esc_attr( $ts ); ?>">
							<div class="g2ab-banr__count-label"><?php esc_html_e( 'TIME TO DEPLOYMENT', 'g2a-booking' ); ?></div>
							<div class="g2ab-banr__count-grid">
								<div class="g2ab-banr__count-cell"><span data-d>00</span><small>D</small></div>
								<div class="g2ab-banr__count-cell"><span data-h>00</span><small>H</small></div>
								<div class="g2ab-banr__count-cell"><span data-m>00</span><small>M</small></div>
								<div class="g2ab-banr__count-cell"><span data-s>00</span><small>S</small></div>
							</div>
						</div>
					<?php endif; ?>
					<div class="g2ab-banr__seats">
						<div class="g2ab-banr__seats-row">
							<span><?php echo (int) $booked; ?> / <?php echo (int) $capacity; ?> LOCKED IN</span>
							<span class="g2ab-banr__seats-left"><?php echo (int) $spots_left; ?> SPOTS LEFT</span>
						</div>
						<div class="g2ab-banr__seats-bar">
							<div class="g2ab-banr__seats-fill" style="width:<?php echo (int) $pct_full; ?>%;"></div>
						</div>
					</div>
				</div>
			</div>
		</section>
		<?php
		return ob_get_clean();
	}

	private function book_url( $type ) {
		$page = get_option( 'g2ab_booking_page_url', '' );
		if ( $page ) return add_query_arg( 'type', $type->slug, $page );
		return home_url( '/?g2ab_book=' . $type->slug );
	}

	private function print_styles() {
		if ( $this->printed ) return;
		$this->printed = true;
		?>
		<style id="g2ab-banr-styles">
		.g2ab-banr{position:relative;background:linear-gradient(135deg,#0F1115 0%,#1A1F26 60%,#0F1115 100%);color:#E8E8E8;overflow:hidden;border:1px solid #2A323D;margin:24px 0;font-family:'Inter',system-ui,-apple-system,sans-serif;}
		.g2ab-banr__camo{position:absolute;inset:0;opacity:.05;background-image:repeating-linear-gradient(45deg,transparent 0,transparent 12px,#4A5D3A 12px,#4A5D3A 16px),repeating-linear-gradient(-45deg,transparent 0,transparent 18px,#D2691E 18px,#D2691E 22px);pointer-events:none;}
		.g2ab-banr__grid{position:absolute;inset:0;background-image:linear-gradient(rgba(74,93,58,.08) 1px,transparent 1px),linear-gradient(90deg,rgba(74,93,58,.08) 1px,transparent 1px);background-size:40px 40px;pointer-events:none;}
		.g2ab-banr__inner{position:relative;display:grid;grid-template-columns:1.2fr 1fr;gap:40px;padding:40px 48px;max-width:1200px;margin:0 auto;align-items:center;}
		@media (max-width:780px){.g2ab-banr__inner{grid-template-columns:1fr;padding:28px 22px;gap:24px;}}
		.g2ab-banr__chip{display:inline-block;background:#D2691E;color:#fff;padding:5px 12px;font-size:10px;letter-spacing:.18em;font-weight:700;font-family:'Rajdhani','Oswald',Impact,sans-serif;margin-bottom:14px;border-radius:2px;}
		.g2ab-banr__title{margin:0 0 18px;font-family:'Rajdhani','Oswald',Impact,sans-serif;font-size:48px;font-weight:700;letter-spacing:.04em;line-height:1.05;color:#fff;text-transform:uppercase;text-shadow:3px 3px 0 #4A5D3A;}
		@media (max-width:780px){.g2ab-banr__title{font-size:32px;text-shadow:2px 2px 0 #4A5D3A;}}
		.g2ab-banr__date{display:flex;align-items:baseline;gap:14px;margin-bottom:14px;border-left:4px solid #D2691E;padding-left:14px;}
		.g2ab-banr__date-day{font-family:'Rajdhani','Oswald',Impact,sans-serif;font-size:28px;font-weight:700;color:#D2691E;letter-spacing:.04em;}
		.g2ab-banr__date-time{font-size:13px;color:#8A95A5;letter-spacing:.06em;text-transform:uppercase;}
		.g2ab-banr__meta{display:flex;flex-wrap:wrap;gap:18px;font-size:11px;letter-spacing:.1em;color:#8A95A5;font-weight:700;margin-bottom:24px;}
		.g2ab-banr__meta span{display:inline-flex;align-items:center;gap:5px;}
		.g2ab-banr__icon{color:#D2691E;font-weight:400;}
		.g2ab-banr__cta{display:inline-flex;align-items:center;gap:14px;background:#D2691E;color:#fff !important;padding:18px 32px;text-decoration:none;font-family:'Rajdhani','Oswald',Impact,sans-serif;font-size:16px;letter-spacing:.16em;font-weight:700;text-transform:uppercase;border-radius:2px;transition:all .15s ease;border:2px solid #D2691E;}
		.g2ab-banr__cta:hover{background:transparent;color:#D2691E !important;letter-spacing:.2em;}
		.g2ab-banr__cta-arrow{font-size:22px;}
		.g2ab-banr__right{display:flex;flex-direction:column;gap:24px;}
		.g2ab-banr__count{background:rgba(0,0,0,.4);padding:18px 20px;border:1px solid #2A323D;border-left:3px solid #D2691E;}
		.g2ab-banr__count-label{font-size:10px;letter-spacing:.18em;color:#8A95A5;font-weight:700;margin-bottom:12px;}
		.g2ab-banr__count-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;}
		.g2ab-banr__count-cell{text-align:center;background:#0F1115;padding:12px 8px;border-radius:2px;border:1px solid #2A323D;}
		.g2ab-banr__count-cell span{display:block;font-family:'Rajdhani','SF Mono','Monaco',monospace;font-size:36px;font-weight:700;color:#D2691E;line-height:1;}
		.g2ab-banr__count-cell small{display:block;font-size:9px;color:#8A95A5;letter-spacing:.16em;margin-top:4px;font-weight:700;}
		.g2ab-banr__seats-row{display:flex;justify-content:space-between;font-size:11px;letter-spacing:.1em;color:#8A95A5;font-weight:700;margin-bottom:8px;}
		.g2ab-banr__seats-left{color:#4CAF50;}
		.g2ab-banr__seats-bar{height:8px;background:#0F1115;overflow:hidden;border:1px solid #2A323D;border-radius:1px;}
		.g2ab-banr__seats-fill{height:100%;background:linear-gradient(90deg,#4A5D3A 0,#D2691E 70%,#C62828 100%);transition:width .4s ease;}
		.g2ab-banr--empty{padding:30px;background:#1A1F26;color:#8A95A5;text-align:center;border-left:3px solid #D2691E;}
		</style>
		<script id="g2ab-banr-js">
		(function(){
			document.querySelectorAll('[data-g2ab-countdown]').forEach(function(el){
				var ts = parseInt(el.getAttribute('data-g2ab-countdown'), 10) * 1000;
				function tick(){
					var d = ts - Date.now();
					if(d < 0) d = 0;
					var days = Math.floor(d/86400000);
					var hrs  = Math.floor((d%86400000)/3600000);
					var mins = Math.floor((d%3600000)/60000);
					var secs = Math.floor((d%60000)/1000);
					var pad = function(n){return n<10?'0'+n:''+n;};
					var dd=el.querySelector('[data-d]'),hh=el.querySelector('[data-h]'),mm=el.querySelector('[data-m]'),ss=el.querySelector('[data-s]');
					if(dd) dd.textContent=pad(days);
					if(hh) hh.textContent=pad(hrs);
					if(mm) mm.textContent=pad(mins);
					if(ss) ss.textContent=pad(secs);
				}
				tick(); setInterval(tick, 1000);
			});
		})();
		</script>
		<?php
	}
}
