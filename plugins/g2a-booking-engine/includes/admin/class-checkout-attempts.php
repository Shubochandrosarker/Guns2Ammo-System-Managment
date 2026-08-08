<?php
/**
 * Restricted checkout attempts screen.
 *
 * @package G2AB
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Admin_Checkout_Attempts {

	private static $instance = null;
	const PAGE_SLUG = 'g2ab-checkout-attempts';

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
			__( 'Checkout Attempts', 'g2a-booking' ),
			__( 'Checkout Attempts', 'g2a-booking' ),
			'manage_g2ab_payments',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	public function render() {
		if ( ! current_user_can( 'manage_g2ab_payments' ) ) {
			wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
		}
		global $wpdb;
		$pt = $wpdb->prefix . 'g2ab_payments';
		$bt = $wpdb->prefix . 'g2ab_bookings';
		$rt = $wpdb->prefix . 'g2ab_resources';
		$tt = $wpdb->prefix . 'g2ab_booking_types';
		$et = $wpdb->prefix . 'g2ab_events';

		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$where  = " WHERE p.gateway = 'stripe' ";
		$args   = array();
		if ( $status ) {
			$where .= ' AND p.status = %s ';
			$args[] = $status;
		}

		$sql = "SELECT p.*, b.uuid, b.status AS booking_status, b.payment_mode, b.customer_name, b.customer_email,
		               b.start_at, b.total_amount, b.paid_amount, r.name AS resource_name, t.name AS type_name, e.title AS event_title
		        FROM {$pt} p
		        LEFT JOIN {$bt} b ON b.id = p.booking_id
		        LEFT JOIN {$rt} r ON r.id = b.resource_id
		        LEFT JOIN {$tt} t ON t.id = b.booking_type_id
		        LEFT JOIN {$et} e ON e.id = b.event_id
		        {$where}
		        ORDER BY p.created_at DESC
		        LIMIT 200";
		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		$this->print_styles();
		?>
		<div class="wrap g2ab-ca">
			<header class="g2ab-ca__header">
				<div>
					<h1><?php esc_html_e( 'Checkout Attempts', 'g2a-booking' ); ?></h1>
					<p><?php esc_html_e( 'Stripe holds, reusable checkout sessions, expiries, failures, and paid attempt records.', 'g2a-booking' ); ?></p>
				</div>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=g2ab-bookings-list' ) ); ?>"><?php esc_html_e( 'Operational Bookings', 'g2a-booking' ); ?></a>
			</header>

			<form method="get" class="g2ab-ca__filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<select name="status">
					<option value=""><?php esc_html_e( 'All attempt statuses', 'g2a-booking' ); ?></option>
					<?php foreach ( array( 'creating', 'open', 'pending', 'failed', 'expired', 'succeeded', 'refunded', 'partial_refund', 'manual_review' ) as $st ) : ?>
						<option value="<?php echo esc_attr( $st ); ?>" <?php selected( $status, $st ); ?>><?php echo esc_html( strtoupper( str_replace( '_', ' ', $st ) ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<button class="button button-primary"><?php esc_html_e( 'Filter', 'g2a-booking' ); ?></button>
			</form>

			<div class="g2ab-ca__table-wrap">
				<?php if ( empty( $rows ) ) : ?>
					<div class="g2ab-ca__empty"><?php esc_html_e( 'No Stripe checkout attempts match this filter.', 'g2a-booking' ); ?></div>
				<?php else : ?>
					<table class="g2ab-ca__table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Attempt', 'g2a-booking' ); ?></th>
								<th><?php esc_html_e( 'Booking', 'g2a-booking' ); ?></th>
								<th><?php esc_html_e( 'Customer', 'g2a-booking' ); ?></th>
								<th><?php esc_html_e( 'Service', 'g2a-booking' ); ?></th>
								<th><?php esc_html_e( 'Amount', 'g2a-booking' ); ?></th>
								<th><?php esc_html_e( 'Stripe IDs', 'g2a-booking' ); ?></th>
								<th><?php esc_html_e( 'Status', 'g2a-booking' ); ?></th>
								<th><?php esc_html_e( 'Expires', 'g2a-booking' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $row ) :
								$gateway_response = json_decode( (string) ( $row['gateway_response'] ?? '' ), true );
								$pay_url = is_array( $gateway_response ) ? esc_url( (string) ( $gateway_response['url'] ?? '' ) ) : '';
								$service = $row['event_title'] ?: ( $row['resource_name'] ?: ( $row['type_name'] ?: __( 'Lane / Range', 'g2a-booking' ) ) );
								$detail_url = $row['booking_id'] ? admin_url( 'admin.php?page=g2ab-bookings-list&view=detail&booking_id=' . (int) $row['booking_id'] ) : '';
								?>
								<tr>
									<td>
										<strong>#<?php echo (int) $row['id']; ?></strong>
										<span><?php echo esc_html( $row['created_at'] ? mysql2date( 'M j, Y g:i A', $row['created_at'] ) : '' ); ?></span>
									</td>
									<td>
										<?php if ( $detail_url ) : ?>
											<a href="<?php echo esc_url( $detail_url ); ?>">#<?php echo (int) $row['booking_id']; ?></a>
										<?php else : ?>
											#<?php echo (int) $row['booking_id']; ?>
										<?php endif; ?>
										<code><?php echo esc_html( $row['uuid'] ?: '' ); ?></code>
									</td>
									<td>
										<strong><?php echo esc_html( $row['customer_name'] ?: __( 'Guest', 'g2a-booking' ) ); ?></strong>
										<span><?php echo esc_html( $row['customer_email'] ?: '' ); ?></span>
									</td>
									<td>
										<strong><?php echo esc_html( $service ); ?></strong>
										<span><?php echo esc_html( $row['start_at'] ? mysql2date( 'M j, Y g:i A', $row['start_at'] ) : '' ); ?></span>
									</td>
									<td><?php echo esc_html( strtoupper( $row['currency'] ?: 'USD' ) ); ?> <?php echo esc_html( number_format( (float) $row['amount'], 2 ) ); ?></td>
									<td>
										<code><?php echo esc_html( $this->mask_id( ( $row['checkout_session_id'] ?? '' ) ?: ( $row['transaction_id'] ?? '' ) ) ); ?></code>
										<?php if ( ! empty( $row['payment_intent_id'] ) ) : ?><code><?php echo esc_html( $this->mask_id( $row['payment_intent_id'] ) ); ?></code><?php endif; ?>
										<?php if ( $pay_url ) : ?><a href="<?php echo esc_url( $pay_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open checkout', 'g2a-booking' ); ?></a><?php endif; ?>
									</td>
									<td>
										<span class="g2ab-ca__pill g2ab-ca__pill--<?php echo esc_attr( sanitize_html_class( $row['status'] ) ); ?>"><?php echo esc_html( strtoupper( str_replace( '_', ' ', $row['status'] ) ) ); ?></span>
										<span><?php
										// The row's `status` key is the ATTEMPT status — relabel the
										// BOOKING's status explicitly so the second line shows the
										// booking state, not a re-label of the attempt.
										$booking_shape = array( 'status' => (string) ( $row['booking_status'] ?? '' ), 'payment_mode' => (string) ( $row['payment_mode'] ?? '' ) );
										echo esc_html( class_exists( 'G2AB_Booking_Visibility' ) ? G2AB_Booking_Visibility::status_label( $booking_shape ) : ( $row['booking_status'] ?: '' ) );
										?></span>
									</td>
									<td><?php echo esc_html( ! empty( $row['checkout_expires_at'] ) ? mysql2date( 'M j, Y g:i A', $row['checkout_expires_at'] ) : __( 'Unknown', 'g2a-booking' ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private function mask_id( $id ) {
		$id = (string) $id;
		if ( strlen( $id ) <= 14 ) {
			return $id ?: '-';
		}
		return substr( $id, 0, 8 ) . '...' . substr( $id, -6 );
	}

	private function print_styles() {
		echo '<style>
.g2ab-ca{max-width:1320px;margin-right:20px;color:#1A2233;}
.g2ab-ca__header{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;margin:20px 0 14px;padding:20px 24px;background:#fff;border:1px solid #dcdcde;border-left:4px solid #635BFF;}
.g2ab-ca__header h1{margin:0 0 4px;padding:0;font-size:28px;font-weight:800;}
.g2ab-ca__header p{margin:0;color:#646970;}
.g2ab-ca__filters{display:flex;gap:8px;align-items:center;padding:12px 14px;background:#fff;border:1px solid #dcdcde;border-bottom:0;}
.g2ab-ca__filters select{min-width:210px;}
.g2ab-ca__table-wrap{background:#fff;border:1px solid #dcdcde;overflow:auto;}
.g2ab-ca__table{width:100%;border-collapse:collapse;font-size:13px;}
.g2ab-ca__table th{text-align:left;padding:11px 12px;background:#f6f7f7;border-bottom:1px solid #dcdcde;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#646970;}
.g2ab-ca__table td{padding:12px;border-bottom:1px solid #f0f0f1;vertical-align:top;}
.g2ab-ca__table strong,.g2ab-ca__table span,.g2ab-ca__table code,.g2ab-ca__table a{display:block;margin-bottom:3px;}
.g2ab-ca__table code{font-size:11px;background:#f0f0f1;padding:2px 5px;border-radius:3px;white-space:nowrap;}
.g2ab-ca__pill{display:inline-block;padding:3px 8px;border-radius:999px;background:#646970;color:#fff;font-size:10px;font-weight:700;}
.g2ab-ca__pill--open,.g2ab-ca__pill--pending,.g2ab-ca__pill--creating{background:#E8802F;}
.g2ab-ca__pill--succeeded{background:#2E7D32;}
.g2ab-ca__pill--failed,.g2ab-ca__pill--expired{background:#C62828;}
.g2ab-ca__empty{padding:36px;text-align:center;color:#646970;}
</style>';
	}
}
