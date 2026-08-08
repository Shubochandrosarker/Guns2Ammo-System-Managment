<?php
/**
 * Admin UI — the "Formistic" dashboard: inbox, view, reply, settings.
 *
 * @package Wpistic_Formistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the branded admin experience.
 */
class Wpistic_Formistic_Admin {

	/** Admin page slug. */
	const PAGE = 'formistic';

	/** Capability required to manage submissions. */
	const CAP = 'manage_options';

	/**
	 * Page-hook suffixes for every Formistic admin screen. Populated as menus
	 * register (here plus Addons / Newsletter) so assets() can enqueue on the
	 * exact hooks instead of guessing from the hook name.
	 *
	 * @var string[]
	 */
	public static $page_hooks = [];

	/**
	 * Register admin hooks.
	 */
	public function register() {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		// Run after every other component (Forms CPT, Newsletter) has registered
		// its submenu so we can enforce the public-facing order.
		add_action( 'admin_menu', [ $this, 'reorder_menu' ], 999 );
		add_action( 'admin_init', [ 'Wpistic_Formistic_Database', 'maybe_upgrade' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_filter( 'plugin_action_links_' . WPISTIC_FORMISTIC_BASENAME, [ $this, 'action_links' ] );
	}

	/**
	 * Enforce the Formistic submenu order: Inbox → Threads → Form →
	 * Analytics → Settings → Addons, then addon-provided submenus (e.g.
	 * Newsletter) serially. Components register their menus at different
	 * priorities, so we normalize the final order on a late admin_menu hook.
	 */
	public function reorder_menu() {
		global $submenu;
		if ( empty( $submenu[ self::PAGE ] ) ) {
			return;
		}

		$order = [
			self::PAGE,                                                 // Inbox.
			self::PAGE . '-threads',                                    // Threads.
			'edit.php?post_type=' . Wpistic_Formistic_Forms::POST_TYPE, // Form.
			self::PAGE . '-analytics',                                  // Analytics.
			self::PAGE . '-settings',                                   // Settings.
			Wpistic_Formistic_Addons::PAGE,                             // Addons.
			Wpistic_Formistic_Newsletter::PAGE,                         // Newsletter (addon).
		];

		// Index existing items by slug, de-duplicating the auto-created
		// first child that mirrors the parent menu.
		$by_slug = [];
		foreach ( $submenu[ self::PAGE ] as $item ) {
			$slug = isset( $item[2] ) ? $item[2] : '';
			if ( '' !== $slug && ! isset( $by_slug[ $slug ] ) ) {
				$by_slug[ $slug ] = $item;
			}
		}

		// Make sure the first entry reads "Inbox" rather than the brand title.
		if ( isset( $by_slug[ self::PAGE ] ) ) {
			$by_slug[ self::PAGE ][0] = __( 'Inbox', 'formistic' );
		}

		$ordered = [];
		foreach ( $order as $slug ) {
			if ( isset( $by_slug[ $slug ] ) ) {
				$ordered[] = $by_slug[ $slug ];
				unset( $by_slug[ $slug ] );
			}
		}
		// Append anything we didn't explicitly order (e.g. an "Add New" link).
		foreach ( $by_slug as $item ) {
			$ordered[] = $item;
		}

		$submenu[ self::PAGE ] = array_values( $ordered );
	}

	/**
	 * Add the top-level menu and submenus.
	 */
	public function menu() {
		$counts = Wpistic_Formistic_Database::status_counts();
		$bubble = $counts['new'] > 0
			? ' <span class="awaiting-mod">' . (int) $counts['new'] . '</span>'
			: '';

		self::$page_hooks[] = add_menu_page(
			__( 'Formistic', 'formistic' ),
			__( 'Formistic', 'formistic' ) . $bubble,
			self::CAP,
			self::PAGE,
			[ $this, 'render_inbox' ],
			'dashicons-email-alt',
			26
		);

		self::$page_hooks[] = add_submenu_page(
			self::PAGE,
			__( 'Inbox', 'formistic' ),
			__( 'Inbox', 'formistic' ),
			self::CAP,
			self::PAGE,
			[ $this, 'render_inbox' ]
		);
		self::$page_hooks[] = add_submenu_page(
			self::PAGE,
			__( 'Threads', 'formistic' ),
			__( 'Threads', 'formistic' ),
			self::CAP,
			self::PAGE . '-threads',
			[ $this, 'render_threads_proxy' ]
		);

		// Form builder — explicit link to the CPT list (the CPT itself uses
		// show_in_menu=false to avoid the string-parent attach timing issue).
		self::$page_hooks[] = add_submenu_page(
			self::PAGE,
			__( 'Forms', 'formistic' ),
			__( 'Form', 'formistic' ),
			self::CAP,
			'edit.php?post_type=' . Wpistic_Formistic_Forms::POST_TYPE
		);

		self::$page_hooks[] = add_submenu_page(
			self::PAGE,
			__( 'Analytics', 'formistic' ),
			__( 'Analytics', 'formistic' ),
			self::CAP,
			self::PAGE . '-analytics',
			[ $this, 'render_analytics_proxy' ]
		);

		self::$page_hooks[] = add_submenu_page(
			self::PAGE,
			__( 'Settings', 'formistic' ),
			__( 'Settings', 'formistic' ),
			self::CAP,
			self::PAGE . '-settings',
			[ $this, 'render_settings_proxy' ]
		);
	}

	/**
	 * Proxy to Wpistic_Formistic_Settings::render so the brand header stays in Wpistic_Formistic_Admin.
	 */
	public function render_settings_proxy() {
		( new Wpistic_Formistic_Settings() )->render( [ $this, 'header' ] );
	}

	/**
	 * Proxy to Wpistic_Formistic_Analytics::render so the brand header stays in Wpistic_Formistic_Admin.
	 */
	public function render_analytics_proxy() {
		( new Wpistic_Formistic_Analytics() )->render( [ $this, 'header' ] );
	}

	/**
	 * Proxy to render the threads variant of inbox.
	 */
	public function render_threads_proxy() {
		$_GET['view'] = 'threads'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$this->render_inbox();
	}

	/**
	 * Quick "Settings" link on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Inbox', 'formistic' ) . '</a>' );
		return $links;
	}

	/**
	 * Read an admin asset file once for inline output.
	 *
	 * @param string $relative Path relative to the plugin root.
	 * @return string File contents, or '' if unreadable.
	 */
	protected static function inline_asset( $relative ) {
		static $cache = [];
		if ( array_key_exists( $relative, $cache ) ) {
			return $cache[ $relative ];
		}
		$file              = WPISTIC_FORMISTIC_PATH . $relative;
		$cache[ $relative ] = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return $cache[ $relative ];
	}

	/**
	 * Admin stylesheet contents for inline output.
	 *
	 * @return string
	 */
	protected static function inline_css() {
		return self::inline_asset( 'assets/admin.css' );
	}

	/**
	 * Admin script contents for inline output.
	 *
	 * @return string
	 */
	protected static function inline_js() {
		return self::inline_asset( 'assets/admin.js' );
	}

	/**
	 * Enqueue admin assets on plugin pages only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function assets( $hook ) {
		// Load on every Formistic admin page (matched by exact captured hook
		// OR by name as a fallback) AND on the form CPT add/edit/list screens.
		$is_plugin_page = in_array( $hook, self::$page_hooks, true )
			|| false !== strpos( (string) $hook, self::PAGE );
		$is_form_screen = false;
		if ( in_array( $hook, [ 'post.php', 'post-new.php', 'edit.php' ], true ) ) {
			$screen         = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			$is_form_screen = $screen && isset( $screen->post_type ) && Wpistic_Formistic_Forms::POST_TYPE === $screen->post_type;
		}
		if ( ! $is_plugin_page && ! $is_form_screen ) {
			return;
		}
		// Print the admin CSS inline (registered with no external src) so it is
		// immune to CSS optimizers, concatenation, stale caches, CDNs and CSP —
		// the dashboard chrome always renders. Falls back to the file if the
		// contents can't be read.
		$css = self::inline_css();
		if ( '' !== $css ) {
			wp_register_style( 'wpistic-formistic-admin', false, [], WPISTIC_FORMISTIC_VERSION );
			wp_enqueue_style( 'wpistic-formistic-admin' );
			wp_add_inline_style( 'wpistic-formistic-admin', $css );
		} else {
			wp_enqueue_style( 'wpistic-formistic-admin', WPISTIC_FORMISTIC_URL . 'assets/admin.css', [], WPISTIC_FORMISTIC_VERSION );
		}
		// Print the admin script inline too, for the same reason — so form
		// optimizers / deferral can't break the dashboard's buttons.
		$js = self::inline_js();
		if ( '' !== $js ) {
			wp_register_script( 'wpistic-formistic-admin', false, [], WPISTIC_FORMISTIC_VERSION, true );
			wp_enqueue_script( 'wpistic-formistic-admin' );
		} else {
			wp_enqueue_script( 'wpistic-formistic-admin', WPISTIC_FORMISTIC_URL . 'assets/admin.js', [], WPISTIC_FORMISTIC_VERSION, true );
		}
		wp_localize_script(
			'wpistic-formistic-admin',
			'Wpistic_Formistic',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wpistic_formistic_admin' ),
				'i18n'    => [
					'sending'         => __( 'Sending…', 'formistic' ),
					'loading'         => __( 'Loading…', 'formistic' ),
					'sendReply'       => __( 'Send Reply', 'formistic' ),
					'sent'            => __( 'Reply sent.', 'formistic' ),
					'error'           => __( 'Something went wrong. Please try again.', 'formistic' ),
					'confirmDel'      => __( 'Delete this submission permanently?', 'formistic' ),
					'confirmBulkDel'  => __( 'Delete the selected submissions permanently? This also removes their replies and attached files.', 'formistic' ),
					'noBulkAction'    => __( 'Pick a bulk action first.', 'formistic' ),
					'noBulkSelection' => __( 'Select at least one submission first.', 'formistic' ),
					'noEmail'         => __( 'This submission has no email address to reply to.', 'formistic' ),
					'detailsTitle'    => __( 'Submission Details', 'formistic' ),
					'statusNew'       => __( 'New', 'formistic' ),
					'statusRead'      => __( 'Viewed', 'formistic' ),
					'statusReplied'   => __( 'Replied', 'formistic' ),
					'showExtras'      => __( 'Show CC / BCC', 'formistic' ),
					'hideExtras'      => __( 'Hide CC / BCC', 'formistic' ),
					'quotedHeader'    => __( "\n\n— On {date}, {name} wrote: —\n", 'formistic' ),
					'noteAdded'       => __( 'Internal note added.', 'formistic' ),
				],
			]
		);
		if ( '' !== $js ) {
			// Runs after the localized data, so window.Wpistic_Formistic is set.
			wp_add_inline_script( 'wpistic-formistic-admin', $js );
		}
	}

	/**
	 * Shared branded page header (called by both render_inbox and the
	 * settings page via Wpistic_Formistic_Settings::render).
	 *
	 * @param string $subtitle Sub-heading text.
	 */
	public function header( $subtitle ) {
		?>
		<div class="wpistic-formistic-brandbar">
			<div class="wpistic-formistic-logo">
				<span class="wpistic-formistic-logo__mark" aria-hidden="true">F</span>
				<span class="wpistic-formistic-logo__text">
					<strong>Formistic</strong>
					<small><?php esc_html_e( 'by Wordpressistic', 'formistic' ); ?></small>
				</span>
			</div>
			<div class="wpistic-formistic-brandbar__title">
				<h1><?php esc_html_e( 'Smart Contact Forms for WordPress Leads', 'formistic' ); ?></h1>
				<p><?php echo esc_html( $subtitle ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the inbox / submissions list.
	 */
	public function render_inbox() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$counts = Wpistic_Formistic_Database::status_counts();
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$form   = isset( $_GET['form'] ) ? sanitize_text_field( wp_unslash( $_GET['form'] ) ) : '';
		$status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
		$sender = isset( $_GET['sender'] ) ? sanitize_email( wp_unslash( $_GET['sender'] ) ) : '';
		$view   = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

		$per_page = 20;
		if ( 'threads' === $view ) {
			$result = Wpistic_Formistic_Database::query_threads( [
				'search'   => $search,
				'paged'    => $paged,
				'per_page' => $per_page,
			] );
		} else {
			$result = Wpistic_Formistic_Database::query_submissions( [
				'search'   => $search,
				'form'     => $form,
				'status'   => $status,
				'paged'    => $paged,
				'per_page' => $per_page,
			] );
		}
		$items = $result['items'];
		$total = $result['total'];
		$pages = (int) ceil( $total / $per_page );

		$notice_slug = isset( $_GET['wpistic_formistic_notice'] ) ? sanitize_key( $_GET['wpistic_formistic_notice'] ) : '';
		$notice_n    = isset( $_GET['n'] ) ? (int) $_GET['n'] : 0;
		$notice      = class_exists( 'Wpistic_Formistic_Bulk' ) ? Wpistic_Formistic_Bulk::notice_for( $notice_slug, $notice_n ) : null;

		$export_base = add_query_arg(
			array_filter( [
				'action' => 'wpistic_formistic_export',
				's'      => $search,
				'form'   => $form,
				'status' => $status,
				'scope'  => 'filtered',
			] ),
			admin_url( 'admin-post.php' )
		);
		$export_csv  = wp_nonce_url( add_query_arg( 'format', 'csv',  $export_base ), 'wpistic_formistic_export' );
		$export_json = wp_nonce_url( add_query_arg( 'format', 'json', $export_base ), 'wpistic_formistic_export' );
		?>
		<div class="wrap wpistic-formistic-wrap">
			<?php $this->header( __( 'Every contact form & website submission, in one inbox.', 'formistic' ) ); ?>

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
					<p><?php echo esc_html( $notice['text'] ); ?></p>
				</div>
			<?php endif; ?>
			<div class="wpistic-formistic-tabs" style="margin-top:14px;">
				<a class="wpistic-formistic-tab<?php echo 'threads' !== $view ? ' is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>"><?php esc_html_e( 'Submissions', 'formistic' ); ?></a>
				<a class="wpistic-formistic-tab<?php echo 'threads' === $view ? ' is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&view=threads' ) ); ?>"><?php esc_html_e( 'Threads', 'formistic' ); ?></a>
			</div>

			<?php if ( 'threads' !== $view ) : ?>
			<div class="wpistic-formistic-stats">
				<?php
				$cards = [
					''        => [ __( 'All Submissions', 'formistic' ), $counts['total'], 'all' ],
					'new'     => [ __( 'New / Unread', 'formistic' ), $counts['new'], 'new' ],
					'read'    => [ __( 'Viewed', 'formistic' ), $counts['read'], 'read' ],
					'replied' => [ __( 'Replied', 'formistic' ), $counts['replied'], 'replied' ],
				];
				foreach ( $cards as $key => $card ) :
					$url    = add_query_arg( array_filter( [ 'page' => self::PAGE, 'status' => $key ] ), admin_url( 'admin.php' ) );
					$active = ( $status === $key ) ? ' is-active' : '';
					?>
					<a class="wpistic-formistic-stat wpistic-formistic-stat--<?php echo esc_attr( $card[2] . $active ); ?>" href="<?php echo esc_url( $url ); ?>">
						<span class="wpistic-formistic-stat__num"><?php echo esc_html( number_format_i18n( $card[1] ) ); ?></span>
						<span class="wpistic-formistic-stat__label"><?php echo esc_html( $card[0] ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>

			<form class="wpistic-formistic-toolbar" method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<?php if ( $status ) : ?>
					<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
				<?php endif; ?>
				<?php if ( 'threads' === $view ) : ?>
					<input type="hidden" name="view" value="threads">
				<?php endif; ?>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search name, email, message…', 'formistic' ); ?>">
				<?php if ( 'threads' !== $view ) : ?>
				<select name="form">
					<option value=""><?php esc_html_e( 'All forms', 'formistic' ); ?></option>
					<?php foreach ( Wpistic_Formistic_Database::form_names() as $fname ) : ?>
						<option value="<?php echo esc_attr( $fname ); ?>" <?php selected( $form, $fname ); ?>><?php echo esc_html( $fname ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php endif; ?>
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'formistic' ); ?></button>
				<?php if ( $search || $form || $status ) : ?>
					<a class="wpistic-formistic-clear" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>"><?php esc_html_e( 'Clear', 'formistic' ); ?></a>
				<?php endif; ?>
			</form>

			<?php if ( $sender ) : ?>
				<?php $this->render_sender_panel( $sender ); ?>
			<?php endif; ?>

			<?php if ( 'threads' !== $view ) : ?>
			<form id="wpistic-formistic-bulk-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wpistic_formistic_bulk">
				<?php wp_nonce_field( 'wpistic_formistic_bulk' ); ?>

				<div class="wpistic-formistic-bulkbar">
					<div class="wpistic-formistic-bulkbar__left">
						<select name="bulk_action" class="wpistic-formistic-bulkbar__action">
							<option value=""><?php esc_html_e( 'Bulk actions', 'formistic' ); ?></option>
							<option value="mark_new"><?php esc_html_e( 'Mark as New', 'formistic' ); ?></option>
							<option value="mark_read"><?php esc_html_e( 'Mark as Viewed', 'formistic' ); ?></option>
							<option value="mark_replied"><?php esc_html_e( 'Mark as Replied', 'formistic' ); ?></option>
							<option value="export_csv"><?php esc_html_e( 'Export selected as CSV', 'formistic' ); ?></option>
							<option value="export_json"><?php esc_html_e( 'Export selected as JSON', 'formistic' ); ?></option>
							<option value="delete"><?php esc_html_e( 'Delete', 'formistic' ); ?></option>
						</select>
						<button type="submit" class="button wpistic-formistic-bulkbar__apply"><?php esc_html_e( 'Apply', 'formistic' ); ?></button>
					</div>
					<div class="wpistic-formistic-bulkbar__right">
						<span class="wpistic-formistic-bulkbar__label"><?php esc_html_e( 'Export filtered:', 'formistic' ); ?></span>
						<a class="button" href="<?php echo esc_url( $export_csv ); ?>"><?php esc_html_e( 'CSV', 'formistic' ); ?></a>
						<a class="button" href="<?php echo esc_url( $export_json ); ?>"><?php esc_html_e( 'JSON', 'formistic' ); ?></a>
					</div>
				</div>

				<div class="wpistic-formistic-panel">
					<table class="wpistic-formistic-table">
						<thead>
							<tr>
								<th class="wpistic-formistic-col-check"><input type="checkbox" id="wpistic-formistic-check-all" aria-label="<?php esc_attr_e( 'Select all', 'formistic' ); ?>"></th>
								<th class="wpistic-formistic-col-form"><?php esc_html_e( 'Form', 'formistic' ); ?></th>
								<th><?php esc_html_e( 'From', 'formistic' ); ?></th>
								<th class="wpistic-formistic-col-preview"><?php esc_html_e( 'Message', 'formistic' ); ?></th>
								<th class="wpistic-formistic-col-date"><?php esc_html_e( 'Received', 'formistic' ); ?></th>
								<th class="wpistic-formistic-col-status"><?php esc_html_e( 'Status', 'formistic' ); ?></th>
								<th class="wpistic-formistic-col-actions"><?php esc_html_e( 'Actions', 'formistic' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php if ( ! $items ) : ?>
							<tr class="wpistic-formistic-empty">
								<td colspan="7">
									<div class="wpistic-formistic-empty__in">
										<span class="dashicons dashicons-email-alt"></span>
										<p><?php esc_html_e( 'No submissions yet. When visitors submit any form on your website, they will appear here.', 'formistic' ); ?></p>
									</div>
								</td>
							</tr>
						<?php else : ?>
							<?php foreach ( $items as $row ) : ?>
								<tr class="wpistic-formistic-row wpistic-formistic-row--<?php echo esc_attr( $row->status ); ?>" data-id="<?php echo esc_attr( $row->id ); ?>">
									<td class="wpistic-formistic-col-check"><input type="checkbox" class="wpistic-formistic-check-row" name="ids[]" value="<?php echo esc_attr( $row->id ); ?>" aria-label="<?php esc_attr_e( 'Select submission', 'formistic' ); ?>"></td>
									<td class="wpistic-formistic-col-form"><span class="wpistic-formistic-formtag"><?php echo esc_html( $row->form_name ?: __( 'Website Form', 'formistic' ) ); ?></span></td>
									<td>
										<strong class="wpistic-formistic-from-name"><?php echo esc_html( $row->sender_name ?: __( '—', 'formistic' ) ); ?></strong>
										<?php if ( $row->sender_email ) : ?>
											<span class="wpistic-formistic-from-email"><?php echo esc_html( $row->sender_email ); ?></span>
										<?php endif; ?>
									</td>
									<td class="wpistic-formistic-col-preview"><?php echo esc_html( wp_trim_words( (string) $row->message, 14, '…' ) ?: '—' ); ?></td>
									<td class="wpistic-formistic-col-date"><?php echo esc_html( $this->human_date( $row->created_at ) ); ?></td>
									<td class="wpistic-formistic-col-status"><?php echo $this->status_pill( $row->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
									<td class="wpistic-formistic-col-actions">
										<button type="button" class="wpistic-formistic-btn wpistic-formistic-btn--view" data-id="<?php echo esc_attr( $row->id ); ?>"><?php esc_html_e( 'View', 'formistic' ); ?></button>
										<button type="button" class="wpistic-formistic-btn wpistic-formistic-btn--reply" data-id="<?php echo esc_attr( $row->id ); ?>"<?php disabled( ! $row->sender_email ); ?>><?php esc_html_e( 'Reply', 'formistic' ); ?></button>
										<button type="button" class="wpistic-formistic-btn wpistic-formistic-btn--del" data-id="<?php echo esc_attr( $row->id ); ?>" aria-label="<?php esc_attr_e( 'Delete', 'formistic' ); ?>"><span class="dashicons dashicons-trash"></span></button>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
						</tbody>
					</table>
				</div>
			</form>
			<?php else : ?>
				<div class="wpistic-formistic-panel">
					<table class="wpistic-formistic-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Sender', 'formistic' ); ?></th>
								<th><?php esc_html_e( 'Submissions', 'formistic' ); ?></th>
								<th><?php esc_html_e( 'Thread Status', 'formistic' ); ?></th>
								<th><?php esc_html_e( 'Last Activity', 'formistic' ); ?></th>
								<th><?php esc_html_e( 'Open', 'formistic' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( ! $items ) : ?>
								<tr><td colspan="5"><?php esc_html_e( 'No threads found.', 'formistic' ); ?></td></tr>
							<?php else : foreach ( $items as $thread ) : ?>
								<?php
								$thread_status = ( (int) $thread->new_count > 0 ) ? 'new' : ( ( (int) $thread->read_count > 0 ) ? 'read' : 'replied' );
								$link = add_query_arg(
									[ 'page' => self::PAGE, 'sender' => (string) $thread->sender_email ],
									admin_url( 'admin.php' )
								);
								?>
								<tr>
									<td><strong><?php echo esc_html( $thread->sender_name ?: __( 'Unknown', 'formistic' ) ); ?></strong><br><span class="wpistic-formistic-from-email"><?php echo esc_html( $thread->sender_email ); ?></span></td>
									<td><?php echo esc_html( number_format_i18n( (int) $thread->submissions_count ) ); ?></td>
									<td><?php echo $this->status_pill( $thread_status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
									<td><?php echo esc_html( $this->human_date( (string) $thread->last_at ) ); ?></td>
									<td><a class="button" href="<?php echo esc_url( $link ); ?>"><?php esc_html_e( 'Open Sender View', 'formistic' ); ?></a></td>
								</tr>
							<?php endforeach; endif; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<?php if ( $pages > 1 ) : ?>
				<div class="wpistic-formistic-pagination">
					<?php
					$base = add_query_arg( array_filter( [
						'page'   => self::PAGE,
						's'      => $search,
						'form'   => $form,
						'status' => $status,
					] ), admin_url( 'admin.php' ) );
					for ( $i = 1; $i <= $pages; $i++ ) :
						$url = add_query_arg( 'paged', $i, $base );
						?>
						<a class="wpistic-formistic-page<?php echo $i === $paged ? ' is-current' : ''; ?>" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $i ); ?></a>
					<?php endfor; ?>
				</div>
			<?php endif; ?>
		</div>

		<?php $this->render_modals(); ?>
		<?php
	}

	/**
	 * Unified sender panel with all submissions and activity.
	 *
	 * @param string $sender_email Sender email.
	 */
	protected function render_sender_panel( $sender_email ) {
		$items = Wpistic_Formistic_Database::sender_activity( $sender_email );
		?>
		<div class="wpistic-formistic-panel" style="margin-bottom:12px;padding:14px;">
			<h2 style="margin:0 0 8px;"><?php esc_html_e( 'Unified Sender View', 'formistic' ); ?></h2>
			<p style="margin:0 0 12px;"><strong><?php echo esc_html( $sender_email ); ?></strong></p>
			<?php if ( ! $items ) : ?>
				<p><?php esc_html_e( 'No submissions found for this sender.', 'formistic' ); ?></p>
			<?php else : ?>
				<table class="wpistic-formistic-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'formistic' ); ?></th>
							<th><?php esc_html_e( 'Form', 'formistic' ); ?></th>
							<th><?php esc_html_e( 'Message', 'formistic' ); ?></th>
							<th><?php esc_html_e( 'Replies', 'formistic' ); ?></th>
							<th><?php esc_html_e( 'Status', 'formistic' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $items as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $this->human_date( (string) $row->created_at ) ); ?></td>
								<td><span class="wpistic-formistic-formtag"><?php echo esc_html( $row->form_name ?: __( 'Website Form', 'formistic' ) ); ?></span></td>
								<td><?php echo esc_html( wp_trim_words( (string) $row->message, 18, '…' ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $row->reply_count ) ); ?></td>
								<td><?php echo $this->status_pill( (string) $row->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The View + Reply modal markup (populated by admin.js via AJAX).
	 */
	protected function render_modals() {
		?>
		<div class="wpistic-formistic-modal" id="wpistic-formistic-modal-view" hidden>
			<div class="wpistic-formistic-modal__backdrop" data-close></div>
			<div class="wpistic-formistic-modal__box" role="dialog" aria-modal="true" aria-labelledby="wpistic-formistic-view-title">
				<header class="wpistic-formistic-modal__head">
					<h2 id="wpistic-formistic-view-title"><?php esc_html_e( 'Submission Details', 'formistic' ); ?></h2>
					<button type="button" class="wpistic-formistic-modal__x" data-close aria-label="<?php esc_attr_e( 'Close', 'formistic' ); ?>">&times;</button>
				</header>
				<div class="wpistic-formistic-modal__body" id="wpistic-formistic-view-body">
					<div class="wpistic-formistic-loading"><?php esc_html_e( 'Loading…', 'formistic' ); ?></div>
				</div>
				<footer class="wpistic-formistic-modal__foot">
					<button type="button" class="wpistic-formistic-btn wpistic-formistic-btn--ghost" data-close><?php esc_html_e( 'Close', 'formistic' ); ?></button>
					<button type="button" class="wpistic-formistic-btn wpistic-formistic-btn--primary" id="wpistic-formistic-view-reply"><?php esc_html_e( 'Reply by Email', 'formistic' ); ?></button>
				</footer>
			</div>
		</div>

		<div class="wpistic-formistic-modal" id="wpistic-formistic-modal-reply" hidden>
			<div class="wpistic-formistic-modal__backdrop" data-close></div>
			<div class="wpistic-formistic-modal__box wpistic-formistic-modal__box--reply" role="dialog" aria-modal="true" aria-labelledby="wpistic-formistic-reply-title">
				<header class="wpistic-formistic-modal__head">
					<h2 id="wpistic-formistic-reply-title"><?php esc_html_e( 'Reply to Submission', 'formistic' ); ?></h2>
					<button type="button" class="wpistic-formistic-modal__x" data-close aria-label="<?php esc_attr_e( 'Close', 'formistic' ); ?>">&times;</button>
				</header>
				<form class="wpistic-formistic-modal__body" id="wpistic-formistic-reply-form">
					<input type="hidden" name="submission_id" value="">
					<label class="wpistic-formistic-field">
						<span><?php esc_html_e( 'To', 'formistic' ); ?></span>
						<input type="email" name="to" readonly>
					</label>

					<div class="wpistic-formistic-reply-extras" id="wpistic-formistic-reply-extras" hidden>
						<label class="wpistic-formistic-field">
							<span><?php esc_html_e( 'CC', 'formistic' ); ?></span>
							<input type="text" name="cc" placeholder="<?php esc_attr_e( 'comma,separated@example.com', 'formistic' ); ?>">
						</label>
						<label class="wpistic-formistic-field">
							<span><?php esc_html_e( 'BCC', 'formistic' ); ?></span>
							<input type="text" name="bcc" placeholder="<?php esc_attr_e( 'comma,separated@example.com', 'formistic' ); ?>">
						</label>
					</div>

					<label class="wpistic-formistic-field">
						<span><?php esc_html_e( 'Subject', 'formistic' ); ?></span>
						<input type="text" name="subject" required>
					</label>

					<div class="wpistic-formistic-reply-tools">
						<select id="wpistic-formistic-reply-template" class="wpistic-formistic-reply-tools__select">
							<option value=""><?php esc_html_e( 'Insert template…', 'formistic' ); ?></option>
						</select>
						<button type="button" class="button button-small" id="wpistic-formistic-reply-quote"><?php esc_html_e( 'Quote original', 'formistic' ); ?></button>
						<button type="button" class="button button-small" id="wpistic-formistic-reply-toggle-extras"><?php esc_html_e( 'Show CC / BCC', 'formistic' ); ?></button>
						<label class="wpistic-formistic-reply-tools__html">
							<input type="checkbox" id="wpistic-formistic-reply-html" name="html_mode" value="1">
							<span><?php esc_html_e( 'Send as HTML', 'formistic' ); ?></span>
						</label>
					</div>

					<label class="wpistic-formistic-field">
						<span><?php esc_html_e( 'Your Reply', 'formistic' ); ?></span>
						<textarea name="body" rows="10" required></textarea>
					</label>
					<div class="wpistic-formistic-reply-status" id="wpistic-formistic-reply-status" hidden></div>
				</form>
				<footer class="wpistic-formistic-modal__foot">
					<button type="button" class="wpistic-formistic-btn wpistic-formistic-btn--ghost" data-close><?php esc_html_e( 'Cancel', 'formistic' ); ?></button>
					<button type="button" class="wpistic-formistic-btn wpistic-formistic-btn--primary" id="wpistic-formistic-reply-send"><?php esc_html_e( 'Send Reply', 'formistic' ); ?></button>
				</footer>
			</div>
		</div>
		<?php
	}

	/**
	 * Status pill markup.
	 *
	 * @param string $status Submission status.
	 * @return string
	 */
	protected function status_pill( $status ) {
		$labels = [
			'new'     => __( 'New', 'formistic' ),
			'read'    => __( 'Viewed', 'formistic' ),
			'replied' => __( 'Replied', 'formistic' ),
		];
		$label = $labels[ $status ] ?? ucfirst( $status );
		return '<span class="wpistic-formistic-pill wpistic-formistic-pill--' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Human-friendly date.
	 *
	 * @param string $mysql_date MySQL datetime.
	 * @return string
	 */
	protected function human_date( $mysql_date ) {
		$ts = strtotime( (string) $mysql_date );
		if ( ! $ts ) {
			return '';
		}
		$diff = time() - $ts;
		if ( $diff < DAY_IN_SECONDS ) {
			/* translators: %s: human time difference */
			return sprintf( __( '%s ago', 'formistic' ), human_time_diff( $ts ) );
		}
		return date_i18n( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), $ts );
	}
}
