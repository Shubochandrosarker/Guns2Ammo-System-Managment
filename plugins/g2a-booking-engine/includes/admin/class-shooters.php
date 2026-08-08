<?php
/**
 * Shooters CRM — the range's customer intelligence screen.
 *
 * In a range every client is a shooter, so this is the "Customers" tab reframed:
 * a clean, light, animated CRM with lifecycle analytics, live search / filtering,
 * and — the part that actually grows revenue — a re-engagement list of shooters
 * who have stopped showing up so staff can pull them back with an offer.
 *
 * The headline analytics render server-side (instant, no spinner). The roster of
 * shooter cards, the filtering and the per-shooter drawer are driven live by the
 * /shooters REST endpoints.
 *
 * @package G2AB
 * @since   1.9.9.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Admin_Shooters {

	private static $instance = null;
	const PAGE_SLUG = 'g2ab-shooters';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 12 );
	}

	public function register_menu() {
		add_submenu_page(
			'g2ab-bookings',
			__( 'Shooters', 'g2a-booking' ),
			__( 'Shooters', 'g2a-booking' ),
			'manage_g2ab_bookings',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
		// Slot Shooters right after Bookings in the submenu order.
		$this->reorder_after( 'g2ab-bookings-list' );
	}

	private function reorder_after( $after_slug ) {
		global $submenu;
		if ( empty( $submenu['g2ab-bookings'] ) ) {
			return;
		}
		$items = $submenu['g2ab-bookings'];
		$mine  = null;
		$rest  = array();
		foreach ( $items as $entry ) {
			if ( isset( $entry[2] ) && self::PAGE_SLUG === $entry[2] ) {
				$mine = $entry;
			} else {
				$rest[] = $entry;
			}
		}
		if ( null === $mine ) {
			return;
		}
		$out = array();
		foreach ( $rest as $entry ) {
			$out[] = $entry;
			if ( isset( $entry[2] ) && $after_slug === $entry[2] ) {
				$out[] = $mine;
			}
		}
		$submenu['g2ab-bookings'] = $out;
	}

	public function render() {
		if ( ! current_user_can( 'manage_g2ab_bookings' ) ) {
			wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
		}

		$stats   = G2AB_Analytics::shooters_stats();
		$lapsed  = $this->lapsed_list( 6 );
		$nonce   = wp_create_nonce( 'wp_rest' );
		$rest    = esc_url_raw( rest_url( G2AB_REST_NAMESPACE . '/shooters' ) );
		$biz     = g2ab_business_name();

		$this->print_styles();
		?>
		<div class="wrap g2ab-sh" data-rest="<?php echo esc_attr( $rest ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">

			<header class="g2ab-sh__head">
				<div class="g2ab-sh__head-main">
					<div class="g2ab-sh__eyebrow"><?php esc_html_e( 'RANGE CRM', 'g2a-booking' ); ?></div>
					<h1 class="g2ab-sh__title"><?php esc_html_e( 'Shooters', 'g2a-booking' ); ?></h1>
					<p class="g2ab-sh__sub"><?php esc_html_e( 'Every client on your range — who keeps coming back, who is worth the most, and who slipped away.', 'g2a-booking' ); ?></p>
				</div>
				<div class="g2ab-sh__head-aside">
					<div class="g2ab-sh__pulse">
						<span class="g2ab-sh__pulse-dot"></span>
						<?php echo esc_html( sprintf( /* translators: %d active shooters */ __( '%d active now', 'g2a-booking' ), (int) $stats['active'] ) ); ?>
					</div>
				</div>
			</header>

			<!-- KPI ROW -->
			<div class="g2ab-sh__kpis">
				<?php
				$this->kpi( __( 'Total Shooters', 'g2a-booking' ), $stats['total'], null, 'roster', __( 'all time', 'g2a-booking' ) );
				$this->kpi( __( 'New (30 days)', 'g2a-booking' ), $stats['new_30'], $stats['new_delta'], 'new', __( 'vs prior 30', 'g2a-booking' ) );
				$this->kpi( __( 'Returning Rate', 'g2a-booking' ), $stats['returning_rate'], null, 'return', __( 'booked 2+ times', 'g2a-booking' ), '%' );
				$this->kpi( __( 'At Risk', 'g2a-booking' ), $stats['at_risk'], null, 'risk', __( '60–120 days quiet', 'g2a-booking' ) );
				$this->kpi( __( 'Lapsed', 'g2a-booking' ), $stats['lapsed'], null, 'lapsed', __( '120+ days quiet', 'g2a-booking' ) );
				?>
			</div>

			<!-- ANALYTICS ROW -->
			<div class="g2ab-sh__analytics">
				<section class="g2ab-sh__card g2ab-sh__growth">
					<header class="g2ab-sh__card-head">
						<h2><?php esc_html_e( 'Shooter growth', 'g2a-booking' ); ?></h2>
						<span class="g2ab-sh__card-sub"><?php esc_html_e( 'Cumulative · last 12 months', 'g2a-booking' ); ?></span>
					</header>
					<?php $this->render_growth_chart( $stats['series'] ); ?>
				</section>

				<section class="g2ab-sh__card g2ab-sh__mix">
					<header class="g2ab-sh__card-head">
						<h2><?php esc_html_e( 'Lifecycle mix', 'g2a-booking' ); ?></h2>
						<span class="g2ab-sh__card-sub"><?php echo esc_html( sprintf( __( '%d shooters', 'g2a-booking' ), (int) $stats['total'] ) ); ?></span>
					</header>
					<?php $this->render_mix( $stats['segments'], $stats['total'] ); ?>
				</section>
			</div>

			<!-- RE-ENGAGEMENT -->
			<?php if ( ! empty( $lapsed ) ) : ?>
			<section class="g2ab-sh__card g2ab-sh__winback">
				<header class="g2ab-sh__card-head">
					<div>
						<h2><?php esc_html_e( 'Win them back', 'g2a-booking' ); ?></h2>
						<span class="g2ab-sh__card-sub"><?php esc_html_e( 'Shooters who have gone quiet — reach out with an offer.', 'g2a-booking' ); ?></span>
					</div>
					<span class="g2ab-sh__winback-count"><?php echo esc_html( (int) ( $stats['at_risk'] + $stats['lapsed'] ) ); ?></span>
				</header>
				<div class="g2ab-sh__winback-list">
					<?php foreach ( $lapsed as $l ) :
						$meta = G2AB_Analytics::segment_meta( $l['segment'] );
						$subject = rawurlencode( sprintf( __( 'We miss you at %s', 'g2a-booking' ), $biz ) );
						$body    = rawurlencode( sprintf( __( "Hi %s,\n\nIt's been a while since your last visit — here's a little something to get you back on the range.\n", 'g2a-booking' ), $l['name'] ) );
						?>
						<div class="g2ab-sh__winback-item">
							<div class="g2ab-sh__avatar" style="--seg:<?php echo esc_attr( $meta['color'] ); ?>;"><?php echo esc_html( $this->initials( $l['name'] ) ); ?></div>
							<div class="g2ab-sh__winback-body">
								<div class="g2ab-sh__winback-name"><?php echo esc_html( $l['name'] ); ?></div>
								<div class="g2ab-sh__winback-meta">
									<span class="g2ab-sh__chip" style="--seg:<?php echo esc_attr( $meta['color'] ); ?>;"><?php echo esc_html( $meta['label'] ); ?></span>
									<?php echo esc_html( sprintf( __( '%d days quiet · $%s lifetime', 'g2a-booking' ), (int) $l['days_since'], number_format( $l['spent'], 2 ) ) ); ?>
								</div>
							</div>
							<a class="g2ab-sh__winback-btn" href="mailto:<?php echo esc_attr( $l['email'] ); ?>?subject=<?php echo $subject; ?>&body=<?php echo $body; ?>">
								<?php esc_html_e( 'Send offer', 'g2a-booking' ); ?>
							</a>
						</div>
					<?php endforeach; ?>
				</div>
			</section>
			<?php endif; ?>

			<!-- ROSTER -->
			<section class="g2ab-sh__roster">
				<div class="g2ab-sh__toolbar">
					<div class="g2ab-sh__search">
						<svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true"><path d="M9 3a6 6 0 104.47 10.03l3.75 3.75 1.06-1.06-3.75-3.75A6 6 0 009 3zm0 1.5a4.5 4.5 0 110 9 4.5 4.5 0 010-9z" fill="currentColor"/></svg>
						<input type="search" id="g2ab-sh-search" placeholder="<?php esc_attr_e( 'Search shooters by name, email, phone…', 'g2a-booking' ); ?>" autocomplete="off" />
					</div>
					<div class="g2ab-sh__filters" role="tablist">
						<?php
						$segs = array(
							'all'     => __( 'All', 'g2a-booking' ),
							'vip'     => __( 'VIP', 'g2a-booking' ),
							'new'     => __( 'New', 'g2a-booking' ),
							'active'  => __( 'Active', 'g2a-booking' ),
							'at_risk' => __( 'At Risk', 'g2a-booking' ),
							'lapsed'  => __( 'Lapsed', 'g2a-booking' ),
						);
						foreach ( $segs as $key => $label ) :
							?>
							<button class="g2ab-sh__filter<?php echo 'all' === $key ? ' is-active' : ''; ?>" data-seg="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></button>
						<?php endforeach; ?>
					</div>
					<div class="g2ab-sh__sort">
						<label for="g2ab-sh-sort"><?php esc_html_e( 'Sort', 'g2a-booking' ); ?></label>
						<select id="g2ab-sh-sort">
							<option value="last_seen:desc"><?php esc_html_e( 'Most recent', 'g2a-booking' ); ?></option>
							<option value="spent:desc"><?php esc_html_e( 'Top spenders', 'g2a-booking' ); ?></option>
							<option value="bookings:desc"><?php esc_html_e( 'Most bookings', 'g2a-booking' ); ?></option>
							<option value="first_seen:desc"><?php esc_html_e( 'Newest', 'g2a-booking' ); ?></option>
							<option value="name:asc"><?php esc_html_e( 'Name A–Z', 'g2a-booking' ); ?></option>
						</select>
					</div>
				</div>

				<div class="g2ab-sh__count" id="g2ab-sh-count"></div>
				<div class="g2ab-sh__grid" id="g2ab-sh-grid" aria-live="polite"></div>
				<div class="g2ab-sh__empty" id="g2ab-sh-empty" hidden>
					<div class="g2ab-sh__empty-icon">◎</div>
					<p><?php esc_html_e( 'No shooters match this view yet.', 'g2a-booking' ); ?></p>
				</div>
				<div class="g2ab-sh__pager" id="g2ab-sh-pager"></div>
			</section>

			<!-- DETAIL DRAWER -->
			<div class="g2ab-sh__drawer" id="g2ab-sh-drawer" hidden>
				<div class="g2ab-sh__drawer-scrim" data-close></div>
				<aside class="g2ab-sh__drawer-panel" role="dialog" aria-modal="true">
					<button class="g2ab-sh__drawer-x" data-close aria-label="<?php esc_attr_e( 'Close', 'g2a-booking' ); ?>">&times;</button>
					<div class="g2ab-sh__drawer-body" id="g2ab-sh-drawer-body"></div>
				</aside>
			</div>
		</div>
		<?php
		$this->print_script();
	}

	/* ─────────────────────────── server render ─────────────────────────── */

	private function kpi( $label, $value, $delta, $tone, $sub, $suffix = '' ) {
		$tones = array(
			'roster' => '#1565C0',
			'new'    => '#2E7D32',
			'return' => '#E8772E',
			'risk'   => '#B26A00',
			'lapsed' => '#C62828',
		);
		$accent = isset( $tones[ $tone ] ) ? $tones[ $tone ] : '#1565C0';
		?>
		<div class="g2ab-sh__kpi" style="--accent:<?php echo esc_attr( $accent ); ?>;">
			<div class="g2ab-sh__kpi-lbl"><?php echo esc_html( $label ); ?></div>
			<div class="g2ab-sh__kpi-val" data-count="<?php echo esc_attr( $value ); ?>" data-suffix="<?php echo esc_attr( $suffix ); ?>">0<?php echo esc_html( $suffix ); ?></div>
			<div class="g2ab-sh__kpi-foot">
				<?php if ( null !== $delta ) :
					$pos   = $delta >= 0;
					$arrow = $pos ? '▲' : '▼';
					$cls   = $pos ? 'is-up' : 'is-down';
					?>
					<span class="g2ab-sh__kpi-delta <?php echo esc_attr( $cls ); ?>"><?php echo esc_html( $arrow . ' ' . abs( $delta ) . '%' ); ?></span>
				<?php endif; ?>
				<span class="g2ab-sh__kpi-sub"><?php echo esc_html( $sub ); ?></span>
			</div>
		</div>
		<?php
	}

	private function render_growth_chart( $series ) {
		if ( empty( $series ) ) {
			echo '<p class="g2ab-sh__chart-empty">' . esc_html__( 'Growth appears as shooters book.', 'g2a-booking' ) . '</p>';
			return;
		}
		$vals = array_map( function ( $p ) {
			return (int) $p['cumulative'];
		}, $series );
		$max = max( max( $vals ), 1 );
		$min = min( $vals );
		$w   = 640;
		$h   = 200;
		$px  = 8;
		$py  = 18;
		$iw  = $w - 2 * $px;
		$ih  = $h - 2 * $py;
		$n   = count( $vals );
		$step = $n > 1 ? $iw / ( $n - 1 ) : 0;
		$range = max( 1, $max - $min );
		$pts = array();
		foreach ( $vals as $i => $v ) {
			$x = $px + $i * $step;
			$y = $py + ( $ih - ( ( $v - $min ) / $range * $ih ) );
			$pts[] = array( round( $x, 1 ), round( $y, 1 ) );
		}
		$line = '';
		foreach ( $pts as $i => $p ) {
			$line .= ( 0 === $i ? 'M' : 'L' ) . $p[0] . ',' . $p[1] . ' ';
		}
		$area = $line . 'L' . $pts[ $n - 1 ][0] . ',' . ( $py + $ih ) . ' L' . $pts[0][0] . ',' . ( $py + $ih ) . ' Z';
		?>
		<div class="g2ab-sh__chart">
			<svg viewBox="0 0 <?php echo (int) $w; ?> <?php echo (int) $h; ?>" preserveAspectRatio="none" width="100%" height="<?php echo (int) $h; ?>">
				<defs>
					<linearGradient id="g2abShGrow" x1="0" y1="0" x2="0" y2="1">
						<stop offset="0%" stop-color="#E8772E" stop-opacity="0.28" />
						<stop offset="100%" stop-color="#E8772E" stop-opacity="0" />
					</linearGradient>
				</defs>
				<path d="<?php echo esc_attr( $area ); ?>" fill="url(#g2abShGrow)" />
				<path class="g2ab-sh__chart-line" d="<?php echo esc_attr( $line ); ?>" fill="none" stroke="#E8772E" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" />
				<?php foreach ( $pts as $i => $p ) : ?>
					<circle cx="<?php echo $p[0]; ?>" cy="<?php echo $p[1]; ?>" r="3" fill="#fff" stroke="#E8772E" stroke-width="2">
						<title><?php echo esc_html( $series[ $i ]['label'] . ': ' . $vals[ $i ] ); ?></title>
					</circle>
				<?php endforeach; ?>
			</svg>
			<div class="g2ab-sh__chart-axis">
				<?php foreach ( $series as $p ) : ?>
					<span><?php echo esc_html( $p['label'] ); ?></span>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	private function render_mix( $segments, $total ) {
		$order = array( 'vip', 'active', 'new', 'at_risk', 'lapsed' );
		?>
		<div class="g2ab-sh__mix-bar">
			<?php
			foreach ( $order as $seg ) {
				$c = isset( $segments[ $seg ] ) ? (int) $segments[ $seg ] : 0;
				if ( $c <= 0 || $total <= 0 ) {
					continue;
				}
				$meta = G2AB_Analytics::segment_meta( $seg );
				$pct  = round( $c / $total * 100, 1 );
				printf(
					'<span class="g2ab-sh__mix-seg" style="width:%1$s%%;background:%2$s;" title="%3$s"></span>',
					esc_attr( $pct ),
					esc_attr( $meta['color'] ),
					esc_attr( $meta['label'] . ': ' . $c )
				);
			}
			?>
		</div>
		<ul class="g2ab-sh__mix-legend">
			<?php foreach ( $order as $seg ) :
				$c    = isset( $segments[ $seg ] ) ? (int) $segments[ $seg ] : 0;
				$meta = G2AB_Analytics::segment_meta( $seg );
				$pct  = $total > 0 ? round( $c / $total * 100 ) : 0;
				?>
				<li>
					<span class="g2ab-sh__dot" style="background:<?php echo esc_attr( $meta['color'] ); ?>;"></span>
					<span class="g2ab-sh__mix-name"><?php echo esc_html( $meta['label'] ); ?></span>
					<span class="g2ab-sh__mix-val"><?php echo (int) $c; ?> <small><?php echo esc_html( $pct ); ?>%</small></span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/** Lapsed + at-risk shooters, worst first, for the win-back strip. */
	private function lapsed_list( $limit ) {
		$all = G2AB_Analytics::shooters_all();
		$out = array();
		foreach ( $all as $r ) {
			if ( in_array( $r['segment'], array( 'at_risk', 'lapsed' ), true ) ) {
				$out[] = $r;
			}
		}
		// Highest lifetime value among the quiet ones first — best to win back.
		usort( $out, function ( $a, $b ) {
			if ( $a['spent'] === $b['spent'] ) {
				return $b['days_since'] <=> $a['days_since'];
			}
			return $b['spent'] <=> $a['spent'];
		} );
		return array_slice( $out, 0, $limit );
	}

	private function initials( $name ) {
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return '?';
		}
		$parts = preg_split( '/\s+/', $name );
		$first = function_exists( 'mb_substr' ) ? mb_substr( $parts[0], 0, 1 ) : substr( $parts[0], 0, 1 );
		$last  = count( $parts ) > 1 ? ( function_exists( 'mb_substr' ) ? mb_substr( end( $parts ), 0, 1 ) : substr( end( $parts ), 0, 1 ) ) : '';
		$ini   = $first . $last;
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $ini ) : strtoupper( $ini );
	}

	/* ───────────────────────────── styles ─────────────────────────────── */

	private function print_styles() {
		echo '<style>' . $this->css() . '</style>';
	}

	private function css() {
		return <<<CSS
.g2ab-sh{--ink:#1A2233;--ink-soft:#5A6577;--ink-faint:#8A93A5;--line:#E7EBF1;--surface:#fff;--bg:#F3F5F9;--brand:#E8772E;--brand-ink:#C25A12;--radius:16px;max-width:1240px;margin:18px 20px 60px 0;font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;color:var(--ink);}
.g2ab-sh *{box-sizing:border-box;}
.g2ab-sh h1,.g2ab-sh h2{font-family:inherit;}
.g2ab-sh__head{display:flex;justify-content:space-between;align-items:flex-end;gap:24px;flex-wrap:wrap;padding:6px 4px 22px;}
.g2ab-sh__eyebrow{font-size:11px;font-weight:700;letter-spacing:.16em;color:var(--brand);margin-bottom:6px;}
.g2ab-sh__title{font-size:30px;font-weight:800;letter-spacing:-.01em;margin:0;color:var(--ink);padding:0;line-height:1.1;}
.g2ab-sh__sub{margin:8px 0 0;color:var(--ink-soft);font-size:14px;max-width:560px;line-height:1.5;}
.g2ab-sh__pulse{display:inline-flex;align-items:center;gap:8px;background:var(--surface);border:1px solid var(--line);border-radius:999px;padding:8px 16px;font-size:13px;font-weight:600;color:var(--ink);box-shadow:0 1px 2px rgba(20,30,55,.05);}
.g2ab-sh__pulse-dot{width:9px;height:9px;border-radius:50%;background:#2E7D32;box-shadow:0 0 0 0 rgba(46,125,50,.5);animation:g2abShPulse 2s infinite;}
@keyframes g2abShPulse{0%{box-shadow:0 0 0 0 rgba(46,125,50,.45);}70%{box-shadow:0 0 0 9px rgba(46,125,50,0);}100%{box-shadow:0 0 0 0 rgba(46,125,50,0);}}

.g2ab-sh__kpis{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:18px;}
@media(max-width:1100px){.g2ab-sh__kpis{grid-template-columns:repeat(2,1fr);}}
@media(max-width:640px){.g2ab-sh__kpis{grid-template-columns:1fr;}}
.g2ab-sh__kpi{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);padding:18px 20px;position:relative;overflow:hidden;box-shadow:0 1px 2px rgba(20,30,55,.04);transition:transform .22s ease,box-shadow .22s ease;opacity:0;transform:translateY(10px);animation:g2abShRise .5s ease forwards;}
.g2ab-sh__kpi:nth-child(1){animation-delay:.02s;}.g2ab-sh__kpi:nth-child(2){animation-delay:.07s;}.g2ab-sh__kpi:nth-child(3){animation-delay:.12s;}.g2ab-sh__kpi:nth-child(4){animation-delay:.17s;}.g2ab-sh__kpi:nth-child(5){animation-delay:.22s;}
@keyframes g2abShRise{to{opacity:1;transform:none;}}
.g2ab-sh__kpi::before{content:"";position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--accent);}
.g2ab-sh__kpi:hover{transform:translateY(-4px);box-shadow:0 18px 36px rgba(20,30,55,.12);}
.g2ab-sh__kpi-lbl{font-size:12px;font-weight:600;color:var(--ink-soft);letter-spacing:.01em;}
.g2ab-sh__kpi-val{font-size:34px;font-weight:800;line-height:1.1;margin:8px 0 6px;color:var(--ink);letter-spacing:-.02em;}
.g2ab-sh__kpi-foot{display:flex;align-items:center;gap:8px;font-size:12px;flex-wrap:wrap;}
.g2ab-sh__kpi-delta{font-weight:700;padding:2px 8px;border-radius:999px;font-size:11px;}
.g2ab-sh__kpi-delta.is-up{background:rgba(46,125,50,.12);color:#1f7a23;}
.g2ab-sh__kpi-delta.is-down{background:rgba(198,40,40,.12);color:#c62828;}
.g2ab-sh__kpi-sub{color:var(--ink-faint);}

.g2ab-sh__analytics{display:grid;grid-template-columns:1.7fr 1fr;gap:14px;margin-bottom:18px;}
@media(max-width:960px){.g2ab-sh__analytics{grid-template-columns:1fr;}}
.g2ab-sh__card{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);box-shadow:0 1px 2px rgba(20,30,55,.04);overflow:hidden;}
.g2ab-sh__card-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:18px 22px 6px;}
.g2ab-sh__card-head h2{margin:0;font-size:16px;font-weight:700;color:var(--ink);}
.g2ab-sh__card-sub{font-size:12px;color:var(--ink-faint);}
.g2ab-sh__chart{padding:6px 14px 14px;}
.g2ab-sh__chart svg{display:block;overflow:visible;}
.g2ab-sh__chart-line{stroke-dasharray:1400;stroke-dashoffset:1400;animation:g2abShDraw 1.3s ease forwards .2s;}
@keyframes g2abShDraw{to{stroke-dashoffset:0;}}
.g2ab-sh__chart-axis{display:flex;justify-content:space-between;padding:6px 4px 0;font-size:10px;color:var(--ink-faint);}
.g2ab-sh__chart-empty,.g2ab-sh__mix-empty{padding:40px 20px;text-align:center;color:var(--ink-faint);font-size:13px;}
.g2ab-sh__mix{display:flex;flex-direction:column;}
.g2ab-sh__mix-bar{display:flex;height:16px;border-radius:999px;overflow:hidden;margin:14px 22px 16px;background:#EEF1F6;}
.g2ab-sh__mix-seg{display:block;height:100%;transition:width .8s ease;}
.g2ab-sh__mix-legend{list-style:none;margin:0;padding:0 22px 18px;}
.g2ab-sh__mix-legend li{display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid #F1F3F8;font-size:13px;}
.g2ab-sh__mix-legend li:last-child{border-bottom:none;}
.g2ab-sh__dot{width:10px;height:10px;border-radius:50%;flex:none;}
.g2ab-sh__mix-name{flex:1;color:var(--ink-soft);}
.g2ab-sh__mix-val{font-weight:700;color:var(--ink);}
.g2ab-sh__mix-val small{color:var(--ink-faint);font-weight:500;margin-left:2px;}

.g2ab-sh__winback{margin-bottom:18px;border-color:#FAD9C2;background:linear-gradient(180deg,#FFF8F2 0%,#fff 60%);}
.g2ab-sh__winback .g2ab-sh__card-head{align-items:flex-start;}
.g2ab-sh__winback-count{background:var(--brand);color:#fff;font-weight:800;font-size:14px;border-radius:999px;min-width:30px;height:30px;display:inline-flex;align-items:center;justify-content:center;padding:0 9px;}
.g2ab-sh__winback-list{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;padding:10px 22px 20px;}
@media(max-width:760px){.g2ab-sh__winback-list{grid-template-columns:1fr;}}
.g2ab-sh__winback-item{display:flex;align-items:center;gap:12px;background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:12px 14px;transition:transform .18s ease,box-shadow .18s ease;}
.g2ab-sh__winback-item:hover{transform:translateY(-2px);box-shadow:0 10px 22px rgba(20,30,55,.08);}
.g2ab-sh__avatar{width:42px;height:42px;border-radius:50%;flex:none;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:15px;color:#fff;background:var(--seg,#5A6577);box-shadow:0 4px 10px rgba(20,30,55,.16);}
.g2ab-sh__winback-body{flex:1;min-width:0;}
.g2ab-sh__winback-name{font-weight:700;font-size:14px;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.g2ab-sh__winback-meta{font-size:11.5px;color:var(--ink-faint);margin-top:2px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
.g2ab-sh__chip{font-size:10px;font-weight:700;letter-spacing:.02em;color:#fff;background:var(--seg,#5A6577);border-radius:999px;padding:1px 8px;}
.g2ab-sh__winback-btn{flex:none;font-size:12px;font-weight:700;color:var(--brand-ink);background:#fff;border:1px solid var(--brand);border-radius:999px;padding:7px 14px;text-decoration:none;transition:background .16s ease,color .16s ease;white-space:nowrap;}
.g2ab-sh__winback-btn:hover{background:var(--brand);color:#fff;}

.g2ab-sh__roster{margin-top:4px;}
.g2ab-sh__toolbar{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px;}
.g2ab-sh__search{position:relative;flex:1;min-width:240px;display:flex;align-items:center;}
.g2ab-sh__search svg{position:absolute;left:14px;color:var(--ink-faint);pointer-events:none;}
.g2ab-sh__search input{width:100%;padding:11px 14px 11px 38px;border:1px solid var(--line);border-radius:12px;background:var(--surface);font-size:14px;color:var(--ink);box-shadow:0 1px 2px rgba(20,30,55,.04);transition:border-color .16s ease,box-shadow .16s ease;}
.g2ab-sh__search input:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 3px rgba(232,119,46,.15);}
.g2ab-sh__filters{display:flex;gap:6px;flex-wrap:wrap;}
.g2ab-sh__filter{border:1px solid var(--line);background:var(--surface);color:var(--ink-soft);font-size:12.5px;font-weight:600;padding:8px 14px;border-radius:999px;cursor:pointer;transition:all .16s ease;font-family:inherit;}
.g2ab-sh__filter:hover{border-color:var(--brand);color:var(--brand-ink);}
.g2ab-sh__filter.is-active{background:var(--ink);color:#fff;border-color:var(--ink);}
.g2ab-sh__sort{display:flex;align-items:center;gap:7px;font-size:12.5px;color:var(--ink-soft);}
.g2ab-sh__sort select{border:1px solid var(--line);border-radius:10px;padding:8px 10px;background:var(--surface);font-size:12.5px;color:var(--ink);font-family:inherit;cursor:pointer;}
.g2ab-sh__count{font-size:12.5px;color:var(--ink-faint);margin-bottom:12px;min-height:16px;}
.g2ab-sh__count b{color:var(--ink);font-weight:700;}

.g2ab-sh__grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;}
.g2ab-sh__scard{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);padding:18px;box-shadow:0 1px 2px rgba(20,30,55,.04);cursor:pointer;position:relative;overflow:hidden;opacity:0;transform:translateY(12px);animation:g2abShRise .42s ease forwards;transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease;}
.g2ab-sh__scard:hover{transform:translateY(-4px);box-shadow:0 18px 38px rgba(20,30,55,.13);border-color:#D7DEEA;}
.g2ab-sh__scard-top{display:flex;align-items:center;gap:13px;}
.g2ab-sh__scard-id{flex:1;min-width:0;}
.g2ab-sh__scard-name{font-weight:700;font-size:15.5px;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.g2ab-sh__scard-email{font-size:12px;color:var(--ink-faint);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:1px;}
.g2ab-sh__scard-seg{position:absolute;top:14px;right:14px;font-size:10px;font-weight:700;color:#fff;border-radius:999px;padding:2px 9px;}
.g2ab-sh__scard-stats{display:flex;gap:8px;margin-top:16px;}
.g2ab-sh__stat{flex:1;background:#F6F8FB;border-radius:10px;padding:9px 10px;text-align:center;}
.g2ab-sh__stat-num{font-weight:800;font-size:16px;color:var(--ink);}
.g2ab-sh__stat-lbl{font-size:10px;color:var(--ink-faint);letter-spacing:.02em;margin-top:1px;}
.g2ab-sh__scard-foot{display:flex;justify-content:space-between;align-items:center;margin-top:14px;font-size:11.5px;color:var(--ink-faint);}
.g2ab-sh__scard-foot b{color:var(--ink-soft);font-weight:600;}

.g2ab-sh__empty{padding:60px 20px;text-align:center;color:var(--ink-faint);}
.g2ab-sh__empty-icon{font-size:40px;color:#CBD3E0;margin-bottom:8px;}
.g2ab-sh__pager{display:flex;justify-content:center;align-items:center;gap:6px;margin-top:24px;flex-wrap:wrap;}
.g2ab-sh__pager button{border:1px solid var(--line);background:var(--surface);color:var(--ink-soft);min-width:36px;height:36px;border-radius:10px;cursor:pointer;font-size:13px;font-weight:600;font-family:inherit;transition:all .15s ease;}
.g2ab-sh__pager button:hover:not(:disabled){border-color:var(--brand);color:var(--brand-ink);}
.g2ab-sh__pager button.is-active{background:var(--ink);color:#fff;border-color:var(--ink);}
.g2ab-sh__pager button:disabled{opacity:.4;cursor:default;}

.g2ab-sh__loading{grid-column:1/-1;text-align:center;padding:50px;color:var(--ink-faint);}
.g2ab-sh__spinner{width:30px;height:30px;border:3px solid #E7EBF1;border-top-color:var(--brand);border-radius:50%;margin:0 auto 12px;animation:g2abShSpin .7s linear infinite;}
@keyframes g2abShSpin{to{transform:rotate(360deg);}}

.g2ab-sh__drawer{position:fixed;inset:0;z-index:99999;display:flex;justify-content:flex-end;}
.g2ab-sh__drawer[hidden]{display:none;}
.g2ab-sh__drawer-scrim{position:absolute;inset:0;background:rgba(15,23,42,.4);backdrop-filter:blur(2px);animation:g2abShFade .2s ease;}
@keyframes g2abShFade{from{opacity:0;}to{opacity:1;}}
.g2ab-sh__drawer-panel{position:relative;width:440px;max-width:92vw;height:100%;background:var(--bg);box-shadow:-20px 0 50px rgba(15,23,42,.25);overflow-y:auto;animation:g2abShSlide .28s cubic-bezier(.2,.8,.2,1);}
@keyframes g2abShSlide{from{transform:translateX(40px);opacity:.6;}to{transform:none;opacity:1;}}
.g2ab-sh__drawer-x{position:absolute;top:14px;right:16px;z-index:2;background:rgba(255,255,255,.85);border:1px solid var(--line);width:34px;height:34px;border-radius:50%;font-size:20px;line-height:1;color:var(--ink-soft);cursor:pointer;}
.g2ab-sh__drawer-x:hover{color:var(--ink);}
.g2ab-sh__dh{background:linear-gradient(135deg,#161B26 0%,#2A3242 100%);color:#fff;padding:30px 26px 24px;}
.g2ab-sh__dh-avatar{width:64px;height:64px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:24px;color:#fff;background:var(--seg,#5A6577);box-shadow:0 8px 20px rgba(0,0,0,.3);margin-bottom:14px;}
.g2ab-sh__dh-name{font-size:21px;font-weight:800;margin:0;color:#fff;}
.g2ab-sh .g2ab-sh__dh-name{color:#fff!important;}
.g2ab-sh__dh-email{font-size:13px;color:rgba(255,255,255,.7);margin-top:4px;word-break:break-all;}
.g2ab-sh__dh-email a{color:rgba(255,255,255,.9);}
.g2ab-sh__dh-tags{display:flex;gap:6px;flex-wrap:wrap;margin-top:14px;}
.g2ab-sh__dh-tag{font-size:11px;font-weight:700;border-radius:999px;padding:3px 10px;background:rgba(255,255,255,.12);color:#fff;}
.g2ab-sh__d-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;padding:18px 22px;}
.g2ab-sh__d-stat{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:14px;text-align:center;}
.g2ab-sh__d-stat-num{font-size:20px;font-weight:800;color:var(--ink);}
.g2ab-sh__d-stat-lbl{font-size:10.5px;color:var(--ink-faint);margin-top:2px;}
.g2ab-sh__d-sec-title{font-size:12px;font-weight:700;letter-spacing:.04em;color:var(--ink-soft);text-transform:uppercase;padding:6px 22px;}
.g2ab-sh__d-history{padding:0 22px 30px;}
.g2ab-sh__d-row{display:flex;align-items:center;gap:12px;background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:11px 14px;margin-bottom:8px;text-decoration:none;color:inherit;transition:transform .15s ease,box-shadow .15s ease;}
.g2ab-sh__d-row:hover{transform:translateX(-3px);box-shadow:0 8px 18px rgba(20,30,55,.08);}
.g2ab-sh__d-row-body{flex:1;min-width:0;}
.g2ab-sh__d-row-label{font-weight:600;font-size:13.5px;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.g2ab-sh__d-row-when{font-size:11.5px;color:var(--ink-faint);margin-top:1px;}
.g2ab-sh__d-row-kind{font-size:10px;font-weight:700;color:var(--ink-soft);background:#EEF1F6;border-radius:999px;padding:2px 8px;}
.g2ab-sh__d-row-amt{font-weight:700;font-size:13px;color:var(--ink);}
.g2ab-sh__d-status{display:inline-block;font-size:10px;font-weight:700;border-radius:999px;padding:1px 8px;margin-top:3px;}
.g2ab-sh__st-confirmed,.g2ab-sh__st-paid,.g2ab-sh__st-completed{background:rgba(46,125,50,.14);color:#1f7a23;}
.g2ab-sh__st-pending,.g2ab-sh__st-reserved{background:rgba(232,119,46,.14);color:#b45309;}
.g2ab-sh__st-cancelled,.g2ab-sh__st-no_show{background:rgba(198,40,40,.12);color:#c62828;}
@media (prefers-reduced-motion: reduce){.g2ab-sh *{animation:none!important;}.g2ab-sh__chart-line{stroke-dashoffset:0!important;}}
CSS;
	}

	/* ───────────────────────────── script ─────────────────────────────── */

	private function print_script() {
		?>
		<script>
		(function(){
			var root = document.querySelector('.g2ab-sh');
			if(!root) return;
			var REST = root.getAttribute('data-rest');
			var NONCE = root.getAttribute('data-nonce');

			/* Count-up on KPI values */
			function countUp(el){
				var target = parseFloat(el.getAttribute('data-count'))||0;
				var suffix = el.getAttribute('data-suffix')||'';
				var dur = 900, start = null;
				function tick(ts){
					if(!start) start = ts;
					var p = Math.min(1,(ts-start)/dur);
					var eased = 1-Math.pow(1-p,3);
					var val = Math.round(target*eased);
					el.textContent = val.toLocaleString() + suffix;
					if(p<1) requestAnimationFrame(tick);
					else el.textContent = target.toLocaleString() + suffix;
				}
				requestAnimationFrame(tick);
			}
			root.querySelectorAll('.g2ab-sh__kpi-val').forEach(countUp);

			/* Roster state */
			var grid = document.getElementById('g2ab-sh-grid');
			var pager = document.getElementById('g2ab-sh-pager');
			var countEl = document.getElementById('g2ab-sh-count');
			var emptyEl = document.getElementById('g2ab-sh-empty');
			var state = { search:'', segment:'all', sort:'last_seen', order:'desc', page:1 };
			var timer = null;

			function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
			function money(n){ return '$'+(parseFloat(n)||0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}); }

			function fetchList(){
				grid.innerHTML = '<div class="g2ab-sh__loading"><div class="g2ab-sh__spinner"></div>Loading shooters…</div>';
				emptyEl.hidden = true;
				var url = REST + '?search='+encodeURIComponent(state.search)+'&segment='+state.segment+'&sort='+state.sort+'&order='+state.order+'&page='+state.page+'&per_page=12';
				fetch(url,{headers:{'X-WP-Nonce':NONCE},credentials:'same-origin'})
					.then(function(r){return r.json();})
					.then(render)
					.catch(function(){ grid.innerHTML='<div class="g2ab-sh__loading">Could not load shooters.</div>'; });
			}

			function render(data){
				if(!data || !data.rows || !data.rows.length){
					grid.innerHTML=''; pager.innerHTML=''; emptyEl.hidden=false;
					countEl.innerHTML='';
					return;
				}
				emptyEl.hidden = true;
				var html = '';
				data.rows.forEach(function(s, i){
					html += card(s, i);
				});
				grid.innerHTML = html;
				grid.querySelectorAll('.g2ab-sh__scard').forEach(function(el){
					el.addEventListener('click',function(){ openDrawer(el.getAttribute('data-email')); });
				});
				var from = (data.page-1)*data.per_page + 1;
				var to = Math.min(data.total, data.page*data.per_page);
				countEl.innerHTML = 'Showing <b>'+from+'–'+to+'</b> of <b>'+data.total+'</b> shooters';
				renderPager(data);
			}

			function card(s, i){
				return '<div class="g2ab-sh__scard" data-email="'+esc(s.email)+'" style="animation-delay:'+(i*0.03)+'s">'
					+ '<span class="g2ab-sh__scard-seg" style="background:'+esc(s.segment_color)+'">'+esc(s.segment_label)+'</span>'
					+ '<div class="g2ab-sh__scard-top">'
					+ '<div class="g2ab-sh__avatar" style="--seg:'+esc(s.segment_color)+'">'+esc(s.initials)+'</div>'
					+ '<div class="g2ab-sh__scard-id"><div class="g2ab-sh__scard-name">'+esc(s.name)+'</div>'
					+ '<div class="g2ab-sh__scard-email">'+esc(s.email)+'</div></div></div>'
					+ '<div class="g2ab-sh__scard-stats">'
					+ '<div class="g2ab-sh__stat"><div class="g2ab-sh__stat-num">'+s.bookings+'</div><div class="g2ab-sh__stat-lbl">Bookings</div></div>'
					+ '<div class="g2ab-sh__stat"><div class="g2ab-sh__stat-num">'+money(s.spent)+'</div><div class="g2ab-sh__stat-lbl">Lifetime</div></div>'
					+ '<div class="g2ab-sh__stat"><div class="g2ab-sh__stat-num">'+s.days_since+'d</div><div class="g2ab-sh__stat-lbl">Since visit</div></div>'
					+ '</div>'
					+ '<div class="g2ab-sh__scard-foot"><span>Last seen <b>'+esc(s.last_seen)+'</b></span>'
					+ (s.wp_login? '<span>@'+esc(s.wp_login)+'</span>':'<span>Guest</span>')+'</div>'
					+ '</div>';
			}

			function renderPager(data){
				if(data.pages<=1){ pager.innerHTML=''; return; }
				var html = '<button '+(data.page<=1?'disabled':'')+' data-pg="'+(data.page-1)+'">‹</button>';
				var start = Math.max(1, data.page-2), end = Math.min(data.pages, start+4);
				start = Math.max(1, end-4);
				if(start>1){ html += '<button data-pg="1">1</button>'; if(start>2) html+='<span style="color:#8A93A5;padding:0 4px;">…</span>'; }
				for(var p=start;p<=end;p++){ html += '<button class="'+(p===data.page?'is-active':'')+'" data-pg="'+p+'">'+p+'</button>'; }
				if(end<data.pages){ if(end<data.pages-1) html+='<span style="color:#8A93A5;padding:0 4px;">…</span>'; html += '<button data-pg="'+data.pages+'">'+data.pages+'</button>'; }
				html += '<button '+(data.page>=data.pages?'disabled':'')+' data-pg="'+(data.page+1)+'">›</button>';
				pager.innerHTML = html;
				pager.querySelectorAll('button[data-pg]').forEach(function(b){
					b.addEventListener('click',function(){ var pg=parseInt(b.getAttribute('data-pg'),10); if(pg>=1){ state.page=pg; fetchList(); window.scrollTo({top:root.querySelector('.g2ab-sh__roster').offsetTop-40,behavior:'smooth'}); } });
				});
			}

			/* Drawer / detail */
			var drawer = document.getElementById('g2ab-sh-drawer');
			var drawerBody = document.getElementById('g2ab-sh-drawer-body');
			function openDrawer(email){
				drawer.hidden = false;
				document.body.style.overflow='hidden';
				drawerBody.innerHTML = '<div class="g2ab-sh__loading" style="padding:80px 0;"><div class="g2ab-sh__spinner"></div>Loading profile…</div>';
				fetch(REST.replace(/\/shooters$/,'/shooters/detail')+'?email='+encodeURIComponent(email),{headers:{'X-WP-Nonce':NONCE},credentials:'same-origin'})
					.then(function(r){return r.json();})
					.then(renderDrawer)
					.catch(function(){ drawerBody.innerHTML='<p style="padding:40px;text-align:center;color:#8A93A5;">Could not load profile.</p>'; });
			}
			function closeDrawer(){ drawer.hidden = true; document.body.style.overflow=''; }
			drawer.querySelectorAll('[data-close]').forEach(function(el){ el.addEventListener('click',closeDrawer); });
			document.addEventListener('keydown',function(e){ if(e.key==='Escape' && !drawer.hidden) closeDrawer(); });

			function renderDrawer(s){
				if(!s || !s.email){ drawerBody.innerHTML='<p style="padding:40px;text-align:center;color:#8A93A5;">Shooter not found.</p>'; return; }
				var tags = '<span class="g2ab-sh__dh-tag" style="background:'+esc(s.segment_color)+'">'+esc(s.segment_label)+'</span>';
				if(s.wp_login) tags += '<span class="g2ab-sh__dh-tag">@'+esc(s.wp_login)+'</span>';
				if(s.phone) tags += '<span class="g2ab-sh__dh-tag">'+esc(s.phone)+'</span>';
				var h = '<div class="g2ab-sh__dh"><div class="g2ab-sh__dh-avatar" style="--seg:'+esc(s.segment_color)+'">'+esc(s.initials)+'</div>'
					+ '<h2 class="g2ab-sh__dh-name">'+esc(s.name)+'</h2>'
					+ '<div class="g2ab-sh__dh-email"><a href="mailto:'+esc(s.email)+'">'+esc(s.email)+'</a></div>'
					+ '<div class="g2ab-sh__dh-tags">'+tags+'</div></div>';
				h += '<div class="g2ab-sh__d-stats">'
					+ '<div class="g2ab-sh__d-stat"><div class="g2ab-sh__d-stat-num">'+s.bookings+'</div><div class="g2ab-sh__d-stat-lbl">Bookings</div></div>'
					+ '<div class="g2ab-sh__d-stat"><div class="g2ab-sh__d-stat-num">'+money(s.spent)+'</div><div class="g2ab-sh__d-stat-lbl">Lifetime</div></div>'
					+ '<div class="g2ab-sh__d-stat"><div class="g2ab-sh__d-stat-num">'+money(s.avg_spend)+'</div><div class="g2ab-sh__d-stat-lbl">Avg / visit</div></div>'
					+ '</div>';
				h += '<div class="g2ab-sh__d-stats" style="padding-top:0;">'
					+ '<div class="g2ab-sh__d-stat"><div class="g2ab-sh__d-stat-num">'+esc(s.first_seen)+'</div><div class="g2ab-sh__d-stat-lbl">First seen</div></div>'
					+ '<div class="g2ab-sh__d-stat"><div class="g2ab-sh__d-stat-num">'+esc(s.last_seen)+'</div><div class="g2ab-sh__d-stat-lbl">Last seen</div></div>'
					+ '<div class="g2ab-sh__d-stat"><div class="g2ab-sh__d-stat-num">'+s.days_since+'d</div><div class="g2ab-sh__d-stat-lbl">Since visit</div></div>'
					+ '</div>';
				h += '<div class="g2ab-sh__d-sec-title">Booking history</div><div class="g2ab-sh__d-history">';
				if(s.history && s.history.length){
					s.history.forEach(function(b){
						h += '<a class="g2ab-sh__d-row" href="'+esc(b.edit)+'">'
							+ '<div class="g2ab-sh__d-row-body"><div class="g2ab-sh__d-row-label">'+esc(b.label)+'</div>'
							+ '<div class="g2ab-sh__d-row-when">'+esc(b.when)+'</div>'
							+ '<span class="g2ab-sh__d-status g2ab-sh__st-'+esc(b.status)+'">'+esc(b.status)+'</span></div>'
							+ '<div style="text-align:right;"><span class="g2ab-sh__d-row-kind">'+esc(b.kind)+'</span>'
							+ '<div class="g2ab-sh__d-row-amt" style="margin-top:5px;">'+money(b.amount)+'</div></div></a>';
					});
				} else {
					h += '<p style="color:#8A93A5;font-size:13px;">No bookings on record.</p>';
				}
				h += '</div>';
				drawerBody.innerHTML = h;
			}

			/* Wire controls */
			var searchEl = document.getElementById('g2ab-sh-search');
			searchEl.addEventListener('input',function(){
				clearTimeout(timer);
				timer = setTimeout(function(){ state.search = searchEl.value.trim(); state.page=1; fetchList(); },280);
			});
			root.querySelectorAll('.g2ab-sh__filter').forEach(function(btn){
				btn.addEventListener('click',function(){
					root.querySelectorAll('.g2ab-sh__filter').forEach(function(b){ b.classList.remove('is-active'); });
					btn.classList.add('is-active');
					state.segment = btn.getAttribute('data-seg'); state.page=1; fetchList();
				});
			});
			document.getElementById('g2ab-sh-sort').addEventListener('change',function(e){
				var parts = e.target.value.split(':'); state.sort=parts[0]; state.order=parts[1]||'desc'; state.page=1; fetchList();
			});

			fetchList();
		})();
		</script>
		<?php
	}
}
