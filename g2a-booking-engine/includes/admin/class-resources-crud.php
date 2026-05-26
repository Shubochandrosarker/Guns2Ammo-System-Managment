<?php
/**
 * Admin Resources CRUD — full add/edit/delete for lanes, classrooms, instructors, packages.
 *
 * @package G2AB
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class G2AB_Admin_Resources_Crud {

	private static $instance = null;
	const PAGE_SLUG = 'g2ab-resources';
	const TYPES = array( 'lane', 'classroom', 'instructor', 'package' );

	public static function instance() {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'override_menu' ), 11 );
		add_action( 'admin_post_g2ab_save_resource', array( $this, 'handle_save' ) );
		add_action( 'admin_post_g2ab_delete_resource', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_g2ab_toggle_resource', array( $this, 'handle_toggle' ) );
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
		add_submenu_page( 'g2ab-bookings', __( 'Resources', 'g2a-booking' ), __( 'Resources', 'g2a-booking' ), 'manage_g2ab_resources', self::PAGE_SLUG, array( $this, 'render' ) );
		if ( $idx >= 0 && isset( $submenu['g2ab-bookings'] ) ) {
			$new = array_pop( $submenu['g2ab-bookings'] );
			array_splice( $submenu['g2ab-bookings'], $idx, 0, array( $new ) );
		}
	}

	public function render() {
		if ( ! current_user_can( 'manage_g2ab_resources' ) ) wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';
		$this->print_styles();
		if ( in_array( $action, array( 'add', 'edit' ), true ) ) $this->render_form( $action );
		else $this->render_list();
	}

	private function render_list() {
		global $wpdb;
		$tbl = $wpdb->prefix . 'g2ab_resources';
		$type_filter = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : '';
		$where = ' WHERE 1=1 '; $args = array();
		if ( $type_filter && in_array( $type_filter, self::TYPES, true ) ) { $where .= ' AND type = %s '; $args[] = $type_filter; }
		$sql = "SELECT * FROM {$tbl} {$where} ORDER BY sort_order ASC, id ASC";
		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql );
		$counts = $wpdb->get_results( "SELECT type, COUNT(*) c FROM {$tbl} GROUP BY type", OBJECT_K );
		$total = $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" );
		$msg = $this->get_notice();
		?>
		<div class="wrap g2ab-admin g2ab-rs">
			<div class="g2ab-rs__header">
				<div>
					<h1><span class="g2ab-rs__stencil"><?php esc_html_e( 'RESOURCES', 'g2a-booking' ); ?></span></h1>
					<p class="g2ab-rs__sub"><?php esc_html_e( 'Lanes · classrooms · instructors · packages', 'g2a-booking' ); ?></p>
				</div>
				<a class="g2ab-rs__btn g2ab-rs__btn--primary" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'action' => 'add' ), admin_url( 'admin.php' ) ) ); ?>"><span>+</span> <?php esc_html_e( 'NEW RESOURCE', 'g2a-booking' ); ?></a>
			</div>
			<?php echo $msg; ?>
			<nav class="g2ab-rs__tabs">
				<a class="<?php echo '' === $type_filter ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>"><?php esc_html_e( 'ALL', 'g2a-booking' ); ?> <span><?php echo (int) $total; ?></span></a>
				<?php foreach ( self::TYPES as $t ) : $c = isset( $counts[ $t ] ) ? (int) $counts[ $t ]->c : 0; ?>
					<a class="<?php echo $type_filter === $t ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'type' => $t ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( strtoupper( $t ) ); ?>S <span><?php echo $c; ?></span></a>
				<?php endforeach; ?>
			</nav>
			<?php if ( empty( $rows ) ) : ?>
				<div class="g2ab-rs__empty">
					<div class="g2ab-rs__empty-icon">⊕</div>
					<h2><?php esc_html_e( 'No resources here yet.', 'g2a-booking' ); ?></h2>
					<p><?php esc_html_e( 'Add a lane, classroom, instructor, or package to start booking.', 'g2a-booking' ); ?></p>
					<a class="g2ab-rs__btn g2ab-rs__btn--primary" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'action' => 'add' ), admin_url( 'admin.php' ) ) ); ?>">+ <?php esc_html_e( 'NEW RESOURCE', 'g2a-booking' ); ?></a>
				</div>
			<?php else : ?>
				<div class="g2ab-rs__grid">
					<?php foreach ( $rows as $r ) : ?>
						<article class="g2ab-rs__card g2ab-rs__card--<?php echo esc_attr( $r->type ); ?> <?php echo $r->is_active ? '' : 'is-inactive'; ?>">
							<div class="g2ab-rs__card-tag"><?php echo esc_html( strtoupper( $r->type ) ); ?></div>
							<div class="g2ab-rs__card-icon"><?php echo esc_html( $this->type_icon( $r->type ) ); ?></div>
							<h3 class="g2ab-rs__card-title"><?php echo esc_html( $r->name ); ?></h3>
							<div class="g2ab-rs__card-slug"><code><?php echo esc_html( $r->slug ); ?></code></div>
							<div class="g2ab-rs__card-meta">
								<span><?php esc_html_e( 'Capacity', 'g2a-booking' ); ?>: <strong><?php echo (int) $r->capacity; ?></strong></span>
								<span><?php esc_html_e( 'Sort', 'g2a-booking' ); ?>: <strong><?php echo (int) $r->sort_order; ?></strong></span>
							</div>
							<?php if ( $r->description ) : ?><p class="g2ab-rs__card-desc"><?php echo esc_html( wp_trim_words( $r->description, 18 ) ); ?></p><?php endif; ?>
							<div class="g2ab-rs__card-actions">
								<a class="g2ab-rs__btn" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'action' => 'edit', 'id' => $r->id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'EDIT', 'g2a-booking' ); ?></a>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
									<?php wp_nonce_field( 'g2ab_toggle_resource_' . $r->id, '_g2ab_nonce' ); ?>
									<input type="hidden" name="action" value="g2ab_toggle_resource" />
									<input type="hidden" name="id" value="<?php echo (int) $r->id; ?>" />
									<button class="g2ab-rs__btn"><?php echo $r->is_active ? esc_html__( 'DISABLE', 'g2a-booking' ) : esc_html__( 'ENABLE', 'g2a-booking' ); ?></button>
								</form>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this resource?', 'g2a-booking' ) ); ?>');">
									<?php wp_nonce_field( 'g2ab_delete_resource_' . $r->id, '_g2ab_nonce' ); ?>
									<input type="hidden" name="action" value="g2ab_delete_resource" />
									<input type="hidden" name="id" value="<?php echo (int) $r->id; ?>" />
									<button class="g2ab-rs__btn g2ab-rs__btn--danger"><?php esc_html_e( 'DELETE', 'g2a-booking' ); ?></button>
								</form>
							</div>
							<div class="g2ab-rs__card-status">
								<span class="g2ab-rs__dot <?php echo $r->is_active ? 'g2ab-rs__dot--ok' : 'g2ab-rs__dot--off'; ?>"></span>
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
		$tbl = $wpdb->prefix . 'g2ab_resources';
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$row = ( 'edit' === $action && $id ) ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d", $id ) ) : null;
		if ( 'edit' === $action && ! $row ) { echo '<div class="wrap"><h1>Resource not found</h1></div>'; return; }
		$is_edit = (bool) $row;
		$settings = $row ? json_decode( (string) $row->settings, true ) : array();
		if ( ! is_array( $settings ) ) $settings = array();
		$allowed_calibers = is_array( $settings['allowed_calibers'] ?? null ) ? $settings['allowed_calibers'] : array();
		?>
		<div class="wrap g2ab-admin g2ab-rs">
			<div class="g2ab-rs__header">
				<div>
					<h1><span class="g2ab-rs__stencil"><?php echo $is_edit ? esc_html__( 'EDIT RESOURCE', 'g2a-booking' ) : esc_html__( 'NEW RESOURCE', 'g2a-booking' ); ?></span></h1>
					<p class="g2ab-rs__sub"><?php echo $is_edit ? esc_html__( 'Modify resource intel', 'g2a-booking' ) : esc_html__( 'Provision a new bookable unit', 'g2a-booking' ); ?></p>
				</div>
				<a class="g2ab-rs__btn" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">← <?php esc_html_e( 'CANCEL', 'g2a-booking' ); ?></a>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="g2ab-rs__form">
				<?php wp_nonce_field( 'g2ab_save_resource', '_g2ab_nonce' ); ?>
				<input type="hidden" name="action" value="g2ab_save_resource" />
				<?php if ( $is_edit ) : ?><input type="hidden" name="id" value="<?php echo (int) $row->id; ?>" /><?php endif; ?>
				<div class="g2ab-rs__form-grid">
					<div class="g2ab-rs__panel">
						<h3><?php esc_html_e( 'IDENTITY', 'g2a-booking' ); ?></h3>
						<div class="g2ab-rs__field"><label><?php esc_html_e( 'Name', 'g2a-booking' ); ?> <span class="req">*</span></label><input type="text" name="name" required value="<?php echo esc_attr( $row->name ?? '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. Lane 7', 'g2a-booking' ); ?>" /></div>
						<div class="g2ab-rs__field"><label><?php esc_html_e( 'Slug', 'g2a-booking' ); ?></label><input type="text" name="slug" value="<?php echo esc_attr( $row->slug ?? '' ); ?>" placeholder="<?php esc_attr_e( 'auto from name', 'g2a-booking' ); ?>" /><small><?php esc_html_e( 'Lowercase, hyphens. Auto-generated if empty.', 'g2a-booking' ); ?></small></div>
						<div class="g2ab-rs__field"><label><?php esc_html_e( 'Type', 'g2a-booking' ); ?> <span class="req">*</span></label><select name="type" required><?php foreach ( self::TYPES as $t ) : ?><option value="<?php echo esc_attr( $t ); ?>" <?php selected( $row->type ?? 'lane', $t ); ?>><?php echo esc_html( ucfirst( $t ) ); ?></option><?php endforeach; ?></select></div>
						<div class="g2ab-rs__field"><label><?php esc_html_e( 'Description', 'g2a-booking' ); ?></label><textarea name="description" rows="3"><?php echo esc_textarea( $row->description ?? '' ); ?></textarea></div>
					</div>
					<div class="g2ab-rs__panel">
						<h3><?php esc_html_e( 'CAPACITY & ORDER', 'g2a-booking' ); ?></h3>
						<div class="g2ab-rs__field"><label><?php esc_html_e( 'Capacity', 'g2a-booking' ); ?></label><input type="number" name="capacity" min="1" max="500" value="<?php echo esc_attr( $row->capacity ?? 1 ); ?>" /><small><?php esc_html_e( 'Lanes = 1, classrooms = seat count.', 'g2a-booking' ); ?></small></div>
						<div class="g2ab-rs__field"><label><?php esc_html_e( 'Sort Order', 'g2a-booking' ); ?></label><input type="number" name="sort_order" min="0" max="9999" value="<?php echo esc_attr( $row->sort_order ?? 0 ); ?>" /><small><?php esc_html_e( 'Lower = first.', 'g2a-booking' ); ?></small></div>
						<div class="g2ab-rs__field"><label class="g2ab-rs__check"><input type="checkbox" name="is_active" value="1" <?php checked( 1, (int) ( $row->is_active ?? 1 ) ); ?> /> <?php esc_html_e( 'Active and bookable', 'g2a-booking' ); ?></label></div>
					</div>
					<div class="g2ab-rs__panel">
						<h3><?php esc_html_e( 'LANE / RANGE OPTIONS', 'g2a-booking' ); ?></h3>
						<div class="g2ab-rs__field"><label><?php esc_html_e( 'Allowed Calibers', 'g2a-booking' ); ?></label><div class="g2ab-rs__check-grid"><?php foreach ( array( 'pistol', 'rifle', 'shotgun', 'rimfire', 'centerfire', 'magnum' ) as $cal ) : ?><label class="g2ab-rs__check"><input type="checkbox" name="settings[allowed_calibers][]" value="<?php echo esc_attr( $cal ); ?>" <?php checked( in_array( $cal, $allowed_calibers, true ) ); ?> /> <?php echo esc_html( ucfirst( $cal ) ); ?></label><?php endforeach; ?></div></div>
						<div class="g2ab-rs__field"><label><?php esc_html_e( 'Internal Notes', 'g2a-booking' ); ?></label><textarea name="settings[notes]" rows="2"><?php echo esc_textarea( $settings['notes'] ?? '' ); ?></textarea><small><?php esc_html_e( 'Staff-only.', 'g2a-booking' ); ?></small></div>
					</div>
				</div>
				<div class="g2ab-rs__form-actions">
					<button type="submit" class="g2ab-rs__btn g2ab-rs__btn--primary"><?php echo $is_edit ? esc_html__( 'UPDATE RESOURCE', 'g2a-booking' ) : esc_html__( 'CREATE RESOURCE', 'g2a-booking' ); ?></button>
					<a class="g2ab-rs__btn" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>"><?php esc_html_e( 'CANCEL', 'g2a-booking' ); ?></a>
				</div>
			</form>
		</div>
		<?php
	}

	public function handle_save() {
		if ( ! current_user_can( 'manage_g2ab_resources' ) ) wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
		check_admin_referer( 'g2ab_save_resource', '_g2ab_nonce' );
		global $wpdb;
		$tbl = $wpdb->prefix . 'g2ab_resources';
		$id = (int) ( $_POST['id'] ?? 0 );
		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$slug = sanitize_title( wp_unslash( $_POST['slug'] ?? '' ) );
		$type = sanitize_key( wp_unslash( $_POST['type'] ?? 'lane' ) );
		$desc = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
		$cap = max( 1, (int) ( $_POST['capacity'] ?? 1 ) );
		$sort = max( 0, (int) ( $_POST['sort_order'] ?? 0 ) );
		$active = isset( $_POST['is_active'] ) ? 1 : 0;

		if ( '' === $name ) { $this->set_notice( 'error', __( 'Name is required.', 'g2a-booking' ) ); wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); exit; }
		if ( ! in_array( $type, self::TYPES, true ) ) $type = 'lane';
		if ( '' === $slug ) $slug = sanitize_title( $name );
		$slug = $this->ensure_unique_slug( $slug, $id );

		$settings_in = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();
		$settings = array(
			'allowed_calibers' => isset( $settings_in['allowed_calibers'] ) && is_array( $settings_in['allowed_calibers'] ) ? array_map( 'sanitize_key', $settings_in['allowed_calibers'] ) : array(),
			'notes' => isset( $settings_in['notes'] ) ? sanitize_textarea_field( $settings_in['notes'] ) : '',
		);

		$now = current_time( 'mysql' );
		$data = array( 'name' => $name, 'slug' => $slug, 'type' => $type, 'capacity' => $cap, 'description' => $desc, 'settings' => wp_json_encode( $settings ), 'sort_order' => $sort, 'is_active' => $active, 'updated_at' => $now );
		$fmt = array( '%s','%s','%s','%d','%s','%s','%d','%d','%s' );

		if ( $id ) {
			$wpdb->update( $tbl, $data, array( 'id' => $id ), $fmt, array( '%d' ) );
			$msg = __( 'Resource updated.', 'g2a-booking' );
		} else {
			$data['created_at'] = $now; $fmt[] = '%s';
			$wpdb->insert( $tbl, $data, $fmt );
			$id = (int) $wpdb->insert_id;
			$msg = __( 'Resource created.', 'g2a-booking' );
		}
		do_action( 'g2ab_resource_saved', $id, $data );
		$this->set_notice( 'success', $msg );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	public function handle_delete() {
		if ( ! current_user_can( 'manage_g2ab_resources' ) ) wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
		$id = (int) ( $_POST['id'] ?? 0 );
		check_admin_referer( 'g2ab_delete_resource_' . $id, '_g2ab_nonce' );
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'g2ab_resources', array( 'id' => $id ), array( '%d' ) );
		do_action( 'g2ab_resource_deleted', $id );
		$this->set_notice( 'success', __( 'Resource deleted.', 'g2a-booking' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	public function handle_toggle() {
		if ( ! current_user_can( 'manage_g2ab_resources' ) ) wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
		$id = (int) ( $_POST['id'] ?? 0 );
		check_admin_referer( 'g2ab_toggle_resource_' . $id, '_g2ab_nonce' );
		global $wpdb;
		$tbl = $wpdb->prefix . 'g2ab_resources';
		$current = (int) $wpdb->get_var( $wpdb->prepare( "SELECT is_active FROM {$tbl} WHERE id = %d", $id ) );
		$new = 1 - $current;
		$wpdb->update( $tbl, array( 'is_active' => $new, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ), array( '%d', '%s' ), array( '%d' ) );
		$this->set_notice( 'success', $new ? __( 'Resource enabled.', 'g2a-booking' ) : __( 'Resource disabled.', 'g2a-booking' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	private function ensure_unique_slug( $slug, $exclude_id = 0 ) {
		global $wpdb;
		$tbl = $wpdb->prefix . 'g2ab_resources';
		$base = $slug; $i = 2;
		while ( true ) {
			$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tbl} WHERE slug = %s AND id != %d LIMIT 1", $slug, $exclude_id ) );
			if ( ! $existing ) return $slug;
			$slug = $base . '-' . $i; $i++;
			if ( $i > 100 ) return $slug;
		}
	}

	private function set_notice( $type, $msg ) { set_transient( 'g2ab_rs_notice_' . get_current_user_id(), array( 'type' => $type, 'msg' => $msg ), 30 ); }
	private function get_notice() {
		$key = 'g2ab_rs_notice_' . get_current_user_id();
		$n = get_transient( $key );
		if ( ! $n ) return '';
		delete_transient( $key );
		$class = 'notice-' . ( 'error' === $n['type'] ? 'error' : 'success' );
		return '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $n['msg'] ) . '</p></div>';
	}
	private function type_icon( $type ) { return array( 'lane' => '◎', 'classroom' => '▦', 'instructor' => '✪', 'package' => '◆' )[ $type ] ?? '○'; }

	private function print_styles() {
		static $done = false; if ( $done ) return; $done = true;
		echo '<style>
.g2ab-rs{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}
.g2ab-rs__header{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:20px;background:linear-gradient(135deg,#0F1115 0%,#1A1F26 100%);color:#E8E8E8;padding:24px 28px;margin:20px 0 0;border-left:4px solid #D2691E;position:relative;overflow:hidden;}
.g2ab-rs__header::before{content:"";position:absolute;inset:0;background-image:repeating-linear-gradient(45deg,rgba(74,93,58,.05) 0,rgba(74,93,58,.05) 2px,transparent 2px,transparent 8px);pointer-events:none;}
.g2ab-rs__stencil{font-family:"Rajdhani","Oswald",Impact,sans-serif;font-size:30px;font-weight:700;letter-spacing:.12em;color:#fff;text-shadow:2px 2px 0 #4A5D3A;}
.g2ab-rs__sub{margin:4px 0 0;color:#8A95A5;font-size:13px;text-transform:uppercase;letter-spacing:.08em;}
.g2ab-rs__tabs{background:#fff;border:1px solid #d0d4d9;border-top:none;display:flex;flex-wrap:wrap;}
.g2ab-rs__tabs a{padding:14px 20px;text-decoration:none;color:#3c434a;font-size:12px;font-weight:700;letter-spacing:.08em;border-right:1px solid #f0f1f3;display:inline-flex;align-items:center;gap:6px;}
.g2ab-rs__tabs a:hover{background:#f8f9fa;}
.g2ab-rs__tabs a.is-active{background:#0F1115;color:#fff;border-right-color:#D2691E;}
.g2ab-rs__tabs a span{background:rgba(0,0,0,.08);padding:1px 7px;border-radius:10px;font-size:11px;}
.g2ab-rs__tabs a.is-active span{background:#D2691E;color:#fff;}
.g2ab-rs__btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#fff;color:#0F1115;border:1px solid #d0d4d9;text-decoration:none;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;border-radius:2px;cursor:pointer;}
.g2ab-rs__btn:hover{background:#f8f9fa;color:#D2691E;}
.g2ab-rs__btn--primary{background:#D2691E;color:#fff;border-color:#D2691E;padding:10px 20px;}
.g2ab-rs__btn--primary:hover{background:#0F1115;color:#fff;border-color:#0F1115;}
.g2ab-rs__btn--danger{color:#C62828;border-color:#fdd;}
.g2ab-rs__btn--danger:hover{background:#C62828;color:#fff;border-color:#C62828;}
.g2ab-rs__grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;margin-top:18px;}
.g2ab-rs__card{background:#fff;border:1px solid #d0d4d9;padding:20px;position:relative;transition:all .15s;border-top:4px solid #4A5D3A;}
.g2ab-rs__card--lane{border-top-color:#D2691E;}
.g2ab-rs__card--classroom{border-top-color:#4A5D3A;}
.g2ab-rs__card--instructor{border-top-color:#0F1115;}
.g2ab-rs__card--package{border-top-color:#F9A825;}
.g2ab-rs__card.is-inactive{opacity:.5;}
.g2ab-rs__card:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,0,0,.08);}
.g2ab-rs__card-tag{position:absolute;top:0;right:0;background:#0F1115;color:#fff;font-size:9px;letter-spacing:.1em;padding:3px 10px;font-weight:700;}
.g2ab-rs__card-icon{font-size:34px;color:#D2691E;line-height:1;margin-bottom:8px;}
.g2ab-rs__card-title{font-size:18px;margin:0 0 4px;color:#0F1115;}
.g2ab-rs__card-slug{margin-bottom:10px;font-size:11px;color:#8A95A5;}
.g2ab-rs__card-slug code{background:#f0f1f3;padding:2px 6px;border-radius:2px;color:#3c434a;}
.g2ab-rs__card-meta{display:flex;gap:16px;font-size:12px;color:#3c434a;margin-bottom:10px;}
.g2ab-rs__card-meta strong{color:#0F1115;}
.g2ab-rs__card-desc{font-size:13px;color:#3c434a;margin:0 0 12px;line-height:1.5;}
.g2ab-rs__card-actions{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;}
.g2ab-rs__card-status{display:flex;align-items:center;gap:6px;font-size:10px;letter-spacing:.1em;color:#8A95A5;font-weight:700;border-top:1px solid #f0f1f3;padding-top:10px;}
.g2ab-rs__dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:#ccc;}
.g2ab-rs__dot--ok{background:#4CAF50;animation:g2abPulse 2s infinite;}
.g2ab-rs__dot--off{background:#C62828;}
@keyframes g2abPulse{0%{box-shadow:0 0 0 0 rgba(76,175,80,.6);}70%{box-shadow:0 0 0 8px rgba(76,175,80,0);}100%{box-shadow:0 0 0 0 rgba(76,175,80,0);}}
.g2ab-rs__empty{background:#fff;padding:60px 20px;text-align:center;border:1px dashed #d0d4d9;}
.g2ab-rs__empty-icon{font-size:48px;color:#8A95A5;margin-bottom:12px;}
.g2ab-rs__form{background:#fff;border:1px solid #d0d4d9;border-top:none;}
.g2ab-rs__form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:0;}
.g2ab-rs__panel{padding:24px 28px;border-right:1px solid #f0f1f3;border-bottom:1px solid #f0f1f3;}
.g2ab-rs__panel h3{font-family:"Rajdhani",sans-serif;font-size:13px;letter-spacing:.12em;color:#D2691E;margin:0 0 16px;font-weight:700;}
.g2ab-rs__field{margin-bottom:16px;}
.g2ab-rs__field label{display:block;font-size:11px;font-weight:700;letter-spacing:.06em;color:#3c434a;text-transform:uppercase;margin-bottom:6px;}
.g2ab-rs__field label .req{color:#C62828;}
.g2ab-rs__field input[type="text"],.g2ab-rs__field input[type="number"],.g2ab-rs__field select,.g2ab-rs__field textarea{width:100%;padding:9px 12px;border:1px solid #d0d4d9;border-radius:2px;font-size:14px;font-family:inherit;}
.g2ab-rs__field input:focus,.g2ab-rs__field select:focus,.g2ab-rs__field textarea:focus{outline:none;border-color:#D2691E;box-shadow:0 0 0 2px rgba(210,105,30,.15);}
.g2ab-rs__field small{display:block;color:#8A95A5;font-size:12px;margin-top:4px;}
.g2ab-rs__check{display:inline-flex;align-items:center;gap:6px;font-size:13px;color:#3c434a;cursor:pointer;text-transform:none;letter-spacing:0;font-weight:400;margin-bottom:0;}
.g2ab-rs__check input{width:16px;height:16px;accent-color:#D2691E;margin:0;}
.g2ab-rs__check-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:6px 12px;}
.g2ab-rs__form-actions{padding:20px 28px;background:#f8f9fa;border-top:1px solid #f0f1f3;display:flex;gap:10px;}
</style>';
	}
}
