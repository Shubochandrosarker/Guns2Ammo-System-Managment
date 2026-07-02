<?php
/**
 * Admin Booking Types CRUD — full add/edit/delete for Lane / Class / Membership types.
 *
 * Self-hooks at admin_menu priority 11 to override Phase 1 placeholder.
 *
 * @package G2AB
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class G2AB_Admin_Booking_Types_Crud {

	private static $instance = null;
	const PAGE_SLUG = 'g2ab-booking-types';
	const CATEGORIES = array( 'lane', 'class', 'membership' );
	const PAYMENT_MODES = array( 'full', 'deposit', 'in_store', 'free' );

	public static function instance() {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'override_menu' ), 11 );
		add_action( 'admin_post_g2ab_save_booking_type', array( $this, 'handle_save' ) );
		add_action( 'admin_post_g2ab_delete_booking_type', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_g2ab_toggle_booking_type', array( $this, 'handle_toggle' ) );
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
		add_submenu_page( 'g2ab-bookings', __( 'Booking Types', 'g2a-booking' ), __( 'Booking Types', 'g2a-booking' ), 'manage_g2ab_settings', self::PAGE_SLUG, array( $this, 'render' ) );
		if ( $idx >= 0 && isset( $submenu['g2ab-bookings'] ) ) {
			$new = array_pop( $submenu['g2ab-bookings'] );
			array_splice( $submenu['g2ab-bookings'], $idx, 0, array( $new ) );
		}
	}

	public function render() {
		if ( ! current_user_can( 'manage_g2ab_settings' ) ) wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';
		$this->print_styles();
		if ( in_array( $action, array( 'add', 'edit' ), true ) ) $this->render_form( $action );
		else $this->render_list();
	}

	private function render_list() {
		global $wpdb;
		$tbl = $wpdb->prefix . 'g2ab_booking_types';
		$cat_filter = isset( $_GET['cat'] ) ? sanitize_key( $_GET['cat'] ) : '';
		$where = ' WHERE 1=1 '; $args = array();
		if ( $cat_filter && in_array( $cat_filter, self::CATEGORIES, true ) ) { $where .= ' AND category = %s '; $args[] = $cat_filter; }
		$sql = "SELECT * FROM {$tbl} {$where} ORDER BY category ASC, id ASC";
		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql );
		$counts = $wpdb->get_results( "SELECT category, COUNT(*) c FROM {$tbl} GROUP BY category", OBJECT_K );
		$total = $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" );
		$msg = $this->get_notice();
		?>
		<div class="wrap g2ab-admin g2ab-bt">
			<div class="g2ab-bt__header">
				<div>
					<h1><span class="g2ab-bt__stencil"><?php esc_html_e( 'BOOKING TYPES', 'g2a-booking' ); ?></span></h1>
					<p class="g2ab-bt__sub"><?php esc_html_e( 'Lane sessions · classes · membership tiers', 'g2a-booking' ); ?></p>
				</div>
				<a class="g2ab-bt__btn g2ab-bt__btn--primary" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'action' => 'add' ), admin_url( 'admin.php' ) ) ); ?>"><span>+</span> <?php esc_html_e( 'NEW TYPE', 'g2a-booking' ); ?></a>
			</div>
			<?php echo $msg; ?>
			<nav class="g2ab-bt__tabs">
				<a class="<?php echo '' === $cat_filter ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>"><?php esc_html_e( 'ALL', 'g2a-booking' ); ?> <span><?php echo (int) $total; ?></span></a>
				<?php foreach ( self::CATEGORIES as $c ) : $n = isset( $counts[ $c ] ) ? (int) $counts[ $c ]->c : 0; ?>
					<a class="<?php echo $cat_filter === $c ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'cat' => $c ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( strtoupper( $c ) ); ?> <span><?php echo $n; ?></span></a>
				<?php endforeach; ?>
			</nav>
			<?php if ( empty( $rows ) ) : ?>
				<div class="g2ab-bt__empty">
					<div class="g2ab-bt__empty-icon">⊟</div>
					<h2><?php esc_html_e( 'No booking types yet.', 'g2a-booking' ); ?></h2>
					<a class="g2ab-bt__btn g2ab-bt__btn--primary" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'action' => 'add' ), admin_url( 'admin.php' ) ) ); ?>">+ <?php esc_html_e( 'CREATE FIRST TYPE', 'g2a-booking' ); ?></a>
				</div>
			<?php else : ?>
				<div class="g2ab-bt__grid">
					<?php foreach ( $rows as $r ) : $modes = array_filter( array_map( 'trim', explode( ',', (string) $r->payment_modes ) ) ); ?>
						<article class="g2ab-bt__card g2ab-bt__card--<?php echo esc_attr( $r->category ); ?> <?php echo $r->is_active ? '' : 'is-inactive'; ?>">
							<div class="g2ab-bt__card-tag"><?php echo esc_html( strtoupper( $r->category ) ); ?></div>
							<h3 class="g2ab-bt__card-title"><?php echo esc_html( $r->name ); ?></h3>
							<div class="g2ab-bt__card-slug"><code><?php echo esc_html( $r->slug ); ?></code></div>
							<div class="g2ab-bt__card-price">$<?php echo esc_html( number_format( (float) $r->base_price, 2 ) ); ?> <small><?php echo (int) $r->duration_min; ?>min</small></div>
							<dl class="g2ab-bt__card-specs">
								<?php if ( (float) $r->deposit_amount > 0 ) : ?>
									<dt>Deposit</dt><dd>$<?php echo esc_html( number_format( (float) $r->deposit_amount, 2 ) ); ?></dd>
								<?php endif; ?>
								<?php if ( (int) $r->buffer_after > 0 ) : ?>
									<dt>Buffer</dt><dd><?php echo (int) $r->buffer_after; ?> min</dd>
								<?php endif; ?>
								<?php if ( (float) $r->member_discount > 0 ) : ?>
									<dt>Member -</dt><dd><?php echo esc_html( number_format( (float) $r->member_discount, 1 ) ); ?>%</dd>
								<?php endif; ?>
								<dt>Modes</dt><dd>
									<?php foreach ( $modes as $m ) : ?>
										<span class="g2ab-bt__pill"><?php echo esc_html( strtoupper( str_replace( '_', ' ', $m ) ) ); ?></span>
									<?php endforeach; ?>
								</dd>
							</dl>
							<div class="g2ab-bt__card-flags">
								<?php if ( (int) $r->requires_waiver ) : ?><span class="g2ab-bt__flag">⚠ WAIVER</span><?php endif; ?>
								<?php if ( (int) $r->members_only ) : ?><span class="g2ab-bt__flag g2ab-bt__flag--gold">★ MEMBERS ONLY</span><?php endif; ?>
							</div>
							<div class="g2ab-bt__card-actions">
								<a class="g2ab-bt__btn" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'action' => 'edit', 'id' => $r->id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'EDIT', 'g2a-booking' ); ?></a>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
									<?php wp_nonce_field( 'g2ab_toggle_booking_type_' . $r->id, '_g2ab_nonce' ); ?>
									<input type="hidden" name="action" value="g2ab_toggle_booking_type" />
									<input type="hidden" name="id" value="<?php echo (int) $r->id; ?>" />
									<button class="g2ab-bt__btn"><?php echo $r->is_active ? esc_html__( 'DISABLE', 'g2a-booking' ) : esc_html__( 'ENABLE', 'g2a-booking' ); ?></button>
								</form>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this booking type?', 'g2a-booking' ) ); ?>');">
									<?php wp_nonce_field( 'g2ab_delete_booking_type_' . $r->id, '_g2ab_nonce' ); ?>
									<input type="hidden" name="action" value="g2ab_delete_booking_type" />
									<input type="hidden" name="id" value="<?php echo (int) $r->id; ?>" />
									<button class="g2ab-bt__btn g2ab-bt__btn--danger"><?php esc_html_e( 'DELETE', 'g2a-booking' ); ?></button>
								</form>
							</div>
							<div class="g2ab-bt__card-status">
								<span class="g2ab-bt__dot <?php echo $r->is_active ? 'g2ab-bt__dot--ok' : 'g2ab-bt__dot--off'; ?>"></span>
								<?php echo $r->is_active ? esc_html__( 'ACTIVE', 'g2a-booking' ) : esc_html__( 'OFFLINE', 'g2a-booking' ); ?>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_form( $action ) {
		global $wpdb;
		$tbl = $wpdb->prefix . 'g2ab_booking_types';
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$row = ( 'edit' === $action && $id ) ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d", $id ) ) : null;
		if ( 'edit' === $action && ! $row ) { echo '<div class="wrap"><h1>Booking type not found</h1></div>'; return; }
		$is_edit = (bool) $row;
		$current_modes = $row ? array_filter( array_map( 'trim', explode( ',', (string) $row->payment_modes ) ) ) : array( 'full', 'in_store' );

		// Forms dropdown.
		$forms_table = $wpdb->prefix . 'g2ab_forms';
		$forms = $wpdb->get_results( "SELECT id, name FROM {$forms_table} WHERE is_active = 1 ORDER BY name ASC" );
		?>
		<div class="wrap g2ab-admin g2ab-bt">
			<div class="g2ab-bt__header">
				<div>
					<h1><span class="g2ab-bt__stencil"><?php echo $is_edit ? esc_html__( 'EDIT BOOKING TYPE', 'g2a-booking' ) : esc_html__( 'NEW BOOKING TYPE', 'g2a-booking' ); ?></span></h1>
					<p class="g2ab-bt__sub"><?php echo $is_edit ? esc_html__( 'Modify pricing & rules', 'g2a-booking' ) : esc_html__( 'Define a new bookable offering', 'g2a-booking' ); ?></p>
				</div>
				<a class="g2ab-bt__btn" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">← <?php esc_html_e( 'CANCEL', 'g2a-booking' ); ?></a>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="g2ab-bt__form">
				<?php wp_nonce_field( 'g2ab_save_booking_type', '_g2ab_nonce' ); ?>
				<input type="hidden" name="action" value="g2ab_save_booking_type" />
				<?php if ( $is_edit ) : ?><input type="hidden" name="id" value="<?php echo (int) $row->id; ?>" /><?php endif; ?>
				<div class="g2ab-bt__form-grid">

					<div class="g2ab-bt__panel">
						<h3><?php esc_html_e( 'IDENTITY', 'g2a-booking' ); ?></h3>
						<div class="g2ab-bt__field"><label><?php esc_html_e( 'Name', 'g2a-booking' ); ?> <span class="req">*</span></label><input type="text" name="name" required value="<?php echo esc_attr( $row->name ?? '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. Standard Lane Session', 'g2a-booking' ); ?>" /></div>
						<div class="g2ab-bt__field"><label><?php esc_html_e( 'Slug', 'g2a-booking' ); ?></label><input type="text" name="slug" value="<?php echo esc_attr( $row->slug ?? '' ); ?>" placeholder="<?php esc_attr_e( 'auto from name', 'g2a-booking' ); ?>" /></div>
						<div class="g2ab-bt__field"><label><?php esc_html_e( 'Category', 'g2a-booking' ); ?> <span class="req">*</span></label><select name="category" required><?php foreach ( self::CATEGORIES as $c ) : ?><option value="<?php echo esc_attr( $c ); ?>" <?php selected( $row->category ?? 'lane', $c ); ?>><?php echo esc_html( ucfirst( $c ) ); ?></option><?php endforeach; ?></select></div>
						<div class="g2ab-bt__field"><label class="g2ab-bt__check"><input type="checkbox" name="is_active" value="1" <?php checked( 1, (int) ( $row->is_active ?? 1 ) ); ?> /> <?php esc_html_e( 'Active and bookable', 'g2a-booking' ); ?></label></div>
					</div>

					<div class="g2ab-bt__panel">
						<h3><?php esc_html_e( 'TIMING', 'g2a-booking' ); ?></h3>
						<div class="g2ab-bt__field"><label><?php esc_html_e( 'Duration (minutes)', 'g2a-booking' ); ?> <span class="req">*</span></label><input type="number" name="duration_min" required min="5" max="1440" value="<?php echo esc_attr( $row->duration_min ?? 60 ); ?>" /></div>
						<div class="g2ab-bt__field"><label><?php esc_html_e( 'Buffer Before (min)', 'g2a-booking' ); ?></label><input type="number" name="buffer_before" min="0" max="120" value="<?php echo esc_attr( $row->buffer_before ?? 0 ); ?>" /><small><?php esc_html_e( 'Time blocked before each booking starts.', 'g2a-booking' ); ?></small></div>
						<div class="g2ab-bt__field"><label><?php esc_html_e( 'Buffer After (min)', 'g2a-booking' ); ?></label><input type="number" name="buffer_after" min="0" max="120" value="<?php echo esc_attr( $row->buffer_after ?? 10 ); ?>" /><small><?php esc_html_e( 'Time blocked after each booking ends.', 'g2a-booking' ); ?></small></div>
					</div>

					<div class="g2ab-bt__panel">
						<h3><?php esc_html_e( 'PRICING', 'g2a-booking' ); ?></h3>
						<div class="g2ab-bt__field"><label><?php esc_html_e( 'Base Price ($)', 'g2a-booking' ); ?></label><input type="number" name="base_price" step="0.01" min="0" value="<?php echo esc_attr( $row->base_price ?? 0 ); ?>" /></div>
						<div class="g2ab-bt__field"><label><?php esc_html_e( 'Deposit Amount ($)', 'g2a-booking' ); ?></label><input type="number" name="deposit_amount" step="0.01" min="0" value="<?php echo esc_attr( $row->deposit_amount ?? 0 ); ?>" /><small><?php esc_html_e( 'For partial-payment mode.', 'g2a-booking' ); ?></small></div>
						<div class="g2ab-bt__field"><label><?php esc_html_e( 'Member Discount (%)', 'g2a-booking' ); ?></label><input type="number" name="member_discount" step="0.1" min="0" max="100" value="<?php echo esc_attr( $row->member_discount ?? 0 ); ?>" /></div>
					</div>

					<div class="g2ab-bt__panel">
						<h3><?php esc_html_e( 'PAYMENT MODES', 'g2a-booking' ); ?></h3>
						<p class="g2ab-bt__desc"><?php esc_html_e( 'Which payment options the customer sees at checkout.', 'g2a-booking' ); ?></p>
						<div class="g2ab-bt__check-grid">
							<?php foreach ( self::PAYMENT_MODES as $m ) : ?>
								<label class="g2ab-bt__check"><input type="checkbox" name="payment_modes[]" value="<?php echo esc_attr( $m ); ?>" <?php checked( in_array( $m, $current_modes, true ) ); ?> /> <?php echo esc_html( strtoupper( str_replace( '_', ' ', $m ) ) ); ?></label>
							<?php endforeach; ?>
						</div>
					</div>

					<div class="g2ab-bt__panel">
						<h3><?php esc_html_e( 'RULES & FORM', 'g2a-booking' ); ?></h3>
						<div class="g2ab-bt__field"><label><?php esc_html_e( 'Booking Form', 'g2a-booking' ); ?></label>
							<select name="form_id">
								<option value="0"><?php esc_html_e( '— Default —', 'g2a-booking' ); ?></option>
								<?php foreach ( $forms as $f ) : ?>
									<option value="<?php echo (int) $f->id; ?>" <?php selected( (int) ( $row->form_id ?? 0 ), (int) $f->id ); ?>><?php echo esc_html( $f->name ); ?></option>
								<?php endforeach; ?>
							</select>
							<small><?php esc_html_e( 'Form shown to customer for this type.', 'g2a-booking' ); ?></small>
						</div>
						<div class="g2ab-bt__field"><label class="g2ab-bt__check"><input type="checkbox" name="requires_waiver" value="1" <?php checked( 1, (int) ( $row->requires_waiver ?? 1 ) ); ?> /> <?php esc_html_e( 'Requires liability waiver', 'g2a-booking' ); ?></label></div>
						<?php
					$membership_integration = $this->detect_membership_integration();
					if ( $membership_integration ) :
					?>
						<div class="g2ab-bt__field">
							<label class="g2ab-bt__check">
								<input type="checkbox" name="members_only" value="1" <?php checked( 1, (int) ( $row->members_only ?? 0 ) ); ?> />
								<?php
								/* translators: %s integration name (PMPro / Memberistic) */
								printf( esc_html__( 'Members-only (%s)', 'g2a-booking' ), esc_html( $membership_integration ) );
								?>
							</label>
						</div>
					<?php else : ?>
						<input type="hidden" name="members_only" value="<?php echo (int) ( $row->members_only ?? 0 ); ?>" />
						<div class="g2ab-bt__field">
							<small style="display:block;color:#8A95A5;font-size:12px;">
								<?php esc_html_e( 'Tip: activate the PMPro Memberships or Memberistic addon to gate this booking type to members only.', 'g2a-booking' ); ?>
							</small>
						</div>
					<?php endif; ?>
						<div class="g2ab-bt__field"><label><?php esc_html_e( 'SEO Landing Template', 'g2a-booking' ); ?></label>
							<?php
							$current_settings = $row ? ( json_decode( (string) $row->settings, true ) ?: array() ) : array();
							$current_tpl = $current_settings['landing_template'] ?? '';
							$tpl_list = class_exists( 'G2AB_Seo' ) ? G2AB_Seo::TEMPLATES : array();
							?>
							<select name="settings[landing_template]">
								<option value=""><?php esc_html_e( '— Auto (by category) —', 'g2a-booking' ); ?></option>
								<?php foreach ( $tpl_list as $tid => $tpl ) : ?>
									<option value="<?php echo esc_attr( $tid ); ?>" <?php selected( $current_tpl, $tid ); ?>><?php echo esc_html( $tpl['icon'] . ' ' . $tpl['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<small><?php
								if ( $row && $row->slug ) {
									printf( esc_html__( 'Landing page: %s', 'g2a-booking' ), '<a target="_blank" href="' . esc_url( home_url( '/event/' . $row->slug . '/' ) ) . '"><code>' . esc_html( home_url( '/event/' . $row->slug . '/' ) ) . '</code></a>' );
								} else {
									esc_html_e( 'Landing page available at /event/{slug}/ after save.', 'g2a-booking' );
								}
							?></small>
						</div>
					</div>
				</div>
				<div class="g2ab-bt__form-actions">
					<button type="submit" class="g2ab-bt__btn g2ab-bt__btn--primary"><?php echo $is_edit ? esc_html__( 'UPDATE TYPE', 'g2a-booking' ) : esc_html__( 'CREATE TYPE', 'g2a-booking' ); ?></button>
					<a class="g2ab-bt__btn" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>"><?php esc_html_e( 'CANCEL', 'g2a-booking' ); ?></a>
				</div>
			</form>
		</div>
		<?php
	}

	public function handle_save() {
		if ( ! current_user_can( 'manage_g2ab_settings' ) ) wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
		check_admin_referer( 'g2ab_save_booking_type', '_g2ab_nonce' );
		global $wpdb;
		$tbl = $wpdb->prefix . 'g2ab_booking_types';
		$id = (int) ( $_POST['id'] ?? 0 );
		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$slug = sanitize_title( wp_unslash( $_POST['slug'] ?? '' ) );
		$cat = sanitize_key( wp_unslash( $_POST['category'] ?? 'lane' ) );
		$dur = max( 5, (int) ( $_POST['duration_min'] ?? 60 ) );
		$bb = max( 0, (int) ( $_POST['buffer_before'] ?? 0 ) );
		$ba = max( 0, (int) ( $_POST['buffer_after'] ?? 0 ) );
		$bp = max( 0, (float) ( $_POST['base_price'] ?? 0 ) );
		$dep = max( 0, (float) ( $_POST['deposit_amount'] ?? 0 ) );
		$disc = max( 0, min( 100, (float) ( $_POST['member_discount'] ?? 0 ) ) );
		$form_id = (int) ( $_POST['form_id'] ?? 0 );
		$requires_waiver = isset( $_POST['requires_waiver'] ) ? 1 : 0;
		$members_only = isset( $_POST['members_only'] ) ? 1 : 0;
		$active = isset( $_POST['is_active'] ) ? 1 : 0;

		$modes_in = isset( $_POST['payment_modes'] ) && is_array( $_POST['payment_modes'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['payment_modes'] ) ) : array();
		$modes = array_values( array_intersect( self::PAYMENT_MODES, $modes_in ) );
		if ( empty( $modes ) ) $modes = array( 'in_store' );

		if ( '' === $name ) { $this->set_notice( 'error', __( 'Name required.', 'g2a-booking' ) ); wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); exit; }
		if ( ! in_array( $cat, self::CATEGORIES, true ) ) $cat = 'lane';
		if ( '' === $slug ) $slug = sanitize_title( $name );
		$slug = $this->ensure_unique_slug( $slug, $id );

		$now = current_time( 'mysql' );
		$settings_in = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();
		$settings = array(
			'landing_template' => isset( $settings_in['landing_template'] ) ? sanitize_key( $settings_in['landing_template'] ) : '',
		);
		$data = array(
			'name' => $name, 'slug' => $slug, 'category' => $cat,
			'duration_min' => $dur, 'buffer_before' => $bb, 'buffer_after' => $ba,
			'base_price' => $bp, 'deposit_amount' => $dep,
			'payment_modes' => implode( ',', $modes ),
			'requires_waiver' => $requires_waiver, 'members_only' => $members_only,
			'member_discount' => $disc,
			'settings' => wp_json_encode( $settings ),
			'is_active' => $active,
			'updated_at' => $now,
		);
		$fmt = array( '%s','%s','%s','%d','%d','%d','%f','%f','%s','%d','%d','%f','%s','%d','%s' );

		// form_id is nullable. If user picked "no form", pass NULL so downstream
		// `IS NULL` checks behave correctly (rather than form_id=0).
		if ( $form_id > 0 ) {
			$data['form_id'] = $form_id;
			$fmt[]           = '%d';
		} else {
			$data['form_id'] = null;
			$fmt[]           = '%s'; // %s allows NULL to pass through wpdb.
		}

		if ( $id ) {
			$wpdb->update( $tbl, $data, array( 'id' => $id ), $fmt, array( '%d' ) );
			$msg = __( 'Booking type updated.', 'g2a-booking' );
		} else {
			$data['created_at'] = $now; $fmt[] = '%s';
			$wpdb->insert( $tbl, $data, $fmt );
			$id = (int) $wpdb->insert_id;
			$msg = __( 'Booking type created.', 'g2a-booking' );
		}
		do_action( 'g2ab_booking_type_saved', $id, $data );
		$this->set_notice( 'success', $msg );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	public function handle_delete() {
		if ( ! current_user_can( 'manage_g2ab_settings' ) ) wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
		$id = (int) ( $_POST['id'] ?? 0 );
		check_admin_referer( 'g2ab_delete_booking_type_' . $id, '_g2ab_nonce' );
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'g2ab_booking_types', array( 'id' => $id ), array( '%d' ) );
		do_action( 'g2ab_booking_type_deleted', $id );
		$this->set_notice( 'success', __( 'Booking type deleted.', 'g2a-booking' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	public function handle_toggle() {
		if ( ! current_user_can( 'manage_g2ab_settings' ) ) wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
		$id = (int) ( $_POST['id'] ?? 0 );
		check_admin_referer( 'g2ab_toggle_booking_type_' . $id, '_g2ab_nonce' );
		global $wpdb;
		$tbl = $wpdb->prefix . 'g2ab_booking_types';
		$cur = (int) $wpdb->get_var( $wpdb->prepare( "SELECT is_active FROM {$tbl} WHERE id = %d", $id ) );
		$new = 1 - $cur;
		$wpdb->update( $tbl, array( 'is_active' => $new, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ), array( '%d', '%s' ), array( '%d' ) );
		$this->set_notice( 'success', $new ? __( 'Type enabled.', 'g2a-booking' ) : __( 'Type disabled.', 'g2a-booking' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Return the active membership integration label, or '' when none is active.
	 * Used to hide members-only controls from the booking type editor when neither
	 * PMPro nor Memberistic is wired up.
	 */
	private function detect_membership_integration() {
		$active_modules = (array) get_option( 'g2ab_addons_active', array() );

		if ( in_array( 'pmpro_memberships', $active_modules, true ) && ( defined( 'PMPRO_VERSION' ) || function_exists( 'pmpro_hasMembershipLevel' ) ) ) {
			return 'PMPro';
		}
		if ( in_array( 'memberistic', $active_modules, true ) ) {
			return 'Memberistic';
		}
		// Filter for custom integrations.
		return (string) apply_filters( 'g2ab_membership_integration_label', '' );
	}

	private function ensure_unique_slug( $slug, $exclude_id = 0 ) {
		global $wpdb;
		$tbl = $wpdb->prefix . 'g2ab_booking_types';
		$base = $slug; $i = 2;
		while ( true ) {
			$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tbl} WHERE slug = %s AND id != %d LIMIT 1", $slug, $exclude_id ) );
			if ( ! $existing ) return $slug;
			$slug = $base . '-' . $i; $i++;
			if ( $i > 100 ) return $slug;
		}
	}

	private function set_notice( $type, $msg ) { set_transient( 'g2ab_bt_notice_' . get_current_user_id(), array( 'type' => $type, 'msg' => $msg ), 30 ); }
	private function get_notice() {
		$key = 'g2ab_bt_notice_' . get_current_user_id();
		$n = get_transient( $key );
		if ( ! $n ) return '';
		delete_transient( $key );
		$cls = 'notice-' . ( 'error' === $n['type'] ? 'error' : 'success' );
		return '<div class="notice ' . esc_attr( $cls ) . ' is-dismissible"><p>' . esc_html( $n['msg'] ) . '</p></div>';
	}

	private function print_styles() {
		static $done = false; if ( $done ) return; $done = true;
		echo '<style>
.g2ab-bt{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}
.g2ab-bt__header{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:20px;background:linear-gradient(135deg,#0F1115 0%,#1A1F26 100%);color:#E8E8E8;padding:24px 28px;margin:20px 0 0;border-left:4px solid #D2691E;position:relative;overflow:hidden;}
.g2ab-bt__header::before{content:"";position:absolute;inset:0;background-image:repeating-linear-gradient(45deg,rgba(74,93,58,.05) 0,rgba(74,93,58,.05) 2px,transparent 2px,transparent 8px);pointer-events:none;}
.g2ab-bt__stencil{font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;font-size:30px;font-weight:700;letter-spacing:.04em;color:#fff;}
.g2ab-bt__sub{margin:4px 0 0;color:#8A95A5;font-size:13px;text-transform:uppercase;letter-spacing:.08em;}
.g2ab-bt__tabs{background:#fff;border:1px solid #d0d4d9;border-top:none;display:flex;flex-wrap:wrap;}
.g2ab-bt__tabs a{padding:14px 20px;text-decoration:none;color:#3c434a;font-size:12px;font-weight:700;letter-spacing:.08em;border-right:1px solid #f0f1f3;display:inline-flex;align-items:center;gap:6px;}
.g2ab-bt__tabs a:hover{background:#f8f9fa;}
.g2ab-bt__tabs a.is-active{background:#0F1115;color:#fff;border-right-color:#D2691E;}
.g2ab-bt__tabs a span{background:rgba(0,0,0,.08);padding:1px 7px;border-radius:10px;font-size:11px;}
.g2ab-bt__tabs a.is-active span{background:#D2691E;color:#fff;}
.g2ab-bt__btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#fff;color:#0F1115;border:1px solid #d0d4d9;text-decoration:none;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;border-radius:2px;cursor:pointer;}
.g2ab-bt__btn:hover{background:#f8f9fa;color:#D2691E;}
.g2ab-bt__btn--primary{background:#D2691E;color:#fff;border-color:#D2691E;padding:10px 20px;}
.g2ab-bt__btn--primary:hover{background:#0F1115;color:#fff;border-color:#0F1115;}
.g2ab-bt__btn--danger{color:#C62828;border-color:#fdd;}
.g2ab-bt__btn--danger:hover{background:#C62828;color:#fff;border-color:#C62828;}
.g2ab-bt__grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(310px,1fr));gap:16px;margin-top:18px;}
.g2ab-bt__card{background:#fff;border:1px solid #d0d4d9;padding:22px;position:relative;transition:all .15s;border-top:4px solid #4A5D3A;}
.g2ab-bt__card--lane{border-top-color:#D2691E;}
.g2ab-bt__card--class{border-top-color:#4A5D3A;}
.g2ab-bt__card--membership{border-top-color:#F9A825;}
.g2ab-bt__card.is-inactive{opacity:.55;}
.g2ab-bt__card:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,0,0,.08);}
.g2ab-bt__card-tag{position:absolute;top:0;right:0;background:#0F1115;color:#fff;font-size:9px;letter-spacing:.1em;padding:3px 10px;font-weight:700;}
.g2ab-bt__card-title{font-size:18px;margin:0 0 4px;color:#0F1115;}
.g2ab-bt__card-slug{margin-bottom:10px;font-size:11px;color:#8A95A5;}
.g2ab-bt__card-slug code{background:#f0f1f3;padding:2px 6px;border-radius:2px;color:#3c434a;}
.g2ab-bt__card-price{font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;font-size:32px;font-weight:700;color:#D2691E;margin-bottom:12px;line-height:1;}
.g2ab-bt__card-price small{font-size:13px;color:#8A95A5;font-weight:600;margin-left:4px;}
.g2ab-bt__card-specs{margin:0 0 12px;display:grid;grid-template-columns:90px 1fr;gap:5px 12px;font-size:12px;}
.g2ab-bt__card-specs dt{color:#8A95A5;text-transform:uppercase;font-size:10px;letter-spacing:.04em;align-self:center;}
.g2ab-bt__card-specs dd{margin:0;color:#3c434a;font-weight:500;}
.g2ab-bt__pill{display:inline-block;background:#f0f1f3;color:#3c434a;padding:2px 7px;font-size:9px;letter-spacing:.04em;font-weight:700;margin:1px 2px 1px 0;border-radius:2px;}
.g2ab-bt__card-flags{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 12px;}
.g2ab-bt__flag{display:inline-block;background:rgba(210,105,30,.12);color:#D2691E;padding:3px 8px;font-size:9px;letter-spacing:.06em;font-weight:700;border-radius:2px;}
.g2ab-bt__flag--gold{background:rgba(249,168,37,.15);color:#9c6500;}
.g2ab-bt__card-actions{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;}
.g2ab-bt__card-status{display:flex;align-items:center;gap:6px;font-size:10px;letter-spacing:.1em;color:#8A95A5;font-weight:700;border-top:1px solid #f0f1f3;padding-top:10px;}
.g2ab-bt__dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:#ccc;}
.g2ab-bt__dot--ok{background:#4CAF50;animation:g2abBtPulse 2s infinite;}
.g2ab-bt__dot--off{background:#C62828;}
@keyframes g2abBtPulse{0%{box-shadow:0 0 0 0 rgba(76,175,80,.6);}70%{box-shadow:0 0 0 8px rgba(76,175,80,0);}100%{box-shadow:0 0 0 0 rgba(76,175,80,0);}}
.g2ab-bt__empty{background:#fff;padding:60px 20px;text-align:center;border:1px dashed #d0d4d9;}
.g2ab-bt__empty-icon{font-size:48px;color:#8A95A5;margin-bottom:12px;}
.g2ab-bt__form{background:#fff;border:1px solid #d0d4d9;border-top:none;}
.g2ab-bt__form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:0;}
.g2ab-bt__panel{padding:24px 28px;border-right:1px solid #f0f1f3;border-bottom:1px solid #f0f1f3;}
.g2ab-bt__panel h3{font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;font-size:13px;letter-spacing:.12em;color:#D2691E;margin:0 0 16px;font-weight:700;}
.g2ab-bt__desc{color:#8A95A5;font-size:12px;margin:0 0 12px;}
.g2ab-bt__field{margin-bottom:16px;}
.g2ab-bt__field label{display:block;font-size:11px;font-weight:700;letter-spacing:.06em;color:#3c434a;text-transform:uppercase;margin-bottom:6px;}
.g2ab-bt__field label .req{color:#C62828;}
.g2ab-bt__field input[type="text"],.g2ab-bt__field input[type="number"],.g2ab-bt__field select,.g2ab-bt__field textarea{width:100%;padding:9px 12px;border:1px solid #d0d4d9;border-radius:2px;font-size:14px;font-family:inherit;}
.g2ab-bt__field input:focus,.g2ab-bt__field select:focus,.g2ab-bt__field textarea:focus{outline:none;border-color:#D2691E;box-shadow:0 0 0 2px rgba(210,105,30,.15);}
.g2ab-bt__field small{display:block;color:#8A95A5;font-size:12px;margin-top:4px;}
.g2ab-bt__check{display:inline-flex;align-items:center;gap:6px;font-size:13px;color:#3c434a;cursor:pointer;text-transform:none;letter-spacing:0;font-weight:400;margin-bottom:0;}
.g2ab-bt__check input{width:16px;height:16px;accent-color:#D2691E;margin:0;}
.g2ab-bt__check-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:6px 12px;}
.g2ab-bt__form-actions{padding:20px 28px;background:#f8f9fa;border-top:1px solid #f0f1f3;display:flex;gap:10px;}
</style>';
	}
}
