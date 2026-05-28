<?php
/**
 * Corporate / Group Membership — Phase 1.
 *
 * A module INSIDE Memberistic (not a separate plugin) so it reuses
 * the existing members, people+waivers, check-ins, QR verification,
 * email logs, activity log, and Stripe infrastructure instead of
 * duplicating eight tables. See docs/CORPORATE_GROUPS_PLAN.md for
 * the full audit + phased plan.
 *
 * Phase 1 delivers: the group-specific schema (groups, members,
 * group-level payments, payment links), capabilities, a repository
 * layer, and the admin "Corporate Groups" list + create flow with
 * nonces, capability checks, sanitization, and activity logging.
 *
 * Later phases (member linking + confirmations, payment links,
 * waiver automation, QR/check-in, front-end UX, reports) build on
 * this foundation and are scoped in the plan doc.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Corporate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module bootstrap + schema owner.
 */
final class Corporate_Module {

	/** Bump when any corporate table changes. */
	const DB_VERSION = '1.0.0';
	const DB_OPTION  = 'memberistic_corporate_db_version';

	/**
	 * Wire the module. Called from the main plugin's register_hooks.
	 */
	public static function register() {
		// Idempotent schema guard — runs on admin_init, cheap.
		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
		// Capabilities onto admin/staff roles.
		add_action( 'admin_init', array( Corporate_Capabilities::class, 'maybe_assign' ) );
		// Admin UI.
		if ( is_admin() ) {
			add_action( 'admin_menu', array( Corporate_Admin::class, 'menu' ), 30 );
			add_action( 'admin_post_memberistic_corp_create', array( Corporate_Admin::class, 'handle_create' ) );
			add_action( 'admin_post_memberistic_corp_update', array( Corporate_Admin::class, 'handle_update' ) );
			add_action( 'admin_enqueue_scripts', array( Corporate_Admin::class, 'assets' ) );
		}
	}

	public static function maybe_install() {
		if ( get_option( self::DB_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Create / upgrade the four group-specific tables. Additive,
	 * dbDelta-safe, never destructive.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix;

		$groups = "CREATE TABLE {$p}memberistic_corporate_groups (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			group_name VARCHAR(191) NOT NULL DEFAULT '',
			company_name VARCHAR(191) NOT NULL DEFAULT '',
			primary_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			plan_key VARCHAR(100) NOT NULL DEFAULT 'corporate',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			seats_total INT UNSIGNED NOT NULL DEFAULT 0,
			seats_used INT UNSIGNED NOT NULL DEFAULT 0,
			max_future_seats INT UNSIGNED NOT NULL DEFAULT 0,
			custom_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid',
			payment_method VARCHAR(30) NOT NULL DEFAULT '',
			visibility VARCHAR(20) NOT NULL DEFAULT 'private',
			admin_notes LONGTEXT NULL,
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY primary_user_id (primary_user_id),
			KEY status (status),
			KEY visibility (visibility)
		) {$charset};";

		$members = "CREATE TABLE {$p}memberistic_group_members (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			group_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			membership_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			role VARCHAR(20) NOT NULL DEFAULT 'member',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			waiver_status VARCHAR(50) NOT NULL DEFAULT 'missing',
			qr_token VARCHAR(64) NOT NULL DEFAULT '',
			invited_at DATETIME NULL,
			joined_at DATETIME NULL,
			removed_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY group_id (group_id),
			KEY user_id (user_id),
			KEY status (status),
			UNIQUE KEY group_user (group_id, user_id)
		) {$charset};";

		$payments = "CREATE TABLE {$p}memberistic_group_payments (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			group_id BIGINT UNSIGNED NOT NULL,
			amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			payment_method VARCHAR(30) NOT NULL DEFAULT '',
			payment_status VARCHAR(20) NOT NULL DEFAULT 'completed',
			payment_reference VARCHAR(191) NOT NULL DEFAULT '',
			wc_order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			payment_link_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			notes LONGTEXT NULL,
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY group_id (group_id),
			KEY payment_status (payment_status)
		) {$charset};";

