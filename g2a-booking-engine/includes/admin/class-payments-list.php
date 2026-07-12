<?php
/**
 * Payments transaction log — shows wp_g2ab_payments + per-booking payment summaries.
 *
 * @package G2AB
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class G2AB_Admin_Payments_List {

	private static $instance = null;
	const PAGE_SLUG = 'g2ab-payments';

	public static function instance() {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'override_menu' ), 11 );
		add_action( 'admin_post_g2ab_export_payments_csv', array( $this, 'handle_export_csv' ) );
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
		add_submenu_page( 'g2ab-bookings', __( 'Payments', 'g2a-booking' ), __( 'Payments', 'g2a-booking' ), 'manage_g2ab_payments', self::PAGE_SLUG, array( $this, 'render' ) );
		if ( $idx >= 0 && isset( $submenu['g2ab-bookings'] ) ) {
			$new = array_pop( $submenu['g2ab-bookings'] );
			array_splice( $submenu['g2ab-bookings'], $idx, 0, array( $new ) );
		}
	}

	public function render() {
		if ( ! current_user_can( 'manage_g2ab_payments' ) ) wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
		global $wpdb;
		$pt = $wpdb->prefix . 'g2ab_payments';
		$bt = $wpdb->prefix . 'g2ab_bookings';

		$gateway = isset( $_GET['gateway'] ) ? sanitize_key( $_GET['gateway'] ) : '';
		$status  = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';

		$where = ' WHERE 1=1 '; $args = array();
		if ( $gateway ) { $where .= ' AND p.gateway = %s '; $args[] = $gateway; }
		if ( $status )  { $where .= ' AND p.status = %s '; $args[] = $status; }

		$sql = "SELECT p.*, b.uuid AS booking_uuid, b.customer_name FROM {$pt} p LEFT JOIN {$bt} b ON b.id = p.booking_id {$where} ORDER BY p.created_at DESC LIMIT 100";
		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql );

		// Summary stats from bookings table (since payments table may be empty in Phase 1).
		$totals = $wpdb->get_row( "SELECT
			COALESCE(SUM(paid_amount),0) AS total_paid,
			COALESCE(SUM(total_amount - paid_amount),0) AS outstanding,
			COUNT(*) AS booking_count,
			SUM(CASE WHEN status='paid' THEN 1 ELSE 0 END) AS paid_n,
			SUM(CASE WHEN status='pending' OR status='reserved' THEN 1 ELSE 0 END) AS pending_n,
			SUM(CASE WHEN status='refunded' THEN 1 ELSE 0 END) AS refunded_n
			FROM {$bt}" );

		$this->print_styles();
		?>
		<div class="wrap g2ab-admin g2ab-pl">
			<div class="g2ab-pl__header">
				<div>
					<h1><span class="g2ab-pl__stencil"><?php esc_html_e( 'PAYMENTS', 'g2a-booking' ); ?></span></h1>
					<p class="g2ab-pl__sub"><?php esc_html_e( 'Transaction log · revenue intel · refund actions', 'g2a-booking' ); ?></p>
				</div>
			</div>

			<div class="g2ab-pl__kpis">
				<div class="g2ab-pl__kpi"><span class="g2ab-pl__kpi-lbl">PAID</span><span class="g2ab-pl__kpi-val">$<?php echo esc_html( number_format( (float) $totals->total_paid, 2 ) ); ?></span></div>
				<div class="g2ab-pl__kpi"><span class="g2ab-pl__kpi-lbl">OUTSTANDING</span><span class="g2ab-pl__kpi-val">$<?php echo esc_html( number_format( (float) $totals->outstanding, 2 ) ); ?></span></div>
				<div class="g2ab-pl__kpi"><span class="g2ab-pl__kpi-lbl">BOOKINGS PAID</span><span class="g2ab-pl__kpi-val"><?php echo (int) $totals->paid_n; ?></span></div>
				<div class="g2ab-pl__kpi"><span class="g2ab-pl__kpi-lbl">PENDING</span><span class="g2ab-pl__kpi-val"><?php echo (int) $totals->pending_n; ?></span></div>
				<div class="g2ab-pl__kpi"><span class="g2ab-pl__kpi-lbl">REFUNDED</span><span class="g2ab-pl__kpi-val"><?php echo (int) $totals->refunded_n; ?></span></div>
			</div>

			<div class="g2ab-pl__notice">
				<strong><?php esc_html_e( 'PHASE 1 — PAY-IN-STORE LIVE', 'g2a-booking' ); ?></strong>
				<p><?php esc_html_e( 'Stripe + PayPal + Fortis Pay + Authorize.net gateway integrations land in Step 7 (architecture locked in PAYMENT-GATEWAY-ARCHITECTURE.md). Live transaction log here will populate once gateways activate.', 'g2a-booking' ); ?></p>
			</div>

			<form method="get" class="g2ab-pl__filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<select name="gateway">
					<option value=""><?php esc_html_e( 'All Gateways', 'g2a-booking' ); ?></option>
					<?php foreach ( array( 'stripe', 'paypal', 'fortis', 'authnet', 'pay_in_store', 'free' ) as $g ) : ?>
						<option value="<?php echo esc_attr( $g ); ?>" <?php selected( $gateway, $g ); ?>><?php echo esc_html( strtoupper( str_replace( '_', ' ', $g ) ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="status">
					<option value=""><?php esc_html_e( 'All Statuses', 'g2a-booking' ); ?></option>
					<?php foreach ( array( 'pending', 'succeeded', 'failed', 'refunded', 'partial_refund' ) as $s ) : ?>
						<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status, $s ); ?>><?php echo esc_html( strtoupper( str_replace( '_', ' ', $s ) ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<button class="button button-primary"><?php esc_html_e( 'FILTER', 'g2a-booking' ); ?></button>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array(
					'action'  => 'g2ab_export_payments_csv',
					'gateway' => $gateway,
					'status'  => $status,
				), admin_url( 'admin-post.php' ) ), 'g2ab_export_payments_csv' ) ); ?>" title="<?php esc_attr_e( 'Download the filtered payment list as a spreadsheet (.csv)', 'g2a-booking' ); ?>">
					<?php esc_html_e( 'EXPORT CSV', 'g2a-booking' ); ?>
				</a>
			</form>

			<div class="g2ab-pl__table-wrap">
				<?php if ( empty( $rows ) ) : ?>
					<div class="g2ab-pl__empty"><p><?php esc_html_e( 'No payment transactions yet. Bookings will produce transaction rows once a payment gateway is configured.', 'g2a-booking' ); ?></p></div>
				<?php else : ?>
					<table class="g2ab-pl__table">
						<thead><tr><th>WHEN</th><th>BOOKING</th><th>CUSTOMER</th><th>GATEWAY</th><th>METHOD</th><th>AMOUNT</th><th>STATUS</th><th>TXN ID</th></tr></thead>
						<tbody>
							<?php foreach ( $rows as $p ) : ?>
								<tr>
									<td><?php echo esc_html( mysql2date( 'M j · g:i A', $p->created_at ) ); ?></td>
									<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=g2ab-bookings-list&view=detail&booking_id=' . $p->booking_id ) ); ?>">#<?php echo (int) $p->booking_id; ?></a></td>
									<td><?php echo esc_html( $p->customer_name ?: '—' ); ?></td>
									<td><span class="g2ab-pl__pill g2ab-pl__pill--<?php echo esc_attr( $p->gateway ); ?>"><?php echo esc_html( strtoupper( str_replace( '_', ' ', $p->gateway ) ) ); ?></span></td>
									<td><?php echo esc_html( $p->payment_method ?: '—' ); ?></td>
									<td>$<?php echo esc_html( number_format( (float) $p->amount, 2 ) ); ?></td>
									<td><span class="g2ab-pl__pill g2ab-pl__pill--<?php echo esc_attr( $p->status ); ?>"><?php echo esc_html( strtoupper( str_replace( '_', ' ', $p->status ) ) ); ?></span></td>
									<td><code><?php echo esc_html( $p->transaction_id ?: '—' ); ?></code></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Stream the payments list as a CSV. Honors gateway + status filters.
	 */
	public function handle_export_csv() {
		if ( ! current_user_can( 'manage_g2ab_payments' ) ) {
			wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
		}
		check_admin_referer( 'g2ab_export_payments_csv' );

		global $wpdb;
		$pt = $wpdb->prefix . 'g2ab_payments';
		$bt = $wpdb->prefix . 'g2ab_bookings';

		$gateway = isset( $_GET['gateway'] ) ? sanitize_key( wp_unslash( $_GET['gateway'] ) ) : '';
		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

		$where = ' WHERE 1=1 ';
		$args  = array();
		if ( $gateway ) { $where .= ' AND p.gateway = %s '; $args[] = $gateway; }
		if ( $status )  { $where .= ' AND p.status = %s ';  $args[] = $status; }

		$sql = "SELECT p.id, p.booking_id, b.uuid AS booking_uuid, b.customer_name, b.customer_email,
		               p.gateway, p.transaction_id, p.amount, p.currency, p.status,
		               p.payment_method, p.refund_amount, p.processed_at, p.created_at
		        FROM {$pt} p
		        LEFT JOIN {$bt} b ON b.id = p.booking_id
		        {$where}
		        ORDER BY p.created_at DESC";
		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );

		$filename = 'g2a-payments-' . wp_date( 'Y-m-d-His' ) . '.csv';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array(
			'ID', 'Booking ID', 'Booking UUID', 'Customer Name', 'Customer Email',
			'Gateway', 'Transaction ID', 'Amount', 'Currency', 'Status',
			'Method', 'Refunded', 'Processed', 'Created',
		) );

		if ( empty( $rows ) ) {
			fputcsv( $out, array( 'No payments match the current filter' ) );
		} else {
			foreach ( $rows as $r ) {
				fputcsv( $out, array(
					$r['id'], $r['booking_id'], $r['booking_uuid'], $r['customer_name'], $r['customer_email'],
					$r['gateway'], $r['transaction_id'], $r['amount'], $r['currency'], $r['status'],
					$r['payment_method'], $r['refund_amount'], $r['processed_at'], $r['created_at'],
				) );
			}
		}

		fclose( $out );
		exit;
	}

	private function print_styles() {
		echo '<style>
.g2ab-pl{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}
.g2ab-pl__header{background:linear-gradient(135deg,#1A191E 0%,#26252C 100%);color:#F7F7F9;padding:24px 28px;margin:20px 0 16px;border-left:4px solid #E8802F;}
.g2ab-pl__stencil{font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;font-size:30px;font-weight:700;letter-spacing:.04em;color:#fff;}
.g2ab-pl__sub{margin:4px 0 0;color:#A7A6AE;font-size:13px;text-transform:uppercase;letter-spacing:.08em;}
.g2ab-pl__kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:16px;}
.g2ab-pl__kpi{background:#fff;border:1px solid #d0d4d9;padding:16px 18px;border-top:3px solid #E8802F;}
.g2ab-pl__kpi-lbl{display:block;font-size:10px;color:#A7A6AE;letter-spacing:.1em;font-weight:700;}
.g2ab-pl__kpi-val{display:block;font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;font-size:28px;color:#1A191E;font-weight:700;line-height:1.1;margin-top:6px;}
.g2ab-pl__notice{background:#fffbe6;border-left:4px solid #E8802F;padding:14px 18px;margin-bottom:14px;}
.g2ab-pl__notice strong{display:block;font-size:11px;color:#E8802F;letter-spacing:.08em;margin-bottom:4px;}
.g2ab-pl__notice p{margin:0;color:#3c434a;font-size:13px;}
.g2ab-pl__filters{background:#fff;padding:14px 18px;border:1px solid #d0d4d9;display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
.g2ab-pl__filters select{padding:8px 12px;border:1px solid #ccd0d4;border-radius:3px;font-size:13px;}
.g2ab-pl__table-wrap{background:#fff;border:1px solid #d0d4d9;border-top:none;}
.g2ab-pl__table{width:100%;border-collapse:collapse;font-size:13px;}
.g2ab-pl__table th{text-align:left;padding:12px 14px;background:#f8f9fa;color:#A7A6AE;font-size:10px;letter-spacing:.08em;border-bottom:2px solid #d0d4d9;}
.g2ab-pl__table td{padding:12px 14px;border-bottom:1px solid #f0f1f3;}
.g2ab-pl__table code{background:#f0f1f3;padding:1px 6px;border-radius:2px;color:#3c434a;font-size:11px;}
.g2ab-pl__pill{display:inline-block;padding:2px 8px;font-size:9px;letter-spacing:.06em;font-weight:700;border-radius:2px;background:#1A191E;color:#fff;}
.g2ab-pl__pill--succeeded{background:#4CAF50;}
.g2ab-pl__pill--failed,.g2ab-pl__pill--refunded{background:#C62828;}
.g2ab-pl__pill--pending{background:#E8802F;}
.g2ab-pl__pill--stripe{background:#635BFF;}
.g2ab-pl__pill--paypal{background:#003087;}
.g2ab-pl__pill--pay_in_store{background:#E8802F;}
.g2ab-pl__empty{padding:40px;text-align:center;color:#A7A6AE;}
</style>';
	}
}
