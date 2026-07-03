<?php
/**
 * WP-admin: Cancellation Review screen.
 *
 * Lists cancellation requests BridGistic queued. Each entry links to the
 * Booking Engine admin page for the referenced booking id (if known) and
 * offers Mark completed / Drop affordances.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Admin;

use WordPressistic\G2ABA\Ops\Audit_Log;
use WordPressistic\G2ABA\Ops\Cancellation_Queue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cancellations_Page {
	private const NONCE_ACTION = 'g2aba_cancel_action';
	private const NONCE_NAME   = '_g2aba_cancel_nonce';
	public  const PAGE_SLUG    = 'g2a-cancellations';

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_g2aba_cancel_action', array( __CLASS__, 'handle' ) );
	}

	public static function add_menu(): void {
		add_submenu_page(
			'options-general.php',
			__( 'G2A · Cancellations', 'g2a-business-api' ),
			__( 'G2A · Cancellations', 'g2a-business-api' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'g2a-business-api' ) );
		}

		$flash = isset( $_GET['msg'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['msg'] ) ) : '';
		$queue = ( new Cancellation_Queue() )->all();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Cancellation Review', 'g2a-business-api' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'BridGistic never auto-cancels bookings. Each row here is a request to finalize in the Booking Engine — mark it completed here after you do.', 'g2a-business-api' ); ?>
			</p>

			<?php if ( '' !== $flash ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $flash ); ?></p></div>
			<?php endif; ?>

			<?php if ( empty( $queue ) ) : ?>
				<p><em><?php esc_html_e( 'No cancellation requests.', 'g2a-business-api' ); ?></em></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Status', 'g2a-business-api' ); ?></th>
							<th><?php esc_html_e( 'Booking', 'g2a-business-api' ); ?></th>
							<th><?php esc_html_e( 'Request', 'g2a-business-api' ); ?></th>
							<th style="width: 22%;"><?php esc_html_e( 'Actions', 'g2a-business-api' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $queue as $row ) :
						$is_awaiting = ( $row['status'] ?? '' ) === Cancellation_Queue::STATUS_AWAITING;
						$booking_id  = (string) ( $row['bookingId'] ?? '' );
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( (string) ( $row['status'] ?? '' ) ); ?></strong>
								<div class="description"><?php echo esc_html( (string) ( $row['createdAt'] ?? '' ) ); ?></div>
								<?php if ( ! empty( $row['notes'] ) ) : ?>
									<div style="margin-top:4px;"><em><?php echo esc_html( (string) $row['notes'] ); ?></em></div>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( '' !== $booking_id ) : ?>
									<code>#<?php echo esc_html( $booking_id ); ?></code>
									<div>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=g2ab-bookings&booking=' . rawurlencode( $booking_id ) ) ); ?>">
											<?php esc_html_e( 'Open in Booking Engine →', 'g2a-business-api' ); ?>
										</a>
									</div>
								<?php else : ?>
									<em><?php esc_html_e( 'No booking id — resolve manually.', 'g2a-business-api' ); ?></em>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( (string) ( $row['query'] ?? '' ) ); ?></td>
							<td>
								<?php if ( $is_awaiting ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom: 8px;">
										<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
										<input type="hidden" name="action" value="g2aba_cancel_action" />
										<input type="hidden" name="op" value="complete" />
										<input type="hidden" name="id" value="<?php echo esc_attr( (string) $row['id'] ); ?>" />
										<input type="text" name="notes" placeholder="Notes (optional)" class="regular-text" />
										<?php submit_button( __( 'Mark completed', 'g2a-business-api' ), 'primary small', 'submit', false ); ?>
									</form>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
										<input type="hidden" name="action" value="g2aba_cancel_action" />
										<input type="hidden" name="op" value="drop" />
										<input type="hidden" name="id" value="<?php echo esc_attr( (string) $row['id'] ); ?>" />
										<?php submit_button( __( 'Drop', 'g2a-business-api' ), 'delete small', 'submit', false ); ?>
									</form>
								<?php else : ?>
									<em><?php esc_html_e( 'No further action.', 'g2a-business-api' ); ?></em>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'g2a-business-api' ) );
		}
		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		$id    = isset( $_POST['id'] )    ? sanitize_text_field( wp_unslash( (string) $_POST['id'] ) )    : '';
		$op    = isset( $_POST['op'] )    ? sanitize_text_field( wp_unslash( (string) $_POST['op'] ) )    : '';
		$notes = isset( $_POST['notes'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['notes'] ) ) : '';

		$queue = new Cancellation_Queue();
		$msg   = '';
		if ( 'complete' === $op ) {
			$updated = $queue->mark_completed( $id, $notes );
			if ( null === $updated ) {
				$msg = __( 'Cancellation not found or already resolved.', 'g2a-business-api' );
			} else {
				( new Audit_Log() )->record( 'cancellation.completed', 'Cancellation ' . $id . ' completed', array( 'cancellationId' => $id ) );
				$msg = __( 'Cancellation marked completed.', 'g2a-business-api' );
			}
		} elseif ( 'drop' === $op ) {
			$updated = $queue->drop( $id, 'Dropped by owner' );
			if ( null === $updated ) {
				$msg = __( 'Cancellation not found or already resolved.', 'g2a-business-api' );
			} else {
				( new Audit_Log() )->record( 'cancellation.dropped', 'Cancellation ' . $id . ' dropped', array( 'cancellationId' => $id ) );
				$msg = __( 'Cancellation dropped.', 'g2a-business-api' );
			}
		} else {
			$msg = __( 'Unknown operation.', 'g2a-business-api' );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'msg' => $msg ), admin_url( 'options-general.php' ) ) );
		exit;
	}
}
