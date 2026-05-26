<?php

/**
 * Modern analytics dashboard — Booking Dashboard.
 *
 * @package G2AB
 */
if (! defined('ABSPATH')) exit;

final class G2AB_Admin_Dashboard
{

	private static $instance = null;
	const PAGE_SLUG = 'g2ab-bookings';

	public static function instance()
	{
		if (null === self::$instance) self::$instance = new self();
		return self::$instance;
	}

	private function __construct()
	{
		add_action('admin_menu', array($this, 'override_menu'), 11);
	}

	public function override_menu()
	{
		global $submenu;
		$idx = -1;
		if (isset($submenu['g2ab-bookings'])) {
			foreach ($submenu['g2ab-bookings'] as $i => $entry) {
				if ($entry[2] === self::PAGE_SLUG) {
					$idx = $i;
					break;
				}
			}
		}
		remove_submenu_page(self::PAGE_SLUG, self::PAGE_SLUG);
		add_submenu_page(self::PAGE_SLUG, __('Dashboard', 'g2a-booking'), __('Dashboard', 'g2a-booking'), 'manage_g2ab_bookings', self::PAGE_SLUG, array($this, 'render'));
		if ($idx >= 0 && isset($submenu['g2ab-bookings'])) {
			$new = array_pop($submenu['g2ab-bookings']);
			array_splice($submenu['g2ab-bookings'], $idx, 0, array($new));
		}
	}

	public function render()
	{
		if (! current_user_can('manage_g2ab_bookings')) wp_die(esc_html__('No permission.', 'g2a-booking'));

		$kpi = $this->get_kpis();
		$trend = $this->get_trend_30();
		$status = $this->get_status_breakdown();
		$top_res = $this->get_top_resources();
		$recent = $this->get_recent_bookings();
		$upcoming = $this->get_upcoming_bookings();

		$this->print_styles();
?>
		<div class="wrap g2ab-admin g2ab-dash">
			<header class="g2ab-dash__header">
				<div>
					<h1><span class="g2ab-dash__stencil"><?php esc_html_e('Booking Dashboard', 'g2a-booking'); ?></span></h1>
					<p class="g2ab-dash__sub"><?php echo esc_html(wp_date('l, F j Y · g:i A')); ?></p>
				</div>
				<div>
					<a class="g2ab-dash__btn" href="<?php echo esc_url(admin_url('admin.php?page=g2ab-bookings-list')); ?>"><?php esc_html_e('VIEW ROSTER', 'g2a-booking'); ?> →</a>
				</div>
			</header>

			<div class="g2ab-dash__kpis">
				<?php $this->render_kpi(__('BOOKINGS', 'g2a-booking'), $kpi['bookings_now'], $kpi['bookings_delta'], '#D2691E', __('this month', 'g2a-booking')); ?>
				<?php $this->render_kpi(__('REVENUE', 'g2a-booking'), '$' . number_format($kpi['revenue_now'], 2), $kpi['revenue_delta'], '#4CAF50', __('this month', 'g2a-booking')); ?>
				<?php $this->render_kpi(__('AVG VALUE', 'g2a-booking'), '$' . number_format($kpi['avg_now'], 2), $kpi['avg_delta'], '#4A5D3A', __('per booking', 'g2a-booking')); ?>
				<?php $this->render_kpi(__('NO-SHOWS', 'g2a-booking'), $kpi['noshow_now'] . '%', $kpi['noshow_delta'], '#C62828', __('rate', 'g2a-booking'), true); ?>
			</div>

			<div class="g2ab-dash__row">
				<div class="g2ab-dash__panel g2ab-dash__panel--wide">
					<header>
						<h3><?php esc_html_e('30-DAY BOOKING TREND', 'g2a-booking'); ?></h3>
						<div class="g2ab-dash__legend"><span class="g2ab-dash__dot" style="background:#D2691E;"></span> <?php esc_html_e('Bookings/day', 'g2a-booking'); ?></div>
					</header>
					<?php $this->render_trend_chart($trend); ?>
				</div>
				<div class="g2ab-dash__panel">
					<header>
						<h3><?php esc_html_e('STATUS BREAKDOWN', 'g2a-booking'); ?></h3>
					</header>
					<?php $this->render_status_donut($status); ?>
				</div>
			</div>

			<div class="g2ab-dash__row">
				<div class="g2ab-dash__panel">
					<header>
						<h3><?php esc_html_e('TOP RESOURCES', 'g2a-booking'); ?></h3><small><?php esc_html_e('Last 30 days', 'g2a-booking'); ?></small>
					</header>
					<?php $this->render_top_resources($top_res); ?>
				</div>
				<div class="g2ab-dash__panel">
					<header>
						<h3><?php esc_html_e('UPCOMING MISSIONS', 'g2a-booking'); ?></h3><small><?php echo esc_html(count($upcoming)); ?> queued</small>
					</header>
					<?php $this->render_upcoming($upcoming); ?>
				</div>
				<div class="g2ab-dash__panel">
					<header>
						<h3><?php esc_html_e('RECENT ACTIVITY', 'g2a-booking'); ?></h3><small><?php esc_html_e('Last 5', 'g2a-booking'); ?></small>
					</header>
					<?php $this->render_recent($recent); ?>
				</div>
			</div>
		</div>
	<?php
	}

