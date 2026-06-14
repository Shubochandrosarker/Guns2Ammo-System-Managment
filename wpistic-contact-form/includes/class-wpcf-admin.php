<?php
/**
 * Admin UI — the "WPistic Contact" dashboard: inbox, view, reply, settings.
 *
 * @package WPISTIC_CF
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the branded admin experience.
 */
class WPISTIC_CF_Admin {

	/** Admin page slug. */
	const PAGE = 'wpistic-contact';

	/** Capability required to manage submissions. */
	const CAP = 'manage_options';

	/**
	 * Register admin hooks.
	 */
	public function register() {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_init', [ 'WPISTIC_CF_Database', 'maybe_upgrade' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_filter( 'plugin_action_links_' . WPISTIC_CF_BASENAME, [ $this, 'action_links' ] );
	}

	/**
	 * Add the top-level menu and submenus.
	 */
	public function menu() {
		$counts = WPISTIC_CF_Database::status_counts();
		$bubble = $counts['new'] > 0
			? ' <span class="awaiting-mod">' . (int) $counts['new'] . '</span>'
			: '';

		add_menu_page(
			__( 'WPistic Contact', 'wpistic-contact-form' ),
			__( 'WPistic Contact', 'wpistic-contact-form' ) . $bubble,
			self::CAP,
			self::PAGE,
			[ $this, 'render_inbox' ],
			'dashicons-email-alt',
			26
		);

		add_submenu_page(
			self::PAGE,
			__( 'Inbox', 'wpistic-contact-form' ),
			__( 'Inbox', 'wpistic-contact-form' ),
			self::CAP,
			self::PAGE,
			[ $this, 'render_inbox' ]
		);
		add_submenu_page(
			self::PAGE,
			__( 'Threads', 'wpistic-contact-form' ),
			__( 'Threads', 'wpistic-contact-form' ),
			self::CAP,
			self::PAGE . '-threads',
			[ $this, 'render_threads_proxy' ]
		);

		add_submenu_page(
			self::PAGE,
			__( 'Analytics', 'wpistic-contact-form' ),
			__( 'Analytics', 'wpistic-contact-form' ),
			self::CAP,
			self::PAGE . '-analytics',
			[ $this, 'render_analytics_proxy' ]
		);

		add_submenu_page(
			self::PAGE,
			__( 'Settings', 'wpistic-contact-form' ),
			__( 'Settings', 'wpistic-contact-form' ),
			self::CAP,
			self::PAGE . '-settings',
			[ $this, 'render_settings_proxy' ]
		);
	}

	/**
	 * Proxy to WPISTIC_CF_Settings::render so the brand header stays in WPISTIC_CF_Admin.
	 */
	public function render_settings_proxy() {
		( new WPISTIC_CF_Settings() )->render( [ $this, 'header' ] );
	}

	/**
	 * Proxy to WPISTIC_CF_Analytics::render so the brand header stays in WPISTIC_CF_Admin.
	 */
	public function render_analytics_proxy() {
		( new WPISTIC_CF_Analytics() )->render( [ $this, 'header' ] );
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
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Inbox', 'wpistic-contact-form' ) . '</a>' );
		return $links;
	}

