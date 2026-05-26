<?php
/**
 * Paid Memberships Pro settings tab.
 *
 * @package G2AB\Modules\PMPro
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class G2AB_PMPro_Settings {

	public function __construct() {
		add_filter( 'g2ab_settings_tabs', array( $this, 'register_tab' ) );
		add_action( 'g2ab_settings_render_pmpro_memberships', array( $this, 'render' ) );
		add_action( 'admin_post_g2ab_save_pmpro_memberships', array( $this, 'save' ) );
	}

	public function register_tab( $tabs ) {
		$tabs['pmpro_memberships'] = 'PMPro Members';
		return $tabs;
	}

	public function render() {
		$module = G2AB_Module_PMPro_Memberships::instance();
		$pmpro_active = $module->pmpro_active();
		$levels = $module->get_levels();
		$rules = $module->get_rules();
		$enabled = (int) get_option( G2AB_Module_PMPro_Memberships::OPT_ENABLED, 1 );
		$mode = get_option( G2AB_Module_PMPro_Memberships::OPT_MEMBERS_ONLY_MODE, 'any_active' );
		$booking_types = $this->booking_types();
		$msg = ! empty( $_GET['saved'] ) ? '<div class="notice notice-success is-dismissible"><p>PMPro booking rules saved.</p></div>' : '';
		?>
		<style>
			.g2ab-pmpro__panel{background:#fff;border:1px solid #d0d4d9;padding:24px;margin-bottom:18px;}
			.g2ab-pmpro__status{display:inline-block;padding:4px 10px;border-radius:2px;color:#fff;font-size:11px;letter-spacing:.06em;font-weight:700;}
			.g2ab-pmpro__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px;}
			.g2ab-pmpro__card{border:1px solid #e2e5e9;background:#fff;padding:18px;}
			.g2ab-pmpro__row{margin-bottom:14px;}
			.g2ab-pmpro__row label{display:block;font-weight:700;margin-bottom:6px;font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:#3c434a;}
			.g2ab-pmpro__row input[type=number],.g2ab-pmpro__row select{width:100%;max-width:260px;}
			.g2ab-pmpro__checks{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:6px 12px;margin-top:8px;}
			.g2ab-pmpro__checks label,.g2ab-pmpro__inline{display:flex;gap:6px;align-items:center;font-weight:400;text-transform:none;letter-spacing:0;}
			.g2ab-pmpro__hint{color:#646970;font-size:12px;margin:6px 0 0;}
			.g2ab-pmpro__example{background:#f6f7f7;border-left:4px solid #179BD7;padding:12px 14px;margin:12px 0 0;}
		</style>
		<?php echo $msg; // phpcs:ignore ?>

		<div class="g2ab-pmpro__panel">
			<h2 style="margin-top:0;">Paid Memberships Pro Connection
				<?php if ( $pmpro_active ) : ?>
					<span class="g2ab-pmpro__status" style="background:#4CAF50;">CONNECTED</span>
				<?php else : ?>
					<span class="g2ab-pmpro__status" style="background:#C62828;">NOT ACTIVE</span>
				<?php endif; ?>
			</h2>
			<?php if ( ! $pmpro_active ) : ?>
				<p>Paid Memberships Pro is not active. Install and activate PMPro, create membership levels, then return here to map discounts.</p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'plugin-install.php?s=paid+memberships+pro&tab=search&type=term' ) ); ?>">Install Paid Memberships Pro</a>
			<?php else : ?>
				<p>Use this screen to decide what each PMPro membership level gets when booking lanes, classes, or member-only booking types.</p>
				<div class="g2ab-pmpro__example">
					<strong>Example:</strong> Set a level discount to <code>100</code> and apply it to all booking types. Logged-in users with that PMPro level will see and book at <code>$0.00</code>.
				</div>
			<?php endif; ?>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'g2ab_save_pmpro_memberships', '_g2ab_nonce' ); ?>
			<input type="hidden" name="action" value="g2ab_save_pmpro_memberships" />

			<div class="g2ab-pmpro__panel">
				<h2 style="margin-top:0;">Global Rules</h2>
				<div class="g2ab-pmpro__row">
					<label class="g2ab-pmpro__inline"><input type="checkbox" name="enabled" value="1" <?php checked( 1, $enabled ); ?> /> Enable PMPro booking discounts</label>
					<p class="g2ab-pmpro__hint">When disabled, booking prices use the normal booking type price and legacy member discount only.</p>
				</div>
				<div class="g2ab-pmpro__row">
					<label>Members-only booking access</label>
					<select name="members_only_mode">
						<option value="any_active" <?php selected( $mode, 'any_active' ); ?>>Any active PMPro level can book members-only types</option>
						<option value="configured_only" <?php selected( $mode, 'configured_only' ); ?>>Only levels configured below can book members-only types</option>
					</select>
				</div>
			</div>

			<div class="g2ab-pmpro__panel">
				<h2 style="margin-top:0;">Membership Level Discounts</h2>
				<?php if ( empty( $levels ) ) : ?>
					<p>No PMPro membership levels detected yet.</p>
				<?php else : ?>
					<div class="g2ab-pmpro__grid">
						<?php foreach ( $levels as $level_id => $level ) :
							$level_name = is_object( $level ) ? ( $level->name ?? ( 'Level #' . $level_id ) ) : ( is_array( $level ) ? ( $level['name'] ?? ( 'Level #' . $level_id ) ) : ( 'Level #' . $level_id ) );
							$rule = isset( $rules[ $level_id ] ) && is_array( $rules[ $level_id ] ) ? $rules[ $level_id ] : array();
							$discount = isset( $rule['discount'] ) ? (float) $rule['discount'] : 0;
							$all = ! empty( $rule['all'] );
							$selected_types = isset( $rule['booking_types'] ) && is_array( $rule['booking_types'] ) ? array_map( 'absint', $rule['booking_types'] ) : array();
						?>
							<div class="g2ab-pmpro__card">
								<h3 style="margin-top:0;"><?php echo esc_html( $level_name ); ?> <small>#<?php echo (int) $level_id; ?></small></h3>
								<div class="g2ab-pmpro__row">
									<label>Discount percentage</label>
									<input type="number" name="rules[<?php echo (int) $level_id; ?>][discount]" min="0" max="100" step="0.01" value="<?php echo esc_attr( $discount ); ?>" />
									<p class="g2ab-pmpro__hint">Use <strong>100</strong> for free member bookings.</p>
								</div>
								<div class="g2ab-pmpro__row">
									<label class="g2ab-pmpro__inline"><input type="checkbox" name="rules[<?php echo (int) $level_id; ?>][all]" value="1" <?php checked( $all ); ?> /> Apply to all booking types</label>
								</div>
								<div class="g2ab-pmpro__row">
									<label>Or select specific booking types</label>
									<div class="g2ab-pmpro__checks">
										<?php foreach ( $booking_types as $bt ) : ?>
											<label><input type="checkbox" name="rules[<?php echo (int) $level_id; ?>][booking_types][]" value="<?php echo (int) $bt->id; ?>" <?php checked( in_array( (int) $bt->id, $selected_types, true ) ); ?> /> <?php echo esc_html( $bt->name ); ?></label>
										<?php endforeach; ?>
									</div>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>

			<button class="button button-primary">Save PMPro Booking Rules</button>
		</form>
		<?php
	}

	public function save() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No permission.' );
		check_admin_referer( 'g2ab_save_pmpro_memberships', '_g2ab_nonce' );

		update_option( G2AB_Module_PMPro_Memberships::OPT_ENABLED, isset( $_POST['enabled'] ) ? 1 : 0 );

		$mode = sanitize_key( $_POST['members_only_mode'] ?? 'any_active' );
		if ( ! in_array( $mode, array( 'any_active', 'configured_only' ), true ) ) {
			$mode = 'any_active';
		}
		update_option( G2AB_Module_PMPro_Memberships::OPT_MEMBERS_ONLY_MODE, $mode );

		$rules_in = isset( $_POST['rules'] ) && is_array( $_POST['rules'] ) ? wp_unslash( $_POST['rules'] ) : array();
		$rules = array();
		foreach ( $rules_in as $level_id => $rule ) {
			$level_id = absint( $level_id );
			if ( ! $level_id || ! is_array( $rule ) ) continue;
			$discount = min( 100, max( 0, (float) ( $rule['discount'] ?? 0 ) ) );
			$type_ids = isset( $rule['booking_types'] ) && is_array( $rule['booking_types'] ) ? array_values( array_unique( array_map( 'absint', $rule['booking_types'] ) ) ) : array();
			$rules[ $level_id ] = array(
				'discount'      => $discount,
				'all'           => ! empty( $rule['all'] ) ? 1 : 0,
				'booking_types' => $type_ids,
			);
		}
		update_option( G2AB_Module_PMPro_Memberships::OPT_RULES, $rules );

		wp_safe_redirect( add_query_arg( array( 'page' => 'g2ab-settings', 'tab' => 'pmpro_memberships', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private function booking_types() {
		global $wpdb;
		$table = $wpdb->prefix . 'g2ab_booking_types';
		return $wpdb->get_results( "SELECT id, name, category FROM {$table} WHERE is_active = 1 ORDER BY category ASC, name ASC" );
	}
}