		$links = "CREATE TABLE {$p}memberistic_payment_links (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			group_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			description VARCHAR(255) NOT NULL DEFAULT '',
			recipient_email VARCHAR(191) NOT NULL DEFAULT '',
			token VARCHAR(64) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'unpaid',
			wc_order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			expires_at DATETIME NULL,
			paid_at DATETIME NULL,
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY group_id (group_id),
			KEY status (status)
		) {$charset};";

		dbDelta( $groups );
		dbDelta( $members );
		dbDelta( $payments );
		dbDelta( $links );

		update_option( self::DB_OPTION, self::DB_VERSION );
	}
}

/**
 * Capabilities for the corporate module.
 */
final class Corporate_Capabilities {

	const CAPS = array(
		'manage_memberistic_groups',
		'view_memberistic_groups',
		'manage_memberistic_group_payments',
	);

	const OPTION = 'memberistic_corporate_caps_assigned';

	public static function maybe_assign() {
		if ( '1' === get_option( self::OPTION ) ) {
			return;
		}
		// Admins get all; the existing memberistic staff role (if any)
		// gets view + manage. Falls back to administrator only.
		foreach ( array( 'administrator' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				foreach ( self::CAPS as $cap ) {
					$role->add_cap( $cap );
				}
			}
		}
		// Memberistic staff role, if present, gets view + payments.
		$staff = get_role( 'memberistic_staff' );
		if ( $staff ) {
			$staff->add_cap( 'view_memberistic_groups' );
			$staff->add_cap( 'manage_memberistic_groups' );
		}
		update_option( self::OPTION, '1' );
	}

	/** Primary capability gate for the admin screens. */
	public static function manage_cap() {
		// Fall back to manage_options so a brand-new install (before
		// caps are assigned) still lets an admin in.
		return current_user_can( 'manage_memberistic_groups' ) ? 'manage_memberistic_groups' : 'manage_options';
	}
}

/**
 * Data access for corporate groups + group payments.
 */
