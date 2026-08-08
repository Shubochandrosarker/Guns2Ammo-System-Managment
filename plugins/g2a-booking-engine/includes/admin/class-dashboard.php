<?php
/**
 * Booking Dashboard — Amelia-style analytics, reorganised by what you book.
 *
 * The range runs three very different operations through one engine: open lane
 * time, ticketed events, and CCW classes. Mixing their numbers hides the story,
 * so the dashboard is split into scope tabs — Overview · Lanes · Events · Classes —
 * each with its own KPIs, revenue trend, status mix and "Top trends" table.
 *
 * All metrics are pulled live through G2AB_Analytics so the dashboard, the
 * Shooters CRM and the Bookings roster always agree.
 *
 * @package G2AB
 * @since   1.9.9.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Admin_Dashboard {

	private static $instance = null;
	const PAGE_SLUG = 'g2ab-bookings';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'override_menu' ), 11 );
	}

	public function override_menu() {
		global $submenu;
		$idx = -1;
		if ( isset( $submenu['g2ab-bookings'] ) ) {
			foreach ( $submenu['g2ab-bookings'] as $i => $entry ) {
				if ( $entry[2] === self::PAGE_SLUG ) {
					$idx = $i;
					break;
				}
			}
		}
		remove_submenu_page( self::PAGE_SLUG, self::PAGE_SLUG );
		add_submenu_page( self::PAGE_SLUG, __( 'Dashboard', 'g2a-booking' ), __( 'Dashboard', 'g2a-booking' ), 'manage_g2ab_bookings', self::PAGE_SLUG, array( $this, 'render' ) );
		if ( $idx >= 0 && isset( $submenu['g2ab-bookings'] ) ) {
			$new = array_pop( $submenu['g2ab-bookings'] );
			array_splice( $submenu['g2ab-bookings'], $idx, 0, array( $new ) );
		}
	}

	private function scopes() {
		return array(
			'all'   => __( 'Overview', 'g2a-booking' ),
			'lane'  => __( 'Lanes', 'g2a-booking' ),
			'event' => __( 'Events', 'g2a-booking' ),
			'class' => __( 'Classes', 'g2a-booking' ),
		);
	}

	public function render() {
		if ( ! current_user_can( 'manage_g2ab_bookings' ) ) {
			wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
		}

		$scope  = isset( $_GET['scope'] ) ? sanitize_key( wp_unslash( $_GET['scope'] ) ) : 'all';
		if ( ! array_key_exists( $scope, $this->scopes() ) ) {
			$scope = 'all';
		}
		$days   = isset( $_GET['days'] ) ? max( 7, min( 365, (int) $_GET['days'] ) ) : 30;

		$stats    = G2AB_Analytics::dashboard_stats( $scope, $days );
		$top      = G2AB_Analytics::dashboard_top( $scope, 8 );
		$upcoming = in_array( $scope, array( 'event', 'class', 'all' ), true ) ? G2AB_Analytics::upcoming_events( 'all' === $scope ? 'event' : $scope, 6 ) : array();
		$recent   = $this->recent_bookings( $scope, 6 );

		$this->print_styles();
		?>
		<div class="wrap g2ab-db">
			<header class="g2ab-db__head">
				<div>
					<div class="g2ab-db__eyebrow"><?php esc_html_e( 'RANGE COMMAND', 'g2a-booking' ); ?></div>
					<h1 class="g2ab-db__title"><?php esc_html_e( 'Dashboard', 'g2a-booking' ); ?></h1>
					<p class="g2ab-db__sub"><?php echo esc_html( wp_date( 'l, F j, Y' ) ); ?></p>
				</div>
				<div class="g2ab-db__head-actions">
					<div class="g2ab-db__period">
						<?php foreach ( array( 7 => '7D', 30 => '30D', 90 => '90D', 365 => '1Y' ) as $d => $lbl ) :
							$url = add_query_arg( array( 'page' => self::PAGE_SLUG, 'scope' => $scope, 'days' => $d ), admin_url( 'admin.php' ) );
							?>
							<a class="g2ab-db__period-btn<?php echo $days === $d ? ' is-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $lbl ); ?></a>
						<?php endforeach; ?>
					</div>
					<a class="g2ab-db__btn" href="<?php echo esc_url( admin_url( 'admin.php?page=g2ab-shooters' ) ); ?>"><?php esc_html_e( 'Shooters CRM', 'g2a-booking' ); ?> →</a>
				</div>
			</header>

			<nav class="g2ab-db__tabs">
				<?php foreach ( $this->scopes() as $key => $label ) :
					$url = add_query_arg( array( 'page' => self::PAGE_SLUG, 'scope' => $key, 'days' => $days ), admin_url( 'admin.php' ) );
					?>
					<a class="g2ab-db__tab<?php echo $scope === $key ? ' is-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<!-- KPI ROW -->
			<div class="g2ab-db__kpis">
				<?php
				$this->kpi( __( 'Bookings', 'g2a-booking' ), $stats['bookings'], $stats['bookings_delta'], '#1565C0', sprintf( __( 'last %d days', 'g2a-booking' ), $days ) );
				$this->kpi( __( 'Revenue', 'g2a-booking' ), $stats['revenue'], $stats['revenue_delta'], '#2E7D32', sprintf( __( 'last %d days', 'g2a-booking' ), $days ), '$' );
				$this->kpi( __( 'Shooters', 'g2a-booking' ), $stats['shooters'], $stats['shooters_delta'], '#E8772E', __( 'unique in range', 'g2a-booking' ) );
				$this->kpi( __( 'Avg Value', 'g2a-booking' ), $stats['avg'], null, '#6A4FB3', __( 'per booking', 'g2a-booking' ), '$' );
				?>
			</div>

			<!-- CHART + STATUS -->
			<div class="g2ab-db__row g2ab-db__row--2">
				<section class="g2ab-db__card">
					<header class="g2ab-db__card-head">
						<div>
							<h2><?php esc_html_e( 'Revenue trend', 'g2a-booking' ); ?></h2>
							<span class="g2ab-db__card-sub"><?php echo esc_html( sprintf( __( '$%s collected · last %d days', 'g2a-booking' ), number_format( $stats['revenue'], 2 ), $days ) ); ?></span>
						</div>
						<div class="g2ab-db__legend"><span class="g2ab-db__dot" style="background:#2E7D32;"></span><?php esc_html_e( 'Daily revenue', 'g2a-booking' ); ?></div>
					</header>
					<?php $this->render_area_chart( $stats['series'] ); ?>
				</section>
				<section class="g2ab-db__card">
					<header class="g2ab-db__card-head">
						<h2><?php esc_html_e( 'Status mix', 'g2a-booking' ); ?></h2>
						<span class="g2ab-db__card-sub"><?php esc_html_e( 'This period', 'g2a-booking' ); ?></span>
					</header>
					<?php $this->render_status( $stats['status'] ); ?>
				</section>
			</div>

			<!-- UPCOMING (event/class/overview) -->
			<?php if ( ! empty( $upcoming ) ) : ?>
			<section class="g2ab-db__card g2ab-db__table-card">
				<header class="g2ab-db__card-head">
					<h2><?php echo esc_html( 'class' === $scope ? __( 'Upcoming classes', 'g2a-booking' ) : __( 'Upcoming events', 'g2a-booking' ) ); ?></h2>
					<a class="g2ab-db__link" href="<?php echo esc_url( admin_url( 'admin.php?page=g2ab-events' ) ); ?>"><?php esc_html_e( 'Manage', 'g2a-booking' ); ?> →</a>
				</header>
				<div class="g2ab-db__table">
					<div class="g2ab-db__thead">
						<span><?php esc_html_e( 'Date & time', 'g2a-booking' ); ?></span>
						<span class="g2ab-db__col-name"><?php esc_html_e( 'Name', 'g2a-booking' ); ?></span>
						<span><?php esc_html_e( 'Status', 'g2a-booking' ); ?></span>
						<span class="g2ab-db__col-num"><?php esc_html_e( 'Booked', 'g2a-booking' ); ?></span>
					</div>
					<?php foreach ( $upcoming as $o ) :
						$cap    = (int) $o['capacity'];
						$booked = (int) $o['booked'];
						$open   = ( 'scheduled' === $o['occ_status'] || 'open' === $o['occ_status'] ) && ( 0 === $cap || $booked < $cap );
						?>
						<div class="g2ab-db__trow">
							<span class="g2ab-db__when"><?php echo esc_html( G2AB_Events::format_local( $o['start_at'], 'M j, Y' ) ); ?><small><?php echo esc_html( G2AB_Events::format_local( $o['start_at'], 'g:i A' ) ); ?></small></span>
							<span class="g2ab-db__col-name"><a href="<?php echo esc_url( admin_url( 'admin.php?page=g2ab-events&action=edit&event_id=' . (int) $o['event_id'] ) ); ?>"><?php echo esc_html( $o['title'] ); ?></a><small><?php echo esc_html( G2AB_Events::category_label( $o['category'] ) ); ?></small></span>
							<span><span class="g2ab-db__badge <?php echo $open ? 'is-open' : 'is-closed'; ?>"><?php echo $open ? esc_html__( 'Open', 'g2a-booking' ) : esc_html__( 'Closed', 'g2a-booking' ); ?></span></span>
							<span class="g2ab-db__col-num"><?php echo esc_html( $booked . ' / ' . ( $cap ? $cap : '∞' ) ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			</section>
			<?php endif; ?>

			<!-- TOP TRENDS + RECENT -->
			<div class="g2ab-db__row g2ab-db__row--trends">
				<section class="g2ab-db__card g2ab-db__table-card">
					<header class="g2ab-db__card-head">
						<h2><?php esc_html_e( 'Top trends', 'g2a-booking' ); ?></h2>
						<span class="g2ab-db__card-sub"><?php echo esc_html( $this->top_label( $scope ) ); ?></span>
					</header>
					<?php $this->render_top( $top ); ?>
				</section>
				<section class="g2ab-db__card g2ab-db__table-card">
					<header class="g2ab-db__card-head">
						<h2><?php esc_html_e( 'Recent activity', 'g2a-booking' ); ?></h2>
						<a class="g2ab-db__link" href="<?php echo esc_url( admin_url( 'admin.php?page=g2ab-bookings-list' ) ); ?>"><?php esc_html_e( 'All bookings', 'g2a-booking' ); ?> →</a>
					</header>
					<?php $this->render_recent( $recent ); ?>
				</section>
			</div>
		</div>
		<?php
		$this->print_script();
	}

	/* ───────────────────────────── pieces ─────────────────────────────── */

	private function kpi( $label, $value, $delta, $accent, $sub, $prefix = '' ) {
		$is_money = '$' === $prefix;
		$display  = $is_money ? number_format( (float) $value, 2 ) : $value;
		?>
		<div class="g2ab-db__kpi" style="--accent:<?php echo esc_attr( $accent ); ?>;">
			<div class="g2ab-db__kpi-lbl"><?php echo esc_html( $label ); ?></div>
			<div class="g2ab-db__kpi-val" data-count="<?php echo esc_attr( (float) $value ); ?>" data-prefix="<?php echo esc_attr( $prefix ); ?>" data-money="<?php echo $is_money ? 1 : 0; ?>"><?php echo esc_html( $prefix . $display ); ?></div>
			<div class="g2ab-db__kpi-foot">
				<?php if ( null !== $delta ) :
					$pos   = $delta >= 0;
					$arrow = $pos ? '▲' : '▼';
					$cls   = $pos ? 'is-up' : 'is-down';
					?>
					<span class="g2ab-db__kpi-delta <?php echo esc_attr( $cls ); ?>"><?php echo esc_html( $arrow . ' ' . abs( $delta ) . '%' ); ?></span>
				<?php endif; ?>
				<span class="g2ab-db__kpi-sub"><?php echo esc_html( $sub ); ?></span>
			</div>
		</div>
		<?php
	}

	private function render_area_chart( $series ) {
		$vals = array_values( $series );
		if ( empty( $vals ) || array_sum( $vals ) <= 0 ) {
			echo '<div class="g2ab-db__chart-empty">' . esc_html__( 'No revenue in this period yet.', 'g2a-booking' ) . '</div>';
			return;
		}
		$labels = array_keys( $series );
		$max    = max( max( $vals ), 1 );
		$w      = 720;
		$h      = 210;
		$px     = 10;
		$py     = 16;
		$iw     = $w - 2 * $px;
		$ih     = $h - 2 * $py;
		$n      = count( $vals );
		$step   = $n > 1 ? $iw / ( $n - 1 ) : 0;
		$pts    = array();
		foreach ( $vals as $i => $v ) {
			$x = $px + $i * $step;
			$y = $py + ( $ih - ( $v / $max * $ih ) );
			$pts[] = array( round( $x, 1 ), round( $y, 1 ) );
		}
		$line = '';
		foreach ( $pts as $i => $p ) {
			$line .= ( 0 === $i ? 'M' : 'L' ) . $p[0] . ',' . $p[1] . ' ';
		}
		$area = $line . 'L' . $pts[ $n - 1 ][0] . ',' . ( $py + $ih ) . ' L' . $pts[0][0] . ',' . ( $py + $ih ) . ' Z';
		?>
		<div class="g2ab-db__chart">
			<svg viewBox="0 0 <?php echo (int) $w; ?> <?php echo (int) $h; ?>" preserveAspectRatio="none" width="100%" height="<?php echo (int) $h; ?>">
				<defs>
					<linearGradient id="g2abDbArea" x1="0" y1="0" x2="0" y2="1">
						<stop offset="0%" stop-color="#2E7D32" stop-opacity="0.22" />
						<stop offset="100%" stop-color="#2E7D32" stop-opacity="0" />
					</linearGradient>
				</defs>
				<path d="<?php echo esc_attr( $area ); ?>" fill="url(#g2abDbArea)" />
				<path class="g2ab-db__chart-line" d="<?php echo esc_attr( $line ); ?>" fill="none" stroke="#2E7D32" stroke-width="2.4" stroke-linejoin="round" stroke-linecap="round" />
			</svg>
			<div class="g2ab-db__chart-axis">
				<span><?php echo esc_html( wp_date( 'M j', strtotime( $labels[0] . ' 12:00:00' ) ) ); ?></span>
				<span><?php echo esc_html( wp_date( 'M j', strtotime( end( $labels ) . ' 12:00:00' ) ) ); ?></span>
			</div>
		</div>
		<?php
	}

	private function render_status( $statuses ) {
		$colors = array(
			'pending'   => '#E8772E',
			'reserved'  => '#B26A00',
			'confirmed' => '#2E7D32',
			'paid'      => '#1565C0',
			'completed' => '#161B26',
			'cancelled' => '#C62828',
			'no_show'   => '#9A2A2A',
			'refunded'  => '#8A93A5',
		);
		$total = array_sum( $statuses );
		if ( 0 === $total ) {
			echo '<div class="g2ab-db__chart-empty">' . esc_html__( 'No bookings in this period.', 'g2a-booking' ) . '</div>';
			return;
		}
		?>
		<div class="g2ab-db__status">
			<div class="g2ab-db__status-bar">
				<?php foreach ( $statuses as $s => $c ) :
					if ( $c <= 0 ) {
						continue;
					}
					$pct = round( $c / $total * 100, 1 );
					?>
					<span style="width:<?php echo esc_attr( $pct ); ?>%;background:<?php echo esc_attr( isset( $colors[ $s ] ) ? $colors[ $s ] : '#8A93A5' ); ?>;" title="<?php echo esc_attr( ucwords( str_replace( '_', ' ', $s ) ) . ': ' . $c ); ?>"></span>
				<?php endforeach; ?>
			</div>
			<ul class="g2ab-db__status-legend">
				<?php foreach ( $statuses as $s => $c ) :
					if ( $c <= 0 ) {
						continue;
					}
					?>
					<li>
						<span class="g2ab-db__dot" style="background:<?php echo esc_attr( isset( $colors[ $s ] ) ? $colors[ $s ] : '#8A93A5' ); ?>;"></span>
						<span class="g2ab-db__status-name"><?php echo esc_html( ucwords( str_replace( '_', ' ', $s ) ) ); ?></span>
						<span class="g2ab-db__status-val"><?php echo (int) $c; ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	private function top_label( $scope ) {
		switch ( $scope ) {
			case 'lane':
				return __( 'Busiest lanes & ranges', 'g2a-booking' );
			case 'class':
				return __( 'Most-booked classes', 'g2a-booking' );
			case 'event':
				return __( 'Most-booked events', 'g2a-booking' );
			default:
				return __( 'Most-booked events', 'g2a-booking' );
		}
	}

	private function render_top( $rows ) {
		if ( empty( $rows ) ) {
			echo '<div class="g2ab-db__chart-empty">' . esc_html__( 'No data yet.', 'g2a-booking' ) . '</div>';
			return;
		}
		$max = 1;
		foreach ( $rows as $r ) {
			$max = max( $max, (int) $r['bookings'] );
		}
		?>
		<div class="g2ab-db__table">
			<div class="g2ab-db__thead">
				<span class="g2ab-db__col-name"><?php esc_html_e( 'Name', 'g2a-booking' ); ?></span>
				<span class="g2ab-db__col-num"><?php esc_html_e( 'Bookings', 'g2a-booking' ); ?></span>
				<span class="g2ab-db__col-num"><?php esc_html_e( 'Revenue', 'g2a-booking' ); ?></span>
				<span class="g2ab-db__col-load"><?php esc_html_e( 'Load', 'g2a-booking' ); ?></span>
			</div>
			<?php foreach ( $rows as $r ) :
				$pct = $max > 0 ? round( (int) $r['bookings'] / $max * 100 ) : 0;
				?>
				<div class="g2ab-db__trow">
					<span class="g2ab-db__col-name g2ab-db__top-name"><?php echo esc_html( $r['label'] ); ?></span>
					<span class="g2ab-db__col-num"><?php echo (int) $r['bookings']; ?></span>
					<span class="g2ab-db__col-num">$<?php echo esc_html( number_format( (float) $r['revenue'], 2 ) ); ?></span>
					<span class="g2ab-db__col-load">
						<span class="g2ab-db__load-track"><span class="g2ab-db__load-fill" style="width:<?php echo (int) $pct; ?>%;"></span></span>
						<small><?php echo (int) $pct; ?>%</small>
					</span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private function render_recent( $rows ) {
		if ( empty( $rows ) ) {
			echo '<div class="g2ab-db__chart-empty">' . esc_html__( 'No bookings yet.', 'g2a-booking' ) . '</div>';
			return;
		}
		?>
		<ul class="g2ab-db__feed">
			<?php foreach ( $rows as $b ) :
				$kind = ! $b->event_id ? __( 'Lane', 'g2a-booking' ) : ( 'ccw-class' === $b->event_category ? __( 'Class', 'g2a-booking' ) : __( 'Event', 'g2a-booking' ) );
				?>
				<li>
					<span class="g2ab-db__feed-avatar"><?php echo esc_html( $this->initials( $b->customer_name ) ); ?></span>
					<span class="g2ab-db__feed-body">
						<span class="g2ab-db__feed-name"><?php echo esc_html( $b->customer_name ? $b->customer_name : __( 'Guest', 'g2a-booking' ) ); ?></span>
						<span class="g2ab-db__feed-meta"><?php echo esc_html( $b->event_title ? $b->event_title : ( $b->resource_name ? $b->resource_name : $kind ) ); ?> · <?php echo esc_html( human_time_diff( strtotime( $b->created_at ), current_time( 'timestamp' ) ) ); ?> <?php esc_html_e( 'ago', 'g2a-booking' ); ?></span>
					</span>
					<span class="g2ab-db__feed-side">
						<span class="g2ab-db__badge2 g2ab-db__st-<?php echo esc_attr( $b->status ); ?>"><?php echo esc_html( $b->status ); ?></span>
						<span class="g2ab-db__feed-amt">$<?php echo esc_html( number_format( (float) $b->paid_amount, 2 ) ); ?></span>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	private function recent_bookings( $scope, $limit ) {
		global $wpdb;
		$bt = $wpdb->prefix . 'g2ab_bookings';
		$et = $wpdb->prefix . 'g2ab_events';
		$rt = $wpdb->prefix . 'g2ab_resources';
		$where = '1=1';
		if ( 'lane' === $scope ) {
			$where = 'b.event_id IS NULL';
		} elseif ( 'event' === $scope ) {
			$where = "b.event_id IS NOT NULL AND (e.category IS NULL OR e.category <> 'ccw-class')";
		} elseif ( 'class' === $scope ) {
			$where = "b.event_id IS NOT NULL AND e.category = 'ccw-class'";
		}
		// Same visibility policy as the KPIs above it: recent activity shows
		// operational bookings only; failed/abandoned checkouts live on the
		// Checkout Attempts screen.
		$where .= ' AND ' . G2AB_Booking_Visibility::operational_sql( 'b' );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT b.id, b.customer_name, b.status, b.paid_amount, b.created_at, b.event_id,
			        e.title AS event_title, e.category AS event_category, r.name AS resource_name
			 FROM {$bt} b
			 LEFT JOIN {$et} e ON e.id = b.event_id
			 LEFT JOIN {$rt} r ON r.id = b.resource_id
			 WHERE {$where}
			 ORDER BY b.created_at DESC LIMIT %d",
			$limit
		) );
	}

	private function initials( $name ) {
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return 'G';
		}
		$parts = preg_split( '/\s+/', $name );
		$ini   = substr( $parts[0], 0, 1 ) . ( count( $parts ) > 1 ? substr( end( $parts ), 0, 1 ) : '' );
		return strtoupper( $ini );
	}

	/* ───────────────────────────── styles ─────────────────────────────── */

	private function print_styles() {
		echo '<style>' . $this->css() . '</style>';
	}

	private function css() {
		return <<<CSS
.g2ab-db{--ink:#1A2233;--ink-soft:#5A6577;--ink-faint:#8A93A5;--line:#E7EBF1;--surface:#fff;--bg:#F3F5F9;--brand:#E8772E;--radius:16px;max-width:1240px;margin:18px 20px 60px 0;font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;color:var(--ink);}
.g2ab-db *{box-sizing:border-box;}
.g2ab-db__head{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;flex-wrap:wrap;padding:6px 4px 16px;}
.g2ab-db__eyebrow{font-size:11px;font-weight:700;letter-spacing:.16em;color:var(--brand);margin-bottom:6px;}
.g2ab-db__title{font-size:30px;font-weight:800;letter-spacing:-.01em;margin:0;color:var(--ink);padding:0;line-height:1.1;}
.g2ab-db__sub{margin:6px 0 0;color:var(--ink-soft);font-size:13.5px;}
.g2ab-db__head-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
.g2ab-db__period{display:inline-flex;background:var(--surface);border:1px solid var(--line);border-radius:10px;overflow:hidden;box-shadow:0 1px 2px rgba(20,30,55,.05);}
.g2ab-db__period-btn{padding:8px 13px;font-size:12px;font-weight:700;color:var(--ink-soft);text-decoration:none;border-right:1px solid var(--line);transition:background .15s ease,color .15s ease;}
.g2ab-db__period-btn:last-child{border-right:none;}
.g2ab-db__period-btn:hover{background:#F6F8FB;color:var(--ink);}
.g2ab-db__period-btn.is-active{background:var(--ink);color:#fff;}
.g2ab-db__btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;background:var(--brand);color:#fff;text-decoration:none;font-size:12.5px;font-weight:700;border-radius:10px;box-shadow:0 6px 16px rgba(232,119,46,.25);transition:transform .15s ease,box-shadow .15s ease;}
.g2ab-db__btn:hover{color:#fff;transform:translateY(-1px);box-shadow:0 10px 22px rgba(232,119,46,.32);}
.g2ab-db__tabs{display:flex;gap:4px;border-bottom:1px solid var(--line);margin-bottom:20px;flex-wrap:wrap;}
.g2ab-db__tab{padding:11px 20px;font-size:14px;font-weight:600;color:var(--ink-soft);text-decoration:none;border-bottom:2.5px solid transparent;margin-bottom:-1px;transition:color .15s ease,border-color .15s ease;}
.g2ab-db__tab:hover{color:var(--ink);}
.g2ab-db__tab.is-active{color:var(--brand);border-bottom-color:var(--brand);font-weight:700;}

.g2ab-db__kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:16px;}
@media(max-width:980px){.g2ab-db__kpis{grid-template-columns:repeat(2,1fr);}}
@media(max-width:560px){.g2ab-db__kpis{grid-template-columns:1fr;}}
.g2ab-db__kpi{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);padding:18px 20px;position:relative;overflow:hidden;box-shadow:0 1px 2px rgba(20,30,55,.04);transition:transform .22s ease,box-shadow .22s ease;opacity:0;transform:translateY(10px);animation:g2abDbRise .5s ease forwards;}
.g2ab-db__kpi:nth-child(2){animation-delay:.06s;}.g2ab-db__kpi:nth-child(3){animation-delay:.12s;}.g2ab-db__kpi:nth-child(4){animation-delay:.18s;}
@keyframes g2abDbRise{to{opacity:1;transform:none;}}
.g2ab-db__kpi::before{content:"";position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--accent);}
.g2ab-db__kpi:hover{transform:translateY(-4px);box-shadow:0 18px 36px rgba(20,30,55,.12);}
.g2ab-db__kpi-lbl{font-size:12px;font-weight:600;color:var(--ink-soft);}
.g2ab-db__kpi-val{font-size:32px;font-weight:800;line-height:1.1;margin:8px 0 6px;color:var(--ink);letter-spacing:-.02em;}
.g2ab-db__kpi-foot{display:flex;align-items:center;gap:8px;font-size:12px;flex-wrap:wrap;}
.g2ab-db__kpi-delta{font-weight:700;padding:2px 8px;border-radius:999px;font-size:11px;}
.g2ab-db__kpi-delta.is-up{background:rgba(46,125,50,.12);color:#1f7a23;}
.g2ab-db__kpi-delta.is-down{background:rgba(198,40,40,.12);color:#c62828;}
.g2ab-db__kpi-sub{color:var(--ink-faint);}

.g2ab-db__row{display:grid;gap:14px;margin-bottom:16px;}
.g2ab-db__row--2{grid-template-columns:1.9fr 1fr;}
.g2ab-db__row--trends{grid-template-columns:1.3fr 1fr;}
@media(max-width:980px){.g2ab-db__row--2,.g2ab-db__row--trends{grid-template-columns:1fr;}}
.g2ab-db__card{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);box-shadow:0 1px 2px rgba(20,30,55,.04);overflow:hidden;}
.g2ab-db__card-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:18px 22px 8px;}
.g2ab-db__card-head h2{margin:0;font-size:16px;font-weight:700;color:var(--ink);}
.g2ab-db__card-sub{font-size:12px;color:var(--ink-faint);}
.g2ab-db__link{font-size:12.5px;font-weight:600;color:var(--brand);text-decoration:none;}
.g2ab-db__link:hover{color:#C25A12;}
.g2ab-db__legend{display:inline-flex;align-items:center;gap:6px;font-size:11px;color:var(--ink-faint);}
.g2ab-db__dot{width:10px;height:10px;border-radius:50%;flex:none;}
.g2ab-db__chart{padding:8px 16px 14px;}
.g2ab-db__chart svg{display:block;}
.g2ab-db__chart-line{stroke-dasharray:1600;stroke-dashoffset:1600;animation:g2abDbDraw 1.3s ease forwards .2s;}
@keyframes g2abDbDraw{to{stroke-dashoffset:0;}}
.g2ab-db__chart-axis{display:flex;justify-content:space-between;padding:4px 6px 0;font-size:11px;color:var(--ink-faint);}
.g2ab-db__chart-empty{padding:46px 20px;text-align:center;color:var(--ink-faint);font-size:13px;}

.g2ab-db__status{padding:14px 22px 18px;}
.g2ab-db__status-bar{display:flex;height:16px;border-radius:999px;overflow:hidden;background:#EEF1F6;margin-bottom:14px;}
.g2ab-db__status-bar span{display:block;height:100%;transition:width .7s ease;}
.g2ab-db__status-legend{list-style:none;margin:0;padding:0;}
.g2ab-db__status-legend li{display:flex;align-items:center;gap:9px;padding:6px 0;border-bottom:1px solid #F1F3F8;font-size:13px;}
.g2ab-db__status-legend li:last-child{border-bottom:none;}
.g2ab-db__status-name{flex:1;color:var(--ink-soft);}
.g2ab-db__status-val{font-weight:700;color:var(--ink);}

.g2ab-db__table-card{display:flex;flex-direction:column;}
.g2ab-db__table{padding:6px 10px 14px;}
.g2ab-db__thead,.g2ab-db__trow{display:grid;grid-template-columns:1.4fr .7fr .9fr 1.1fr;align-items:center;gap:10px;padding:9px 12px;}
.g2ab-db__thead{font-size:10.5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--ink-faint);border-bottom:1px solid var(--line);}
.g2ab-db__trow{border-bottom:1px solid #F4F6FA;font-size:13px;transition:background .14s ease;}
.g2ab-db__trow:last-child{border-bottom:none;}
.g2ab-db__trow:hover{background:#F8FAFC;}
.g2ab-db__col-num{text-align:right;font-variant-numeric:tabular-nums;font-weight:600;color:var(--ink);}
.g2ab-db__col-name{min-width:0;}
.g2ab-db__col-name a{color:var(--ink);text-decoration:none;font-weight:600;}
.g2ab-db__col-name a:hover{color:var(--brand);}
.g2ab-db__col-name small,.g2ab-db__when small{display:block;font-size:11px;color:var(--ink-faint);font-weight:400;margin-top:1px;}
.g2ab-db__top-name{font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.g2ab-db__when{font-weight:600;color:var(--ink);}
.g2ab-db__col-load{display:flex;align-items:center;gap:8px;}
.g2ab-db__load-track{flex:1;height:7px;background:#EEF1F6;border-radius:999px;overflow:hidden;}
.g2ab-db__load-fill{display:block;height:100%;background:linear-gradient(90deg,#E8772E,#F0A35E);border-radius:999px;animation:g2abDbGrow 1s ease;}
@keyframes g2abDbGrow{from{width:0!important;}}
.g2ab-db__col-load small{font-size:11px;color:var(--ink-faint);min-width:30px;text-align:right;}
.g2ab-db__badge{font-size:11px;font-weight:700;border-radius:999px;padding:3px 11px;display:inline-block;}
.g2ab-db__badge.is-open{background:rgba(46,125,50,.13);color:#1f7a23;}
.g2ab-db__badge.is-closed{background:#EEF1F6;color:var(--ink-soft);}

.g2ab-db__feed{list-style:none;margin:0;padding:6px 0;}
.g2ab-db__feed li{display:flex;align-items:center;gap:12px;padding:10px 22px;border-bottom:1px solid #F4F6FA;}
.g2ab-db__feed li:last-child{border-bottom:none;}
.g2ab-db__feed-avatar{width:36px;height:36px;border-radius:50%;flex:none;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;color:#fff;background:linear-gradient(135deg,#2A3242,#475067);}
.g2ab-db__feed-body{flex:1;min-width:0;}
.g2ab-db__feed-name{display:block;font-weight:600;font-size:13.5px;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.g2ab-db__feed-meta{display:block;font-size:11.5px;color:var(--ink-faint);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:1px;}
.g2ab-db__feed-side{text-align:right;flex:none;}
.g2ab-db__feed-amt{display:block;font-weight:700;font-size:13px;color:var(--ink);margin-top:4px;}
.g2ab-db__badge2{font-size:10px;font-weight:700;border-radius:999px;padding:2px 8px;text-transform:capitalize;}
.g2ab-db__st-confirmed,.g2ab-db__st-paid,.g2ab-db__st-completed{background:rgba(46,125,50,.14);color:#1f7a23;}
.g2ab-db__st-pending,.g2ab-db__st-reserved{background:rgba(232,119,46,.14);color:#b45309;}
.g2ab-db__st-cancelled,.g2ab-db__st-no_show,.g2ab-db__st-refunded{background:rgba(198,40,40,.12);color:#c62828;}
@media (prefers-reduced-motion: reduce){.g2ab-db *{animation:none!important;}.g2ab-db__chart-line{stroke-dashoffset:0!important;}}
CSS;
	}

	private function print_script() {
		?>
		<script>
		(function(){
			var root = document.querySelector('.g2ab-db');
			if(!root) return;
			function countUp(el){
				var target = parseFloat(el.getAttribute('data-count'))||0;
				var prefix = el.getAttribute('data-prefix')||'';
				var money = el.getAttribute('data-money')==='1';
				var dur = 850, start=null;
				function fmt(v){ return money ? v.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}) : Math.round(v).toLocaleString(); }
				function tick(ts){
					if(!start) start=ts;
					var p=Math.min(1,(ts-start)/dur), eased=1-Math.pow(1-p,3);
					el.textContent = prefix + fmt(target*eased);
					if(p<1) requestAnimationFrame(tick); else el.textContent = prefix + fmt(target);
				}
				requestAnimationFrame(tick);
			}
			root.querySelectorAll('.g2ab-db__kpi-val').forEach(countUp);
		})();
		</script>
		<?php
	}
}
