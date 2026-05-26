<?php
/**
 * [g2a_upcoming_events] — list of upcoming bookable events/classes.
 *
 * Self-registers shortcode + tactical styles. Pure SVG/CSS, no JS deps.
 * Reads from booking_types where category='class' AND active, joins with
 * existing bookings to compute spots remaining.
 *
 * @package G2AB
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class G2AB_Frontend_Shortcode_Events {

	private static $instance = null;
	private $printed = false;

	public static function instance() {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'g2a_upcoming_events', array( $this, 'render' ) );
	}

	public function render( $atts = array() ) {
		$atts = shortcode_atts( array(
			'limit'             => 6,
			'type'              => '',          // booking type slug
			'category'          => '',          // 'class', 'lane', 'membership'
			'layout'            => 'grid',      // 'grid' or 'list'
			'show_book_button'  => 'yes',
			'days_ahead'        => 60,
			'members_only'      => '',          // 'yes' / 'no' / ''
			'theme'             => 'dark',
		), $atts, 'g2a_upcoming_events' );

		global $wpdb;
		$btt = $wpdb->prefix . 'g2ab_booking_types';
		$bt  = $wpdb->prefix . 'g2ab_bookings';
		$rt  = $wpdb->prefix . 'g2ab_resources';

		$where = " WHERE t.is_active = 1 ";
		$args  = array();

		if ( $atts['type'] ) {
			$where .= " AND t.slug = %s ";
			$args[] = sanitize_title( $atts['type'] );
		} elseif ( $atts['category'] ) {
			$where .= " AND t.category = %s ";
			$args[] = sanitize_key( $atts['category'] );
		} else {
			// Default: classes (the typical "events" use case).
			$where .= " AND t.category IN ('class','membership') ";
		}

		if ( 'yes' === $atts['members_only'] ) $where .= " AND t.members_only = 1 ";
		if ( 'no'  === $atts['members_only'] ) $where .= " AND t.members_only = 0 ";

		$sql = "SELECT t.* FROM {$btt} t {$where} ORDER BY t.id ASC LIMIT %d";
		$args[] = max( 1, (int) $atts['limit'] );
		$types  = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );

		if ( empty( $types ) ) {
			return $this->render_empty( __( 'No upcoming events.', 'g2a-booking' ) );
		}

		// For each type, compute next available slot + seat counts.
		$now = current_time( 'mysql' );
		$end = wp_date( 'Y-m-d 23:59:59', strtotime( '+' . max( 1, (int) $atts['days_ahead'] ) . ' days' ) );

		$events = array();
		foreach ( $types as $t ) {
			$settings = json_decode( (string) ( $t->settings ?? '' ), true );
			$settings = is_array( $settings ) ? $settings : array();
			// Count active bookings for this type in window (gives a "spots taken" feel).
			$booked = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$bt} WHERE booking_type_id = %d AND start_at BETWEEN %s AND %s AND status IN ('pending','reserved','confirmed','paid')",
				$t->id, $now, $end
			) );

			// Pick first instructor or classroom resource by default (assumes one resource per class type).
			// No placeholders here — {$rt} is an internal table name — so prepare() is not used.
			$resource_id = (int) $wpdb->get_var(
				"SELECT id FROM {$rt} WHERE is_active = 1 AND type IN ('classroom','instructor') ORDER BY sort_order ASC LIMIT 1"
			);

			$capacity   = ! empty( $settings['event_total_seats'] )
				? max( 1, (int) $settings['event_total_seats'] )
				: ( $resource_id ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT capacity FROM {$rt} WHERE id = %d", $resource_id ) ) : 1 );
			$spots_left = max( 0, $capacity - $booked );
			$pct_full   = $capacity > 0 ? min( 100, (int) round( ( $booked / $capacity ) * 100 ) ) : 0;

			$events[] = array(
				'type'        => $t,
				'capacity'    => $capacity,
				'booked'      => $booked,
				'spots_left'  => $spots_left,
				'pct_full'    => $pct_full,
				'resource_id' => $resource_id,
				'instructor'  => sanitize_text_field( (string) ( $settings['event_instructor'] ?? '' ) ),
				'time_start'  => sanitize_text_field( (string) ( $settings['event_start_time'] ?? '' ) ),
				'time_end'    => sanitize_text_field( (string) ( $settings['event_end_time'] ?? '' ) ),
			);
		}

		ob_start();
		$this->print_styles();
		?>
		<div class="g2ab-evt g2ab-evt--<?php echo esc_attr( $atts['layout'] ); ?> g2ab-evt--theme-<?php echo esc_attr( $atts['theme'] ); ?>">
			<?php foreach ( $events as $e ) : $t = $e['type']; ?>
				<article class="g2ab-evt__card g2ab-evt__card--<?php echo esc_attr( $t->category ); ?>">
					<div class="g2ab-evt__camo"></div>
					<header class="g2ab-evt__head">
						<div class="g2ab-evt__tag"><?php echo esc_html( strtoupper( $t->category ) ); ?></div>
						<?php if ( (int) $t->members_only ) : ?>
							<div class="g2ab-evt__members">★ MEMBERS ONLY</div>
						<?php endif; ?>
					</header>
					<h3 class="g2ab-evt__name"><?php echo esc_html( $t->name ); ?></h3>
					<div class="g2ab-evt__meta">
						<span class="g2ab-evt__meta-item"><span class="g2ab-evt__meta-icon">⏱</span> <?php echo (int) $t->duration_min; ?> min</span>
						<?php if ( ! empty( $e['time_start'] ) && ! empty( $e['time_end'] ) ) : ?>
							<span class="g2ab-evt__meta-item"><span class="g2ab-evt__meta-icon">🕘</span> <?php echo esc_html( $e['time_start'] . ' - ' . $e['time_end'] ); ?></span>
						<?php endif; ?>
						<?php if ( ! empty( $e['instructor'] ) ) : ?>
							<span class="g2ab-evt__meta-item"><span class="g2ab-evt__meta-icon">🎯</span> <?php echo esc_html( $e['instructor'] ); ?></span>
						<?php endif; ?>
						<?php if ( (int) $t->requires_waiver ) : ?>
							<span class="g2ab-evt__meta-item"><span class="g2ab-evt__meta-icon">⚠</span> WAIVER</span>
						<?php endif; ?>
					</div>
					<div class="g2ab-evt__price">
						<span class="g2ab-evt__price-amt">$<?php echo esc_html( number_format( (float) $t->base_price, 2 ) ); ?></span>
						<?php if ( (float) $t->member_discount > 0 ) : ?>
							<span class="g2ab-evt__price-disc">MEMBERS -<?php echo esc_html( number_format( (float) $t->member_discount, 0 ) ); ?>%</span>
						<?php endif; ?>
					</div>
					<div class="g2ab-evt__seats">
						<div class="g2ab-evt__seats-row">
							<span class="g2ab-evt__seats-label"><?php echo (int) $e['booked']; ?> of <?php echo (int) $e['capacity']; ?> LOCKED</span>
							<span class="g2ab-evt__seats-left"><?php echo (int) $e['spots_left']; ?> SPOTS</span>
						</div>
						<div class="g2ab-evt__seats-bar">
							<div class="g2ab-evt__seats-fill" style="width:<?php echo (int) $e['pct_full']; ?>%;"></div>
						</div>
					</div>
					<?php if ( 'yes' === $atts['show_book_button'] ) : ?>
						<a class="g2ab-evt__cta" href="<?php echo esc_url( $this->book_url( $t ) ); ?>">
							<span><?php esc_html_e( 'RECRUIT NOW', 'g2a-booking' ); ?></span>
							<span class="g2ab-evt__cta-arrow">→</span>
						</a>
					<?php endif; ?>
				</article>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private function book_url( $type ) {
		// Default to /book-now/?type={slug}, fallback to homepage with hash.
		$book_page = get_option( 'g2ab_booking_page_url', '' );
		if ( $book_page ) {
			return add_query_arg( 'type', $type->slug, $book_page );
		}
		return home_url( '/?g2ab_book=' . $type->slug );
	}

	private function render_empty( $msg ) {
		$this->print_styles();
		return '<div class="g2ab-evt g2ab-evt--empty"><p>' . esc_html( $msg ) . '</p></div>';
	}

	private function print_styles() {
		if ( $this->printed ) return;
		$this->printed = true;
		?>
		<style id="g2ab-evt-styles">
		.g2ab-evt{font-family:'Inter',system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;display:grid;gap:18px;margin:24px 0;}
		.g2ab-evt--grid{grid-template-columns:repeat(auto-fill,minmax(280px,1fr));}
		.g2ab-evt--list{grid-template-columns:1fr;max-width:780px;}
		.g2ab-evt--list .g2ab-evt__card{display:grid;grid-template-columns:auto 1fr auto;gap:20px;align-items:center;padding:20px 24px;}
		.g2ab-evt--list .g2ab-evt__card>*{position:relative;}
		.g2ab-evt--list .g2ab-evt__name{margin:0;}
		.g2ab-evt--list .g2ab-evt__seats{min-width:160px;}
		.g2ab-evt__card{position:relative;background:#0F1115;color:#E8E8E8;border:1px solid #2A323D;border-top:4px solid #D2691E;padding:24px 22px;overflow:hidden;transition:transform .15s ease,box-shadow .15s ease;}
		.g2ab-evt__card--lane{border-top-color:#D2691E;}
		.g2ab-evt__card--class{border-top-color:#4A5D3A;}
		.g2ab-evt__card--membership{border-top-color:#F9A825;}
		.g2ab-evt__card:hover{transform:translateY(-3px);box-shadow:0 12px 28px rgba(0,0,0,.5);}
		.g2ab-evt__camo{position:absolute;inset:0;pointer-events:none;opacity:.06;background-image:repeating-linear-gradient(45deg,transparent 0,transparent 8px,#4A5D3A 8px,#4A5D3A 12px),repeating-linear-gradient(-45deg,transparent 0,transparent 12px,#D2691E 12px,#D2691E 14px);}
		.g2ab-evt__head{position:relative;display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;}
		.g2ab-evt__tag{display:inline-block;background:#D2691E;color:#fff;padding:3px 10px;font-size:9px;letter-spacing:.12em;font-weight:700;border-radius:2px;font-family:'Rajdhani','Oswald',Impact,sans-serif;}
		.g2ab-evt__members{font-size:9px;letter-spacing:.1em;color:#F9A825;font-weight:700;}
		.g2ab-evt__name{position:relative;margin:0 0 10px;font-size:20px;line-height:1.2;font-weight:700;color:#fff;font-family:'Rajdhani','Oswald',Impact,sans-serif;letter-spacing:.04em;text-transform:uppercase;}
		.g2ab-evt__meta{position:relative;display:flex;gap:14px;flex-wrap:wrap;font-size:11px;color:#8A95A5;letter-spacing:.06em;margin-bottom:14px;}
		.g2ab-evt__meta-item{display:inline-flex;align-items:center;gap:4px;}
		.g2ab-evt__meta-icon{color:#D2691E;}
		.g2ab-evt__price{position:relative;display:flex;align-items:baseline;gap:10px;margin-bottom:14px;}
		.g2ab-evt__price-amt{font-family:'Rajdhani','Oswald',Impact,sans-serif;font-size:32px;font-weight:700;color:#D2691E;line-height:1;}
		.g2ab-evt__price-disc{font-size:10px;letter-spacing:.08em;color:#F9A825;font-weight:700;background:rgba(249,168,37,.12);padding:3px 7px;}
		.g2ab-evt__seats{position:relative;margin-bottom:16px;}
		.g2ab-evt__seats-row{display:flex;justify-content:space-between;font-size:10px;letter-spacing:.08em;color:#8A95A5;font-weight:700;margin-bottom:6px;}
		.g2ab-evt__seats-left{color:#4CAF50;}
		.g2ab-evt__seats-bar{height:6px;background:#1A1F26;overflow:hidden;border-radius:1px;}
		.g2ab-evt__seats-fill{height:100%;background:linear-gradient(90deg,#4A5D3A 0,#D2691E 70%,#C62828 100%);transition:width .4s ease;}
		.g2ab-evt__cta{position:relative;display:flex;justify-content:space-between;align-items:center;background:#D2691E;color:#fff !important;padding:14px 18px;text-decoration:none;font-family:'Rajdhani','Oswald',Impact,sans-serif;font-size:14px;letter-spacing:.12em;font-weight:700;text-transform:uppercase;border-radius:2px;transition:all .15s ease;}
		.g2ab-evt__cta:hover{background:#0F1115;color:#D2691E !important;box-shadow:0 0 0 2px #D2691E inset;}
		.g2ab-evt__cta-arrow{font-size:18px;}
		.g2ab-evt--empty{padding:30px;background:#1A1F26;color:#8A95A5;text-align:center;border-left:3px solid #D2691E;}
		</style>
		<?php
	}
}
