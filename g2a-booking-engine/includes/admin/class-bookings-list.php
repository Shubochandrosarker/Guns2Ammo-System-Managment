<?php
/**
 * Modern admin bookings list — replaces Phase 1 placeholder.
 *
 * @package G2AB
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class G2AB_Admin_Bookings_List {

	private static $instance = null;
	const PAGE_SLUG = 'g2ab-bookings-list';

	public static function instance() {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'override_menu' ), 11 );
		add_action( 'admin_post_g2ab_change_status', array( $this, 'handle_status_change' ) );
		add_action( 'admin_post_g2ab_delete_booking', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_g2ab_export_bookings_csv', array( $this, 'handle_export_csv' ) );
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
		add_submenu_page( 'g2ab-bookings', __( 'Bookings', 'g2a-booking' ), __( 'Bookings', 'g2a-booking' ), 'manage_g2ab_bookings', self::PAGE_SLUG, array( $this, 'render' ) );
		if ( $idx >= 0 && isset( $submenu['g2ab-bookings'] ) ) {
			$new = array_pop( $submenu['g2ab-bookings'] );
			array_splice( $submenu['g2ab-bookings'], $idx, 0, array( $new ) );
		}
	}

	public function render() {
		if ( ! current_user_can( 'manage_g2ab_bookings' ) ) wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
		$view = isset( $_GET['view'] ) && 'detail' === $_GET['view'] ? 'detail' : 'list';
		if ( 'detail' === $view && isset( $_GET['booking_id'] ) ) { $this->render_detail( (int) $_GET['booking_id'] ); return; }
		$this->render_list();
	}

	private function render_list() {
		global $wpdb;
		$bt = $wpdb->prefix . 'g2ab_bookings';
		$rt = $wpdb->prefix . 'g2ab_resources';
		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$per_page = 20;
		$offset = ( $paged - 1 ) * $per_page;
		$where = ' WHERE 1=1 '; $args = array();
		if ( $status ) { $where .= ' AND b.status = %s '; $args[] = $status; }
		if ( $search ) { $where .= ' AND (b.customer_name LIKE %s OR b.customer_email LIKE %s OR b.uuid LIKE %s) '; $like = '%' . $wpdb->esc_like( $search ) . '%'; $args[] = $like; $args[] = $like; $args[] = $like; }
		$sql = "SELECT b.*, r.name AS resource_name FROM {$bt} b LEFT JOIN {$rt} r ON r.id = b.resource_id {$where} ORDER BY b.start_at DESC LIMIT %d OFFSET %d";
		$args2 = $args; $args2[] = $per_page; $args2[] = $offset;
		$rows = $args2 ? $wpdb->get_results( $wpdb->prepare( $sql, $args2 ) ) : $wpdb->get_results( $sql );
		$count_sql = "SELECT COUNT(*) FROM {$bt} b {$where}";
		$total = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) : $wpdb->get_var( $count_sql ) );
		$pages = max( 1, (int) ceil( $total / $per_page ) );
		$status_counts = $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM {$bt} GROUP BY status" );
		$counts = array( 'all' => 0, 'pending' => 0, 'confirmed' => 0, 'paid' => 0, 'cancelled' => 0, 'completed' => 0, 'no_show' => 0 );
		foreach ( $status_counts as $sc ) { $counts[ $sc->status ] = (int) $sc->c; $counts['all'] += (int) $sc->c; }
		$this->print_styles();
		?>
		<div class="wrap g2ab-admin g2ab-mod">
			<div class="g2ab-mod__header">
				<div>
					<h1><span class="g2ab-mod__stencil"><?php esc_html_e( 'BOOKINGS', 'g2a-booking' ); ?></span></h1>
					<p class="g2ab-mod__sub"><?php esc_html_e( 'Mission roster · live booking intel', 'g2a-booking' ); ?></p>
				</div>
				<div class="g2ab-mod__stats">
					<div class="g2ab-mod__stat"><span class="g2ab-mod__stat-num"><?php echo (int) $counts['all']; ?></span><span class="g2ab-mod__stat-lbl">TOTAL</span></div>
					<div class="g2ab-mod__stat g2ab-mod__stat--warn"><span class="g2ab-mod__stat-num"><?php echo (int) $counts['pending']; ?></span><span class="g2ab-mod__stat-lbl">PENDING</span></div>
					<div class="g2ab-mod__stat g2ab-mod__stat--ok"><span class="g2ab-mod__stat-num"><?php echo (int) $counts['confirmed'] + (int) $counts['paid']; ?></span><span class="g2ab-mod__stat-lbl">CONFIRMED</span></div>
					<div class="g2ab-mod__stat"><span class="g2ab-mod__stat-num"><?php echo (int) $counts['completed']; ?></span><span class="g2ab-mod__stat-lbl">COMPLETED</span></div>
					<div class="g2ab-mod__stat g2ab-mod__stat--bad"><span class="g2ab-mod__stat-num"><?php echo (int) $counts['cancelled'] + (int) $counts['no_show']; ?></span><span class="g2ab-mod__stat-lbl">LOST</span></div>
				</div>
			</div>
			<form method="get" class="g2ab-mod__filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<select name="status">
					<option value=""><?php esc_html_e( 'All Statuses', 'g2a-booking' ); ?></option>
					<?php foreach ( array( 'pending', 'reserved', 'confirmed', 'paid', 'completed', 'cancelled', 'no_show', 'refunded' ) as $st ) : ?>
						<option value="<?php echo esc_attr( $st ); ?>" <?php selected( $status, $st ); ?>><?php echo esc_html( strtoupper( $st ) ); ?> (<?php echo (int) ( $counts[ $st ] ?? 0 ); ?>)</option>
					<?php endforeach; ?>
				</select>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search name, email, or confirmation', 'g2a-booking' ); ?>" />
				<button class="button button-primary"><?php esc_html_e( 'FILTER', 'g2a-booking' ); ?></button>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array(
					'action' => 'g2ab_export_bookings_csv',
					'status' => $status,
					's'      => $search,
				), admin_url( 'admin-post.php' ) ), 'g2ab_export_bookings_csv' ) ); ?>" title="<?php esc_attr_e( 'Download the filtered booking list as a spreadsheet (.csv)', 'g2a-booking' ); ?>">
					<?php esc_html_e( 'EXPORT CSV', 'g2a-booking' ); ?>
				</a>
			</form>
			<?php if ( empty( $rows ) ) : ?>
				<div class="g2ab-mod__empty">
					<div class="g2ab-mod__empty-icon">⊕</div>
					<h2><?php esc_html_e( 'No bookings on the roster yet.', 'g2a-booking' ); ?></h2>
					<p><?php esc_html_e( 'Place [g2a_lane_booking] on a page to start taking reservations.', 'g2a-booking' ); ?></p>
				</div>
			<?php else : ?>
				<div class="g2ab-mod__cards">
					<?php foreach ( $rows as $r ) : ?>
						<article class="g2ab-mod__card g2ab-mod__card--<?php echo esc_attr( $r->status ); ?>">
							<header class="g2ab-mod__card-head">
								<span class="g2ab-mod__card-id">#<?php echo (int) $r->id; ?></span>
								<span class="g2ab-mod__badge g2ab-mod__badge--<?php echo esc_attr( $r->status ); ?>"><?php echo esc_html( strtoupper( str_replace( '_', ' ', $r->status ) ) ); ?></span>
							</header>
							<div class="g2ab-mod__card-body">
								<div class="g2ab-mod__card-name"><?php echo esc_html( $r->customer_name ); ?></div>
								<div class="g2ab-mod__card-line"><span>📧</span> <a href="mailto:<?php echo esc_attr( $r->customer_email ); ?>"><?php echo esc_html( $r->customer_email ); ?></a></div>
								<?php if ( $r->customer_phone ) : ?><div class="g2ab-mod__card-line"><span>📞</span> <a href="tel:<?php echo esc_attr( $r->customer_phone ); ?>"><?php echo esc_html( $r->customer_phone ); ?></a></div><?php endif; ?>
								<div class="g2ab-mod__card-line"><span>🎯</span> <?php echo esc_html( $r->resource_name ?: '—' ); ?></div>
								<div class="g2ab-mod__card-line"><span>🕒</span> <?php echo esc_html( mysql2date( 'D M j · g:i A', $r->start_at ) ); ?></div>
								<div class="g2ab-mod__card-line"><span>👥</span> <?php echo (int) $r->party_size; ?> shooter<?php echo $r->party_size > 1 ? 's' : ''; ?></div>
							</div>
							<footer class="g2ab-mod__card-foot">
								<div class="g2ab-mod__card-amount">$<?php echo esc_html( number_format( (float) $r->total_amount, 2 ) ); ?></div>
								<a class="g2ab-mod__btn" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'view' => 'detail', 'booking_id' => $r->id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'INTEL', 'g2a-booking' ); ?> →</a>
							</footer>
						</article>
					<?php endforeach; ?>
				</div>
				<?php if ( $pages > 1 ) : ?>
					<nav class="g2ab-mod__pages">
						<?php for ( $p = 1; $p <= $pages; $p++ ) : ?>
							<a class="<?php echo $p === $paged ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'paged', $p ) ); ?>"><?php echo (int) $p; ?></a>
						<?php endfor; ?>
					</nav>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_detail( $id ) {
		global $wpdb;
		$bt = $wpdb->prefix . 'g2ab_bookings';
		$rt = $wpdb->prefix . 'g2ab_resources';
		$btt = $wpdb->prefix . 'g2ab_booking_types';
		$lt = $wpdb->prefix . 'g2ab_logs';
		$b = $wpdb->get_row( $wpdb->prepare( "SELECT b.*, r.name AS resource_name, t.name AS type_name FROM {$bt} b LEFT JOIN {$rt} r ON r.id = b.resource_id LEFT JOIN {$btt} t ON t.id = b.booking_type_id WHERE b.id = %d", $id ) );
		if ( ! $b ) { echo '<div class="wrap"><h1>Booking not found</h1></div>'; return; }
		$logs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$lt} WHERE booking_id = %d ORDER BY created_at DESC LIMIT 50", $id ) );
		$form_data = json_decode( (string) $b->form_data, true ) ?: array();
		$msg = isset( $_GET['updated'] ) && '1' === $_GET['updated'] ? '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Booking updated.', 'g2a-booking' ) . '</p></div>' : '';
		$this->print_styles();
		?>
		<div class="wrap g2ab-admin g2ab-mod">
			<div class="g2ab-mod__detail-back"><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">← <?php esc_html_e( 'Back to roster', 'g2a-booking' ); ?></a></div>
			<?php echo $msg; ?>
			<div class="g2ab-mod__detail">
				<header class="g2ab-mod__detail-head">
					<div>
						<div class="g2ab-mod__detail-kicker"><?php esc_html_e( 'Booking Detail', 'g2a-booking' ); ?></div>
						<h1 class="g2ab-mod__detail-title"><?php echo esc_html( $b->customer_name ?: __( 'Guest booking', 'g2a-booking' ) ); ?></h1>
						<div class="g2ab-mod__detail-uuid"><?php esc_html_e( 'Confirmation:', 'g2a-booking' ); ?> <code><?php echo esc_html( $b->uuid ); ?></code></div>
					</div>
					<span class="g2ab-mod__badge g2ab-mod__badge--<?php echo esc_attr( $b->status ); ?> g2ab-mod__badge--xl"><?php echo esc_html( strtoupper( str_replace( '_', ' ', $b->status ) ) ); ?></span>
				</header>
				<div class="g2ab-mod__detail-grid">
					<div class="g2ab-mod__panel">
						<h3><?php esc_html_e( 'Customer', 'g2a-booking' ); ?></h3>
						<dl>
							<dt>Name</dt><dd><?php echo esc_html( $b->customer_name ); ?></dd>
							<dt>Email</dt><dd><a href="mailto:<?php echo esc_attr( $b->customer_email ); ?>"><?php echo esc_html( $b->customer_email ); ?></a></dd>
							<?php if ( $b->customer_phone ) : ?><dt>Phone</dt><dd><a href="tel:<?php echo esc_attr( $b->customer_phone ); ?>"><?php echo esc_html( $b->customer_phone ); ?></a></dd><?php endif; ?>
							<dt>Account</dt><dd><?php echo $b->user_id ? esc_html( get_user_by( 'id', $b->user_id )->user_login ?? '#' . $b->user_id ) : 'Guest'; ?></dd>
						</dl>
					</div>
					<div class="g2ab-mod__panel">
						<h3><?php esc_html_e( 'Booking', 'g2a-booking' ); ?></h3>
						<dl>
							<dt>Type</dt><dd><?php echo esc_html( $b->type_name ?: '—' ); ?></dd>
							<dt>Resource</dt><dd><?php echo esc_html( $b->resource_name ?: '—' ); ?></dd>
							<dt>Start</dt><dd><?php echo esc_html( mysql2date( 'D M j Y · g:i A', $b->start_at ) ); ?></dd>
							<dt>End</dt><dd><?php echo esc_html( mysql2date( 'D M j Y · g:i A', $b->end_at ) ); ?></dd>
							<dt>Duration</dt><dd><?php echo (int) $b->duration_min; ?> min</dd>
							<dt>Party Size</dt><dd><?php echo (int) $b->party_size; ?></dd>
							<dt>Waiver</dt><dd><?php echo $b->waiver_signed ? 'Signed' : 'Missing'; ?></dd>
						</dl>
					</div>
					<div class="g2ab-mod__panel">
						<h3><?php esc_html_e( 'Payment', 'g2a-booking' ); ?></h3>
						<dl>
							<dt>Total</dt><dd>$<?php echo esc_html( number_format( (float) $b->total_amount, 2 ) ); ?></dd>
							<dt>Paid</dt><dd>$<?php echo esc_html( number_format( (float) $b->paid_amount, 2 ) ); ?></dd>
							<dt>Mode</dt><dd><?php echo esc_html( $b->payment_mode ); ?></dd>
							<dt>Currency</dt><dd><?php echo esc_html( $b->currency ); ?></dd>
							<dt>Source</dt><dd><?php echo esc_html( $b->source ); ?></dd>
							<dt>Created</dt><dd><?php echo esc_html( mysql2date( 'M j · g:i A', $b->created_at ) ); ?></dd>
						</dl>
					</div>
				</div>
				<?php if ( $form_data ) : ?>
				<div class="g2ab-mod__panel">
					<h3><?php esc_html_e( 'Form Submission', 'g2a-booking' ); ?></h3>
					<dl class="g2ab-mod__formdata">
						<?php foreach ( $form_data as $k => $v ) : ?>
							<dt><?php echo esc_html( ucwords( str_replace( '_', ' ', $k ) ) ); ?></dt>
							<dd><?php echo esc_html( is_scalar( $v ) ? (string) $v : wp_json_encode( $v ) ); ?></dd>
						<?php endforeach; ?>
					</dl>
				</div>
				<?php endif; ?>
				<div class="g2ab-mod__panel">
					<h3><?php esc_html_e( 'Status', 'g2a-booking' ); ?></h3>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="g2ab-mod__statusform">
						<?php wp_nonce_field( 'g2ab_change_status_' . $b->id, '_g2ab_nonce' ); ?>
						<input type="hidden" name="action" value="g2ab_change_status" />
						<input type="hidden" name="booking_id" value="<?php echo (int) $b->id; ?>" />
						<select name="status">
							<?php foreach ( array( 'pending','reserved','confirmed','paid','completed','cancelled','no_show','refunded' ) as $s ) : ?>
								<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $b->status, $s ); ?>><?php echo esc_html( strtoupper( str_replace( '_', ' ', $s ) ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<button class="button button-primary">UPDATE STATUS</button>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;" onsubmit="return confirm('<?php echo esc_js( __( 'Permanently delete this booking?', 'g2a-booking' ) ); ?>');">
						<?php wp_nonce_field( 'g2ab_delete_booking_' . $b->id, '_g2ab_nonce' ); ?>
						<input type="hidden" name="action" value="g2ab_delete_booking" />
						<input type="hidden" name="booking_id" value="<?php echo (int) $b->id; ?>" />
						<button class="button" style="background:#C62828;color:#fff;border-color:#C62828;">DELETE BOOKING</button>
					</form>
				</div>
				<div class="g2ab-mod__panel">
					<h3><?php esc_html_e( 'Audit Log', 'g2a-booking' ); ?></h3>
					<?php if ( empty( $logs ) ) : ?><p><em>No log entries.</em></p><?php else : ?>
						<ul class="g2ab-mod__log">
							<?php foreach ( $logs as $log ) : ?>
								<li>
									<span class="g2ab-mod__log-time"><?php echo esc_html( mysql2date( 'M j · H:i:s', $log->created_at ) ); ?></span>
									<span class="g2ab-mod__log-event g2ab-mod__log-event--<?php echo esc_attr( $log->severity ); ?>"><?php echo esc_html( strtoupper( $log->event_type ) ); ?></span>
									<span class="g2ab-mod__log-msg"><?php echo esc_html( $log->message ); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	public function handle_status_change() {
		if ( ! current_user_can( 'manage_g2ab_bookings' ) ) wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
		$id = (int) ( $_POST['booking_id'] ?? 0 );
		check_admin_referer( 'g2ab_change_status_' . $id, '_g2ab_nonce' );
		$status = sanitize_key( wp_unslash( $_POST['status'] ?? '' ) );
		$allowed = array( 'pending','reserved','confirmed','paid','completed','cancelled','no_show','refunded' );
		if ( ! in_array( $status, $allowed, true ) ) wp_die( esc_html__( 'Invalid status.', 'g2a-booking' ) );
		global $wpdb;
		$prev = (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}g2ab_bookings WHERE id = %d", $id ) );
		$wpdb->update( $wpdb->prefix . 'g2ab_bookings', array( 'status' => $status, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ), array( '%s', '%s' ), array( '%d' ) );
		$wpdb->insert( $wpdb->prefix . 'g2ab_logs', array(
			'booking_id' => $id, 'user_id' => get_current_user_id(), 'event_type' => 'status_changed', 'severity' => 'info',
			'message' => sprintf( 'Status set to %s by admin', $status ), 'context' => wp_json_encode( array( 'previous_status' => $prev ) ), 'created_at' => current_time( 'mysql' ),
		) );
		do_action( 'g2ab_booking_status_changed', $id, $status, $prev );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'view' => 'detail', 'booking_id' => $id, 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_delete() {
		if ( ! current_user_can( 'delete_g2ab_bookings' ) ) wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
		$id = (int) ( $_POST['booking_id'] ?? 0 );
		check_admin_referer( 'g2ab_delete_booking_' . $id, '_g2ab_nonce' );
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'g2ab_bookings', array( 'id' => $id ), array( '%d' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Stream the bookings list as a CSV. Honors the same status + search filters
	 * as the on-screen list so what you see is what you export.
	 */
	public function handle_export_csv() {
		if ( ! current_user_can( 'manage_g2ab_bookings' ) ) {
			wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
		}
		check_admin_referer( 'g2ab_export_bookings_csv' );

		global $wpdb;
		$bt  = $wpdb->prefix . 'g2ab_bookings';
		$rt  = $wpdb->prefix . 'g2ab_resources';
		$btt = $wpdb->prefix . 'g2ab_booking_types';

		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		$where = ' WHERE 1=1 ';
		$args  = array();
		if ( $status ) {
			$where  .= ' AND b.status = %s ';
			$args[]  = $status;
		}
		if ( $search ) {
			$where  .= ' AND (b.customer_name LIKE %s OR b.customer_email LIKE %s OR b.uuid LIKE %s) ';
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = $like;
		}
		$sql  = "SELECT b.id, b.uuid, b.customer_name, b.customer_email, b.customer_phone,
		                b.start_at, b.end_at, b.duration_min, b.party_size, b.status,
		                b.payment_mode, b.total_amount, b.paid_amount, b.currency,
		                b.created_at, r.name AS resource_name, t.name AS booking_type
		         FROM {$bt} b
		         LEFT JOIN {$rt} r ON r.id = b.resource_id
		         LEFT JOIN {$btt} t ON t.id = b.booking_type_id
		         {$where}
		         ORDER BY b.start_at DESC";
		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );

		$filename = 'g2a-bookings-' . wp_date( 'Y-m-d-His' ) . '.csv';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' );
		// Excel-friendly UTF-8 BOM.
		fwrite( $out, "\xEF\xBB\xBF" );

		$headers = array(
			'ID', 'Confirmation', 'Customer Name', 'Customer Email', 'Customer Phone',
			'Start', 'End', 'Duration (min)', 'Party Size', 'Status',
			'Payment Mode', 'Total', 'Paid', 'Currency', 'Created', 'Resource', 'Booking Type',
		);
		fputcsv( $out, $headers );

		if ( empty( $rows ) ) {
			fputcsv( $out, array( 'No bookings match the current filter' ) );
		} else {
			foreach ( $rows as $r ) {
				$line = array(
					$r['id'], $r['uuid'], $r['customer_name'], $r['customer_email'],
					$r['customer_phone'], $r['start_at'], $r['end_at'], $r['duration_min'],
					$r['party_size'], $r['status'], $r['payment_mode'], $r['total_amount'],
					$r['paid_amount'], $r['currency'], $r['created_at'], $r['resource_name'],
					$r['booking_type'],
				);
				fputcsv( $out, array_map( array( __CLASS__, 'csv_escape' ), $line ) );
			}
		}

		fclose( $out );
		exit;
	}

	/**
	 * Defuse CSV-injection. Cells starting with =, +, -, @, tab or CR are
	 * prefixed with a leading apostrophe so spreadsheet apps don't treat
	 * them as formulas. https://owasp.org/www-community/attacks/CSV_Injection
	 */
	public static function csv_escape( $val ) {
		$s = (string) $val;
		if ( '' === $s ) return $s;
		$first = $s[0];
		if ( '=' === $first || '+' === $first || '-' === $first || '@' === $first || "\t" === $first || "\r" === $first ) {
			return "'" . $s;
		}
		return $s;
	}

	private function print_styles() {
		static $printed = false; if ( $printed ) return; $printed = true;
		echo '<style>
.g2ab-mod{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}
.g2ab-mod__header{display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:20px;background:linear-gradient(135deg,#0F1115 0%,#1A1F26 100%);color:#E8E8E8;padding:24px 28px;margin:20px 0 0;border-left:4px solid #D2691E;position:relative;overflow:hidden;}
.g2ab-mod__header::before{content:"";position:absolute;inset:0;background-image:repeating-linear-gradient(45deg,rgba(74,93,58,.05) 0,rgba(74,93,58,.05) 2px,transparent 2px,transparent 8px);pointer-events:none;}
.g2ab-mod__stencil{font-family:"Rajdhani","Oswald",Impact,sans-serif;font-size:32px;font-weight:700;letter-spacing:.12em;color:#fff;text-shadow:2px 2px 0 #4A5D3A;}
.g2ab-mod__sub{margin:4px 0 0;color:#8A95A5;font-size:13px;text-transform:uppercase;letter-spacing:.08em;}
.g2ab-mod__stats{display:flex;gap:16px;flex-wrap:wrap;}
.g2ab-mod__stat{text-align:center;background:rgba(255,255,255,.06);padding:10px 16px;min-width:78px;border-bottom:3px solid #4A5D3A;}
.g2ab-mod__stat--warn{border-color:#F9A825;}
.g2ab-mod__stat--ok{border-color:#4CAF50;}
.g2ab-mod__stat--bad{border-color:#C62828;}
.g2ab-mod__stat-num{display:block;font-family:"Rajdhani",sans-serif;font-size:28px;font-weight:700;color:#fff;line-height:1;}
.g2ab-mod__stat-lbl{display:block;font-size:10px;color:#8A95A5;letter-spacing:.1em;margin-top:4px;}
.g2ab-mod__filters{background:#fff;padding:16px 20px;border:1px solid #d0d4d9;border-top:none;display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
.g2ab-mod__filters select,.g2ab-mod__filters input{padding:8px 12px;border:1px solid #ccd0d4;border-radius:3px;font-size:13px;}
.g2ab-mod__filters input[type="search"]{flex:1;min-width:200px;}
.g2ab-mod__cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;margin-top:18px;}
.g2ab-mod__card{background:#fff;border:1px solid #d0d4d9;border-left:4px solid #4A5D3A;display:flex;flex-direction:column;transition:all .15s;}
.g2ab-mod__card:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,0,0,.08);}
.g2ab-mod__card--pending{border-left-color:#F9A825;}
.g2ab-mod__card--cancelled,.g2ab-mod__card--no_show,.g2ab-mod__card--refunded{border-left-color:#C62828;opacity:.85;}
.g2ab-mod__card--completed{border-left-color:#8A95A5;}
.g2ab-mod__card--confirmed,.g2ab-mod__card--paid{border-left-color:#4CAF50;}
.g2ab-mod__card-head{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid #f0f1f3;}
.g2ab-mod__card-id{font-family:"Rajdhani",sans-serif;font-size:14px;color:#8A95A5;font-weight:600;letter-spacing:.04em;}
.g2ab-mod__badge{display:inline-block;padding:3px 10px;font-size:10px;font-weight:700;letter-spacing:.08em;border-radius:2px;background:#0F1115;color:#fff;}
.g2ab-mod__badge--pending{background:#F9A825;}
.g2ab-mod__badge--reserved{background:#666;}
.g2ab-mod__badge--confirmed,.g2ab-mod__badge--paid{background:#4CAF50;}
.g2ab-mod__badge--cancelled,.g2ab-mod__badge--no_show,.g2ab-mod__badge--refunded{background:#C62828;}
.g2ab-mod__badge--completed{background:#0F1115;}
.g2ab-mod__badge--xl{font-size:13px;padding:6px 16px;}
.g2ab-mod__card-body{padding:14px 16px;flex:1;}
.g2ab-mod__card-name{font-size:17px;font-weight:700;margin-bottom:8px;color:#0F1115;}
.g2ab-mod__card-line{display:flex;gap:8px;font-size:13px;color:#3c434a;line-height:1.7;}
.g2ab-mod__card-line a{text-decoration:none;color:#0F1115;}
.g2ab-mod__card-foot{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;background:#f8f9fa;border-top:1px solid #f0f1f3;}
.g2ab-mod__card-amount{font-family:"Rajdhani",sans-serif;font-size:20px;font-weight:700;color:#D2691E;}
.g2ab-mod__btn{background:#0F1115;color:#fff;padding:6px 14px;font-size:11px;font-weight:700;letter-spacing:.08em;text-decoration:none;text-transform:uppercase;border-radius:2px;}
.g2ab-mod__btn:hover{background:#D2691E;color:#fff;}
.g2ab-mod__pages{margin-top:20px;display:flex;gap:6px;flex-wrap:wrap;}
.g2ab-mod__pages a{display:inline-block;padding:8px 14px;background:#fff;border:1px solid #d0d4d9;text-decoration:none;color:#0F1115;}
.g2ab-mod__pages a.is-active{background:#D2691E;color:#fff;border-color:#D2691E;}
.g2ab-mod__empty{background:#fff;padding:60px 20px;text-align:center;border:1px dashed #d0d4d9;}
.g2ab-mod__empty-icon{font-size:48px;color:#8A95A5;margin-bottom:12px;}
.g2ab-mod__detail{background:#fff;border:1px solid #d0d4d9;}
.g2ab-mod__detail-head{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:22px 28px;background:#fff;border-bottom:3px solid #D2691E;}.g2ab-mod__detail-kicker{font-size:11px;font-weight:700;letter-spacing:.16em;text-transform:uppercase;color:#8A95A5;margin-bottom:2px;}.g2ab-mod__detail-head h1.g2ab-mod__detail-title{margin:0;padding:0;font-size:26px;line-height:1.2;font-weight:700;color:#1d2327;}
.g2ab-mod__detail-uuid{font-size:11px;color:#8A95A5;letter-spacing:.08em;margin-top:6px;}
.g2ab-mod__detail-uuid code{background:rgba(255,255,255,.1);color:#D2691E;padding:2px 8px;}
.g2ab-mod__detail-back{margin:14px 0 8px;}
.g2ab-mod__detail-back a{color:#0F1115;text-decoration:none;font-size:13px;}
.g2ab-mod__detail-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:0;}
.g2ab-mod__panel{padding:20px 28px;border-right:1px solid #f0f1f3;border-bottom:1px solid #f0f1f3;}
.g2ab-mod__panel h3{font-family:"Rajdhani",sans-serif;font-size:13px;letter-spacing:.12em;color:#D2691E;margin:0 0 14px;font-weight:700;}
.g2ab-mod__panel dl{margin:0;display:grid;grid-template-columns:120px 1fr;gap:6px 16px;font-size:13px;}
.g2ab-mod__panel dt{color:#8A95A5;text-transform:uppercase;font-size:11px;letter-spacing:.06em;align-self:center;}
.g2ab-mod__panel dd{margin:0;color:#0F1115;font-weight:500;}
.g2ab-mod__formdata{grid-template-columns:160px 1fr;}
.g2ab-mod__statusform{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
.g2ab-mod__log{list-style:none;padding:0;margin:0;font-family:"SF Mono","Monaco","Menlo",monospace;font-size:12px;}
.g2ab-mod__log li{padding:6px 0;border-bottom:1px solid #f0f1f3;display:grid;grid-template-columns:140px 100px 1fr;gap:10px;align-items:center;}
.g2ab-mod__log-time{color:#8A95A5;}
.g2ab-mod__log-event{padding:2px 8px;background:#f0f1f3;color:#3c434a;font-size:10px;letter-spacing:.06em;text-align:center;}
.g2ab-mod__log-event--error{background:#C62828;color:#fff;}
.g2ab-mod__log-event--warning{background:#F9A825;color:#fff;}
</style>';
	}
}