	private function get_kpis()
	{
		global $wpdb;
		$tbl = $wpdb->prefix . 'g2ab_bookings';
		$now_start = wp_date('Y-m-01 00:00:00');
		$now_end = wp_date('Y-m-d 23:59:59');
		$prev_start = wp_date('Y-m-01 00:00:00', strtotime('-1 month'));
		$prev_end = wp_date('Y-m-t 23:59:59', strtotime('-1 month'));

		$bookings_now = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tbl} WHERE created_at BETWEEN %s AND %s", $now_start, $now_end));
		$bookings_prev = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tbl} WHERE created_at BETWEEN %s AND %s", $prev_start, $prev_end));
		$revenue_now = (float) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(paid_amount),0) FROM {$tbl} WHERE created_at BETWEEN %s AND %s AND status IN ('paid','confirmed','completed')", $now_start, $now_end));
		$revenue_prev = (float) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(paid_amount),0) FROM {$tbl} WHERE created_at BETWEEN %s AND %s AND status IN ('paid','confirmed','completed')", $prev_start, $prev_end));
		$avg_now = $bookings_now > 0 ? (float) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(AVG(total_amount),0) FROM {$tbl} WHERE created_at BETWEEN %s AND %s", $now_start, $now_end)) : 0;
		$avg_prev = $bookings_prev > 0 ? (float) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(AVG(total_amount),0) FROM {$tbl} WHERE created_at BETWEEN %s AND %s", $prev_start, $prev_end)) : 0;
		$total_now = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tbl} WHERE start_at BETWEEN %s AND %s AND status IN ('confirmed','paid','completed','no_show')", $now_start, $now_end));
		$noshow_n = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tbl} WHERE start_at BETWEEN %s AND %s AND status = 'no_show'", $now_start, $now_end));
		$noshow_now = $total_now > 0 ? round($noshow_n / $total_now * 100, 1) : 0;
		$total_prev = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tbl} WHERE start_at BETWEEN %s AND %s AND status IN ('confirmed','paid','completed','no_show')", $prev_start, $prev_end));
		$noshow_p = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tbl} WHERE start_at BETWEEN %s AND %s AND status = 'no_show'", $prev_start, $prev_end));
		$noshow_prev = $total_prev > 0 ? round($noshow_p / $total_prev * 100, 1) : 0;

		return array(
			'bookings_now' => $bookings_now,
			'bookings_delta' => $this->pct_delta($bookings_now, $bookings_prev),
			'revenue_now' => $revenue_now,
			'revenue_delta' => $this->pct_delta($revenue_now, $revenue_prev),
			'avg_now' => $avg_now,
			'avg_delta' => $this->pct_delta($avg_now, $avg_prev),
			'noshow_now' => $noshow_now,
			'noshow_delta' => $this->pct_delta($noshow_now, $noshow_prev),
		);
	}

	private function pct_delta($now, $prev)
	{
		if ($prev <= 0) return $now > 0 ? 100 : 0;
		return round((($now - $prev) / $prev) * 100, 1);
	}

	private function get_trend_30()
	{
		global $wpdb;
		$tbl = $wpdb->prefix . 'g2ab_bookings';
		$days = array();
		for ($i = 29; $i >= 0; $i--) $days[wp_date('Y-m-d', strtotime("-{$i} days"))] = 0;
		$start = wp_date('Y-m-d 00:00:00', strtotime('-29 days'));
		$rows = $wpdb->get_results($wpdb->prepare("SELECT DATE(created_at) AS d, COUNT(*) AS c FROM {$tbl} WHERE created_at >= %s GROUP BY DATE(created_at)", $start));
		foreach ($rows as $r) if (isset($days[$r->d])) $days[$r->d] = (int) $r->c;
		return $days;
	}

	private function get_status_breakdown()
	{
		global $wpdb;
		$tbl = $wpdb->prefix . 'g2ab_bookings';
		$rows = $wpdb->get_results("SELECT status, COUNT(*) c FROM {$tbl} GROUP BY status");
		$out = array();
		foreach ($rows as $r) $out[$r->status] = (int) $r->c;
		return $out;
	}

	private function get_top_resources()
	{
		global $wpdb;
		$bt = $wpdb->prefix . 'g2ab_bookings';
		$rt = $wpdb->prefix . 'g2ab_resources';
		$start = wp_date('Y-m-d 00:00:00', strtotime('-29 days'));
		return $wpdb->get_results($wpdb->prepare("SELECT r.name, r.type, COUNT(b.id) AS c FROM {$rt} r LEFT JOIN {$bt} b ON b.resource_id = r.id AND b.created_at >= %s WHERE r.is_active = 1 GROUP BY r.id ORDER BY c DESC LIMIT 6", $start));
	}

	private function get_recent_bookings()
	{
		global $wpdb;
		$bt = $wpdb->prefix . 'g2ab_bookings';
		$rt = $wpdb->prefix . 'g2ab_resources';
		return $wpdb->get_results("SELECT b.*, r.name AS resource_name FROM {$bt} b LEFT JOIN {$rt} r ON r.id = b.resource_id ORDER BY b.created_at DESC LIMIT 5");
	}

	private function get_upcoming_bookings()
	{
		global $wpdb;
		$bt = $wpdb->prefix . 'g2ab_bookings';
		$rt = $wpdb->prefix . 'g2ab_resources';
		$now = current_time('mysql');
		return $wpdb->get_results($wpdb->prepare("SELECT b.*, r.name AS resource_name FROM {$bt} b LEFT JOIN {$rt} r ON r.id = b.resource_id WHERE b.start_at > %s AND b.status IN ('pending','reserved','confirmed','paid') ORDER BY b.start_at ASC LIMIT 5", $now));
	}

	private function render_kpi($label, $value, $delta, $accent, $sub, $invert = false)
	{
		$pos = $delta >= 0;
		$good = $invert ? ! $pos : $pos;
		$cls = $good ? 'is-good' : 'is-bad';
		$arrow = $pos ? '▲' : '▼';
	?>
		<div class="g2ab-dash__kpi" style="--accent:<?php echo esc_attr($accent); ?>;">
			<div class="g2ab-dash__kpi-lbl"><?php echo esc_html($label); ?></div>
			<div class="g2ab-dash__kpi-val"><?php echo esc_html($value); ?></div>
			<div class="g2ab-dash__kpi-foot">
				<span class="g2ab-dash__kpi-delta <?php echo esc_attr($cls); ?>"><?php echo esc_html($arrow . ' ' . abs($delta) . '%'); ?></span>
				<span class="g2ab-dash__kpi-sub"><?php echo esc_html($sub); ?></span>
			</div>
		</div>
	<?php
	}

	private function render_trend_chart($days)
	{
		$values = array_values($days);
		$max = max(max($values), 1);
		$labels = array_keys($days);
		$count = count($values);
		$w = 720;
		$h = 180;
		$pad_x = 32;
		$pad_y = 20;
		$inner_w = $w - 2 * $pad_x;
		$inner_h = $h - 2 * $pad_y;
		$step = $count > 1 ? $inner_w / ($count - 1) : 0;
		$path = '';
		$points = array();
		foreach ($values as $i => $v) {
			$x = $pad_x + $i * $step;
			$y = $pad_y + ($inner_h - ($v / $max * $inner_h));
			$points[] = array($x, $y);
			$path .= (0 === $i ? 'M' : 'L') . round($x, 2) . ',' . round($y, 2) . ' ';
		}
		$area = $path . 'L' . round($pad_x + ($count - 1) * $step, 2) . ',' . ($pad_y + $inner_h) . ' L' . $pad_x . ',' . ($pad_y + $inner_h) . ' Z';
		$total = array_sum($values);
	?>
		<div class="g2ab-dash__chart">
			<svg viewBox="0 0 <?php echo (int) $w; ?> <?php echo (int) $h; ?>" preserveAspectRatio="none" width="100%" height="<?php echo (int) $h; ?>">
				<defs>
					<linearGradient id="g2abGrad" x1="0" y1="0" x2="0" y2="1">
						<stop offset="0%" stop-color="#D2691E" stop-opacity="0.4" />
						<stop offset="100%" stop-color="#D2691E" stop-opacity="0" />
					</linearGradient>
					<pattern id="g2abGrid" width="60" height="40" patternUnits="userSpaceOnUse">
						<path d="M60 0 L0 0 0 40" fill="none" stroke="#f0f1f3" stroke-width="0.5" />
					</pattern>
				</defs>
				<rect width="<?php echo (int) $w; ?>" height="<?php echo (int) $h; ?>" fill="url(#g2abGrid)" />
				<path d="<?php echo esc_attr($area); ?>" fill="url(#g2abGrad)" />
				<path d="<?php echo esc_attr($path); ?>" fill="none" stroke="#D2691E" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" />
				<?php foreach ($points as $i => $p) : if ($i % 5 !== 0 && $i !== $count - 1) continue; ?>
					<circle cx="<?php echo (int) $p[0]; ?>" cy="<?php echo (int) $p[1]; ?>" r="3.5" fill="#0F1115" stroke="#D2691E" stroke-width="2" />
				<?php endforeach; ?>
			</svg>
			<div class="g2ab-dash__chart-foot">
				<span><?php echo esc_html(wp_date('M j', strtotime($labels[0] . ' 12:00:00'))); ?></span>
				<strong><?php echo (int) $total; ?> bookings · 30 days</strong>
				<span><?php echo esc_html(wp_date('M j', strtotime(end($labels) . ' 12:00:00'))); ?></span>
			</div>
		</div>
	<?php
	}

	private function render_status_donut($statuses)
	{
		$colors = array('pending' => '#F9A825', 'reserved' => '#8A95A5', 'confirmed' => '#4CAF50', 'paid' => '#4CAF50', 'completed' => '#0F1115', 'cancelled' => '#C62828', 'no_show' => '#C62828', 'refunded' => '#666');
		$total = array_sum($statuses);
		if (0 === $total) {
			echo '<p class="g2ab-dash__empty"><em>No bookings yet.</em></p>';
			return;
		}
		$grad = array();
		$start = 0;
		foreach ($statuses as $s => $c) {
			$pct = ($c / $total) * 100;
			if ($pct <= 0) continue;
			$end = $start + $pct;
			$grad[] = sprintf('%s %s%% %s%%', $colors[$s] ?? '#666', round($start, 2), round($end, 2));
			$start = $end;
		}
	?>
		<div class="g2ab-dash__donut-wrap">
			<div class="g2ab-dash__donut" style="background:conic-gradient(<?php echo esc_attr(implode(',', $grad)); ?>);">
				<div class="g2ab-dash__donut-hole"><strong><?php echo (int) $total; ?></strong><span>TOTAL</span></div>
			</div>
			<ul class="g2ab-dash__donut-legend">
				<?php foreach ($statuses as $s => $c) : if (0 === $c) continue; ?>
					<li>
						<span class="g2ab-dash__dot" style="background:<?php echo esc_attr($colors[$s] ?? '#666'); ?>;"></span>
						<span class="g2ab-dash__legend-name"><?php echo esc_html(strtoupper(str_replace('_', ' ', $s))); ?></span>
						<span class="g2ab-dash__legend-val"><?php echo (int) $c; ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php
	}

	private function render_top_resources($rows)
	{
		if (empty($rows)) {
			echo '<p class="g2ab-dash__empty"><em>No resources yet.</em></p>';
			return;
		}
		$max = 1;
		foreach ($rows as $r) $max = max($max, (int) $r->c);
	?>
		<ul class="g2ab-dash__bars">
			<?php foreach ($rows as $r) : $pct = ((int) $r->c / $max) * 100; ?>
				<li>
					<div class="g2ab-dash__bar-row">
						<span class="g2ab-dash__bar-name"><?php echo esc_html($r->name); ?></span>
						<span class="g2ab-dash__bar-val"><?php echo (int) $r->c; ?></span>
					</div>
					<div class="g2ab-dash__bar-track">
						<div class="g2ab-dash__bar-fill" style="width:<?php echo (float) $pct; ?>%;"></div>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php
	}

	private function render_upcoming($rows)
	{
		if (empty($rows)) {
			echo '<p class="g2ab-dash__empty"><em>No upcoming bookings.</em></p>';
			return;
		}
	?>
		<ul class="g2ab-dash__feed">
			<?php foreach ($rows as $b) : ?>
				<li>
					<div class="g2ab-dash__feed-when"><?php echo esc_html(wp_date('M j', strtotime($b->start_at))); ?><br><span><?php echo esc_html(wp_date('g:i A', strtotime($b->start_at))); ?></span></div>
					<div class="g2ab-dash__feed-body">
						<div class="g2ab-dash__feed-name"><?php echo esc_html($b->customer_name); ?></div>
						<div class="g2ab-dash__feed-meta"><?php echo esc_html($b->resource_name); ?> · <?php echo (int) $b->party_size; ?>p</div>
					</div>
					<a class="g2ab-dash__feed-link" href="<?php echo esc_url(admin_url('admin.php?page=g2ab-bookings-list&view=detail&booking_id=' . $b->id)); ?>">→</a>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php
	}

	private function render_recent($rows)
	{
		if (empty($rows)) {
			echo '<p class="g2ab-dash__empty"><em>No bookings yet.</em></p>';
			return;
		}
	?>
		<ul class="g2ab-dash__feed">
			<?php foreach ($rows as $b) : ?>
				<li>
					<div class="g2ab-dash__feed-when"><?php echo esc_html(human_time_diff(strtotime($b->created_at), current_time('timestamp'))); ?><br><span>ago</span></div>
					<div class="g2ab-dash__feed-body">
						<div class="g2ab-dash__feed-name"><?php echo esc_html($b->customer_name); ?></div>
						<div class="g2ab-dash__feed-meta"><span class="g2ab-dash__pill g2ab-dash__pill--<?php echo esc_attr($b->status); ?>"><?php echo esc_html(strtoupper($b->status)); ?></span> $<?php echo esc_html(number_format((float) $b->total_amount, 2)); ?></div>
					</div>
					<a class="g2ab-dash__feed-link" href="<?php echo esc_url(admin_url('admin.php?page=g2ab-bookings-list&view=detail&booking_id=' . $b->id)); ?>">→</a>
				</li>
			<?php endforeach; ?>
		</ul>
<?php
	}

	private function print_styles()
	{
		echo '<style>
.g2ab-dash{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}
.g2ab-dash__header{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:20px;background:linear-gradient(135deg,#0F1115 0%,#1A1F26 100%);color:#E8E8E8;padding:24px 28px;margin:20px 0 16px;border-left:4px solid #D2691E;}
.g2ab-dash__stencil{font-family:"Rajdhani","Oswald",Impact,sans-serif;font-size:32px;font-weight:700;letter-spacing:.12em;color:#fff;text-shadow:2px 2px 0 #4A5D3A;}
.g2ab-dash__sub{margin:4px 0 0;color:#8A95A5;font-size:13px;letter-spacing:.04em;}
.g2ab-dash__btn{display:inline-block;padding:10px 18px;background:#D2691E;color:#fff;text-decoration:none;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;border-radius:2px;}
.g2ab-dash__btn:hover{background:#fff;color:#0F1115;}
.g2ab-dash__kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-bottom:16px;}
.g2ab-dash__kpi{background:#fff;border:1px solid #e4e7ec;padding:20px 22px;border-top:4px solid var(--accent);position:relative;overflow:hidden;box-shadow:0 16px 34px rgba(15, 32, 68, .08);transition:transform .24s ease,box-shadow .24s ease;}
.g2ab-dash__kpi:hover{transform:translateY(-3px);box-shadow:0 24px 48px rgba(15, 32, 68, .14);}
.g2ab-dash__kpi::after{content:"";position:absolute;top:-10px;right:-20px;width:96px;height:96px;border-radius:50%;background:var(--accent);opacity:.08;}
.g2ab-dash__kpi-lbl{font-size:11px;color:#8A95A5;letter-spacing:.1em;font-weight:700;}
.g2ab-dash__kpi-val{font-family:"Rajdhani","Oswald",sans-serif;font-size:36px;font-weight:700;color:#0F1115;line-height:1.1;margin:6px 0;}
.g2ab-dash__kpi-foot{display:flex;align-items:center;gap:8px;font-size:12px;}
.g2ab-dash__kpi-delta{font-weight:700;padding:2px 8px;border-radius:2px;font-size:11px;}
.g2ab-dash__kpi-delta.is-good{background:rgba(76,175,80,.12);color:#1f7a23;}
.g2ab-dash__kpi-delta.is-bad{background:rgba(198,40,40,.12);color:#c62828;}
.g2ab-dash__kpi-sub{color:#8A95A5;font-size:11px;letter-spacing:.04em;}
.g2ab-dash__row{display:grid;grid-template-columns:2fr 1fr;gap:14px;margin-bottom:14px;}
.g2ab-dash__row:last-child{grid-template-columns:1fr 1fr 1fr;}
@media (max-width:1100px){.g2ab-dash__row,.g2ab-dash__row:last-child{grid-template-columns:1fr;}}
.g2ab-dash__panel{background:#fff;border:1px solid #e4e7ec;border-radius:18px;overflow:hidden;box-shadow:0 15px 30px rgba(15, 32, 68, .06);transition:transform .24s ease,box-shadow .24s ease;}
.g2ab-dash__panel:hover{transform:translateY(-2px);box-shadow:0 24px 40px rgba(15, 32, 68, .1);}
.g2ab-dash__panel header{display:flex;justify-content:space-between;align-items:center;padding:18px 22px;border-bottom:1px solid #f0f1f3;background:#fbfbfd;}
.g2ab-dash__panel h3{margin:0;font-family:"Rajdhani",sans-serif;font-size:13px;letter-spacing:.12em;color:#D2691E;font-weight:700;}
.g2ab-dash__panel header small{color:#8A95A5;font-size:11px;letter-spacing:.06em;}
.g2ab-dash__legend{display:inline-flex;align-items:center;gap:6px;font-size:11px;color:#8A95A5;letter-spacing:.04em;}
.g2ab-dash__dot{display:inline-block;width:10px;height:10px;border-radius:50%;}
.g2ab-dash__chart{padding:18px 20px;}
.g2ab-dash__chart svg{display:block;}
.g2ab-dash__chart-foot{display:flex;justify-content:space-between;font-size:11px;color:#8A95A5;letter-spacing:.04em;margin-top:6px;}
.g2ab-dash__chart-foot strong{color:#0F1115;font-size:12px;}
.g2ab-dash__donut-wrap{padding:18px 20px;}
.g2ab-dash__donut{width:180px;height:180px;border-radius:50%;margin:0 auto 16px;display:flex;align-items:center;justify-content:center;}
.g2ab-dash__donut-hole{width:120px;height:120px;background:#fff;border-radius:50%;display:flex;flex-direction:column;align-items:center;justify-content:center;}
.g2ab-dash__donut-hole strong{font-family:"Rajdhani",sans-serif;font-size:32px;color:#0F1115;line-height:1;}
.g2ab-dash__donut-hole span{font-size:9px;color:#8A95A5;letter-spacing:.1em;font-weight:700;}
.g2ab-dash__donut-legend{list-style:none;padding:0;margin:0;font-size:12px;}
.g2ab-dash__donut-legend li{display:flex;align-items:center;gap:8px;padding:5px 0;border-bottom:1px solid #f8f9fa;}
.g2ab-dash__legend-name{flex:1;color:#3c434a;font-size:11px;letter-spacing:.04em;}
.g2ab-dash__legend-val{font-weight:700;color:#0F1115;}
.g2ab-dash__bars{list-style:none;padding:18px 20px;margin:0;}
.g2ab-dash__bars li{margin-bottom:14px;}
.g2ab-dash__bar-row{display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px;}
.g2ab-dash__bar-name{color:#3c434a;font-weight:600;}
.g2ab-dash__bar-val{color:#D2691E;font-weight:700;}
.g2ab-dash__bar-track{background:#f0f1f3;height:8px;border-radius:1px;overflow:hidden;}
.g2ab-dash__bar-fill{height:100%;background:linear-gradient(90deg,#4A5D3A,#D2691E);}
.g2ab-dash__feed{list-style:none;padding:8px 0;margin:0;}
.g2ab-dash__feed li{display:flex;gap:12px;padding:10px 20px;border-bottom:1px solid #f8f9fa;}
.g2ab-dash__feed li:last-child{border:none;}
.g2ab-dash__feed-when{min-width:60px;font-size:11px;color:#0F1115;font-weight:700;line-height:1.2;text-align:right;border-right:2px solid #D2691E;padding-right:10px;}
.g2ab-dash__feed-when span{color:#8A95A5;font-weight:400;font-size:10px;}
.g2ab-dash__feed-body{flex:1;}
.g2ab-dash__feed-name{font-size:13px;font-weight:600;color:#0F1115;}
.g2ab-dash__feed-meta{font-size:11px;color:#8A95A5;margin-top:2px;}
.g2ab-dash__feed-link{color:#D2691E;text-decoration:none;font-size:18px;align-self:center;}
.g2ab-dash__feed-link:hover{color:#0F1115;}
.g2ab-dash__pill{display:inline-block;padding:1px 6px;font-size:9px;letter-spacing:.06em;font-weight:700;border-radius:2px;background:#0F1115;color:#fff;margin-right:4px;}
.g2ab-dash__pill--pending{background:#F9A825;}
.g2ab-dash__pill--confirmed,.g2ab-dash__pill--paid{background:#4CAF50;}
.g2ab-dash__pill--cancelled,.g2ab-dash__pill--no_show{background:#C62828;}
.g2ab-dash__empty{padding:30px 20px;text-align:center;color:#8A95A5;}
</style>';
	}
}
