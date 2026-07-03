<?php
/**
 * WP-admin: Email Drafts review screen.
 *
 * Table of drafts BridGistic created. Each row has Review/Send/Discard
 * affordances. Overrides for recipient/subject/body are captured inline —
 * "send now" always requires a valid recipient.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Admin;

use WordPressistic\G2ABA\Ops\Audit_Log;
use WordPressistic\G2ABA\Ops\Email_Draft_Store;
use WordPressistic\G2ABA\Ops\Email_Sender;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Email_Drafts_Page {
	private const NONCE_ACTION = 'g2aba_email_draft_action';
	private const NONCE_NAME   = '_g2aba_email_nonce';
	public  const PAGE_SLUG    = 'g2a-email-drafts';

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_g2aba_email_draft_action', array( __CLASS__, 'handle' ) );
	}

	public static function add_menu(): void {
		add_submenu_page(
			'options-general.php',
			__( 'G2A · Email Drafts', 'g2a-business-api' ),
			__( 'G2A · Email Drafts', 'g2a-business-api' ),
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

		$store   = new Email_Draft_Store();
		$drafts  = $store->all();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Email Drafts', 'g2a-business-api' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'BridGistic-generated email drafts waiting for owner review. Nothing is sent until you click Send.', 'g2a-business-api' ); ?>
			</p>

			<?php if ( '' !== $flash ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $flash ); ?></p></div>
			<?php endif; ?>

			<?php if ( empty( $drafts ) ) : ?>
				<p><em><?php esc_html_e( 'No drafts yet.', 'g2a-business-api' ); ?></em></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th style="width: 18%;"><?php esc_html_e( 'Status', 'g2a-business-api' ); ?></th>
							<th><?php esc_html_e( 'Draft', 'g2a-business-api' ); ?></th>
							<th style="width: 25%;"><?php esc_html_e( 'Actions', 'g2a-business-api' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $drafts as $d ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( (string) ( $d['status'] ?? '' ) ); ?></strong>
								<div class="description">
									<?php echo esc_html( (string) ( $d['createdAt'] ?? '' ) ); ?>
								</div>
								<?php if ( ! empty( $d['error'] ) ) : ?>
									<div style="color:#b32d2e;"><?php echo esc_html( (string) $d['error'] ); ?></div>
								<?php endif; ?>
							</td>
							<td>
								<div><strong><?php esc_html_e( 'Query:', 'g2a-business-api' ); ?></strong> <?php echo esc_html( (string) ( $d['query'] ?? '' ) ); ?></div>
								<div style="margin-top:6px;"><strong><?php esc_html_e( 'Subject:', 'g2a-business-api' ); ?></strong> <?php echo esc_html( (string) ( $d['subject'] ?? '' ) ); ?></div>
								<pre style="white-space: pre-wrap; margin-top:6px; background:#f6f7f7; padding:8px; max-width:640px;"><?php echo esc_html( (string) ( $d['body'] ?? '' ) ); ?></pre>
							</td>
							<td>
								<?php if ( ( $d['status'] ?? '' ) === Email_Draft_Store::STATUS_PENDING ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom: 8px;">
										<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
										<input type="hidden" name="action" value="g2aba_email_draft_action" />
										<input type="hidden" name="op" value="send" />
										<input type="hidden" name="id" value="<?php echo esc_attr( (string) $d['id'] ); ?>" />
										<input type="email" name="to" placeholder="recipient@example.com" required class="regular-text" value="<?php echo esc_attr( (string) ( $d['to'] ?? '' ) ); ?>" />
										<?php submit_button( __( 'Send now', 'g2a-business-api' ), 'primary small', 'submit', false ); ?>
									</form>

									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
										<input type="hidden" name="action" value="g2aba_email_draft_action" />
										<input type="hidden" name="op" value="discard" />
										<input type="hidden" name="id" value="<?php echo esc_attr( (string) $d['id'] ); ?>" />
										<?php submit_button( __( 'Discard', 'g2a-business-api' ), 'delete small', 'submit', false ); ?>
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

		$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['id'] ) ) : '';
		$op = isset( $_POST['op'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['op'] ) ) : '';

		$store = new Email_Draft_Store();
		$draft = $store->find( $id );

		$msg = '';
		if ( null === $draft ) {
			$msg = __( 'Draft not found.', 'g2a-business-api' );
		} elseif ( 'discard' === $op ) {
			$store->transition( $id, Email_Draft_Store::STATUS_DISCARDED, 'Discarded by owner' );
			( new Audit_Log() )->record( 'email.discarded', 'Discarded draft ' . $id, array( 'draftId' => $id ) );
			$msg = __( 'Draft discarded.', 'g2a-business-api' );
		} elseif ( 'send' === $op ) {
			$to = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( (string) $_POST['to'] ) ) : '';
			if ( '' === $to ) {
				$msg = __( 'A recipient email address is required to send.', 'g2a-business-api' );
			} else {
				$draft['to'] = $to;
				$updated = ( new Email_Sender() )->send( $draft );
				$msg = ( $updated['status'] ?? '' ) === Email_Draft_Store::STATUS_SENT
					? __( 'Draft sent.', 'g2a-business-api' )
					: __( 'Send failed — see draft status.', 'g2a-business-api' );
			}
		} else {
			$msg = __( 'Unknown operation.', 'g2a-business-api' );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'msg' => $msg ), admin_url( 'options-general.php' ) ) );
		exit;
	}
}