final class Corporate_Groups_Repository {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'memberistic_corporate_groups';
	}
	public static function payments_table() {
		global $wpdb;
		return $wpdb->prefix . 'memberistic_group_payments';
	}

	/**
	 * Allowed enum values — single source of truth for validation
	 * AND for the admin <select> options.
	 */
	public static function statuses() {
		return array( 'pending', 'active', 'expired', 'cancelled' );
	}
	public static function payment_statuses() {
		return array( 'unpaid', 'partial', 'deposit', 'paid', 'comped' );
	}
	public static function payment_methods() {
		return array( 'cash', 'pos', 'card', 'payment_link', 'manual_comp' );
	}
	public static function visibilities() {
		return array( 'private', 'call_for_details', 'public' );
	}

	/**
	 * Create a group from sanitized input. Returns new ID or 0.
	 *
	 * @param array $data Sanitized fields.
	 */
	public static function create( array $data ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$row = self::sanitize( $data );
		$row['seats_used'] = 0;
		$row['created_by'] = get_current_user_id();
		$row['created_at'] = $now;
		$row['updated_at'] = $now;

		$ok = $wpdb->insert( self::table(), $row );
		if ( ! $ok ) {
			return 0;
		}
		$id = (int) $wpdb->insert_id;

		// Record the initial group-level payment if an amount + method
		// were supplied (e.g. the $600 POS sale).
		$amount = isset( $data['initial_payment'] ) ? (float) $data['initial_payment'] : 0;
		if ( $amount > 0 ) {
			self::add_payment( $id, array(
				'amount'            => $amount,
				'payment_method'    => $row['payment_method'],
				'payment_status'    => 'completed',
				'payment_reference' => isset( $data['payment_reference'] ) ? sanitize_text_field( (string) $data['payment_reference'] ) : '',
				'notes'             => __( 'Initial payment recorded at group creation.', 'memberistic' ),
			) );
		}

		self::log_activity( $id, 'group_created', sprintf(
			/* translators: %s: group name */
			__( 'Corporate group "%s" created.', 'memberistic' ),
			$row['group_name']
		) );

		return $id;
	}

	/**
	 * Update an existing group.
	 */
	public static function update( $id, array $data ) {
		global $wpdb;
		$id  = (int) $id;
		$row = self::sanitize( $data );
		$row['updated_at'] = current_time( 'mysql' );
		$ok = $wpdb->update( self::table(), $row, array( 'id' => $id ) );
		if ( false !== $ok ) {
			self::log_activity( $id, 'group_updated', __( 'Corporate group updated.', 'memberistic' ) );
		}
		return false !== $ok;
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ) );
	}

	public static function all( $args = array() ) {
		global $wpdb;
		$per  = max( 1, (int) ( $args['per_page'] ?? 50 ) );
		$page = max( 1, (int) ( $args['paged'] ?? 1 ) );
		$off  = ( $page - 1 ) * $per;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' ORDER BY created_at DESC LIMIT %d OFFSET %d',
			$per,
			$off
		) );
	}

	public static function count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() );
	}

	/**
	 * Sum of completed payments for a group.
	 */
	public static function paid_total( $group_id ) {
		global $wpdb;
		return (float) $wpdb->get_var( $wpdb->prepare(
			'SELECT COALESCE(SUM(amount),0) FROM ' . self::payments_table() . " WHERE group_id = %d AND payment_status = 'completed'",
			(int) $group_id
		) );
	}

	public static function add_payment( $group_id, array $data ) {
		global $wpdb;
		$row = array(
			'group_id'          => (int) $group_id,
			'amount'            => round( (float) ( $data['amount'] ?? 0 ), 2 ),
			'payment_method'    => in_array( $data['payment_method'] ?? '', self::payment_methods(), true ) ? $data['payment_method'] : 'cash',
			'payment_status'    => sanitize_key( (string) ( $data['payment_status'] ?? 'completed' ) ),
			'payment_reference' => sanitize_text_field( (string) ( $data['payment_reference'] ?? '' ) ),
			'wc_order_id'       => (int) ( $data['wc_order_id'] ?? 0 ),
			'payment_link_id'   => (int) ( $data['payment_link_id'] ?? 0 ),
			'notes'             => sanitize_textarea_field( (string) ( $data['notes'] ?? '' ) ),
			'created_by'        => get_current_user_id(),
			'created_at'        => current_time( 'mysql' ),
		);
		return $wpdb->insert( self::payments_table(), $row ) ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Whitelist + sanitize incoming group fields.
	 */
	private static function sanitize( array $d ) {
		$status  = in_array( $d['status'] ?? '', self::statuses(), true ) ? $d['status'] : 'pending';
		$pstatus = in_array( $d['payment_status'] ?? '', self::payment_statuses(), true ) ? $d['payment_status'] : 'unpaid';
		$method  = in_array( $d['payment_method'] ?? '', self::payment_methods(), true ) ? $d['payment_method'] : '';
		$vis     = in_array( $d['visibility'] ?? '', self::visibilities(), true ) ? $d['visibility'] : 'private';

		$seats_total = max( 0, (int) ( $d['seats_total'] ?? 0 ) );
		$max_future  = max( $seats_total, (int) ( $d['max_future_seats'] ?? 0 ) );

		return array(
			'group_name'       => sanitize_text_field( (string) ( $d['group_name'] ?? '' ) ),
			'company_name'     => sanitize_text_field( (string) ( $d['company_name'] ?? '' ) ),
			'primary_user_id'  => (int) ( $d['primary_user_id'] ?? 0 ),
			'plan_key'         => sanitize_key( (string) ( $d['plan_key'] ?? 'corporate' ) ),
			'status'           => $status,
			'seats_total'      => $seats_total,
			'max_future_seats' => $max_future,
			'custom_price'     => round( (float) ( $d['custom_price'] ?? 0 ), 2 ),
			'payment_status'   => $pstatus,
			'payment_method'   => $method,
			'visibility'       => $vis,
			'admin_notes'      => sanitize_textarea_field( (string) ( $d['admin_notes'] ?? '' ) ),
		);
	}

	/**
	 * Log to the shared Memberistic activity table so group events
	 * appear in the same timeline as membership events. Uses the
	 * generic 'staff_note_added' type with a corporate object type
	 * (the activity table validates type against its own whitelist).
	 */
	public static function log_activity( $group_id, $action, $message ) {
		if ( ! class_exists( '\WordPressistic\Memberistic\Database\Activity_Repository' ) ) {
			return;
		}
		\WordPressistic\Memberistic\Database\Activity_Repository::log( array(
			'activity_type'       => 'staff_note_added',
			'title'               => 'corporate:' . sanitize_key( $action ),
			'description'         => $message,
			'related_object_type' => 'corporate_group',
			'related_object_id'   => (string) (int) $group_id,
		) );
	}
}

