<?php
/**
 * [g2a_event_banner] — featured-event hero with switchable formats.
 *
 * Reads a real event + its next occurrence and renders it in one of several
 * customizable, animated layouts (Amelia-style choice of formats):
 *
 *   style="spotlight"  full hero, big title, live countdown + seats bar (default)
 *   style="strip"      compact one-row banner — great above the fold / in headers
 *   style="ticket"     premium event-ticket card with a perforated stub
 *
 * Attributes: id | slug | category (which event), countdown, cta, accent,
 * align, theme. All timezone-safe and animated (not static).
 *
 * @package G2AB
 * @since   1.5.0 (multi-format in 1.6.0)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Frontend_Shortcode_Banner {

	private static $instance = null;
	private $printed = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'g2a_event_banner', array( $this, 'render' ) );
	}

	public function render( $atts = array() ) {
		$atts = shortcode_atts( array(
			'id'        => 0,
			'slug'      => '',
			'type'      => '',          // alias for slug (back-compat)
			'category'  => '',
			'style'     => 'spotlight', // spotlight | strip | ticket
			'countdown' => 'yes',
			'cta'       => 'RESERVE A SEAT',
			'accent'    => '',          // override the event colour
			'theme'     => 'dark',
		), $atts, 'g2a_event_banner' );

		if ( ! class_exists( 'G2AB_Events' ) ) {
			return '';
		}

		// Resolve the featured event.
		$event = null;
		if ( $atts['id'] ) {
			$event = G2AB_Events::get_event( (int) $atts['id'] );
		} elseif ( $atts['slug'] || $atts['type'] ) {
			$event = G2AB_Events::get_event( $atts['slug'] ?: $atts['type'] );
		} else {
			$list    = G2AB_Events::get_events( array( 'status' => 'publish', 'category' => $atts['category'] ? sanitize_key( $atts['category'] ) : '', 'limit' => 25 ) );
			$soonest = null;
			foreach ( $list as $candidate ) {
				$next = G2AB_Events::next_occurrence( $candidate->id );
				if ( $next && ( ! $soonest || G2AB_Events::timestamp( $next->start_at ) < G2AB_Events::timestamp( $soonest->start_at ) ) ) {
					$soonest = $next;
					$event   = $candidate;
				}
			}
		}

		if ( ! $event || 'publish' !== $event->status ) {
			return '<div class="g2ab-banr g2ab-banr--empty"><p>' . esc_html__( 'No featured event configured.', 'g2a-booking' ) . '</p></div>';
		}
		$occ = G2AB_Events::next_occurrence( $event->id );
		if ( ! $occ ) {
			return '<div class="g2ab-banr g2ab-banr--empty"><p>' . esc_html__( 'No upcoming dates for this event yet.', 'g2a-booking' ) . '</p></div>';
		}

		$accent = $atts['accent'] ?: ( $event->color ? $event->color : '#E8802F' );
		$cap    = (int) $occ->seats_total;
		$booked = (int) $occ->seats_booked;
		$v = array(
			'event'      => $event,
			'occ'        => $occ,
			'accent'     => $accent,
			'cta'        => $atts['cta'],
			'countdown'  => ( 'yes' === $atts['countdown'] ),
			'theme'      => ( 'light' === $atts['theme'] ) ? 'light' : 'dark',
			'ts'         => G2AB_Events::timestamp( $occ->start_at ),
			'is_free'    => ( (int) $event->is_free === 1 || (float) $occ->price_effective <= 0 ),
			'price'      => (float) $occ->price_effective,
			'cap'        => $cap,
			'booked'     => $booked,
			'left'       => (int) $occ->seats_left,
			'pct'        => $cap > 0 ? min( 100, (int) round( ( $booked / $cap ) * 100 ) ) : 0,
			'url'        => $this->book_url( $event ),
			'cat'        => G2AB_Events::category_label( $event->category ),
			'day'        => G2AB_Events::format_local( $occ->start_at, 'M j' ),
			'dow'        => G2AB_Events::format_local( $occ->start_at, 'l' ),
			'time'       => G2AB_Events::format_local( $occ->start_at, 'g:i A' ),
			'd_num'      => G2AB_Events::format_local( $occ->start_at, 'd' ),
			'd_mon'      => G2AB_Events::format_local( $occ->start_at, 'M' ),
		);

		$style = sanitize_key( $atts['style'] );
		ob_start();
		$this->print_styles();
		switch ( $style ) {
			case 'strip':
				$this->render_strip( $v );
				break;
			case 'ticket':
				$this->render_ticket( $v );
				break;
			default:
				$this->render_spotlight( $v );
		}
		return ob_get_clean();
	}

	/* ───────────────────────── Formats ───────────────────────── */

	private function render_spotlight( $v ) {
		$e = $v['event'];
		?>
		<section class="g2ab-banr g2ab-banr--spotlight g2ab-banr--<?php echo esc_attr( $v['theme'] ); ?>" style="--banr-accent:<?php echo esc_attr( $v['accent'] ); ?>;">
			<div class="g2ab-banr__grid"></div>
			<div class="g2ab-banr__glow"></div>
			<div class="g2ab-banr__inner">
				<div class="g2ab-banr__left">
					<div class="g2ab-banr__chip"><?php echo esc_html( strtoupper( $v['cat'] ) ); ?> · <?php esc_html_e( 'NEXT EVENT', 'g2a-booking' ); ?></div>
					<h2 class="g2ab-banr__title"><?php echo esc_html( $e->title ); ?></h2>
					<?php if ( $e->summary ) : ?><p class="g2ab-banr__summary"><?php echo esc_html( $e->summary ); ?></p><?php endif; ?>
					<div class="g2ab-banr__date">
						<div class="g2ab-banr__date-day"><?php echo esc_html( $v['day'] ); ?></div>
						<div class="g2ab-banr__date-time"><?php echo esc_html( $v['dow'] . ' · ' . $v['time'] ); ?></div>
					</div>
					<div class="g2ab-banr__meta">
						<span><span class="g2ab-banr__icon">⏱</span> <?php echo (int) $e->duration_min; ?> MIN</span>
						<span><span class="g2ab-banr__icon"><?php echo $v['is_free'] ? '★' : '$'; ?></span> <?php echo $v['is_free'] ? esc_html__( 'FREE', 'g2a-booking' ) : esc_html( number_format( $v['price'], 2 ) ); ?></span>
						<?php if ( (int) $e->requires_waiver ) : ?><span><span class="g2ab-banr__icon">⚠</span> WAIVER REQ</span><?php endif; ?>
						<?php if ( $e->location ) : ?><span><span class="g2ab-banr__icon">⌖</span> <?php echo esc_html( strtoupper( $e->location ) ); ?></span><?php endif; ?>
					</div>
					<a class="g2ab-banr__cta" href="<?php echo esc_url( $v['url'] ); ?>"><span><?php echo esc_html( $v['cta'] ); ?></span><span class="g2ab-banr__cta-arrow">→</span></a>
				</div>
				<div class="g2ab-banr__right">
					<?php if ( $v['countdown'] ) : ?>
						<div class="g2ab-banr__count" data-g2ab-countdown="<?php echo esc_attr( $v['ts'] ); ?>">
							<div class="g2ab-banr__count-label"><?php esc_html_e( 'STARTS IN', 'g2a-booking' ); ?></div>
							<div class="g2ab-banr__count-grid">
								<div class="g2ab-banr__count-cell"><span data-d>00</span><small>D</small></div>
								<div class="g2ab-banr__count-cell"><span data-h>00</span><small>H</small></div>
								<div class="g2ab-banr__count-cell"><span data-m>00</span><small>M</small></div>
								<div class="g2ab-banr__count-cell"><span data-s>00</span><small>S</small></div>
							</div>
						</div>
					<?php endif; ?>
					<div class="g2ab-banr__seats">
						<div class="g2ab-banr__seats-row"><span><?php echo (int) $v['booked']; ?> / <?php echo (int) $v['cap']; ?> RESERVED</span><span class="g2ab-banr__seats-left"><?php echo (int) $v['left']; ?> SEATS LEFT</span></div>
						<div class="g2ab-banr__seats-bar"><div class="g2ab-banr__seats-fill" style="width:<?php echo (int) $v['pct']; ?>%;"></div></div>
					</div>
				</div>
			</div>
		</section>
		<?php
	}

	private function render_strip( $v ) {
		$e = $v['event'];
		?>
		<section class="g2ab-banr g2ab-banr--strip g2ab-banr--<?php echo esc_attr( $v['theme'] ); ?>" style="--banr-accent:<?php echo esc_attr( $v['accent'] ); ?>;">
			<div class="g2ab-banr__glow"></div>
			<div class="g2ab-strip__in">
				<span class="g2ab-strip__tag"><?php echo esc_html( strtoupper( $v['cat'] ) ); ?></span>
				<div class="g2ab-strip__main">
					<span class="g2ab-strip__title"><?php echo esc_html( $e->title ); ?></span>
					<span class="g2ab-strip__when"><?php echo esc_html( $v['dow'] . ', ' . $v['day'] . ' · ' . $v['time'] ); ?></span>
				</div>
				<span class="g2ab-strip__price"><?php echo $v['is_free'] ? esc_html__( 'FREE', 'g2a-booking' ) : '$' . esc_html( number_format( $v['price'], 2 ) ); ?></span>
				<span class="g2ab-strip__seats <?php echo $v['left'] > 0 ? '' : 'is-out'; ?>"><?php echo $v['left'] > 0 ? ( (int) $v['left'] . ' ' . esc_html__( 'left', 'g2a-booking' ) ) : esc_html__( 'Sold out', 'g2a-booking' ); ?></span>
				<a class="g2ab-strip__cta" href="<?php echo esc_url( $v['url'] ); ?>"><?php echo esc_html( $v['cta'] ); ?> →</a>
			</div>
		</section>
		<?php
	}

	private function render_ticket( $v ) {
		$e = $v['event'];
		?>
		<section class="g2ab-banr g2ab-banr--ticket g2ab-banr--<?php echo esc_attr( $v['theme'] ); ?>" style="--banr-accent:<?php echo esc_attr( $v['accent'] ); ?>;">
			<div class="g2ab-ticket">
				<div class="g2ab-ticket__stub">
					<span class="g2ab-ticket__d"><?php echo esc_html( $v['d_num'] ); ?></span>
					<span class="g2ab-ticket__mo"><?php echo esc_html( strtoupper( $v['d_mon'] ) ); ?></span>
					<span class="g2ab-ticket__time"><?php echo esc_html( $v['time'] ); ?></span>
				</div>
				<div class="g2ab-ticket__perf"></div>
				<div class="g2ab-ticket__body">
					<span class="g2ab-ticket__tag"><?php echo esc_html( strtoupper( $v['cat'] ) ); ?></span>
					<h3 class="g2ab-ticket__title"><?php echo esc_html( $e->title ); ?></h3>
					<?php if ( $e->summary ) : ?><p class="g2ab-ticket__sum"><?php echo esc_html( $e->summary ); ?></p><?php endif; ?>
					<div class="g2ab-ticket__meta">
						<span><b><?php echo $v['is_free'] ? esc_html__( 'FREE', 'g2a-booking' ) : '$' . esc_html( number_format( $v['price'], 2 ) ); ?></b></span>
						<span class="<?php echo $v['left'] > 0 ? 'is-green' : 'is-out'; ?>"><?php echo $v['left'] > 0 ? ( (int) $v['left'] . ' ' . esc_html__( 'seats left', 'g2a-booking' ) ) : esc_html__( 'Sold out', 'g2a-booking' ); ?></span>
						<?php if ( $e->location ) : ?><span>⌖ <?php echo esc_html( $e->location ); ?></span><?php endif; ?>
					</div>
					<a class="g2ab-ticket__cta" href="<?php echo esc_url( $v['url'] ); ?>"><?php echo esc_html( $v['cta'] ); ?> →</a>
				</div>
			</div>
		</section>
		<?php
	}

	private function book_url( $event ) {
		return home_url( '/event/' . $event->slug . '/' );
	}

	/* ───────────────────────── Styles + countdown JS ───────────────────────── */

	private function print_styles() {
		if ( $this->printed ) {
			return;
		}
		$this->printed = true;
		?>
		<style id="g2ab-banr-styles">
		.g2ab-banr{--banr-accent:#E8802F;--banr-bg:#1A191E;--banr-surface:#26252C;--banr-border:#2A323D;--banr-text:#F7F7F9;--banr-muted:#A7A6AE;position:relative;color:var(--banr-text);overflow:hidden;margin:24px 0;font-family:'Inter',system-ui,-apple-system,sans-serif;border-radius:12px;animation:g2ab-banr-in .6s cubic-bezier(.16,.8,.3,1) both;}
		.g2ab-banr--light{--banr-bg:#fff;--banr-surface:#F4F6FA;--banr-border:#E2E6EE;--banr-text:#1F2733;--banr-muted:#667085;}
		.g2ab-banr *{box-sizing:border-box;}
		@keyframes g2ab-banr-in{from{opacity:0;transform:translateY(16px);}to{opacity:1;transform:translateY(0);}}
		.g2ab-banr__glow{position:absolute;z-index:0;top:-40%;left:-10%;width:60%;height:180%;background:radial-gradient(closest-side, color-mix(in srgb,var(--banr-accent) 26%,transparent), transparent);filter:blur(20px);pointer-events:none;animation:g2ab-banr-glow 8s ease-in-out infinite alternate;}
		@keyframes g2ab-banr-glow{from{transform:translateX(0);opacity:.7;}to{transform:translateX(40%);opacity:1;}}

		/* ── Spotlight ── */
		.g2ab-banr--spotlight{background:linear-gradient(135deg,var(--banr-bg) 0%,#26252C 60%,var(--banr-bg) 100%);border:1px solid var(--banr-border);}
		.g2ab-banr__summary{margin:0 0 16px;color:#B6C0CE;font-size:14px;line-height:1.5;max-width:48ch;}
		.g2ab-banr__grid{position:absolute;inset:0;background-image:linear-gradient(rgba(74,93,58,.08) 1px,transparent 1px),linear-gradient(90deg,rgba(74,93,58,.08) 1px,transparent 1px);background-size:40px 40px;pointer-events:none;}
		.g2ab-banr__inner{position:relative;z-index:1;display:grid;grid-template-columns:1.2fr 1fr;gap:40px;padding:40px 48px;max-width:1200px;margin:0 auto;align-items:center;}
		@media (max-width:780px){.g2ab-banr__inner{grid-template-columns:1fr;padding:28px 22px;gap:24px;}}
		.g2ab-banr__chip{display:inline-block;background:var(--banr-accent);color:#111;padding:5px 12px;font-size:10px;letter-spacing:.18em;font-weight:700;font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;margin-bottom:14px;border-radius:3px;}
		.g2ab-banr__title{margin:0 0 14px;font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:clamp(30px,5vw,50px);font-weight:700;letter-spacing:.02em;line-height:1.04;color:#fff;text-transform:uppercase;}
		.g2ab-banr--light .g2ab-banr__title{color:#11151b;}
		.g2ab-banr__date{display:flex;align-items:baseline;gap:14px;margin-bottom:14px;border-left:4px solid var(--banr-accent);padding-left:14px;}
		.g2ab-banr__date-day{font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:28px;font-weight:700;color:var(--banr-accent);letter-spacing:.04em;}
		.g2ab-banr__date-time{font-size:13px;color:var(--banr-muted);letter-spacing:.06em;text-transform:uppercase;}
		.g2ab-banr__meta{display:flex;flex-wrap:wrap;gap:18px;font-size:11px;letter-spacing:.1em;color:var(--banr-muted);font-weight:700;margin-bottom:24px;}
		.g2ab-banr__meta span{display:inline-flex;align-items:center;gap:5px;}
		.g2ab-banr__icon{color:var(--banr-accent);font-weight:400;}
		.g2ab-banr__cta{display:inline-flex;align-items:center;gap:14px;background:var(--banr-accent);color:#111 !important;padding:16px 30px;text-decoration:none;font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:15px;letter-spacing:.14em;font-weight:700;text-transform:uppercase;border-radius:4px;transition:all .18s ease;border:2px solid var(--banr-accent);}
		.g2ab-banr__cta:hover{background:transparent;color:var(--banr-accent) !important;transform:translateY(-2px);box-shadow:0 10px 24px color-mix(in srgb,var(--banr-accent) 30%,transparent);}
		.g2ab-banr__cta-arrow{font-size:20px;transition:transform .18s ease;}
		.g2ab-banr__cta:hover .g2ab-banr__cta-arrow{transform:translateX(4px);}
		.g2ab-banr__right{display:flex;flex-direction:column;gap:22px;}
		.g2ab-banr__count{background:rgba(0,0,0,.4);padding:18px 20px;border:1px solid var(--banr-border);border-left:3px solid var(--banr-accent);border-radius:8px;}
		.g2ab-banr__count-label{font-size:10px;letter-spacing:.18em;color:var(--banr-muted);font-weight:700;margin-bottom:12px;}
		.g2ab-banr__count-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;}
		.g2ab-banr__count-cell{text-align:center;background:var(--banr-bg);padding:12px 8px;border-radius:6px;border:1px solid var(--banr-border);}
		.g2ab-banr__count-cell span{display:block;font-family:'SF Mono','Roboto Mono',Menlo,Consolas,monospace;font-size:clamp(26px,4vw,36px);font-weight:700;color:var(--banr-accent);line-height:1;}
		.g2ab-banr__count-cell small{display:block;font-size:9px;color:var(--banr-muted);letter-spacing:.16em;margin-top:4px;font-weight:700;}
		.g2ab-banr__seats-row{display:flex;justify-content:space-between;font-size:11px;letter-spacing:.1em;color:var(--banr-muted);font-weight:700;margin-bottom:8px;}
		.g2ab-banr__seats-left{color:#4CAF50;}
		.g2ab-banr__seats-bar{height:8px;background:var(--banr-bg);overflow:hidden;border:1px solid var(--banr-border);border-radius:999px;}
		.g2ab-banr__seats-fill{height:100%;background:linear-gradient(90deg,#E8802F 0,var(--banr-accent) 70%,#C62828 100%);transition:width .8s cubic-bezier(.16,.8,.3,1);}

		/* ── Strip ── */
		.g2ab-banr--strip{background:linear-gradient(120deg,var(--banr-bg),#26252C);border:1px solid var(--banr-border);border-left:4px solid var(--banr-accent);}
		.g2ab-strip__in{position:relative;z-index:1;display:flex;align-items:center;gap:18px;padding:16px 22px;max-width:1200px;margin:0 auto;flex-wrap:wrap;}
		.g2ab-strip__tag{background:var(--banr-accent);color:#111;font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:11px;font-weight:700;letter-spacing:.12em;padding:5px 12px;border-radius:3px;}
		.g2ab-strip__main{display:flex;flex-direction:column;gap:2px;margin-right:auto;}
		.g2ab-strip__title{font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:20px;font-weight:700;color:#fff;letter-spacing:.02em;text-transform:uppercase;line-height:1;}
		.g2ab-banr--light .g2ab-strip__title{color:#11151b;}
		.g2ab-strip__when{font-size:12px;color:var(--banr-muted);}
		.g2ab-strip__price{font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:20px;font-weight:700;color:var(--banr-accent);}
		.g2ab-strip__seats{font-size:12px;font-weight:700;color:#4CAF50;}
		.g2ab-strip__seats.is-out{color:#C62828;}
		.g2ab-strip__cta{background:var(--banr-accent);color:#111 !important;text-decoration:none;font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:13px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;padding:11px 20px;border-radius:4px;transition:all .15s ease;}
		.g2ab-strip__cta:hover{transform:translateY(-2px);box-shadow:0 8px 20px color-mix(in srgb,var(--banr-accent) 30%,transparent);}
		@media (max-width:640px){.g2ab-strip__main{margin-right:0;flex:1 1 100%;order:-1;}}

		/* ── Ticket ── */
		.g2ab-banr--ticket{background:transparent;overflow:visible;}
		.g2ab-ticket{position:relative;z-index:1;display:flex;max-width:720px;margin:0 auto;background:var(--banr-surface);border:1px solid var(--banr-border);border-radius:14px;overflow:hidden;box-shadow:0 20px 50px rgba(0,0,0,.35);}
		.g2ab-ticket__stub{flex:0 0 130px;background:linear-gradient(160deg,color-mix(in srgb,var(--banr-accent) 70%,#000),color-mix(in srgb,var(--banr-accent) 40%,#000));color:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:22px 10px;text-align:center;}
		.g2ab-ticket__d{font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:46px;font-weight:700;line-height:.9;}
		.g2ab-ticket__mo{font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:16px;font-weight:700;letter-spacing:.2em;}
		.g2ab-ticket__time{margin-top:8px;font-size:11px;letter-spacing:.08em;opacity:.92;}
		.g2ab-ticket__perf{flex:0 0 0;border-left:2px dashed color-mix(in srgb,var(--banr-border) 80%,#000);position:relative;}
		.g2ab-ticket__perf::before,.g2ab-ticket__perf::after{content:"";position:absolute;left:-9px;width:16px;height:16px;border-radius:50%;background:var(--banr-bg);}
		.g2ab-ticket__perf::before{top:-9px;}
		.g2ab-ticket__perf::after{bottom:-9px;}
		.g2ab-ticket__body{flex:1;padding:22px 26px;min-width:0;}
		.g2ab-ticket__tag{display:inline-block;color:var(--banr-accent);font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:11px;font-weight:700;letter-spacing:.16em;margin-bottom:6px;}
		.g2ab-ticket__title{margin:0 0 6px;font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:clamp(22px,3vw,30px);font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:.01em;line-height:1.05;}
		.g2ab-banr--light .g2ab-ticket__title{color:#11151b;}
		.g2ab-ticket__sum{margin:0 0 12px;color:var(--banr-muted);font-size:13.5px;line-height:1.5;}
		.g2ab-ticket__meta{display:flex;flex-wrap:wrap;gap:8px 16px;font-size:12px;color:var(--banr-muted);font-weight:600;margin-bottom:16px;}
		.g2ab-ticket__meta b{color:var(--banr-accent);font-size:16px;}
		.g2ab-ticket__meta .is-green{color:#4CAF50;}
		.g2ab-ticket__meta .is-out{color:#C62828;}
		.g2ab-ticket__cta{display:inline-block;background:var(--banr-accent);color:#111 !important;text-decoration:none;font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:14px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;padding:12px 24px;border-radius:5px;transition:all .15s ease;}
		.g2ab-ticket__cta:hover{transform:translateY(-2px);box-shadow:0 10px 24px color-mix(in srgb,var(--banr-accent) 30%,transparent);}
		@media (max-width:560px){.g2ab-ticket{flex-direction:column;}.g2ab-ticket__stub{flex-direction:row;gap:10px;flex:none;}.g2ab-ticket__perf{display:none;}}

		.g2ab-banr--empty{padding:30px;background:#26252C;color:#A7A6AE;text-align:center;border-left:3px solid #E8802F;border-radius:10px;}
		@media (prefers-reduced-motion: reduce){.g2ab-banr,.g2ab-banr__glow{animation:none;}}
		</style>
		<script id="g2ab-banr-js">
		(function(){
			document.querySelectorAll('[data-g2ab-countdown]').forEach(function(el){
				if (el.dataset.cdBooted) return; el.dataset.cdBooted='1';
				var ts = parseInt(el.getAttribute('data-g2ab-countdown'), 10) * 1000;
				function tick(){
					var d = ts - Date.now(); if(d < 0) d = 0;
					var pad = function(n){return n<10?'0'+n:''+n;};
					var dd=el.querySelector('[data-d]'),hh=el.querySelector('[data-h]'),mm=el.querySelector('[data-m]'),ss=el.querySelector('[data-s]');
					if(dd) dd.textContent=pad(Math.floor(d/86400000));
					if(hh) hh.textContent=pad(Math.floor((d%86400000)/3600000));
					if(mm) mm.textContent=pad(Math.floor((d%3600000)/60000));
					if(ss) ss.textContent=pad(Math.floor((d%60000)/1000));
				}
				tick(); setInterval(tick, 1000);
			});
		})();
		</script>
		<?php
	}
}
