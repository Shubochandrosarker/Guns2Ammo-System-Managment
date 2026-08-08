<?php
/**
 * Admin: Range Guests / Non-members list + CSV export.
 *
 * Paid lane/event customers with NO membership — the segment that replaces
 * automatic Guest Pass enrollment. Staff can review, search and export the
 * list; lifetime booking stats come from G2AB_Range_Guest_Service.
 *
 * @package G2AB\Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Admin_Range_Guests {

	const PAGE_SLUG = 'g2ab-range-guests';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 32 );
		add_action( 'admin_post_g2ab_export_range_guests_csv', array( $this, 'handle_export_csv' ) );
	}

	public function register_menu() {
		add_submenu_page(
			'g2a-booking',
			__( 'Range Guests', 'g2a-booking' ),
			__( 'Range Guests', 'g2a-booking' ),
			'manage_g2ab_bookings',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	public function render() {
		if ( ! current_user_can( 'manage_g2ab_bookings' ) ) {
			wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
		}
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$guests = class_exists( 'G2AB_Range_Guest_Service' )
			? G2AB_Range_Guest_Service::get_range_guests( array( 'search' => $search, 'number' => 200 ) )
			: array();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Range Guests / Non-members', 'g2a-booking' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Customers who paid for lane time or event seats without a membership. Booking a lane never creates a membership — these are customer records with booking history only.', 'g2a-booking' ); ?>
			</p>
			<form method="get" style="margin:12px 0;">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search name or email…', 'g2a-booking' ); ?>" />
				<button class="button"><?php esc_html_e( 'Search', 'g2a-booking' ); ?></button>
				<a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'g2ab_export_range_guests_csv' ), admin_url( 'admin-post.php' ) ), 'g2ab_export_range_guests_csv' ) ); ?>">
					<?php esc_html_e( 'Export CSV', 'g2a-booking' ); ?>
				</a>
			</form>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Customer', 'g2a-booking' ); ?></th>
						<th><?php esc_html_e( 'Email', 'g2a-booking' ); ?></th>
						<th><?php esc_html_e( 'First booking', 'g2a-booking' ); ?></th>
						<th><?php esc_html_e( 'Last booking', 'g2a-booking' ); ?></th>
						<th><?php esc_html_e( 'Paid bookings', 'g2a-booking' ); ?></th>
						<th><?php esc_html_e( 'Lifetime paid', 'g2a-booking' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $guests ) ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No range guests found yet.', 'g2a-booking' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $guests as $user ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>"><?php echo esc_html( $user->display_name ); ?></a></td>
							<td><?php echo esc_html( $user->user_email ); ?></td>
							<td><?php echo esc_html( (string) get_user_meta( $user->ID, G2AB_Range_Guest_Service::FIRST_BOOKING_META, true ) ?: '—' ); ?></td>
							<td><?php echo esc_html( (string) get_user_meta( $user->ID, G2AB_Range_Guest_Service::LAST_BOOKING_META, true ) ?: '—' ); ?></td>
							<td><?php echo (int) get_user_meta( $user->ID, G2AB_Range_Guest_Service::BOOKING_COUNT_META, true ); ?></td>
							<td><?php echo esc_html( function_exists( 'g2ab_format_currency' )
								? g2ab_format_currency( (float) get_user_meta( $user->ID, G2AB_Range_Guest_Service::LIFETIME_PAID_META, true ) )
								: number_format_i18n( (float) get_user_meta( $user->ID, G2AB_Range_Guest_Service::LIFETIME_PAID_META, true ), 2 ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public function handle_export_csv() {
		if ( ! current_user_can( 'manage_g2ab_bookings' ) ) {
			wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
		}
		check_admin_referer( 'g2ab_export_range_guests_csv' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=range-guests-' . gmdate( 'Ymd-His' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		foreach ( G2AB_Range_Guest_Service::export_rows() as $row ) {
			fputcsv( $out, $row );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
