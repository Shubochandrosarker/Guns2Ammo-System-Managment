<?php
/**
 * Admin Transfers view.
 *
 * @package WpisticFFL
 */
defined( 'ABSPATH' ) || exit;

global $wpdb;

$t_table = \WpisticFFL\DB::table( 'transfers' );
$d_table = \WpisticFFL\DB::table( 'dealers' );

// Handle single transfer view
$action      = sanitize_text_field( $_GET['action'] ?? '' );
$transfer_id = absint( $_GET['id'] ?? 0 );

if ( 'view' === $action && $transfer_id ) {
	$transfer = $wpdb->get_row( $wpdb->prepare(
		"SELECT t.*, d.business_name AS dealer_name, d.license_number AS dealer_license,
		        d.premise_street AS dealer_street, d.premise_city AS dealer_city,
		        d.premise_state AS dealer_state, d.premise_zip AS dealer_zip, d.phone AS dealer_phone
		 FROM {$t_table} t LEFT JOIN {$d_table} d ON d.id = t.dealer_id WHERE t.id = %d",
		$transfer_id
	) );
	$events   = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM " . \WpisticFFL\DB::table( 'events' ) . " WHERE transfer_id = %d ORDER BY created_at ASC",
		$transfer_id
	) );

	include __DIR__ . '/transfer-detail.php';
	return;
}

// Filters
$per_page    = 25;
$current_page= max( 1, absint( $_GET['paged'] ?? 1 ) );
$offset      = ( $current_page - 1 ) * $per_page;
$status_filter= sanitize_text_field( $_GET['status'] ?? '' );
$search      = sanitize_text_field( $_GET['s'] ?? '' );

$where  = 'WHERE 1=1';
$values = [];
if ( $status_filter ) {
	$where   .= ' AND t.status = %s';
	$values[] = $status_filter;
}
if ( $search ) {
	$where   .= ' AND (t.transfer_ref LIKE %s OR t.customer_name LIKE %s OR t.customer_email LIKE %s)';
	$like     = '%' . $wpdb->esc_like( $search ) . '%';
	$values   = array_merge( $values, [ $like, $like, $like ] );
}

$total     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t_table} t {$where}", ...$values ) );
$values[]  = $per_page;
$values[]  = $offset;
$transfers = $wpdb->get_results( $wpdb->prepare(
	"SELECT t.*, d.business_name AS dealer_name FROM {$t_table} t LEFT JOIN {$d_table} d ON d.id = t.dealer_id {$where} ORDER BY t.created_at DESC LIMIT %d OFFSET %d",
	...$values
) );

// Keys MUST match TypeScript TransferStatus union in fflData.ts exactly.
$status_labels = [
	'pending_selection'  => [ 'label' => 'Awaiting Dealer',   'class' => 'status-new' ],
	'dealer_selected'    => [ 'label' => 'Dealer Selected',   'class' => 'status-new' ],
	'shipped_to_dealer'  => [ 'label' => 'Shipped to Dealer', 'class' => 'status-shipped' ],
	'received_by_dealer' => [ 'label' => 'At Dealer',         'class' => 'status-at-dealer' ],
	'background_check'   => [ 'label' => 'NICS Pending',      'class' => 'status-pending' ],
	'approved'           => [ 'label' => 'NICS Approved',     'class' => 'status-approved' ],
	'delayed'            => [ 'label' => 'NICS Delayed',      'class' => 'status-delayed' ],
	'denied'             => [ 'label' => 'NICS Denied',       'class' => 'status-denied' ],
	'transferred'        => [ 'label' => 'Transferred',       'class' => 'status-complete' ],
	'cancelled'          => [ 'label' => 'Cancelled',         'class' => 'status-cancelled' ],
];
?>