/**
 * Admin UI — Corporate Groups list + create/edit.
 */
final class Corporate_Admin {

	const PAGE = 'memberistic-corporate-groups';

	public static function menu() {
		add_submenu_page(
			'memberistic-dashboard',
			__( 'Corporate Groups', 'memberistic' ),
			__( 'Corporate Groups', 'memberistic' ),
			Corporate_Capabilities::manage_cap(),
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}
		// Lean inline styling — tactical-luxury dark cards. No extra
		// asset request for Phase 1.
		$css = '
		.g2a-corp-wrap{max-width:1100px;}
		.g2a-corp-badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;}
		.g2a-corp-badge.active{background:rgba(157,224,91,.15);color:#5a8a2c;border:1px solid rgba(157,224,91,.5);}
		.g2a-corp-badge.pending{background:rgba(232,128,47,.15);color:#b5611f;border:1px solid rgba(232,128,47,.5);}
		.g2a-corp-badge.expired,.g2a-corp-badge.cancelled{background:rgba(150,150,150,.15);color:#777;border:1px solid #ccc;}
		.g2a-corp-badge.paid{background:rgba(157,224,91,.15);color:#5a8a2c;border:1px solid rgba(157,224,91,.5);}
		.g2a-corp-badge.unpaid{background:rgba(232,80,80,.12);color:#b53030;border:1px solid rgba(232,80,80,.4);}
		.g2a-corp-badge.partial,.g2a-corp-badge.deposit{background:rgba(232,128,47,.15);color:#b5611f;border:1px solid rgba(232,128,47,.5);}
		.g2a-corp-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:20px 24px;margin:16px 0;}
		.g2a-corp-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;}
		.g2a-corp-field label{display:block;font-weight:600;margin:0 0 4px;}
		.g2a-corp-field input,.g2a-corp-field select,.g2a-corp-field textarea{width:100%;}
		';
		wp_register_style( 'memberistic-corp-inline', false );
		wp_enqueue_style( 'memberistic-corp-inline' );
		wp_add_inline_style( 'memberistic-corp-inline', $css );
	}

	/**
	 * Route: list (default), new, or single.
	 */
	public static function render() {
		if ( ! current_user_can( Corporate_Capabilities::manage_cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to manage corporate groups.', 'memberistic' ) );
		}
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'new' === $view ) {
			self::render_form();
		} elseif ( 'single' === $view ) {
			self::render_single();
		} else {
			self::render_list();
		}
	}

	private static function render_list() {
		$groups = Corporate_Groups_Repository::all();
		$new_url = admin_url( 'admin.php?page=' . self::PAGE . '&view=new' );
		?>
		<div class="wrap g2a-corp-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Corporate Groups', 'memberistic' ); ?></h1>
			<a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Create Group', 'memberistic' ); ?></a>
			<?php if ( isset( $_GET['created'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Corporate group created.', 'memberistic' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Corporate group updated.', 'memberistic' ); ?></p></div>
			<?php endif; ?>

			<p class="description"><?php esc_html_e( 'One organization pays once; each member keeps their own account, waiver, and QR check-in. Group plans stay hidden from public pricing unless set to "Call for Details" or "Public".', 'memberistic' ); ?></p>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Group', 'memberistic' ); ?></th>
						<th><?php esc_html_e( 'Primary Payer', 'memberistic' ); ?></th>
						<th><?php esc_html_e( 'Seats', 'memberistic' ); ?></th>
						<th><?php esc_html_e( 'Paid', 'memberistic' ); ?></th>
						<th><?php esc_html_e( 'Payment', 'memberistic' ); ?></th>
						<th><?php esc_html_e( 'Status', 'memberistic' ); ?></th>
						<th><?php esc_html_e( 'Created', 'memberistic' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $groups ) ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'No corporate groups yet. Click “Create Group” to set one up.', 'memberistic' ); ?></td></tr>
					<?php else : foreach ( $groups as $g ) :
						$payer = $g->primary_user_id ? get_userdata( $g->primary_user_id ) : null;
						$paid  = Corporate_Groups_Repository::paid_total( $g->id );
						$single_url = admin_url( 'admin.php?page=' . self::PAGE . '&view=single&id=' . (int) $g->id );
					?>
						<tr>
							<td><strong><a href="<?php echo esc_url( $single_url ); ?>"><?php echo esc_html( $g->group_name ?: $g->company_name ?: ( '#' . $g->id ) ); ?></a></strong><?php if ( $g->company_name && $g->company_name !== $g->group_name ) : ?><br><span class="description"><?php echo esc_html( $g->company_name ); ?></span><?php endif; ?></td>
							<td><?php echo $payer ? esc_html( $payer->display_name . ' (' . $payer->user_email . ')' ) : '&mdash;'; ?></td>
							<td><?php echo esc_html( (int) $g->seats_used . ' / ' . (int) $g->seats_total ); ?><?php if ( (int) $g->max_future_seats > (int) $g->seats_total ) : ?> <span class="description">(<?php echo esc_html( sprintf( __( 'up to %d', 'memberistic' ), (int) $g->max_future_seats ) ); ?>)</span><?php endif; ?></td>
							<td>$<?php echo esc_html( number_format( $paid, 2 ) ); ?><?php if ( (float) $g->custom_price > 0 ) : ?> <span class="description">/ $<?php echo esc_html( number_format( (float) $g->custom_price, 2 ) ); ?></span><?php endif; ?></td>
							<td><span class="g2a-corp-badge <?php echo esc_attr( $g->payment_status ); ?>"><?php echo esc_html( ucfirst( $g->payment_status ) ); ?></span></td>
							<td><span class="g2a-corp-badge <?php echo esc_attr( $g->status ); ?>"><?php echo esc_html( ucfirst( $g->status ) ); ?></span></td>
							<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $g->created_at ) ); ?></td>
						</tr>
					<?php endforeach; endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function render_form( $group = null ) {
		$is_edit = ( $group instanceof \stdClass );
		$action  = $is_edit ? 'memberistic_corp_update' : 'memberistic_corp_create';
		$nonce   = $is_edit ? 'memberistic_corp_update_' . (int) $group->id : 'memberistic_corp_create';
		$back    = admin_url( 'admin.php?page=' . self::PAGE );
		$val     = function ( $field, $default = '' ) use ( $group, $is_edit ) {
			return $is_edit && isset( $group->$field ) ? $group->$field : $default;
		};
		?>
		<div class="wrap g2a-corp-wrap">
			<h1><?php echo $is_edit ? esc_html__( 'Edit Corporate Group', 'memberistic' ) : esc_html__( 'Create Corporate Group', 'memberistic' ); ?></h1>
			<a href="<?php echo esc_url( $back ); ?>">&larr; <?php esc_html_e( 'Back to all groups', 'memberistic' ); ?></a>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="g2a-corp-card">
				<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
				<?php if ( $is_edit ) : ?><input type="hidden" name="group_id" value="<?php echo (int) $group->id; ?>"><?php endif; ?>
				<?php wp_nonce_field( $nonce ); ?>

				<div class="g2a-corp-grid">
					<div class="g2a-corp-field">
						<label for="group_name"><?php esc_html_e( 'Group / Display Name', 'memberistic' ); ?> *</label>
						<input type="text" id="group_name" name="group_name" required value="<?php echo esc_attr( $val( 'group_name' ) ); ?>">
					</div>
					<div class="g2a-corp-field">
						<label for="company_name"><?php esc_html_e( 'Company Name', 'memberistic' ); ?></label>
						<input type="text" id="company_name" name="company_name" value="<?php echo esc_attr( $val( 'company_name' ) ); ?>">
					</div>
					<div class="g2a-corp-field">
						<label for="primary_user_id"><?php esc_html_e( 'Primary Payer (WP user)', 'memberistic' ); ?></label>
						<?php
						wp_dropdown_users( array(
							'name'             => 'primary_user_id',
							'id'               => 'primary_user_id',
							'selected'         => (int) $val( 'primary_user_id', 0 ),
							'show_option_none' => __( '— Select payer —', 'memberistic' ),
							'option_none_value'=> 0,
							'show'             => 'display_name_with_login',
						) );
						?>
					</div>
					<div class="g2a-corp-field">
						<label for="plan_key"><?php esc_html_e( 'Plan Key', 'memberistic' ); ?></label>
						<input type="text" id="plan_key" name="plan_key" value="<?php echo esc_attr( $val( 'plan_key', 'corporate' ) ); ?>">
					</div>
					<div class="g2a-corp-field">
						<label for="seats_total"><?php esc_html_e( 'Seats Purchased', 'memberistic' ); ?></label>
						<input type="number" min="0" id="seats_total" name="seats_total" value="<?php echo esc_attr( $val( 'seats_total', 10 ) ); ?>">
					</div>
					<div class="g2a-corp-field">
						<label for="max_future_seats"><?php esc_html_e( 'Max Future Seats', 'memberistic' ); ?></label>
						<input type="number" min="0" id="max_future_seats" name="max_future_seats" value="<?php echo esc_attr( $val( 'max_future_seats', 60 ) ); ?>">
					</div>
					<div class="g2a-corp-field">
						<label for="custom_price"><?php esc_html_e( 'Custom Price ($)', 'memberistic' ); ?></label>
						<input type="number" step="0.01" min="0" id="custom_price" name="custom_price" value="<?php echo esc_attr( $val( 'custom_price', '600.00' ) ); ?>">
					</div>
					<div class="g2a-corp-field">
						<label for="status"><?php esc_html_e( 'Group Status', 'memberistic' ); ?></label>
						<select id="status" name="status">
							<?php foreach ( Corporate_Groups_Repository::statuses() as $s ) : ?>
								<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $val( 'status', 'pending' ), $s ); ?>><?php echo esc_html( ucfirst( $s ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="g2a-corp-field">
						<label for="payment_status"><?php esc_html_e( 'Payment Status', 'memberistic' ); ?></label>
						<select id="payment_status" name="payment_status">
							<?php foreach ( Corporate_Groups_Repository::payment_statuses() as $s ) : ?>
								<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $val( 'payment_status', 'unpaid' ), $s ); ?>><?php echo esc_html( ucfirst( $s ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="g2a-corp-field">
						<label for="payment_method"><?php esc_html_e( 'Payment Method', 'memberistic' ); ?></label>
						<select id="payment_method" name="payment_method">
							<option value=""><?php esc_html_e( '— none —', 'memberistic' ); ?></option>
							<?php foreach ( Corporate_Groups_Repository::payment_methods() as $s ) : ?>
								<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $val( 'payment_method', '' ), $s ); ?>><?php echo esc_html( ucwords( str_replace( '_', ' ', $s ) ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="g2a-corp-field">
						<label for="visibility"><?php esc_html_e( 'Public Visibility', 'memberistic' ); ?></label>
						<select id="visibility" name="visibility">
							<?php
							$vis_labels = array(
								'private'          => __( 'Private (hidden from site)', 'memberistic' ),
								'call_for_details' => __( 'Call for Details (CTA only)', 'memberistic' ),
								'public'           => __( 'Public (listed)', 'memberistic' ),
							);
							foreach ( Corporate_Groups_Repository::visibilities() as $s ) : ?>
								<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $val( 'visibility', 'private' ), $s ); ?>><?php echo esc_html( $vis_labels[ $s ] ?? $s ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<?php if ( ! $is_edit ) : ?>
				<hr>
				<p><strong><?php esc_html_e( 'Record initial payment (optional)', 'memberistic' ); ?></strong> — <?php esc_html_e( 'e.g. the in-store POS/cash sale.', 'memberistic' ); ?></p>
				<div class="g2a-corp-grid">
					<div class="g2a-corp-field">
						<label for="initial_payment"><?php esc_html_e( 'Amount Paid ($)', 'memberistic' ); ?></label>
						<input type="number" step="0.01" min="0" id="initial_payment" name="initial_payment" value="600.00">
					</div>
					<div class="g2a-corp-field">
						<label for="payment_reference"><?php esc_html_e( 'POS Receipt / Reference', 'memberistic' ); ?></label>
						<input type="text" id="payment_reference" name="payment_reference" placeholder="<?php esc_attr_e( 'e.g. POS-2026-00481', 'memberistic' ); ?>">
					</div>
				</div>
				<?php endif; ?>

				<div class="g2a-corp-field" style="margin-top:14px;">
					<label for="admin_notes"><?php esc_html_e( 'Internal Admin Notes', 'memberistic' ); ?></label>
					<textarea id="admin_notes" name="admin_notes" rows="3"><?php echo esc_textarea( $val( 'admin_notes' ) ); ?></textarea>
				</div>

				<p style="margin-top:18px;">
					<button type="submit" class="button button-primary"><?php echo $is_edit ? esc_html__( 'Save Changes', 'memberistic' ) : esc_html__( 'Create Group', 'memberistic' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	private static function render_single() {
		$id    = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$group = Corporate_Groups_Repository::get( $id );
		if ( ! $group ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Group not found.', 'memberistic' ) . '</p></div>';
			return;
		}
		// Phase 1 single view == the edit form prefilled. Tabs for
		// Members / Payments / Waivers / QR / Emails / Activity land
		// in later phases (see plan doc).
		self::render_form( $group );
	}

	public static function handle_create() {
		if ( ! current_user_can( Corporate_Capabilities::manage_cap() ) ) {
			wp_die( esc_html__( 'Permission denied.', 'memberistic' ) );
		}
		check_admin_referer( 'memberistic_corp_create' );
		$id = Corporate_Groups_Repository::create( wp_unslash( $_POST ) );
		$redirect = $id
			? admin_url( 'admin.php?page=' . self::PAGE . '&created=1' )
			: admin_url( 'admin.php?page=' . self::PAGE . '&view=new&error=1' );
		wp_safe_redirect( $redirect );
		exit;
	}

	public static function handle_update() {
		if ( ! current_user_can( Corporate_Capabilities::manage_cap() ) ) {
			wp_die( esc_html__( 'Permission denied.', 'memberistic' ) );
		}
		$id = isset( $_POST['group_id'] ) ? (int) $_POST['group_id'] : 0;
		check_admin_referer( 'memberistic_corp_update_' . $id );
		Corporate_Groups_Repository::update( $id, wp_unslash( $_POST ) );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&updated=1' ) );
		exit;
	}
}
