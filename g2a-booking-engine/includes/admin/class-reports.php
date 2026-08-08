<?php
/**
 * Reports — date-range filtered analytics + export.
 *
 * @package G2AB
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class G2AB_Admin_Reports {

	private static $instance = null;
	const PAGE_SLUG = 'g2ab-reports';

	public static function instance() {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'override_menu' ), 11 );
		add_action( 'admin_post_g2ab_export_csv', array( $this, 'handle_export_csv' ) );
		add_action( 'admin_post_g2ab_export_monthly_csv', array( $this, 'handle_export_monthly_csv' ) );
	}

	public function override_menu() {
		global $submenu;
		$idx = -1;
		if ( isset( $submenu['g2ab-bookings'] ) ) {
			foreach ( $submenu['g2ab-bookings'] as $i => $entry ) {
				if ( $entry[2] === self::PAGE_SLUG ) { $idx = $i; break; }
			}
		}
		remove_submenu_page( 'g2ab-bookings', self::PAGE_SLUG );
		add_submenu_page( 'g2ab-bookings', __( 'Reports', 'g2a-booking' ), __( 'Reports', 'g2a-booking' ), 'view_g2ab_reports', self::PAGE_SLUG, array( $this, 'render' ) );
		if ( $idx >= 0 && isset( $submenu['g2ab-bookings'] ) ) {
			$new = array_pop( $submenu['g2ab-bookings'] );
			array_splice( $submenu['g2ab-bookings'], $idx, 0, array( $new ) );
		}
	}

	public function render() {
		if ( ! current_user_can( 'view_g2ab_reports' ) ) wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );

		$from = isset( $_GET['from'] ) ? sanitize_text_field( $_GET['from'] ) : wp_date( 'Y-m-01' );
		$to   = isset( $_GET['to'] ) ? sanitize_text_field( $_GET['to'] ) : wp_date( 'Y-m-d' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) $from = wp_date( 'Y-m-01' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) )   $to   = wp_date( 'Y-m-d' );

		global $wpdb;
		$bt = $wpdb->prefix . 'g2ab_bookings';
		$rt = $wpdb->prefix . 'g2ab_resources';
		$btt = $wpdb->prefix . 'g2ab_booking_types';

		$f = $from . ' 00:00:00';
		$t = $to . ' 23:59:59';
		$operational = class_exists( 'G2AB_Booking_Visibility' )
			? G2AB_Booking_Visibility::operational_sql( 'b' )
			: "( b.status IN ('confirmed','paid','completed') OR ( b.status = 'reserved' AND b.payment_mode = 'in_store' ) )";

		// Totals
		$totals = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) AS bookings, COALESCE(SUM(b.total_amount),0) AS revenue, COALESCE(SUM(b.paid_amount),0) AS paid, COALESCE(AVG(b.total_amount),0) AS avg_value, COALESCE(SUM(b.party_size),0) AS shooters FROM {$bt} b WHERE b.created_at BETWEEN %s AND %s AND {$operational}", $f, $t
		) );

		$status_breakdown = $wpdb->get_results( $wpdb->prepare(
			"SELECT b.status, COUNT(*) c FROM {$bt} b WHERE b.created_at BETWEEN %s AND %s AND {$operational} GROUP BY b.status", $f, $t
		), OBJECT_K );

		// Daily bookings
		$daily = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(b.created_at) AS d, COUNT(*) AS c, COALESCE(SUM(b.total_amount),0) AS rev FROM {$bt} b WHERE b.created_at BETWEEN %s AND %s AND {$operational} GROUP BY DATE(b.created_at) ORDER BY d ASC", $f, $t
		) );

		// Top resources
		$top_resources = $wpdb->get_results( $wpdb->prepare(
			"SELECT r.name, r.type, COUNT(b.id) c, COALESCE(SUM(b.total_amount),0) rev FROM {$bt} b LEFT JOIN {$rt} r ON r.id = b.resource_id WHERE b.created_at BETWEEN %s AND %s AND {$operational} GROUP BY b.resource_id ORDER BY c DESC LIMIT 10", $f, $t
		) );

		// Top booking types
		$top_types = $wpdb->get_results( $wpdb->prepare(
			"SELECT t.name, t.category, COUNT(b.id) c, COALESCE(SUM(b.total_amount),0) rev FROM {$bt} b LEFT JOIN {$btt} t ON t.id = b.booking_type_id WHERE b.created_at BETWEEN %s AND %s AND {$operational} GROUP BY b.booking_type_id ORDER BY c DESC LIMIT 10", $f, $t
		) );

		$this->print_styles();
		?>
		<div class="wrap g2ab-admin g2ab-rp">
			<div class="g2ab-rp__header">
				<div>
					<h1><span class="g2ab-rp__stencil"><?php esc_html_e( 'REPORTS', 'g2a-booking' ); ?></span></h1>
					<p class="g2ab-rp__sub"><?php esc_html_e( 'Operational intel · revenue · utilization · exports', 'g2a-booking' ); ?></p>
				</div>
				<form method="get" class="g2ab-rp__range">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
					<label>FROM</label> <input type="date" name="from" value="<?php echo esc_attr( $from ); ?>" />
					<label>TO</label> <input type="date" name="to" value="<?php echo esc_attr( $to ); ?>" />
					<button class="g2ab-rp__btn"><?php esc_html_e( 'APPLY', 'g2a-booking' ); ?></button>
					<a class="g2ab-rp__btn g2ab-rp__btn--alt" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=g2ab_export_csv&from=' . $from . '&to=' . $to ), 'g2ab_export_csv' ) ); ?>"><?php esc_html_e( 'EXPORT CSV', 'g2a-booking' ); ?></a>
					<a class="g2ab-rp__btn g2ab-rp__btn--alt" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=g2ab_export_monthly_csv&from=' . $from . '&to=' . $to ), 'g2ab_export_monthly_csv' ) ); ?>" title="<?php esc_attr_e( 'Monthly summary: bookings, revenue, refunds per month', 'g2a-booking' ); ?>"><?php esc_html_e( 'MONTHLY SUMMARY', 'g2a-booking' ); ?></a>
				</form>
			</div>

			<div class="g2ab-rp__kpis">
				<div class="g2ab-rp__kpi"><span>BOOKINGS</span><strong><?php echo (int) $totals->bookings; ?></strong></div>
				<div class="g2ab-rp__kpi"><span>REVENUE</span><strong>$<?php echo esc_html( number_format( (float) $totals->revenue, 2 ) ); ?></strong></div>
				<div class="g2ab-rp__kpi"><span>COLLECTED</span><strong>$<?php echo esc_html( number_format( (float) $totals->paid, 2 ) ); ?></strong></div>
				<div class="g2ab-rp__kpi"><span>AVG VALUE</span><strong>$<?php echo esc_html( number_format( (float) $totals->avg_value, 2 ) ); ?></strong></div>
				<div class="g2ab-rp__kpi"><span>SHOOTERS</span><strong><?php echo (int) $totals->shooters; ?></strong></div>
				<div class="g2ab-rp__kpi"><span>NO-SHOWS</span><strong><?php echo (int) ( $status_breakdown['no_show']->c ?? 0 ); ?></strong></div>
			</div>

			<div class="g2ab-rp__row">
				<div class="g2ab-rp__panel">
					<h3><?php esc_html_e( 'DAILY BOOKINGS', 'g2a-booking' ); ?></h3>
					<?php $this->render_daily_chart( $daily, $from, $to ); ?>
				</div>
				<div class="g2ab-rp__panel">
					<h3><?php esc_html_e( 'STATUS BREAKDOWN', 'g2a-booking' ); ?></h3>
					<?php $this->render_status_table( $status_breakdown ); ?>
				</div>
			</div>

			<div class="g2ab-rp__row">
				<div class="g2ab-rp__panel">
					<h3><?php esc_html_e( 'TOP RESOURCES', 'g2a-booking' ); ?></h3>
					<?php $this->render_table( $top_resources, array( 'name' => 'RESOURCE', 'c' => 'BOOKINGS', 'rev' => 'REVENUE' ) ); ?>
				</div>
				<div class="g2ab-rp__panel">
					<h3><?php esc_html_e( 'TOP BOOKING TYPES', 'g2a-booking' ); ?></h3>
					<?php $this->render_table( $top_types, array( 'name' => 'TYPE', 'c' => 'BOOKINGS', 'rev' => 'REVENUE' ) ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	private function render_daily_chart( $rows, $from, $to ) {
		// Build daily map covering full range.
		$days = array();
		$cur = strtotime( $from );
		$end = strtotime( $to );
		while ( $cur <= $end ) {
			$days[ wp_date( 'Y-m-d', $cur ) ] = array( 'c' => 0, 'rev' => 0 );
			$cur += 86400;
		}
		foreach ( $rows as $r ) {
			if ( isset( $days[ $r->d ] ) ) {
				$days[ $r->d ] = array( 'c' => (int) $r->c, 'rev' => (float) $r->rev );
			}
		}
		$values = array_column( $days, 'c' );
		if ( empty( $values ) ) { echo '<p style="color:#A7A6AE;">No data for this range.</p>'; return; }
		$max = max( max( $values ), 1 );

		$w = 720; $h = 180; $pad = 24;
		$inner_w = $w - 2 * $pad; $inner_h = $h - 2 * $pad;
		$count = count( $values );
		$step = $count > 1 ? $inner_w / ( $count - 1 ) : 0;
		$path = ''; $area = '';
		$labels = array_keys( $days );
		foreach ( $values as $i => $v ) {
			$x = $pad + $i * $step;
			$y = $pad + ( $inner_h - ( $v / $max * $inner_h ) );
			$path .= ( 0 === $i ? 'M' : 'L' ) . round( $x, 2 ) . ',' . round( $y, 2 ) . ' ';
		}
		$area = $path . 'L' . round( $pad + ( $count - 1 ) * $step, 2 ) . ',' . ( $pad + $inner_h ) . ' L' . $pad . ',' . ( $pad + $inner_h ) . ' Z';
		?>
		<svg viewBox="0 0 <?php echo (int) $w; ?> <?php echo (int) $h; ?>" preserveAspectRatio="none" width="100%" height="<?php echo (int) $h; ?>">
			<defs>
				<linearGradient id="g2abRpGrad" x1="0" y1="0" x2="0" y2="1">
					<stop offset="0%" stop-color="#E8802F" stop-opacity="0.4"/>
					<stop offset="100%" stop-color="#E8802F" stop-opacity="0"/>
				</linearGradient>
			</defs>
			<path d="<?php echo esc_attr( $area ); ?>" fill="url(#g2abRpGrad)"/>
			<path d="<?php echo esc_attr( $path ); ?>" fill="none" stroke="#E8802F" stroke-width="2.5" stroke-linejoin="round"/>
		</svg>
		<div class="g2ab-rp__chart-foot"><span><?php echo esc_html( $labels[0] ); ?></span><strong><?php echo array_sum( $values ); ?> bookings · <?php echo $count; ?> days</strong><span><?php echo esc_html( end( $labels ) ); ?></span></div>
		<?php
	}

	private function render_status_table( $statuses ) {
		if ( empty( $statuses ) ) { echo '<p style="color:#A7A6AE;">No bookings.</p>'; return; }
		$total = 0;
		foreach ( $statuses as $s ) $total += (int) $s->c;
		echo '<table class="g2ab-rp__table"><thead><tr><th>STATUS</th><th>COUNT</th><th>%</th></tr></thead><tbody>';
		foreach ( $statuses as $key => $s ) {
			$pct = $total > 0 ? round( ( (int) $s->c / $total ) * 100, 1 ) : 0;
			echo '<tr>';
			echo '<td><span class="g2ab-rp__pill g2ab-rp__pill--' . esc_attr( $key ) . '">' . esc_html( strtoupper( str_replace( '_', ' ', $key ) ) ) . '</span></td>';
			echo '<td><strong>' . (int) $s->c . '</strong></td>';
			echo '<td>' . esc_html( $pct ) . '%</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private function render_table( $rows, $cols ) {
		if ( empty( $rows ) ) { echo '<p style="color:#A7A6AE;">No data.</p>'; return; }
		echo '<table class="g2ab-rp__table"><thead><tr>';
		foreach ( $cols as $label ) echo '<th>' . esc_html( $label ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr>';
			foreach ( $cols as $field => $label ) {
				$v = $r->{$field} ?? '';
				if ( 'rev' === $field ) $v = '$' . number_format( (float) $v, 2 );
				echo '<td>' . esc_html( $v ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	public function handle_export_csv() {
		if ( ! current_user_can( 'view_g2ab_reports' ) ) wp_die( 'No permission.' );
		check_admin_referer( 'g2ab_export_csv' );

		$from = isset( $_GET['from'] ) ? sanitize_text_field( $_GET['from'] ) : wp_date( 'Y-m-01' );
		$to   = isset( $_GET['to'] ) ? sanitize_text_field( $_GET['to'] ) : wp_date( 'Y-m-d' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) wp_die( 'Bad date.' );

		global $wpdb;
		$bt = $wpdb->prefix . 'g2ab_bookings';
		$rt = $wpdb->prefix . 'g2ab_resources';
		$btt = $wpdb->prefix . 'g2ab_booking_types';
		$operational = class_exists( 'G2AB_Booking_Visibility' )
			? G2AB_Booking_Visibility::operational_sql( 'b' )
			: "( b.status IN ('confirmed','paid','completed') OR ( b.status = 'reserved' AND b.payment_mode = 'in_store' ) )";
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT b.id, b.uuid, b.customer_name, b.customer_email, b.customer_phone, b.start_at, b.end_at, b.duration_min, b.party_size, b.status, b.payment_mode, b.total_amount, b.paid_amount, b.currency, r.name AS resource, t.name AS type FROM {$bt} b LEFT JOIN {$rt} r ON r.id = b.resource_id LEFT JOIN {$btt} t ON t.id = b.booking_type_id WHERE b.created_at BETWEEN %s AND %s AND {$operational} ORDER BY b.created_at DESC",
			$from . ' 00:00:00', $to . ' 23:59:59'
		), ARRAY_A );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=g2a-bookings-' . $from . '-to-' . $to . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM for Excel.
		if ( ! empty( $rows ) ) {
			fputcsv( $out, array_keys( $rows[0] ) );
			foreach ( $rows as $r ) fputcsv( $out, $r );
		} else {
			fputcsv( $out, array( 'No data' ) );
		}
		fclose( $out );
		exit;
	}

	/**
	 * Monthly summary export.
	 *
	 * Aggregates bookings month-by-month between `from` and `to` (inclusive).
	 * Columns: Month, Bookings, Confirmed, Cancelled, No-Show, Refunded,
	 *          Gross Revenue, Paid Revenue, Refunded Amount.
	 */
	public function handle_export_monthly_csv() {
		if ( ! current_user_can( 'view_g2ab_reports' ) ) wp_die( 'No permission.' );
		check_admin_referer( 'g2ab_export_monthly_csv' );

		$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : wp_date( 'Y-01-01' );
		$to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : wp_date( 'Y-m-d' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) wp_die( 'Bad date.' );

		global $wpdb;
		$bt = $wpdb->prefix . 'g2ab_bookings';
		$pt = $wpdb->prefix . 'g2ab_payments';
		$operational = class_exists( 'G2AB_Booking_Visibility' )
			? G2AB_Booking_Visibility::operational_sql( 'b' )
			: "( b.status IN ('confirmed','paid','completed') OR ( b.status = 'reserved' AND b.payment_mode = 'in_store' ) )";

		// One pass over operational-OR-terminal rows: the cancelled/no-show/
		// refunded columns can only be non-zero if those statuses are actually
		// selected, while revenue columns still count operational rows only —
		// pending/expired checkout holds never appear in either.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE_FORMAT(b.start_at, '%%Y-%%m') AS ym,
			        SUM(CASE WHEN {$operational} THEN 1 ELSE 0 END) AS bookings,
			        SUM(CASE WHEN b.status IN ('confirmed','paid','completed','partially_refunded') THEN 1 ELSE 0 END) AS confirmed,
			        SUM(CASE WHEN b.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
			        SUM(CASE WHEN b.status = 'no_show'   THEN 1 ELSE 0 END) AS no_show,
			        SUM(CASE WHEN b.status = 'refunded'  THEN 1 ELSE 0 END) AS refunded,
			        COALESCE(SUM(CASE WHEN {$operational} THEN b.total_amount ELSE 0 END), 0) AS gross_revenue,
			        COALESCE(SUM(CASE WHEN {$operational} THEN b.paid_amount  ELSE 0 END), 0) AS paid_revenue
			 FROM {$bt} b
			 WHERE b.start_at BETWEEN %s AND %s
			   AND ( {$operational} OR b.status IN ('cancelled','no_show','refunded') )
			 GROUP BY ym
			 ORDER BY ym ASC",
			$from . ' 00:00:00', $to . ' 23:59:59'
		), ARRAY_A );

		// Refunds aren't always on the booking row — look them up from the payments table.
		$refunds = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE_FORMAT(COALESCE(processed_at, created_at), '%%Y-%%m') AS ym,
			        COALESCE(SUM(refund_amount), 0) AS refunded_amount
			 FROM {$pt}
			 WHERE COALESCE(processed_at, created_at) BETWEEN %s AND %s
			 GROUP BY ym",
			$from . ' 00:00:00', $to . ' 23:59:59'
		), OBJECT_K );

		$filename = 'g2a-monthly-summary-' . $from . '-to-' . $to . '.csv';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array(
			'Month', 'Bookings', 'Confirmed', 'Cancelled', 'No-Show', 'Refunded (count)',
			'Gross Revenue', 'Paid Revenue', 'Refunded Amount',
		) );

		if ( empty( $rows ) ) {
			fputcsv( $out, array( 'No bookings in this range' ) );
		} else {
			foreach ( $rows as $r ) {
				$refunded_amount = isset( $refunds[ $r['ym'] ] ) ? (float) $refunds[ $r['ym'] ]->refunded_amount : 0;
				fputcsv( $out, array(
					$r['ym'],
					(int) $r['bookings'],
					(int) $r['confirmed'],
					(int) $r['cancelled'],
					(int) $r['no_show'],
					(int) $r['refunded'],
					number_format( (float) $r['gross_revenue'], 2, '.', '' ),
					number_format( (float) $r['paid_revenue'], 2, '.', '' ),
					number_format( $refunded_amount, 2, '.', '' ),
				) );
			}
		}

		fclose( $out );
		exit;
	}

	private function print_styles() {
		echo '<style>
.g2ab-rp{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}
.g2ab-rp__header{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:18px;background:linear-gradient(135deg,#1A191E 0%,#26252C 100%);color:#F7F7F9;padding:24px 28px;margin:20px 0 16px;border-left:4px solid #E8802F;}
.g2ab-rp__stencil{font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;font-size:30px;font-weight:700;letter-spacing:.04em;color:#fff;}
.g2ab-rp__sub{margin:4px 0 0;color:#A7A6AE;font-size:13px;text-transform:uppercase;letter-spacing:.08em;}
.g2ab-rp__range{display:flex;gap:8px;align-items:center;background:rgba(255,255,255,.06);padding:10px 14px;flex-wrap:wrap;}
.g2ab-rp__range label{font-size:10px;color:#A7A6AE;letter-spacing:.08em;font-weight:700;}
.g2ab-rp__range input[type="date"]{padding:6px 10px;border:1px solid #2A323D;background:#1A191E;color:#F7F7F9;font-size:13px;border-radius:2px;}
.g2ab-rp__btn{display:inline-block;background:#E8802F;color:#fff;padding:8px 14px;border:none;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;cursor:pointer;text-decoration:none;border-radius:2px;}
.g2ab-rp__btn--alt{background:transparent;border:1px solid #E8802F;}
.g2ab-rp__btn:hover{background:#fff;color:#E8802F;}
.g2ab-rp__kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin-bottom:16px;}
.g2ab-rp__kpi{background:#fff;border:1px solid #d0d4d9;padding:16px 18px;border-top:3px solid #E8802F;}
.g2ab-rp__kpi span{display:block;font-size:10px;color:#A7A6AE;letter-spacing:.1em;font-weight:700;}
.g2ab-rp__kpi strong{display:block;font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;font-size:26px;color:#1A191E;font-weight:700;line-height:1.1;margin-top:6px;}
.g2ab-rp__row{display:grid;grid-template-columns:1.4fr 1fr;gap:14px;margin-bottom:14px;}
@media (max-width:1000px){.g2ab-rp__row{grid-template-columns:1fr;}}
.g2ab-rp__panel{background:#fff;border:1px solid #d0d4d9;padding:18px 22px;}
.g2ab-rp__panel h3{font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;font-size:13px;letter-spacing:.12em;color:#E8802F;margin:0 0 14px;font-weight:700;}
.g2ab-rp__chart-foot{display:flex;justify-content:space-between;font-size:11px;color:#A7A6AE;letter-spacing:.04em;margin-top:6px;}
.g2ab-rp__chart-foot strong{color:#1A191E;}
.g2ab-rp__table{width:100%;border-collapse:collapse;font-size:13px;}
.g2ab-rp__table th{text-align:left;padding:8px 10px;color:#A7A6AE;font-size:10px;letter-spacing:.06em;border-bottom:2px solid #d0d4d9;}
.g2ab-rp__table td{padding:8px 10px;border-bottom:1px solid #f0f1f3;}
.g2ab-rp__pill{display:inline-block;padding:2px 8px;font-size:9px;letter-spacing:.06em;font-weight:700;border-radius:2px;background:#1A191E;color:#fff;}
.g2ab-rp__pill--paid,.g2ab-rp__pill--confirmed{background:#4CAF50;}
.g2ab-rp__pill--cancelled,.g2ab-rp__pill--no_show,.g2ab-rp__pill--refunded{background:#C62828;}
.g2ab-rp__pill--pending{background:#E8802F;}
</style>';
	}
}
