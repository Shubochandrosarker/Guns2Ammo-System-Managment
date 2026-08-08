<?php
/**
 * G2A — "🧩 Add-ons" admin page.
 *
 * Lets a store turn off any distributor drop-ship client it doesn't use.
 * A disabled distributor's class is never instantiated by the main
 * bootstrap (see advanced-ffl-checkout.php) -- no hooks, no wp_ajax_*
 * actions, no admin-menu registration for it happen at all, and its tab
 * disappears from the shared "📦 Distributors" page. Disabling never
 * deletes that distributor's settings, credentials, or catalog/order
 * history in the shared distributor_products/distributor_orders tables
 * -- turning it back on picks up exactly where it left off.
 *
 * Every distributor ships enabled by default (see
 * G2A_Distributor_Registry::is_enabled()'s own docblock) so an
 * already-running site upgrading to this version sees no functional
 * change until it explicitly disables one here.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Addons_Admin {

	const PAGE_SLUG = 'wpistic-ffl-addons';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_admin_page' ], 48 );
		add_action( 'wp_ajax_wpistic_ffl_addons_save', [ __CLASS__, 'ajax_save' ] );
	}

	public function register_admin_page(): void {
		add_submenu_page(
			'wpistic-ffl',
			__( 'Add-ons', 'advanced-ffl-checkout' ),
			__( '🧩 Add-ons', 'advanced-ffl-checkout' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			[ $this, 'render_admin_page' ]
		);
	}

	public static function ajax_save(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification -- covered by check_ajax_referer() above
		$submitted = $_POST['enabled'] ?? [];
		// A wholly absent key correctly means "every checkbox was left
		// unchecked" (a real, intentional "disable everything" save) --
		// but if it's PRESENT and not actually an array (a malformed
		// request shape a hand-crafted POST or a broken client could send
		// instead of enabled[slug]=1 per checkbox), don't silently treat
		// that the same way; reject it instead of quietly disabling all
		// five distributors from one bad request.
		if ( ! is_array( $submitted ) ) {
			wp_send_json_error( [ 'message' => __( 'Unexpected form data -- nothing was changed.', 'advanced-ffl-checkout' ) ], 400 );
		}
		foreach ( array_keys( G2A_Distributor_Registry::known_distributors() ) as $slug ) {
			// An unchecked checkbox is simply absent from $_POST -- isset() is the correct "was this one left on" test.
			G2A_Distributor_Registry::set_enabled( $slug, isset( $submitted[ $slug ] ) );
		}
		wp_send_json_success();
	}

	public function render_admin_page(): void {
		$known = G2A_Distributor_Registry::known_distributors();
		$nonce = wp_create_nonce( 'wpistic_ffl_admin_nonce' );
		?>
		<div class="wrap wpistic-ffl-admin">
			<h1 style="display:flex;align-items:center;gap:12px;">
				<span style="font-size:24px;">🧩</span>
				<?php esc_html_e( 'Add-ons', 'advanced-ffl-checkout' ); ?>
			</h1>
			<p class="description" style="max-width:800px;">
				<?php esc_html_e( 'Turn on only the distributor drop-ship clients this store actually uses. A disabled distributor loads none of its code, registers no hooks or AJAX actions, and its tab disappears from the 📦 Distributors page -- its settings and catalog/order history stay intact if you turn it back on later.', 'advanced-ffl-checkout' ); ?>
			</p>

			<form id="wpistic-ffl-addons-form" style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:10px;margin-top:20px;max-width:760px;">
				<table class="widefat striped">
					<thead><tr>
						<th style="width:70px;"><?php esc_html_e( 'Enabled', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Distributor', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Protocol', 'advanced-ffl-checkout' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $known as $slug => $meta ) : ?>
						<tr>
							<td>
								<input type="checkbox" name="enabled[<?php echo esc_attr( $slug ); ?>]" value="1"
									<?php checked( G2A_Distributor_Registry::is_enabled( $slug ) ); ?>>
							</td>
							<td><?php echo esc_html( $meta['label'] ); ?></td>
							<td><span style="font-size:12px;color:#888;"><?php echo esc_html( $meta['protocol'] ); ?></span></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( empty( $known ) ) : ?>
						<tr><td colspan="3"><?php esc_html_e( 'No distributor clients are registered.', 'advanced-ffl-checkout' ); ?></td></tr>
					<?php endif; ?>
					</tbody>
				</table>
				<p style="margin-top:16px;">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Add-ons', 'advanced-ffl-checkout' ); ?></button>
					<span id="wpistic-ffl-addons-msg" style="font-weight:600;margin-left:8px;"></span>
				</p>
			</form>
		</div>
		<script>
		(function(){
			var nonce = <?php echo wp_json_encode( $nonce ); ?>;
			document.getElementById('wpistic-ffl-addons-form').addEventListener('submit', function(e){
				e.preventDefault();
				var msg = document.getElementById('wpistic-ffl-addons-msg');
				var fd = new FormData(e.target);
				fd.append('action', 'wpistic_ffl_addons_save');
				fd.append('nonce', nonce);
				msg.textContent = 'Saving…'; msg.style.color = '#666';
				fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
					.then(function(r){ return r.json(); })
					.then(function(j){
						msg.style.color = j.success ? '#16A34A' : '#DC2626';
						msg.textContent = j.success ? '✓ Saved — reloading…' : '✗ ' + ((j.data && j.data.message) || 'Failed');
						if (j.success) { setTimeout(function(){ location.reload(); }, 400); }
					});
			});
		})();
		</script>
		<?php
	}
}