	/**
	 * Enqueue admin assets on plugin pages only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function assets( $hook ) {
		// Load on every plugin admin page AND on the form CPT edit screens.
		$is_plugin_page = false !== strpos( (string) $hook, self::PAGE );
		$is_form_screen = false;
		if ( in_array( $hook, [ 'post.php', 'post-new.php', 'edit.php' ], true ) ) {
			$screen         = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			$is_form_screen = $screen && isset( $screen->post_type ) && 'WPISTIC_CF_form' === $screen->post_type;
		}
		if ( ! $is_plugin_page && ! $is_form_screen ) {
			return;
		}
		wp_enqueue_style( 'WPISTIC_CF-admin', WPISTIC_CF_URL . 'assets/admin.css', [], WPISTIC_CF_VERSION );
		wp_enqueue_script( 'WPISTIC_CF-admin', WPISTIC_CF_URL . 'assets/admin.js', [], WPISTIC_CF_VERSION, true );
		wp_localize_script(
			'WPISTIC_CF-admin',
			'WPISTIC_CF',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'WPISTIC_CF_admin' ),
				'i18n'    => [
					'sending'         => __( 'Sending…', 'wpistic-contact-form' ),
					'loading'         => __( 'Loading…', 'wpistic-contact-form' ),
					'sendReply'       => __( 'Send Reply', 'wpistic-contact-form' ),
					'sent'            => __( 'Reply sent.', 'wpistic-contact-form' ),
					'error'           => __( 'Something went wrong. Please try again.', 'wpistic-contact-form' ),
					'confirmDel'      => __( 'Delete this submission permanently?', 'wpistic-contact-form' ),
					'confirmBulkDel'  => __( 'Delete the selected submissions permanently? This also removes their replies and attached files.', 'wpistic-contact-form' ),
					'noBulkAction'    => __( 'Pick a bulk action first.', 'wpistic-contact-form' ),
					'noBulkSelection' => __( 'Select at least one submission first.', 'wpistic-contact-form' ),
					'noEmail'         => __( 'This submission has no email address to reply to.', 'wpistic-contact-form' ),
					'detailsTitle'    => __( 'Submission Details', 'wpistic-contact-form' ),
					'statusNew'       => __( 'New', 'wpistic-contact-form' ),
					'statusRead'      => __( 'Viewed', 'wpistic-contact-form' ),
					'statusReplied'   => __( 'Replied', 'wpistic-contact-form' ),
					'showExtras'      => __( 'Show CC / BCC', 'wpistic-contact-form' ),
					'hideExtras'      => __( 'Hide CC / BCC', 'wpistic-contact-form' ),
					'quotedHeader'    => __( "\n\n— On {date}, {name} wrote: —\n", 'wpistic-contact-form' ),
					'noteAdded'       => __( 'Internal note added.', 'wpistic-contact-form' ),
				],
			]
		);
	}

	/**
	 * Shared branded page header (called by both render_inbox and the
	 * settings page via WPISTIC_CF_Settings::render).
	 *
	 * @param string $subtitle Sub-heading text.
	 */
	public function header( $subtitle ) {
		?>
		<div class="WPISTIC_CF-brandbar">
			<div class="WPISTIC_CF-logo">
				<span class="WPISTIC_CF-logo__mark">W</span>
				<span class="WPISTIC_CF-logo__text">WordPress<strong>istic</strong></span>
			</div>
			<div class="WPISTIC_CF-brandbar__title">
				<h1><?php esc_html_e( 'WPistic Contact', 'wpistic-contact-form' ); ?></h1>
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

		$counts = WPISTIC_CF_Database::status_counts();
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$form   = isset( $_GET['form'] ) ? sanitize_text_field( wp_unslash( $_GET['form'] ) ) : '';
		$status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
		$sender = isset( $_GET['sender'] ) ? sanitize_email( wp_unslash( $_GET['sender'] ) ) : '';
		$view   = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

		$per_page = 20;
		if ( 'threads' === $view ) {
			$result = WPISTIC_CF_Database::query_threads( [
				'search'   => $search,
				'paged'    => $paged,
				'per_page' => $per_page,
			] );
		} else {
			$result = WPISTIC_CF_Database::query_submissions( [
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

		$notice_slug = isset( $_GET['WPISTIC_CF_notice'] ) ? sanitize_key( $_GET['WPISTIC_CF_notice'] ) : '';
		$notice_n    = isset( $_GET['n'] ) ? (int) $_GET['n'] : 0;
		$notice      = class_exists( 'WPISTIC_CF_Bulk' ) ? WPISTIC_CF_Bulk::notice_for( $notice_slug, $notice_n ) : null;

		$export_base = add_query_arg(
			array_filter( [
				'action' => 'WPISTIC_CF_export',
				's'      => $search,
				'form'   => $form,
				'status' => $status,
				'scope'  => 'filtered',
			] ),
			admin_url( 'admin-post.php' )
		);
		$export_csv  = wp_nonce_url( add_query_arg( 'format', 'csv',  $export_base ), 'WPISTIC_CF_export' );
		$export_json = wp_nonce_url( add_query_arg( 'format', 'json', $export_base ), 'WPISTIC_CF_export' );
		?>
		<div class="wrap WPISTIC_CF-wrap">
			<?php $this->header( __( 'Every contact form & website submission, in one inbox.', 'wpistic-contact-form' ) ); ?>

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
					<p><?php echo esc_html( $notice['text'] ); ?></p>
				</div>
			<?php endif; ?>
			<div class="WPISTIC_CF-tabs" style="margin-top:14px;">
				<a class="WPISTIC_CF-tab<?php echo 'threads' !== $view ? ' is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>"><?php esc_html_e( 'Submissions', 'wpistic-contact-form' ); ?></a>
				<a class="WPISTIC_CF-tab<?php echo 'threads' === $view ? ' is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&view=threads' ) ); ?>"><?php esc_html_e( 'Threads', 'wpistic-contact-form' ); ?></a>
			</div>

			<?php if ( 'threads' !== $view ) : ?>
			<div class="WPISTIC_CF-stats">
				<?php
				$cards = [
					''        => [ __( 'All Submissions', 'wpistic-contact-form' ), $counts['total'], 'all' ],
					'new'     => [ __( 'New / Unread', 'wpistic-contact-form' ), $counts['new'], 'new' ],
					'read'    => [ __( 'Viewed', 'wpistic-contact-form' ), $counts['read'], 'read' ],
					'replied' => [ __( 'Replied', 'wpistic-contact-form' ), $counts['replied'], 'replied' ],
				];
				foreach ( $cards as $key => $card ) :
					$url    = add_query_arg( array_filter( [ 'page' => self::PAGE, 'status' => $key ] ), admin_url( 'admin.php' ) );
					$active = ( $status === $key ) ? ' is-active' : '';
					?>
					<a class="WPISTIC_CF-stat WPISTIC_CF-stat--<?php echo esc_attr( $card[2] . $active ); ?>" href="<?php echo esc_url( $url ); ?>">
						<span class="WPISTIC_CF-stat__num"><?php echo esc_html( number_format_i18n( $card[1] ) ); ?></span>
						<span class="WPISTIC_CF-stat__label"><?php echo esc_html( $card[0] ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>

			<form class="WPISTIC_CF-toolbar" method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<?php if ( $status ) : ?>
					<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
				<?php endif; ?>
				<?php if ( 'threads' === $view ) : ?>
					<input type="hidden" name="view" value="threads">
				<?php endif; ?>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search name, email, message…', 'wpistic-contact-form' ); ?>">
				<?php if ( 'threads' !== $view ) : ?>
				<select name="form">
					<option value=""><?php esc_html_e( 'All forms', 'wpistic-contact-form' ); ?></option>
					<?php foreach ( WPISTIC_CF_Database::form_names() as $fname ) : ?>
						<option value="<?php echo esc_attr( $fname ); ?>" <?php selected( $form, $fname ); ?>><?php echo esc_html( $fname ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php endif; ?>
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'wpistic-contact-form' ); ?></button>
				<?php if ( $search || $form || $status ) : ?>
					<a class="WPISTIC_CF-clear" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>"><?php esc_html_e( 'Clear', 'wpistic-contact-form' ); ?></a>
				<?php endif; ?>
			</form>

			<?php if ( $sender ) : ?>
				<?php $this->render_sender_panel( $sender ); ?>
			<?php endif; ?>

			<?php if ( 'threads' !== $view ) : ?>
			<form id="WPISTIC_CF-bulk-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="WPISTIC_CF_bulk">
				<?php wp_nonce_field( 'WPISTIC_CF_bulk' ); ?>

				<div class="WPISTIC_CF-bulkbar">
					<div class="WPISTIC_CF-bulkbar__left">
						<select name="bulk_action" class="WPISTIC_CF-bulkbar__action">
							<option value=""><?php esc_html_e( 'Bulk actions', 'wpistic-contact-form' ); ?></option>
							<option value="mark_new"><?php esc_html_e( 'Mark as New', 'wpistic-contact-form' ); ?></option>
							<option value="mark_read"><?php esc_html_e( 'Mark as Viewed', 'wpistic-contact-form' ); ?></option>
							<option value="mark_replied"><?php esc_html_e( 'Mark as Replied', 'wpistic-contact-form' ); ?></option>
							<option value="export_csv"><?php esc_html_e( 'Export selected as CSV', 'wpistic-contact-form' ); ?></option>
							<option value="export_json"><?php esc_html_e( 'Export selected as JSON', 'wpistic-contact-form' ); ?></option>
							<option value="delete"><?php esc_html_e( 'Delete', 'wpistic-contact-form' ); ?></option>
						</select>
						<button type="submit" class="button WPISTIC_CF-bulkbar__apply"><?php esc_html_e( 'Apply', 'wpistic-contact-form' ); ?></button>
					</div>
					<div class="WPISTIC_CF-bulkbar__right">
						<span class="WPISTIC_CF-bulkbar__label"><?php esc_html_e( 'Export filtered:', 'wpistic-contact-form' ); ?></span>
						<a class="button" href="<?php echo esc_url( $export_csv ); ?>"><?php esc_html_e( 'CSV', 'wpistic-contact-form' ); ?></a>
						<a class="button" href="<?php echo esc_url( $export_json ); ?>"><?php esc_html_e( 'JSON', 'wpistic-contact-form' ); ?></a>
					</div>
				</div>

				<div class="WPISTIC_CF-panel">
					<table class="WPISTIC_CF-table">
						<thead>
							<tr>
								<th class="WPISTIC_CF-col-check"><input type="checkbox" id="WPISTIC_CF-check-all" aria-label="<?php esc_attr_e( 'Select all', 'wpistic-contact-form' ); ?>"></th>
								<th class="WPISTIC_CF-col-form"><?php esc_html_e( 'Form', 'wpistic-contact-form' ); ?></th>
								<th><?php esc_html_e( 'From', 'wpistic-contact-form' ); ?></th>
								<th class="WPISTIC_CF-col-preview"><?php esc_html_e( 'Message', 'wpistic-contact-form' ); ?></th>
								<th class="WPISTIC_CF-col-date"><?php esc_html_e( 'Received', 'wpistic-contact-form' ); ?></th>
								<th class="WPISTIC_CF-col-status"><?php esc_html_e( 'Status', 'wpistic-contact-form' ); ?></th>
								<th class="WPISTIC_CF-col-actions"><?php esc_html_e( 'Actions', 'wpistic-contact-form' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php if ( ! $items ) : ?>
							<tr class="WPISTIC_CF-empty">
								<td colspan="7">
									<div class="WPISTIC_CF-empty__in">
										<span class="dashicons dashicons-email-alt"></span>
										<p><?php esc_html_e( 'No submissions yet. When visitors submit any form on your website, they will appear here.', 'wpistic-contact-form' ); ?></p>
									</div>
								</td>
							</tr>
						<?php else : ?>
							<?php foreach ( $items as $row ) : ?>
								<tr class="WPISTIC_CF-row WPISTIC_CF-row--<?php echo esc_attr( $row->status ); ?>" data-id="<?php echo esc_attr( $row->id ); ?>">
									<td class="WPISTIC_CF-col-check"><input type="checkbox" class="WPISTIC_CF-check-row" name="ids[]" value="<?php echo esc_attr( $row->id ); ?>" aria-label="<?php esc_attr_e( 'Select submission', 'wpistic-contact-form' ); ?>"></td>
									<td class="WPISTIC_CF-col-form"><span class="WPISTIC_CF-formtag"><?php echo esc_html( $row->form_name ?: __( 'Website Form', 'wpistic-contact-form' ) ); ?></span></td>
									<td>
										<strong class="WPISTIC_CF-from-name"><?php echo esc_html( $row->sender_name ?: __( '—', 'wpistic-contact-form' ) ); ?></strong>
										<?php if ( $row->sender_email ) : ?>
											<span class="WPISTIC_CF-from-email"><?php echo esc_html( $row->sender_email ); ?></span>
										<?php endif; ?>
									</td>
									<td class="WPISTIC_CF-col-preview"><?php echo esc_html( wp_trim_words( (string) $row->message, 14, '…' ) ?: '—' ); ?></td>
									<td class="WPISTIC_CF-col-date"><?php echo esc_html( $this->human_date( $row->created_at ) ); ?></td>
									<td class="WPISTIC_CF-col-status"><?php echo $this->status_pill( $row->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
									<td class="WPISTIC_CF-col-actions">
										<button type="button" class="WPISTIC_CF-btn WPISTIC_CF-btn--view" data-id="<?php echo esc_attr( $row->id ); ?>"><?php esc_html_e( 'View', 'wpistic-contact-form' ); ?></button>
										<button type="button" class="WPISTIC_CF-btn WPISTIC_CF-btn--reply" data-id="<?php echo esc_attr( $row->id ); ?>"<?php disabled( ! $row->sender_email ); ?>><?php esc_html_e( 'Reply', 'wpistic-contact-form' ); ?></button>
										<button type="button" class="WPISTIC_CF-btn WPISTIC_CF-btn--del" data-id="<?php echo esc_attr( $row->id ); ?>" aria-label="<?php esc_attr_e( 'Delete', 'wpistic-contact-form' ); ?>"><span class="dashicons dashicons-trash"></span></button>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
						</tbody>
					</table>
				</div>
			</form>
			<?php else : ?>
				<div class="WPISTIC_CF-panel">
					<table class="WPISTIC_CF-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Sender', 'wpistic-contact-form' ); ?></th>
								<th><?php esc_html_e( 'Submissions', 'wpistic-contact-form' ); ?></th>
								<th><?php esc_html_e( 'Thread Status', 'wpistic-contact-form' ); ?></th>
								<th><?php esc_html_e( 'Last Activity', 'wpistic-contact-form' ); ?></th>
								<th><?php esc_html_e( 'Open', 'wpistic-contact-form' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( ! $items ) : ?>
								<tr><td colspan="5"><?php esc_html_e( 'No threads found.', 'wpistic-contact-form' ); ?></td></tr>
							<?php else : foreach ( $items as $thread ) : ?>
								<?php
								$thread_status = ( (int) $thread->new_count > 0 ) ? 'new' : ( ( (int) $thread->read_count > 0 ) ? 'read' : 'replied' );
								$link = add_query_arg(
									[ 'page' => self::PAGE, 'sender' => (string) $thread->sender_email ],
									admin_url( 'admin.php' )
								);
								?>
								<tr>
									<td><strong><?php echo esc_html( $thread->sender_name ?: __( 'Unknown', 'wpistic-contact-form' ) ); ?></strong><br><span class="WPISTIC_CF-from-email"><?php echo esc_html( $thread->sender_email ); ?></span></td>
									<td><?php echo esc_html( number_format_i18n( (int) $thread->submissions_count ) ); ?></td>
									<td><?php echo $this->status_pill( $thread_status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
									<td><?php echo esc_html( $this->human_date( (string) $thread->last_at ) ); ?></td>
									<td><a class="button" href="<?php echo esc_url( $link ); ?>"><?php esc_html_e( 'Open Sender View', 'wpistic-contact-form' ); ?></a></td>
								</tr>
							<?php endforeach; endif; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<?php if ( $pages > 1 ) : ?>
				<div class="WPISTIC_CF-pagination">
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
						<a class="WPISTIC_CF-page<?php echo $i === $paged ? ' is-current' : ''; ?>" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $i ); ?></a>
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
		$items = WPISTIC_CF_Database::sender_activity( $sender_email );
		?>
		<div class="WPISTIC_CF-panel" style="margin-bottom:12px;padding:14px;">
			<h2 style="margin:0 0 8px;"><?php esc_html_e( 'Unified Sender View', 'wpistic-contact-form' ); ?></h2>
			<p style="margin:0 0 12px;"><strong><?php echo esc_html( $sender_email ); ?></strong></p>
			<?php if ( ! $items ) : ?>
				<p><?php esc_html_e( 'No submissions found for this sender.', 'wpistic-contact-form' ); ?></p>
			<?php else : ?>
				<table class="WPISTIC_CF-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'wpistic-contact-form' ); ?></th>
							<th><?php esc_html_e( 'Form', 'wpistic-contact-form' ); ?></th>
							<th><?php esc_html_e( 'Message', 'wpistic-contact-form' ); ?></th>
							<th><?php esc_html_e( 'Replies', 'wpistic-contact-form' ); ?></th>
							<th><?php esc_html_e( 'Status', 'wpistic-contact-form' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $items as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $this->human_date( (string) $row->created_at ) ); ?></td>
								<td><span class="WPISTIC_CF-formtag"><?php echo esc_html( $row->form_name ?: __( 'Website Form', 'wpistic-contact-form' ) ); ?></span></td>
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
		<div class="WPISTIC_CF-modal" id="WPISTIC_CF-modal-view" hidden>
			<div class="WPISTIC_CF-modal__backdrop" data-close></div>
			<div class="WPISTIC_CF-modal__box" role="dialog" aria-modal="true" aria-labelledby="WPISTIC_CF-view-title">
				<header class="WPISTIC_CF-modal__head">
					<h2 id="WPISTIC_CF-view-title"><?php esc_html_e( 'Submission Details', 'wpistic-contact-form' ); ?></h2>
					<button type="button" class="WPISTIC_CF-modal__x" data-close aria-label="<?php esc_attr_e( 'Close', 'wpistic-contact-form' ); ?>">&times;</button>
				</header>
				<div class="WPISTIC_CF-modal__body" id="WPISTIC_CF-view-body">
					<div class="WPISTIC_CF-loading"><?php esc_html_e( 'Loading…', 'wpistic-contact-form' ); ?></div>
				</div>
				<footer class="WPISTIC_CF-modal__foot">
					<button type="button" class="WPISTIC_CF-btn WPISTIC_CF-btn--ghost" data-close><?php esc_html_e( 'Close', 'wpistic-contact-form' ); ?></button>
					<button type="button" class="WPISTIC_CF-btn WPISTIC_CF-btn--primary" id="WPISTIC_CF-view-reply"><?php esc_html_e( 'Reply by Email', 'wpistic-contact-form' ); ?></button>
				</footer>
			</div>
		</div>

		<div class="WPISTIC_CF-modal" id="WPISTIC_CF-modal-reply" hidden>
			<div class="WPISTIC_CF-modal__backdrop" data-close></div>
			<div class="WPISTIC_CF-modal__box WPISTIC_CF-modal__box--reply" role="dialog" aria-modal="true" aria-labelledby="WPISTIC_CF-reply-title">
				<header class="WPISTIC_CF-modal__head">
					<h2 id="WPISTIC_CF-reply-title"><?php esc_html_e( 'Reply to Submission', 'wpistic-contact-form' ); ?></h2>
					<button type="button" class="WPISTIC_CF-modal__x" data-close aria-label="<?php esc_attr_e( 'Close', 'wpistic-contact-form' ); ?>">&times;</button>
				</header>
				<form class="WPISTIC_CF-modal__body" id="WPISTIC_CF-reply-form">
					<input type="hidden" name="submission_id" value="">
					<label class="WPISTIC_CF-field">
						<span><?php esc_html_e( 'To', 'wpistic-contact-form' ); ?></span>
						<input type="email" name="to" readonly>
					</label>

					<div class="WPISTIC_CF-reply-extras" id="WPISTIC_CF-reply-extras" hidden>
						<label class="WPISTIC_CF-field">
							<span><?php esc_html_e( 'CC', 'wpistic-contact-form' ); ?></span>
							<input type="text" name="cc" placeholder="<?php esc_attr_e( 'comma,separated@example.com', 'wpistic-contact-form' ); ?>">
						</label>
						<label class="WPISTIC_CF-field">
							<span><?php esc_html_e( 'BCC', 'wpistic-contact-form' ); ?></span>
							<input type="text" name="bcc" placeholder="<?php esc_attr_e( 'comma,separated@example.com', 'wpistic-contact-form' ); ?>">
						</label>
					</div>

					<label class="WPISTIC_CF-field">
						<span><?php esc_html_e( 'Subject', 'wpistic-contact-form' ); ?></span>
						<input type="text" name="subject" required>
					</label>

					<div class="WPISTIC_CF-reply-tools">
						<select id="WPISTIC_CF-reply-template" class="WPISTIC_CF-reply-tools__select">
							<option value=""><?php esc_html_e( 'Insert template…', 'wpistic-contact-form' ); ?></option>
						</select>
						<button type="button" class="button button-small" id="WPISTIC_CF-reply-quote"><?php esc_html_e( 'Quote original', 'wpistic-contact-form' ); ?></button>
						<button type="button" class="button button-small" id="WPISTIC_CF-reply-toggle-extras"><?php esc_html_e( 'Show CC / BCC', 'wpistic-contact-form' ); ?></button>
						<label class="WPISTIC_CF-reply-tools__html">
							<input type="checkbox" id="WPISTIC_CF-reply-html" name="html_mode" value="1">
							<span><?php esc_html_e( 'Send as HTML', 'wpistic-contact-form' ); ?></span>
						</label>
					</div>

					<label class="WPISTIC_CF-field">
						<span><?php esc_html_e( 'Your Reply', 'wpistic-contact-form' ); ?></span>
						<textarea name="body" rows="10" required></textarea>
					</label>
					<div class="WPISTIC_CF-reply-status" id="WPISTIC_CF-reply-status" hidden></div>
				</form>
				<footer class="WPISTIC_CF-modal__foot">
					<button type="button" class="WPISTIC_CF-btn WPISTIC_CF-btn--ghost" data-close><?php esc_html_e( 'Cancel', 'wpistic-contact-form' ); ?></button>
					<button type="button" class="WPISTIC_CF-btn WPISTIC_CF-btn--primary" id="WPISTIC_CF-reply-send"><?php esc_html_e( 'Send Reply', 'wpistic-contact-form' ); ?></button>
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
			'new'     => __( 'New', 'wpistic-contact-form' ),
			'read'    => __( 'Viewed', 'wpistic-contact-form' ),
			'replied' => __( 'Replied', 'wpistic-contact-form' ),
		];
		$label = $labels[ $status ] ?? ucfirst( $status );
		return '<span class="WPISTIC_CF-pill WPISTIC_CF-pill--' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
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
			return sprintf( __( '%s ago', 'wpistic-contact-form' ), human_time_diff( $ts ) );
		}
		return date_i18n( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), $ts );
	}
}