<div class="wrap wpistic-ffl-admin">
	<div class="wpistic-ffl-page-header">
		<h1 class="wpistic-ffl-page-title"><?php esc_html_e( 'G2A - FFL Transfers', 'advanced-ffl-checkout' ); ?></h1>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpistic-ffl-transfers&action=export' ) ); ?>" class="button wpistic-ffl-export-btn">⬇ <?php esc_html_e( 'Export CSV', 'advanced-ffl-checkout' ); ?></a>
	</div>

	<!-- Filter bar -->
	<form method="get" action="" class="wpistic-ffl-filter-form">
		<input type="hidden" name="page" value="wpistic-ffl-transfers">
		<div class="wpistic-ffl-filter-row">
			<input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search transfers…', 'advanced-ffl-checkout' ); ?>" class="wpistic-ffl-search-input">
			<select name="status" class="wpistic-ffl-select">
				<option value=""><?php esc_html_e( 'All Statuses', 'advanced-ffl-checkout' ); ?></option>
				<?php foreach ( $status_labels as $key => $info ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status_filter, $key ); ?>><?php echo esc_html( $info['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button wpistic-ffl-filter-btn"><?php esc_html_e( 'Filter', 'advanced-ffl-checkout' ); ?></button>
			<?php if ( $search || $status_filter ) : ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpistic-ffl-transfers' ) ); ?>" class="button"><?php esc_html_e( 'Clear', 'advanced-ffl-checkout' ); ?></a>
			<?php endif; ?>
		</div>
	</form>

	<div class="wpistic-ffl-card wpistic-ffl-mt">
		<div class="wpistic-ffl-table-meta">
			<?php printf( esc_html__( '%d transfers found', 'advanced-ffl-checkout' ), $total ); ?>
		</div>
		<table class="wpistic-ffl-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Transfer Ref', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Order', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Customer', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Item', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Dealer', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Status', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Created', 'advanced-ffl-checkout' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( $transfers ) : ?>
				<?php foreach ( $transfers as $t ) :
					$s = $status_labels[ $t->status ] ?? [ 'label' => $t->status, 'class' => 'status-new' ];
				?>
				<tr>
					<td><strong><?php echo esc_html( $t->transfer_ref ); ?></strong></td>
					<td>
						<?php if ( $t->order_id ) : ?>
						<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $t->order_id . '&action=edit' ) ); ?>">#<?php echo esc_html( $t->order_id ); ?></a>
						<?php else : ?>—<?php endif; ?>
					</td>
					<td>
						<div><?php echo esc_html( $t->customer_name ); ?></div>
						<small style="color:#9ca3af;"><?php echo esc_html( $t->customer_email ); ?></small>
					</td>
					<td>
						<div><?php echo esc_html( wp_trim_words( $t->item_description, 5 ) ); ?></div>
						<?php if ( $t->item_sku ) : ?><small style="color:#9ca3af;">SKU: <?php echo esc_html( $t->item_sku ); ?></small><?php endif; ?>
					</td>
					<td><?php echo esc_html( $t->dealer_name ?: '—' ); ?></td>
					<td><span class="wpistic-ffl-status <?php echo esc_attr( $s['class'] ); ?>"><?php echo esc_html( $s['label'] ); ?></span></td>
					<td><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $t->created_at ) ) ); ?></td>
					<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=wpistic-ffl-transfers&action=view&id=' . $t->id ) ); ?>" class="button button-small"><?php esc_html_e( 'View', 'advanced-ffl-checkout' ); ?></a></td>
				</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr><td colspan="8" class="wpistic-ffl-empty"><?php esc_html_e( 'No transfers found.', 'advanced-ffl-checkout' ); ?></td></tr>
			<?php endif; ?>
			</tbody>
		</table>

		<!-- Pagination -->
		<?php if ( $total > $per_page ) : ?>
		<div class="wpistic-ffl-pagination">
			<?php
			$total_pages = (int) ceil( $total / $per_page );
			echo paginate_links( [
				'base'      => add_query_arg( 'paged', '%#%' ),
				'format'    => '',
				'current'   => $current_page,
				'total'     => $total_pages,
				'prev_text' => '&laquo;',
				'next_text' => '&raquo;',
			] );
			?>
		</div>
		<?php endif; ?>
	</div>
</div>
