<?php
/**
 * WP-admin: Opt-outs review screen.
 *
 * Owner-only. Lists every opt-out (per email × category) with reason and
 * timestamp. Supports per-row Restore, a manual Add form, and category-
 * scoped restore of every entry for an address.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Admin;

use WordPressistic\G2ABA\Ops\Audit_Log;
use WordPressistic\G2ABA\Ops\Opt_Out_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Opt_Outs_Page {
	private const NONCE_ACTION = 'g2aba_opt_out_action';
	private const NONCE_NAME   = '_g2aba_opt_out_nonce';
	public  const PAGE_SLUG    = 'g2a-opt-outs';

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_g2aba_opt_out_action', array( __CLASS__, 'handle' ) );
	}

	public static function add_menu(): void {
		add_submenu_page(
			'options-general.php',
			__( 'G2A · Opt-outs', 'g2a-business-api' ),
			__( 'G2A · Opt-outs', 'g2a-business-api' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'g2a-business-api' ) );
		}
		$flash   = isset( $_GET['msg'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['msg'] ) ) : '';
		$entries = ( new Opt_Out_Store() )->all_entries();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Email opt-outs', 'g2a-business-api' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Recipients who have asked not to receive email from Guns2Ammo. The sender respects these entries before every send. Internal-category mail (staff alerts) is not gated by this list.', 'g2a-business-api' ); ?>
			</p>

			<?php if ( '' !== $flash ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $flash ); ?></p></div>
			<?php endif; ?>

			<h2 class="title" style="margin-top: 24px;"><?php esc_html_e( 'Add opt-out', 'g2a-business-api' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom: 24px;">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<input type="hidden" name="action" value="g2aba_opt_out_action" />
				<input type="hidden" name="op" value="add" />
				<table class="form-table"><tbody>
					<tr>
						<th><label for="g2aba_opt_out_email"><?php esc_html_e( 'Email', 'g2a-business-api' ); ?></label></th>
						<td><input id="g2aba_opt_out_email" name="email" type="email" class="regular-text" required /></td>
					</tr>
					<tr>
						<th><label for="g2aba_opt_out_category"><?php esc_html_e( 'Category', 'g2a-business-api' ); ?></label></th>
						<td>
							<select id="g2aba_opt_out_category" name="category">
								<?php foreach ( Opt_Out_Store::categories() as $cat ) : ?>
									<option value="<?php echo esc_attr( $cat ); ?>"><?php echo esc_html( $cat ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="g2aba_opt_out_reason"><?php esc_html_e( 'Reason', 'g2a-business-api' ); ?></label></th>
						<td><input id="g2aba_opt_out_reason" name="reason" type="text" class="regular-text" value="operator_added" /></td>
					</tr>
				</tbody></table>
				<?php submit_button( __( 'Add opt-out', 'g2a-business-api' ), 'primary small', 'submit', false ); ?>
			</form>

			<h2 class="title"><?php echo esc_html( sprintf( __( 'Current opt-outs (%d)', 'g2a-business-api' ), count( $entries ) ) ); ?></h2>

			<?php if ( empty( $entries ) ) : ?>
				<p><em><?php esc_html_e( 'No opt-outs recorded yet.', 'g2a-business-api' ); ?></em></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Email', 'g2a-business-api' ); ?></th>
							<th><?php esc_html_e( 'Category', 'g2a-business-api' ); ?></th>
							<th><?php esc_html_e( 'Recorded at', 'g2a-business-api' ); ?></th>
							<th><?php esc_html_e( 'Reason', 'g2a-business-api' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $entries as $e ) : ?>
						<tr>
							<td><code><?php echo esc_html( $e['email'] ); ?></code></td>
							<td><?php echo esc_html( $e['category'] ); ?></td>
							<td><?php echo esc_html( $e['optedOutAt'] ); ?></td>
							<td><?php echo esc_html( $e['reason'] ); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
									<input type="hidden" name="action" value="g2aba_opt_out_action" />
									<input type="hidden" name="op" value="restore" />
									<input type="hidden" name="email" value="<?php echo esc_attr( $e['email'] ); ?>" />
									<input type="hidden" name="category" value="<?php echo esc_attr( $e['category'] ); ?>" />
									<?php submit_button( __( 'Restore', 'g2a-business-api' ), 'delete small', 'submit', false ); ?>
								</form>
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

		$op       = isset( $_POST['op'] )       ? sanitize_text_field( wp_unslash( (string) $_POST['op'] ) )       : '';
		$email    = isset( $_POST['email'] )    ? sanitize_email( wp_unslash( (string) $_POST['email'] ) )         : '';
		$category = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['category'] ) ) : Opt_Out_Store::CATEGORY_ALL;
		$reason   = isset( $_POST['reason'] )   ? sanitize_text_field( wp_unslash( (string) $_POST['reason'] ) )   : 'operator_added';

		$store = new Opt_Out_Store();
		$audit = new Audit_Log();
		$msg   = '';

		if ( 'add' === $op && '' !== $email ) {
			$store->record( $email, $category, $reason );
			$audit->record( 'opt_out.added_by_owner', sprintf( 'Owner added %s (%s)', $email, $category ), array( 'email' => $email, 'category' => $category ) );
			$msg = __( 'Opt-out added.', 'g2a-business-api' );
		} elseif ( 'restore' === $op && '' !== $email ) {
			$store->restore( $email, $category );
			$audit->record( 'opt_out.restored', sprintf( 'Owner restored %s (%s)', $email, $category ), array( 'email' => $email, 'category' => $category ) );
			$msg = __( 'Opt-out restored.', 'g2a-business-api' );
		} else {
			$msg = __( 'Unknown operation.', 'g2a-business-api' );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'msg' => $msg ), admin_url( 'options-general.php' ) ) );
		exit;
	}
}
