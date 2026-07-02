<?php
/**
 * Bookings roster — Amelia-style, organised by what was booked.
 *
 * One engine handles three very different things, so the roster is tabbed:
 * All · Lanes · Events · Classes. Each tab is the same clean light table with
 * live search, status filter, type chips, status badges and CSV export. The
 * detail view keeps the full booking intel + status control + audit log.
 *
 * @package G2AB
 * @since   1.9.9.1
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Admin_Bookings_List {

	private static $instance = null;
	const PAGE_SLUG = 'g2ab-bookings-list';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
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
				if ( $entry[2] === self::PAGE_SLUG ) {
					$idx = $i;
					break;
				}
			}
		}
		remove_submenu_page( 'g2ab-bookings', self::PAGE_SLUG );
		add_submenu_page( 'g2ab-bookings', __( 'Bookings', 'g2a-booking' ), __( 'Bookings', 'g2a-booking' ), 'manage_g2ab_bookings', self::PAGE_SLUG, array( $this, 'render' ) );
		if ( $idx >= 0 && isset( $submenu['g2ab-bookings'] ) ) {
			$new = array_pop( $submenu['g2ab-bookings'] );
			array_splice( $submenu['g2ab-bookings'], $idx, 0, array( $new ) );
		}
	}

	private function tabs() {
		return array(
			'all'   => __( 'All', 'g2a-booking' ),
			'lane'  => __( 'Lanes', 'g2a-booking' ),
			'event' => __( 'Events', 'g2a-booking' ),
			'class' => __( 'Classes', 'g2a-booking' ),
		);
	}

	public function render() {
		if ( ! current_user_can( 'manage_g2ab_bookings' ) ) {
			wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
		}
		$view = isset( $_GET['view'] ) && 'detail' === $_GET['view'] ? 'detail' : 'list';
		if ( 'detail' === $view && isset( $_GET['booking_id'] ) ) {
			$this->render_detail( (int) $_GET['booking_id'] );
			return;
		}
		$this->render_list();
	}

	private function render_list() {
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'all';
		if ( ! array_key_exists( $tab, $this->tabs() ) ) {
			$tab = 'all';
		}
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = max( 1, (int) ( $_GET['paged'] ?? 1 ) );

		$result = G2AB_Analytics::bookings_query( array(
			'tab'      => $tab,
			'status'   => $status,
			'search'   => $search,
			'page'     => $paged,
			'per_page' => 20,
		) );
		$rows   = $result['rows'];
		$counts = $result['counts'];
		$pages  = $result['pages'];

		$this->print_styles();
		?>
		<div class="wrap g2ab-bk">
			<header class="g2ab-bk__head">
				<div>
					<div class="g2ab-bk__eyebrow"><?php esc_html_e( 'RANGE ROSTER', 'g2a-booking' ); ?></div>
					<h1 class="g2ab-bk__title"><?php esc_html_e( 'Bookings', 'g2a-booking' ); ?></h1>
					<p class="g2ab-bk__sub"><?php esc_html_e( 'Every reservation across lanes, events and classes — in one place.', 'g2a-booking' ); ?></p>
				</div>
				<div class="g2ab-bk__head-actions">
					<a class="g2ab-bk__btn g2ab-bk__btn--ghost" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'g2ab_export_bookings_csv', 'tab' => $tab, 'status' => $status, 's' => $search ), admin_url( 'admin-post.php' ) ), 'g2ab_export_bookings_csv' ) ); ?>">
						<span aria-hidden="true">↓</span> <?php esc_html_e( 'Export CSV', 'g2a-booking' ); ?>
					</a>
					<a class="g2ab-bk__btn g2ab-bk__btn--primary" href="<?php echo esc_url( admin_url( 'admin.php?page=g2ab-manual-booking' ) ); ?>">
						<span aria-hidden="true">+</span> <?php esc_html_e( 'Book', 'g2a-booking' ); ?>
					</a>
				</div>
			</header>

			<nav class="g2ab-bk__tabs">
				<?php foreach ( $this->tabs() as $key => $label ) :
					$url = add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => $key ), admin_url( 'admin.php' ) );
					?>
					<a class="g2ab-bk__tab<?php echo $tab === $key ? ' is-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>">
						<?php echo esc_html( $label ); ?>
						<span class="g2ab-bk__tab-count"><?php echo (int) ( isset( $counts[ $key ] ) ? $counts[ $key ] : 0 ); ?></span>
					</a>
				<?php endforeach; ?>
			</nav>

			<form method="get" class="g2ab-bk__toolbar">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>" />
				<div class="g2ab-bk__search">
					<svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true"><path d="M9 3a6 6 0 104.47 10.03l3.75 3.75 1.06-1.06-3.75-3.75A6 6 0 009 3zm0 1.5a4.5 4.5 0 110 9 4.5 4.5 0 010-9z" fill="currentColor"/></svg>
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search bookings by name, email, phone…', 'g2a-booking' ); ?>" />
				</div>
				<select name="status" class="g2ab-bk__select" onchange="this.form.submit()">
					<option value=""><?php esc_html_e( 'All statuses', 'g2a-booking' ); ?></option>
					<?php foreach ( array( 'pending', 'reserved', 'confirmed', 'paid', 'completed', 'cancelled', 'no_show', 'refunded', 'expired' ) as $st ) : ?>
						<option value="<?php echo esc_attr( $st ); ?>" <?php selected( $status, $st ); ?>><?php echo esc_html( ucwords( str_replace( '_', ' ', $st ) ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<button class="g2ab-bk__btn g2ab-bk__btn--filter"><?php esc_html_e( 'Filter', 'g2a-booking' ); ?></button>
			</form>

			<?php if ( empty( $rows ) ) : ?>
				<div class="g2ab-bk__empty">
					<div class="g2ab-bk__empty-icon">◎</div>
					<h2><?php esc_html_e( 'No bookings in this view yet.', 'g2a-booking' ); ?></h2>
					<p><?php esc_html_e( 'Reservations will appear here as shooters book lanes, events and classes.', 'g2a-booking' ); ?></p>
				</div>
			<?php else : ?>
				<div class="g2ab-bk__table-wrap">
					<table class="g2ab-bk__table">
						<thead>
							<tr>
								<th class="g2ab-bk__c-id"><?php esc_html_e( 'ID', 'g2a-booking' ); ?></th>
								<th class="g2ab-bk__c-when"><?php esc_html_e( 'Date & time', 'g2a-booking' ); ?></th>
								<th><?php esc_html_e( 'Shooter', 'g2a-booking' ); ?></th>
								<th><?php esc_html_e( 'Booked', 'g2a-booking' ); ?></th>
								<th class="g2ab-bk__c-type"><?php esc_html_e( 'Type', 'g2a-booking' ); ?></th>
								<th class="g2ab-bk__c-status"><?php esc_html_e( 'Status', 'g2a-booking' ); ?></th>
								<th class="g2ab-bk__c-amt"><?php esc_html_e( 'Total', 'g2a-booking' ); ?></th>
								<th class="g2ab-bk__c-act"></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $r ) :
								$kind     = G2AB_Analytics::classify_booking( $r );
								$kind_meta = $this->kind_meta( $kind );
								$what     = $r['event_title'] ? $r['event_title'] : ( $r['resource_name'] ? $r['resource_name'] : ( $r['type_name'] ? $r['type_name'] : __( 'Lane / Range', 'g2a-booking' ) ) );
								$detail_url = add_query_arg( array( 'page' => self::PAGE_SLUG, 'view' => 'detail', 'booking_id' => $r['id'] ), admin_url( 'admin.php' ) );
								?>
								<tr onclick="window.location='<?php echo esc_url( $detail_url ); ?>'">
									<td class="g2ab-bk__c-id">#<?php echo (int) $r['id']; ?></td>
									<td class="g2ab-bk__c-when">
										<span class="g2ab-bk__date"><?php echo esc_html( G2AB_Events::format_local( $r['start_at'], 'M j, Y' ) ); ?></span>
										<span class="g2ab-bk__time"><?php echo esc_html( G2AB_Events::format_local( $r['start_at'], 'g:i A' ) ); ?></span>
									</td>
									<td>
										<div class="g2ab-bk__cust">
											<span class="g2ab-bk__avatar"><?php echo esc_html( $this->initials( $r['customer_name'] ) ); ?></span>
											<span class="g2ab-bk__cust-id">
												<span class="g2ab-bk__cust-name"><?php echo esc_html( $r['customer_name'] ? $r['customer_name'] : __( 'Guest', 'g2a-booking' ) ); ?></span>
												<span class="g2ab-bk__cust-email"><?php echo esc_html( $r['customer_email'] ); ?></span>
											</span>
										</div>
									</td>
									<td><span class="g2ab-bk__what"><?php echo esc_html( $what ); ?></span></td>
									<td class="g2ab-bk__c-type">
										<span class="g2ab-bk__chip" style="--c:<?php echo esc_attr( $kind_meta['color'] ); ?>;">
											<span class="g2ab-bk__chip-ico"><?php echo esc_html( $kind_meta['icon'] ); ?></span><?php echo esc_html( $kind_meta['label'] ); ?>
										</span>
									</td>
									<td class="g2ab-bk__c-status">
										<span class="g2ab-bk__badge g2ab-bk__st-<?php echo esc_attr( $r['status'] ); ?>"><?php echo esc_html( ucwords( str_replace( '_', ' ', $r['status'] ) ) ); ?></span>
									</td>
									<td class="g2ab-bk__c-amt">$<?php echo esc_html( number_format( (float) $r['total_amount'], 2 ) ); ?></td>
									<td class="g2ab-bk__c-act"><a class="g2ab-bk__view" href="<?php echo esc_url( $detail_url ); ?>" onclick="event.stopPropagation()">→</a></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<?php if ( $pages > 1 ) : ?>
					<nav class="g2ab-bk__pager">
						<?php
						$base = add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => $tab, 'status' => $status, 's' => $search ), admin_url( 'admin.php' ) );
						$prev = max( 1, $paged - 1 );
						$next = min( $pages, $paged + 1 );
						?>
						<a class="g2ab-bk__pg<?php echo $paged <= 1 ? ' is-disabled' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'paged', $prev, $base ) ); ?>">‹</a>
						<?php for ( $p = 1; $p <= $pages; $p++ ) :
							if ( $p > 3 && $p < $pages && abs( $p - $paged ) > 1 ) {
								if ( $p === 4 && $paged > 5 ) {
									echo '<span class="g2ab-bk__pg-gap">…</span>';
								} elseif ( $p > 4 && $p < $pages - 1 && abs( $p - $paged ) > 1 ) {
									continue;
								}
								if ( abs( $p - $paged ) > 1 && $p !== $pages ) {
									continue;
								}
							}
							?>
							<a class="g2ab-bk__pg<?php echo $p === $paged ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'paged', $p, $base ) ); ?>"><?php echo (int) $p; ?></a>
						<?php endfor; ?>
						<a class="g2ab-bk__pg<?php echo $paged >= $pages ? ' is-disabled' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'paged', $next, $base ) ); ?>">›</a>
					</nav>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function kind_meta( $kind ) {
		switch ( $kind ) {
			case 'event':
				return array( 'label' => __( 'Event', 'g2a-booking' ), 'color' => '#1565C0', 'icon' => '★' );
			case 'class':
				return array( 'label' => __( 'Class', 'g2a-booking' ), 'color' => '#6A4FB3', 'icon' => '🎓' );
			default:
				return array( 'label' => __( 'Lane', 'g2a-booking' ), 'color' => '#E8772E', 'icon' => '◎' );
		}
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

	private function render_detail( $id ) {
		global $wpdb;
		$bt  = $wpdb->prefix . 'g2ab_bookings';
		$rt  = $wpdb->prefix . 'g2ab_resources';
		$btt = $wpdb->prefix . 'g2ab_booking_types';
		$et  = $wpdb->prefix . 'g2ab_events';
		$lt  = $wpdb->prefix . 'g2ab_logs';
		$b   = $wpdb->get_row( $wpdb->prepare( "SELECT b.*, r.name AS resource_name, t.name AS type_name, e.title AS event_title, e.category AS event_category FROM {$bt} b LEFT JOIN {$rt} r ON r.id = b.resource_id LEFT JOIN {$btt} t ON t.id = b.booking_type_id LEFT JOIN {$et} e ON e.id = b.event_id WHERE b.id = %d", $id ) );
		if ( ! $b ) {
			echo '<div class="wrap g2ab-bk"><h1>' . esc_html__( 'Booking not found', 'g2a-booking' ) . '</h1></div>';
			return;
		}
		$logs      = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$lt} WHERE booking_id = %d ORDER BY created_at DESC LIMIT 50", $id ) );
		$form_data = json_decode( (string) $b->form_data, true ) ?: array();
		$kind      = ! $b->event_id ? 'lane' : ( 'ccw-class' === $b->event_category ? 'class' : 'event' );
		$kmeta     = $this->kind_meta( $kind );
		$msg       = isset( $_GET['updated'] ) && '1' === $_GET['updated'] ? '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Booking updated.', 'g2a-booking' ) . '</p></div>' : '';
		$this->print_styles();
		?>
		<div class="wrap g2ab-bk">
			<div class="g2ab-bk__back"><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">← <?php esc_html_e( 'Back to roster', 'g2a-booking' ); ?></a></div>
			<?php echo $msg; ?>

			<div class="g2ab-bk__detail">
				<header class="g2ab-bk__dhead">
					<div class="g2ab-bk__dhead-main">
						<span class="g2ab-bk__avatar g2ab-bk__avatar--lg"><?php echo esc_html( $this->initials( $b->customer_name ) ); ?></span>
						<div>
							<h1 class="g2ab-bk__dname"><?php echo esc_html( $b->customer_name ? $b->customer_name : __( 'Guest', 'g2a-booking' ) ); ?></h1>
							<div class="g2ab-bk__dmeta">
								<span class="g2ab-bk__chip g2ab-bk__chip--light" style="--c:<?php echo esc_attr( $kmeta['color'] ); ?>;"><?php echo esc_html( $kmeta['icon'] . ' ' . $kmeta['label'] ); ?></span>
								<span class="g2ab-bk__badge g2ab-bk__st-<?php echo esc_attr( $b->status ); ?>"><?php echo esc_html( ucwords( str_replace( '_', ' ', $b->status ) ) ); ?></span>
								<code class="g2ab-bk__uuid"><?php echo esc_html( $b->uuid ); ?></code>
							</div>
						</div>
					</div>
					<div class="g2ab-bk__damount">
						<span class="g2ab-bk__damount-num">$<?php echo esc_html( number_format( (float) $b->total_amount, 2 ) ); ?></span>
						<span class="g2ab-bk__damount-lbl"><?php echo esc_html( sprintf( __( '$%s paid', 'g2a-booking' ), number_format( (float) $b->paid_amount, 2 ) ) ); ?></span>
					</div>
				</header>

				<div class="g2ab-bk__dgrid">
					<section class="g2ab-bk__panel">
						<h3><?php esc_html_e( 'Shooter', 'g2a-booking' ); ?></h3>
						<dl>
							<dt><?php esc_html_e( 'Name', 'g2a-booking' ); ?></dt><dd><?php echo esc_html( $b->customer_name ); ?></dd>
							<dt><?php esc_html_e( 'Email', 'g2a-booking' ); ?></dt><dd><a href="mailto:<?php echo esc_attr( $b->customer_email ); ?>"><?php echo esc_html( $b->customer_email ); ?></a></dd>
							<?php if ( $b->customer_phone ) : ?><dt><?php esc_html_e( 'Phone', 'g2a-booking' ); ?></dt><dd><a href="tel:<?php echo esc_attr( $b->customer_phone ); ?>"><?php echo esc_html( $b->customer_phone ); ?></a></dd><?php endif; ?>
							<dt><?php esc_html_e( 'Account', 'g2a-booking' ); ?></dt><dd><?php echo $b->user_id ? esc_html( get_user_by( 'id', $b->user_id )->user_login ?? '#' . $b->user_id ) : esc_html__( 'Guest', 'g2a-booking' ); ?></dd>
						</dl>
					</section>
					<section class="g2ab-bk__panel">
						<h3><?php esc_html_e( 'Reservation', 'g2a-booking' ); ?></h3>
						<dl>
							<?php if ( $b->event_title ) : ?><dt><?php esc_html_e( 'Event', 'g2a-booking' ); ?></dt><dd><?php echo esc_html( $b->event_title ); ?></dd><?php endif; ?>
							<dt><?php esc_html_e( 'Type', 'g2a-booking' ); ?></dt><dd><?php echo esc_html( $b->type_name ?: $kmeta['label'] ); ?></dd>
							<dt><?php esc_html_e( 'Resource', 'g2a-booking' ); ?></dt><dd><?php echo esc_html( $b->resource_name ?: '—' ); ?></dd>
							<dt><?php esc_html_e( 'Start', 'g2a-booking' ); ?></dt><dd><?php echo esc_html( G2AB_Events::format_local( $b->start_at, 'D M j, Y · g:i A' ) ); ?></dd>
							<dt><?php esc_html_e( 'End', 'g2a-booking' ); ?></dt><dd><?php echo esc_html( G2AB_Events::format_local( $b->end_at, 'D M j, Y · g:i A' ) ); ?></dd>
							<dt><?php esc_html_e( 'Party size', 'g2a-booking' ); ?></dt><dd><?php echo (int) $b->party_size; ?></dd>
							<dt><?php esc_html_e( 'Waiver', 'g2a-booking' ); ?></dt><dd><?php echo $b->waiver_signed ? esc_html__( 'Signed', 'g2a-booking' ) : esc_html__( 'Missing', 'g2a-booking' ); ?></dd>
						</dl>
					</section>
					<section class="g2ab-bk__panel">
						<h3><?php esc_html_e( 'Payment', 'g2a-booking' ); ?></h3>
						<dl>
							<dt><?php esc_html_e( 'Total', 'g2a-booking' ); ?></dt><dd>$<?php echo esc_html( number_format( (float) $b->total_amount, 2 ) ); ?></dd>
							<dt><?php esc_html_e( 'Paid', 'g2a-booking' ); ?></dt><dd>$<?php echo esc_html( number_format( (float) $b->paid_amount, 2 ) ); ?></dd>
							<dt><?php esc_html_e( 'Mode', 'g2a-booking' ); ?></dt><dd><?php echo esc_html( $b->payment_mode ); ?></dd>
							<dt><?php esc_html_e( 'Currency', 'g2a-booking' ); ?></dt><dd><?php echo esc_html( $b->currency ); ?></dd>
							<dt><?php esc_html_e( 'Source', 'g2a-booking' ); ?></dt><dd><?php echo esc_html( $b->source ); ?></dd>
							<dt><?php esc_html_e( 'Created', 'g2a-booking' ); ?></dt><dd><?php echo esc_html( G2AB_Events::format_local( $b->created_at, 'M j, Y · g:i A' ) ); ?></dd>
						</dl>
					</section>
				</div>

				<?php if ( $form_data ) : ?>
				<section class="g2ab-bk__panel g2ab-bk__panel--wide">
					<h3><?php esc_html_e( 'Form submission', 'g2a-booking' ); ?></h3>
					<dl class="g2ab-bk__formdata">
						<?php foreach ( $form_data as $k => $v ) : ?>
							<dt><?php echo esc_html( ucwords( str_replace( '_', ' ', $k ) ) ); ?></dt>
							<dd><?php echo esc_html( is_scalar( $v ) ? (string) $v : wp_json_encode( $v ) ); ?></dd>
						<?php endforeach; ?>
					</dl>
				</section>
				<?php endif; ?>

				<div class="g2ab-bk__dgrid g2ab-bk__dgrid--2">
					<section class="g2ab-bk__panel">
						<h3><?php esc_html_e( 'Status control', 'g2a-booking' ); ?></h3>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="g2ab-bk__statusform">
							<?php wp_nonce_field( 'g2ab_change_status_' . $b->id, '_g2ab_nonce' ); ?>
							<input type="hidden" name="action" value="g2ab_change_status" />
							<input type="hidden" name="booking_id" value="<?php echo (int) $b->id; ?>" />
							<select name="status" class="g2ab-bk__select">
								<?php foreach ( array( 'pending', 'reserved', 'confirmed', 'paid', 'completed', 'cancelled', 'no_show', 'refunded', 'expired' ) as $s ) : ?>
									<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $b->status, $s ); ?>><?php echo esc_html( ucwords( str_replace( '_', ' ', $s ) ) ); ?></option>
								<?php endforeach; ?>
							</select>
							<button class="g2ab-bk__btn g2ab-bk__btn--primary"><?php esc_html_e( 'Update status', 'g2a-booking' ); ?></button>
						</form>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="g2ab-bk__deleteform" onsubmit="return confirm('<?php echo esc_js( __( 'Permanently delete this booking?', 'g2a-booking' ) ); ?>');">
							<?php wp_nonce_field( 'g2ab_delete_booking_' . $b->id, '_g2ab_nonce' ); ?>
							<input type="hidden" name="action" value="g2ab_delete_booking" />
							<input type="hidden" name="booking_id" value="<?php echo (int) $b->id; ?>" />
							<button class="g2ab-bk__btn g2ab-bk__btn--danger"><?php esc_html_e( 'Delete booking', 'g2a-booking' ); ?></button>
						</form>
					</section>
					<section class="g2ab-bk__panel">
						<h3><?php esc_html_e( 'Audit log', 'g2a-booking' ); ?></h3>
						<?php if ( empty( $logs ) ) : ?>
							<p class="g2ab-bk__muted"><em><?php esc_html_e( 'No log entries.', 'g2a-booking' ); ?></em></p>
						<?php else : ?>
							<ul class="g2ab-bk__log">
								<?php foreach ( $logs as $log ) : ?>
									<li>
										<span class="g2ab-bk__log-time"><?php echo esc_html( G2AB_Events::format_local( $log->created_at, 'M j · H:i' ) ); ?></span>
										<span class="g2ab-bk__log-event g2ab-bk__log-event--<?php echo esc_attr( $log->severity ); ?>"><?php echo esc_html( $log->event_type ); ?></span>
										<span class="g2ab-bk__log-msg"><?php echo esc_html( $log->message ); ?></span>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</section>
				</div>
			</div>
		</div>
		<?php
	}

	public function handle_status_change() {
		if ( ! current_user_can( 'manage_g2ab_bookings' ) ) {
			wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
		}
		$id = (int) ( $_POST['booking_id'] ?? 0 );
		check_admin_referer( 'g2ab_change_status_' . $id, '_g2ab_nonce' );
		$status  = sanitize_key( wp_unslash( $_POST['status'] ?? '' ) );
		$allowed = array( 'pending', 'reserved', 'confirmed', 'paid', 'completed', 'cancelled', 'no_show', 'refunded', 'expired' );
		if ( ! in_array( $status, $allowed, true ) ) {
			wp_die( esc_html__( 'Invalid status.', 'g2a-booking' ) );
		}
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'g2ab_bookings', array( 'status' => $status, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ), array( '%s', '%s' ), array( '%d' ) );
		$wpdb->insert( $wpdb->prefix . 'g2ab_logs', array(
			'booking_id' => $id,
			'user_id'    => get_current_user_id(),
			'event_type' => 'status_changed',
			'severity'   => 'info',
			'message'    => sprintf( 'Status set to %s by admin', $status ),
			'context'    => wp_json_encode( array() ),
			'created_at' => current_time( 'mysql' ),
		) );
		do_action( 'g2ab_booking_status_changed', $id, $status );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'view' => 'detail', 'booking_id' => $id, 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_delete() {
		if ( ! current_user_can( 'delete_g2ab_bookings' ) ) {
			wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
		}
		$id = (int) ( $_POST['booking_id'] ?? 0 );
		check_admin_referer( 'g2ab_delete_booking_' . $id, '_g2ab_nonce' );
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'g2ab_bookings', array( 'id' => $id ), array( '%d' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Stream the bookings list as a CSV. Honors the same tab + status + search
	 * filters as the on-screen roster so what you see is what you export.
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
		$et  = $wpdb->prefix . 'g2ab_events';

		$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'all';
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		$where = ' WHERE 1=1 ';
		$args  = array();
		if ( 'lane' === $tab ) {
			$where .= ' AND b.event_id IS NULL ';
		} elseif ( 'event' === $tab ) {
			$where .= " AND b.event_id IS NOT NULL AND (e.category IS NULL OR e.category <> 'ccw-class') ";
		} elseif ( 'class' === $tab ) {
			$where .= " AND b.event_id IS NOT NULL AND e.category = 'ccw-class' ";
		}
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
		                b.created_at, r.name AS resource_name, t.name AS booking_type, e.title AS event_title
		         FROM {$bt} b
		         LEFT JOIN {$rt} r ON r.id = b.resource_id
		         LEFT JOIN {$btt} t ON t.id = b.booking_type_id
		         LEFT JOIN {$et} e ON e.id = b.event_id
		         {$where}
		         ORDER BY b.start_at DESC";
		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );

		$filename = 'g2a-bookings-' . wp_date( 'Y-m-d-His' ) . '.csv';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );

		$headers = array(
			'ID', 'Confirmation', 'Customer Name', 'Customer Email', 'Customer Phone',
			'Start', 'End', 'Duration (min)', 'Party Size', 'Status',
			'Payment Mode', 'Total', 'Paid', 'Currency', 'Created', 'Resource', 'Booking Type', 'Event',
		);
		fputcsv( $out, $headers );

		if ( empty( $rows ) ) {
			fputcsv( $out, array( 'No bookings match the current filter' ) );
		} else {
			foreach ( $rows as $r ) {
				fputcsv( $out, array(
					$r['id'], $r['uuid'], $r['customer_name'], $r['customer_email'], $r['customer_phone'],
					$r['start_at'], $r['end_at'], $r['duration_min'], $r['party_size'], $r['status'],
					$r['payment_mode'], $r['total_amount'], $r['paid_amount'], $r['currency'],
					$r['created_at'], $r['resource_name'], $r['booking_type'], $r['event_title'],
				) );
			}
		}

		fclose( $out );
		exit;
	}

	private function print_styles() {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		echo '<style>' . $this->css() . '</style>';
	}

	private function css() {
		return <<<CSS
.g2ab-bk{--ink:#1A2233;--ink-soft:#5A6577;--ink-faint:#8A93A5;--line:#E7EBF1;--surface:#fff;--bg:#F3F5F9;--brand:#E8772E;--radius:16px;max-width:1240px;margin:18px 20px 60px 0;font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;color:var(--ink);}
.g2ab-bk *{box-sizing:border-box;}
.g2ab-bk__head{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;flex-wrap:wrap;padding:6px 4px 16px;}
.g2ab-bk__eyebrow{font-size:11px;font-weight:700;letter-spacing:.16em;color:var(--brand);margin-bottom:6px;}
.g2ab-bk__title{font-size:30px;font-weight:800;letter-spacing:-.01em;margin:0;color:var(--ink);padding:0;line-height:1.1;}
.g2ab-bk__sub{margin:6px 0 0;color:var(--ink-soft);font-size:13.5px;}
.g2ab-bk__head-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.g2ab-bk__btn{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border-radius:10px;font-size:13px;font-weight:700;text-decoration:none;cursor:pointer;border:1px solid var(--line);background:var(--surface);color:var(--ink);font-family:inherit;transition:transform .15s ease,box-shadow .15s ease,background .15s ease,color .15s ease,border-color .15s ease;}
.g2ab-bk__btn:hover{transform:translateY(-1px);}
.g2ab-bk__btn--ghost{color:var(--ink-soft);}
.g2ab-bk__btn--ghost:hover{border-color:var(--brand);color:var(--brand);}
.g2ab-bk__btn--primary{background:var(--brand);color:#fff;border-color:var(--brand);box-shadow:0 6px 16px rgba(232,119,46,.25);}
.g2ab-bk__btn--primary:hover{color:#fff;box-shadow:0 10px 22px rgba(232,119,46,.32);}
.g2ab-bk__btn--filter{background:var(--ink);color:#fff;border-color:var(--ink);}
.g2ab-bk__btn--filter:hover{color:#fff;background:#2A3242;}
.g2ab-bk__btn--danger{background:#fff;color:#C62828;border-color:#E7C4C4;}
.g2ab-bk__btn--danger:hover{background:#C62828;color:#fff;border-color:#C62828;}

.g2ab-bk__tabs{display:flex;gap:4px;border-bottom:1px solid var(--line);margin-bottom:18px;flex-wrap:wrap;}
.g2ab-bk__tab{display:inline-flex;align-items:center;gap:8px;padding:11px 18px;font-size:14px;font-weight:600;color:var(--ink-soft);text-decoration:none;border-bottom:2.5px solid transparent;margin-bottom:-1px;transition:color .15s ease,border-color .15s ease;}
.g2ab-bk__tab:hover{color:var(--ink);}
.g2ab-bk__tab.is-active{color:var(--brand);border-bottom-color:var(--brand);font-weight:700;}
.g2ab-bk__tab-count{font-size:11px;font-weight:700;background:#EEF1F6;color:var(--ink-soft);border-radius:999px;padding:1px 8px;}
.g2ab-bk__tab.is-active .g2ab-bk__tab-count{background:rgba(232,119,46,.14);color:var(--brand);}

.g2ab-bk__toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px;}
.g2ab-bk__search{position:relative;flex:1;min-width:240px;display:flex;align-items:center;}
.g2ab-bk__search svg{position:absolute;left:14px;color:var(--ink-faint);pointer-events:none;}
.g2ab-bk__search input{width:100%;padding:10px 14px 10px 38px;border:1px solid var(--line);border-radius:12px;background:var(--surface);font-size:14px;color:var(--ink);box-shadow:0 1px 2px rgba(20,30,55,.04);}
.g2ab-bk__search input:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 3px rgba(232,119,46,.15);}
.g2ab-bk__select{padding:10px 12px;border:1px solid var(--line);border-radius:12px;background:var(--surface);font-size:13.5px;color:var(--ink);font-family:inherit;cursor:pointer;}
.g2ab-bk__select:focus{outline:none;border-color:var(--brand);}

.g2ab-bk__table-wrap{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);box-shadow:0 1px 2px rgba(20,30,55,.04);overflow:hidden;}
.g2ab-bk__table{width:100%;border-collapse:collapse;font-size:13.5px;}
.g2ab-bk__table thead th{text-align:left;font-size:10.5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--ink-faint);padding:14px 16px;border-bottom:1px solid var(--line);background:#FBFCFE;}
.g2ab-bk__table tbody tr{border-bottom:1px solid #F4F6FA;cursor:pointer;transition:background .14s ease;}
.g2ab-bk__table tbody tr:last-child{border-bottom:none;}
.g2ab-bk__table tbody tr:hover{background:#F8FAFC;}
.g2ab-bk__table td{padding:13px 16px;vertical-align:middle;}
.g2ab-bk__c-id{color:var(--ink-faint);font-weight:600;font-variant-numeric:tabular-nums;width:64px;}
.g2ab-bk__c-when{white-space:nowrap;}
.g2ab-bk__date{display:block;font-weight:600;color:var(--ink);}
.g2ab-bk__time{display:block;font-size:11.5px;color:var(--ink-faint);margin-top:1px;}
.g2ab-bk__cust{display:flex;align-items:center;gap:11px;min-width:0;}
.g2ab-bk__avatar{width:36px;height:36px;border-radius:50%;flex:none;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;color:#fff;background:linear-gradient(135deg,#2A3242,#475067);}
.g2ab-bk__avatar--lg{width:58px;height:58px;font-size:20px;}
.g2ab-bk__cust-id{min-width:0;}
.g2ab-bk__cust-name{display:block;font-weight:600;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:170px;}
.g2ab-bk__cust-email{display:block;font-size:11.5px;color:var(--ink-faint);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:170px;}
.g2ab-bk__what{color:var(--ink-soft);font-weight:500;}
.g2ab-bk__chip{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:700;color:var(--c,#5A6577);background:#F2F4F8;background:color-mix(in srgb, var(--c,#5A6577) 12%, #fff);border-radius:999px;padding:3px 11px;white-space:nowrap;}
.g2ab-bk__chip-ico{font-size:11px;}
.g2ab-bk__chip--light{font-size:12px;}
.g2ab-bk__badge{display:inline-block;font-size:11px;font-weight:700;border-radius:999px;padding:3px 11px;text-transform:capitalize;white-space:nowrap;}
.g2ab-bk__st-confirmed,.g2ab-bk__st-paid{background:rgba(46,125,50,.13);color:#1f7a23;}
.g2ab-bk__st-completed{background:rgba(21,101,192,.12);color:#1565C0;}
.g2ab-bk__st-pending,.g2ab-bk__st-reserved{background:rgba(232,119,46,.14);color:#b45309;}
.g2ab-bk__st-cancelled,.g2ab-bk__st-no_show,.g2ab-bk__st-refunded{background:rgba(198,40,40,.12);color:#c62828;}
.g2ab-bk__st-expired{background:#EEF1F6;color:var(--ink-soft);}
.g2ab-bk__c-amt{text-align:right;font-weight:700;color:var(--ink);font-variant-numeric:tabular-nums;white-space:nowrap;}
.g2ab-bk__c-act{width:40px;text-align:right;}
.g2ab-bk__view{color:var(--ink-faint);text-decoration:none;font-size:18px;font-weight:700;}
.g2ab-bk__view:hover{color:var(--brand);}

.g2ab-bk__empty{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);padding:60px 20px;text-align:center;}
.g2ab-bk__empty-icon{font-size:42px;color:#CBD3E0;margin-bottom:10px;}
.g2ab-bk__empty h2{margin:0 0 6px;font-size:18px;color:var(--ink);}
.g2ab-bk__empty p{margin:0;color:var(--ink-faint);font-size:13.5px;}

.g2ab-bk__pager{display:flex;justify-content:center;align-items:center;gap:6px;margin-top:22px;flex-wrap:wrap;}
.g2ab-bk__pg{min-width:36px;height:36px;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--line);background:var(--surface);color:var(--ink-soft);border-radius:10px;text-decoration:none;font-size:13px;font-weight:600;transition:all .15s ease;}
.g2ab-bk__pg:hover{border-color:var(--brand);color:var(--brand);}
.g2ab-bk__pg.is-active{background:var(--ink);color:#fff;border-color:var(--ink);}
.g2ab-bk__pg.is-disabled{opacity:.4;pointer-events:none;}
.g2ab-bk__pg-gap{color:var(--ink-faint);padding:0 4px;}

/* Detail */
.g2ab-bk__back{margin:6px 0 12px;}
.g2ab-bk__back a{color:var(--ink-soft);text-decoration:none;font-size:13px;font-weight:600;}
.g2ab-bk__back a:hover{color:var(--brand);}
.g2ab-bk__detail{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;box-shadow:0 1px 2px rgba(20,30,55,.05);}
.g2ab-bk__dhead{display:flex;justify-content:space-between;align-items:center;gap:20px;flex-wrap:wrap;padding:26px 28px;background:linear-gradient(135deg,#161B26 0%,#2A3242 100%);color:#fff;}
.g2ab-bk__dhead-main{display:flex;align-items:center;gap:16px;}
.g2ab-bk__dname{margin:0;font-size:22px;font-weight:800;color:#fff;padding:0;line-height:1.1;}
.g2ab-bk__dmeta{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:8px;}
.g2ab-bk__uuid{font-size:11px;color:rgba(255,255,255,.7);background:rgba(255,255,255,.1);padding:2px 8px;border-radius:6px;}
.g2ab-bk__chip--light{background:rgba(255,255,255,.14);color:#fff;}
.g2ab-bk__damount{text-align:right;}
.g2ab-bk__damount-num{display:block;font-size:26px;font-weight:800;color:#fff;}
.g2ab-bk__damount-lbl{display:block;font-size:12px;color:rgba(255,255,255,.65);margin-top:2px;}
.g2ab-bk__dgrid{display:grid;grid-template-columns:repeat(3,1fr);gap:0;}
.g2ab-bk__dgrid--2{grid-template-columns:1fr 1fr;}
@media(max-width:860px){.g2ab-bk__dgrid,.g2ab-bk__dgrid--2{grid-template-columns:1fr;}}
.g2ab-bk__panel{padding:20px 26px;border-right:1px solid #F1F3F8;border-bottom:1px solid #F1F3F8;}
.g2ab-bk__panel--wide{border-right:none;}
.g2ab-bk__panel h3{font-size:12px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--brand);margin:0 0 14px;}
.g2ab-bk__panel dl{margin:0;display:grid;grid-template-columns:110px 1fr;gap:8px 16px;font-size:13px;}
.g2ab-bk__panel dt{color:var(--ink-faint);font-size:12px;align-self:center;}
.g2ab-bk__panel dd{margin:0;color:var(--ink);font-weight:600;}
.g2ab-bk__panel dd a{color:var(--brand);text-decoration:none;}
.g2ab-bk__formdata{grid-template-columns:180px 1fr;}
.g2ab-bk__statusform{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
.g2ab-bk__deleteform{margin-top:14px;}
.g2ab-bk__muted{color:var(--ink-faint);}
.g2ab-bk__log{list-style:none;padding:0;margin:0;font-size:12px;}
.g2ab-bk__log li{padding:8px 0;border-bottom:1px solid #F4F6FA;display:grid;grid-template-columns:100px 110px 1fr;gap:10px;align-items:center;}
.g2ab-bk__log li:last-child{border-bottom:none;}
.g2ab-bk__log-time{color:var(--ink-faint);}
.g2ab-bk__log-event{padding:2px 8px;background:#EEF1F6;color:var(--ink-soft);font-size:10px;font-weight:700;border-radius:999px;text-align:center;text-transform:uppercase;letter-spacing:.03em;}
.g2ab-bk__log-event--error{background:rgba(198,40,40,.12);color:#c62828;}
.g2ab-bk__log-event--warning{background:rgba(232,119,46,.14);color:#b45309;}
.g2ab-bk__log-msg{color:var(--ink-soft);}
CSS;
	}
}
