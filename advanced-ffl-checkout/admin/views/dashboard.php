<?php
/**
 * Admin Dashboard view — v1.0.2
 *
 * @package WpisticFFL
 */
defined( 'ABSPATH' ) || exit;

global $wpdb;

$t_table      = \WpisticFFL\DB::table( 'transfers' );
$d_table      = \WpisticFFL\DB::table( 'dealers' );

$total_transfers  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t_table}" );
$active_transfers = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t_table} WHERE status NOT IN ('transferred','cancelled','nics_denied')" );
$active_dealers   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$d_table} WHERE is_active = 1" );
$nics_delayed     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t_table} WHERE status = 'nics_delayed'" );
$this_month       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t_table} WHERE MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())" );

$recent_transfers = $wpdb->get_results(
	"SELECT t.*, d.business_name AS dealer_name FROM {$t_table} t LEFT JOIN {$d_table} d ON d.id = t.dealer_id ORDER BY t.created_at DESC LIMIT 8"
);

// Gap #15 — real charts instead of HTML-only KPI tiles: transfers by
// status, and a zero-filled 30-day daily-created trend.
$status_counts = $wpdb->get_results( "SELECT status, COUNT(*) AS cnt FROM {$t_table} GROUP BY status" );
$status_count_map = [];
foreach ( $status_counts as $row ) {
	$status_count_map[ $row->status ] = (int) $row->cnt;
}

$daily_labels = [];
$daily_counts = [];
for ( $i = 29; $i >= 0; $i-- ) {
	$day = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
	$daily_labels[ $day ] = date_i18n( 'M j', strtotime( $day ) );
	$daily_counts[ $day ] = 0;
}
$since = gmdate( 'Y-m-d H:i:s', strtotime( '-29 days' ) );
$daily_rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
	"SELECT DATE( created_at ) AS d, COUNT(*) AS cnt FROM {$t_table} WHERE created_at >= %s GROUP BY DATE( created_at )",
	$since
) );
foreach ( $daily_rows as $row ) {
	if ( isset( $daily_counts[ $row->d ] ) ) {
		$daily_counts[ $row->d ] = (int) $row->cnt;
	}
}

$status_labels = [
	'dealer_selected'   => [ 'label' => 'Dealer Selected',    'class' => 'status-new' ],
	'payment_confirmed' => [ 'label' => 'Payment Confirmed',  'class' => 'status-new' ],
	'processing'        => [ 'label' => 'Processing',         'class' => 'status-processing' ],
	'shipped'           => [ 'label' => 'Shipped',            'class' => 'status-shipped' ],
	'dealer_received'   => [ 'label' => 'At Dealer',          'class' => 'status-at-dealer' ],
	'nics_pending'      => [ 'label' => 'NICS Pending',       'class' => 'status-pending' ],
	'nics_delayed'      => [ 'label' => 'NICS Delayed',       'class' => 'status-delayed' ],
	'nics_approved'     => [ 'label' => 'NICS Approved',      'class' => 'status-approved' ],
	'nics_denied'       => [ 'label' => 'NICS Denied',        'class' => 'status-denied' ],
	'transferred'       => [ 'label' => 'Transferred',        'class' => 'status-complete' ],
	'cancelled'         => [ 'label' => 'Cancelled',          'class' => 'status-cancelled' ],
];

$status_chart = [ 'labels' => [], 'data' => [] ];
foreach ( $status_count_map as $status => $count ) {
	if ( $count > 0 ) {
		$status_chart['labels'][] = $status_labels[ $status ]['label'] ?? $status;
		$status_chart['data'][]   = $count;
	}
}
$daily_chart = [
	'labels' => array_values( $daily_labels ),
	'series' => [ [ 'label' => __( 'Transfers Created', 'advanced-ffl-checkout' ), 'color' => '#3b82f6', 'data' => array_values( $daily_counts ) ] ],
];
?>

<div class="wrap wpistic-ffl-admin">

	<!-- Page header -->
	<div class="wpistic-ffl-page-header">
		<div class="wpistic-ffl-logo-wrap">
			<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 160 36" class="wpistic-ffl-wordmark" aria-label="Guns2ammo">
				<rect width="250" height="36" rx="6" fill="#ffedd6"/>
				<text x="10" y="24" font-family="system-ui,sans-serif" font-weight="700" font-size="24" fill="#111827">GUNS 2 AMMO</text>
			</svg>
		</div>
		<div class="wpistic-ffl-page-title-wrap">
			<h1 class="wpistic-ffl-page-title"><?php esc_html_e( 'FFL Dashboard', 'advanced-ffl-checkout' ); ?></h1>
			<span class="wpistic-ffl-version">v<?php echo esc_html( WPISTIC_FFL_VERSION ); ?></span>
		</div>
	</div>

	<?php if ( $active_dealers === 0 ) : ?>
	<!-- Setup notice when no dealers yet -->
	<div class="wpistic-ffl-setup-notice">
		<div class="wpistic-ffl-setup-icon">🚀</div>
		<div class="wpistic-ffl-setup-body">
			<h3><?php esc_html_e( 'Welcome! Let\'s get your FFL dealer database loaded.', 'advanced-ffl-checkout' ); ?></h3>
			<p><?php esc_html_e( 'No dealers in database yet. Complete these 2 steps to go live:', 'advanced-ffl-checkout' ); ?></p>
			<ol>
				<li><?php esc_html_e( 'Download the ATF FFL dealer list from:', 'advanced-ffl-checkout' ); ?>
					<a href="https://www.atf.gov/firearms/listing-federal-firearms-licensees" target="_blank" rel="noopener">
						atf.gov/firearms/listing-federal-firearms-licensees
					</a>
					<?php esc_html_e( '(free CSV, no account needed)', 'advanced-ffl-checkout' ); ?>
				</li>
				<li><?php esc_html_e( 'Upload the CSV file below — it imports instantly, no cron required.', 'advanced-ffl-checkout' ); ?></li>
			</ol>
		</div>
	</div>
	<?php endif; ?>

	<!-- KPI Cards -->
	<div class="wpistic-ffl-kpi-grid">
		<div class="wpistic-kpi-card">
			<div class="wpistic-kpi-icon wpistic-kpi-icon--purple">🏪</div>
			<div class="wpistic-kpi-body">
				<div class="wpistic-kpi-value wpistic-kpi-dealer-count" id="wpistic-ffl-dealer-count"><?php echo esc_html( number_format( $active_dealers ) ); ?></div>
				<div class="wpistic-kpi-label"><?php esc_html_e( 'Active FFL Dealers', 'advanced-ffl-checkout' ); ?></div>
			</div>
		</div>
		<div class="wpistic-kpi-card">
			<div class="wpistic-kpi-icon wpistic-kpi-icon--blue">📋</div>
			<div class="wpistic-kpi-body">
				<div class="wpistic-kpi-value"><?php echo esc_html( number_format( $active_transfers ) ); ?></div>
				<div class="wpistic-kpi-label"><?php esc_html_e( 'Active Transfers', 'advanced-ffl-checkout' ); ?></div>
			</div>
		</div>
		<div class="wpistic-kpi-card <?php echo $nics_delayed > 0 ? 'wpistic-kpi-card--warning' : ''; ?>">
			<div class="wpistic-kpi-icon wpistic-kpi-icon--orange">⏳</div>
			<div class="wpistic-kpi-body">
				<div class="wpistic-kpi-value"><?php echo esc_html( $nics_delayed ); ?></div>
				<div class="wpistic-kpi-label"><?php esc_html_e( 'NICS Delayed', 'advanced-ffl-checkout' ); ?></div>
			</div>
		</div>
		<div class="wpistic-kpi-card">
			<div class="wpistic-kpi-icon wpistic-kpi-icon--green">✅</div>
			<div class="wpistic-kpi-body">
				<div class="wpistic-kpi-value"><?php echo esc_html( $this_month ); ?></div>
				<div class="wpistic-kpi-label"><?php esc_html_e( 'Transfers This Month', 'advanced-ffl-checkout' ); ?></div>
			</div>
		</div>
	</div>

	<!-- Charts -->
	<div class="wpistic-ffl-cols" style="margin-top:24px;">
		<div class="wpistic-ffl-card wpistic-ffl-col-2">
			<div class="wpistic-ffl-card-header">
				<h2><?php esc_html_e( 'Transfers Created — Last 30 Days', 'advanced-ffl-checkout' ); ?></h2>
			</div>
			<div class="wpistic-ffl-card-body">
				<canvas id="wpistic-ffl-daily-chart" data-height="200"></canvas>
			</div>
		</div>
		<div class="wpistic-ffl-card">
			<div class="wpistic-ffl-card-header">
				<h2><?php esc_html_e( 'Transfers by Status', 'advanced-ffl-checkout' ); ?></h2>
			</div>
			<div class="wpistic-ffl-card-body">
				<?php if ( empty( $status_chart['data'] ) ) : ?>
					<p class="wpistic-ffl-empty"><?php esc_html_e( 'No transfers yet.', 'advanced-ffl-checkout' ); ?></p>
				<?php else : ?>
					<canvas id="wpistic-ffl-status-chart" data-height="200"></canvas>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<script>
	document.addEventListener('DOMContentLoaded', function () {
		if (!window.WpisticFFLCharts) { return; }
		window.WpisticFFLCharts.renderTimeSeries(
			document.getElementById('wpistic-ffl-daily-chart'),
			<?php echo wp_json_encode( $daily_chart ); ?>
		);
		var statusCanvas = document.getElementById('wpistic-ffl-status-chart');
		if (statusCanvas) {
			window.WpisticFFLCharts.renderBars( statusCanvas, <?php echo wp_json_encode( $status_chart ); ?> );
		}
	});
	</script>

	<div class="wpistic-ffl-cols">

		<!-- Recent transfers -->
		<div class="wpistic-ffl-card wpistic-ffl-col-2">
			<div class="wpistic-ffl-card-header">
				<h2><?php esc_html_e( 'Recent Transfers', 'advanced-ffl-checkout' ); ?></h2>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpistic-ffl-transfers' ) ); ?>" class="wpistic-ffl-link"><?php esc_html_e( 'View All →', 'advanced-ffl-checkout' ); ?></a>
			</div>
			<table class="wpistic-ffl-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Ref', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Dealer', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Status', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Date', 'advanced-ffl-checkout' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( $recent_transfers ) : ?>
					<?php foreach ( $recent_transfers as $t ) :
						$s = $status_labels[ $t->status ] ?? [ 'label' => $t->status, 'class' => 'status-new' ];
					?>
					<tr>
						<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=wpistic-ffl-transfers&action=view&id=' . $t->id ) ); ?>" class="wpistic-ffl-ref"><?php echo esc_html( $t->transfer_ref ); ?></a></td>
						<td><?php echo esc_html( $t->customer_name ); ?></td>
						<td><?php echo esc_html( $t->dealer_name ?: '—' ); ?></td>
						<td><span class="wpistic-ffl-status <?php echo esc_attr( $s['class'] ); ?>"><?php echo esc_html( $s['label'] ); ?></span></td>
						<td><?php echo esc_html( date_i18n( 'M j', strtotime( $t->created_at ) ) ); ?></td>
					</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr><td colspan="5" class="wpistic-ffl-empty"><?php esc_html_e( 'No transfers yet. Transfers appear here when customers complete checkout with an FFL product.', 'advanced-ffl-checkout' ); ?></td></tr>
				<?php endif; ?>
				</tbody>
			</table>
		</div>

		<!-- Right panel: CSV Import + DB Status -->
		<div>

			<!-- ── ATF CSV Import ── -->
			<div class="wpistic-ffl-card wpistic-ffl-mb">
				<div class="wpistic-ffl-card-header">
					<h2>📥 <?php esc_html_e( 'Import ATF Dealer Data', 'advanced-ffl-checkout' ); ?></h2>
				</div>
				<div class="wpistic-ffl-card-body">
					<p class="wpistic-ffl-import-desc">
						<?php esc_html_e( 'Download the monthly FFL list from', 'advanced-ffl-checkout' ); ?>
						<a href="https://www.atf.gov/firearms/listing-federal-firearms-licensees" target="_blank" rel="noopener">ATF.gov</a>
						<?php esc_html_e( 'and upload it here. The file is imported directly in your browser — no cron job, no server timeout.', 'advanced-ffl-checkout' ); ?>
					</p>

					<!-- Upload zone -->
					<div id="wpistic-ffl-upload-zone" class="wpistic-ffl-upload-zone">
						<div class="wpistic-ffl-upload-icon">📂</div>
						<p class="wpistic-ffl-upload-text"><?php esc_html_e( 'Drop ATF CSV here or click to browse', 'advanced-ffl-checkout' ); ?></p>
						<p class="wpistic-ffl-upload-hint"><?php esc_html_e( 'Accepts: .csv files from ATF.gov (e.g. 0326-ffl-list.csv)', 'advanced-ffl-checkout' ); ?></p>
						<input type="file" id="wpistic-ffl-csv-input" accept=".csv" style="display:none;">
						<button type="button" id="wpistic-ffl-upload-btn" class="button wpistic-ffl-action-btn">
							<?php esc_html_e( '📁 Choose CSV File', 'advanced-ffl-checkout' ); ?>
						</button>
					</div>

					<!-- Progress bar (hidden until import starts) -->
					<div class="wpistic-ffl-csv-progress" style="display:none;">
						<div class="wpistic-ffl-progress-bar-wrap">
							<div class="wpistic-ffl-csv-progress-bar" style="width:0%"></div>
						</div>
						<div class="wpistic-ffl-csv-progress-label">Starting…</div>
					</div>

					<!-- Success notice -->
					<div id="wpistic-ffl-import-done" class="wpistic-ffl-import-done" style="display:none;">
						<span>✅</span>
						<span><?php esc_html_e( 'Import complete! Dealers are now searchable at checkout.', 'advanced-ffl-checkout' ); ?></span>
					</div>
				</div>
			</div>

			<!-- ── Database Status ── -->
			<div class="wpistic-ffl-card wpistic-ffl-mb">
				<div class="wpistic-ffl-card-header">
					<h2>🗄️ <?php esc_html_e( 'Database Status', 'advanced-ffl-checkout' ); ?></h2>
				</div>
				<div class="wpistic-ffl-card-body">
					<div class="wpistic-ffl-db-status-grid">
						<div class="wpistic-ffl-db-stat">
							<div class="wpistic-ffl-db-stat-value" id="wpistic-ffl-dealer-count"><?php echo esc_html( number_format( $active_dealers ) ); ?></div>
							<div class="wpistic-ffl-db-stat-label"><?php esc_html_e( 'FFL Dealers', 'advanced-ffl-checkout' ); ?></div>
						</div>
						<div class="wpistic-ffl-db-stat">
							<?php $zip_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . \WpisticFFL\DB::table( 'zip_coords' ) ); ?>
							<div class="wpistic-ffl-db-stat-value" id="wpistic-ffl-zip-count"><?php echo esc_html( number_format( $zip_count ) ); ?></div>
							<div class="wpistic-ffl-db-stat-label"><?php esc_html_e( 'ZIP Coords Cached', 'advanced-ffl-checkout' ); ?></div>
						</div>
						<div class="wpistic-ffl-db-stat">
							<div class="wpistic-ffl-db-stat-value"><?php echo esc_html( number_format( $total_transfers ) ); ?></div>
							<div class="wpistic-ffl-db-stat-label"><?php esc_html_e( 'Total Transfers', 'advanced-ffl-checkout' ); ?></div>
						</div>
					</div>
					<p class="wpistic-ffl-db-note">
						💡 <?php esc_html_e( 'ZIP coordinates are looked up automatically (free API) when customers search at checkout. No pre-import needed.', 'advanced-ffl-checkout' ); ?>
					</p>
				</div>
			</div>

			<!-- ── FFL Widget Setup Guide ── -->
			<div class="wpistic-ffl-card">
				<div class="wpistic-ffl-card-header">
					<h2>🛒 <?php esc_html_e( 'Enable FFL Widget at Checkout', 'advanced-ffl-checkout' ); ?></h2>
				</div>
				<div class="wpistic-ffl-card-body">
					<ol class="wpistic-ffl-guide-steps">
						<li>
							<strong><?php esc_html_e( 'Mark a product as FFL Required:', 'advanced-ffl-checkout' ); ?></strong><br>
							<?php esc_html_e( 'Go to', 'advanced-ffl-checkout' ); ?>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=product' ) ); ?>"><?php esc_html_e( 'Products', 'advanced-ffl-checkout' ); ?></a>
							→ <?php esc_html_e( 'Edit product → General tab → check "FFL Transfer Required" → Save.', 'advanced-ffl-checkout' ); ?>
						</li>
						<li>
							<strong><?php esc_html_e( 'Test checkout:', 'advanced-ffl-checkout' ); ?></strong><br>
							<?php esc_html_e( 'Add the flagged product to cart → go to Checkout → the FFL dealer selector appears below the billing form.', 'advanced-ffl-checkout' ); ?>
						</li>
					</ol>
					<?php if ( $active_dealers === 0 ) : ?>
					<div class="wpistic-ffl-warning-box">
						⚠️ <?php esc_html_e( 'Import dealers first — the widget shows "No dealers found" until data is loaded.', 'advanced-ffl-checkout' ); ?>
					</div>
					<?php else : ?>
					<div class="wpistic-ffl-success-box">
						✅ <?php echo esc_html( sprintf( __( '%s dealers ready. The FFL widget will work at checkout.', 'advanced-ffl-checkout' ), number_format( $active_dealers ) ) ); ?>
					</div>
					<?php endif; ?>
				</div>
			</div>

		</div><!-- end right col -->

	</div><!-- .wpistic-ffl-cols -->

</div><!-- .wrap -->
