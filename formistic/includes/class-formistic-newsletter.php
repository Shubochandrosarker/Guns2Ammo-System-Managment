<?php
/**
 * Newsletter — email-list capture, admin list table, and CSV export.
 *
 * Wires the footer "GET RANGE UPDATES" form (and any [wpistic_formistic_newsletter]
 * shortcode placement) to a dedicated subscribers table so the client
 * can see and export their list from a single dashboard tab.
 *
 * @package Wpistic_Formistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Newsletter capture + admin list + CSV export.
 */
class Wpistic_Formistic_Newsletter {

	/** Subscribers table name (without prefix). */
	const TABLE = 'wpistic_formistic_subscribers';

	/** Admin page slug under the Formistic menu. */
	const PAGE = 'formistic-newsletter';

	/** AJAX action used by the public form. */
	const ACTION = 'wpistic_formistic_newsletter_subscribe';

	/** Nonce action used for both public subscribe + admin export/unsubscribe. */
	const NONCE = 'wpistic_formistic_newsletter';

	/** Per-IP throttle window (seconds). */
	const THROTTLE = 60;

	/** Fully-qualified subscribers table name. */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Register hooks for both the public capture path and admin UI.
	 */
	public function register() {
		// Public AJAX (logged-in + nopriv).
		add_action( 'wp_ajax_' . self::ACTION,        [ __CLASS__, 'handle_subscribe' ] );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, [ __CLASS__, 'handle_subscribe' ] );

		// REST mirror — themes can also POST here from JS that already
		// uses the WP REST nonce (works alongside ajax-url path).
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest' ] );

		// Shortcode for in-content placement, e.g. [wpistic_formistic_newsletter].
		add_shortcode( 'wpistic_formistic_newsletter', [ __CLASS__, 'shortcode' ] );

		// Register the front-end submit handler for the shortcode form.
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'register_assets' ] );

		// Auto-subscribe when a contact-form submission opts in via a
		// `*_newsletter` checkbox (e.g. the theme contact page's
		// `g2a_f_newsletter` field). Looks for any captured field whose
		// name ends with `_newsletter` and is truthy.
		add_action( 'wpistic_formistic_submission_captured', [ __CLASS__, 'maybe_subscribe_from_form' ], 10, 3 );

		if ( is_admin() ) {
			add_action( 'admin_menu', [ __CLASS__, 'register_admin_page' ], 20 );
			add_action( 'admin_post_wpistic_formistic_newsletter_export',      [ __CLASS__, 'handle_export' ] );
			add_action( 'admin_post_wpistic_formistic_newsletter_unsubscribe', [ __CLASS__, 'handle_admin_unsubscribe' ] );
		}
	}

	/**
	 * Subscribe the submitter when a contact form includes an opted-in
	 * `*_newsletter` checkbox.
	 */
	public static function maybe_subscribe_from_form( $id, $form_name, $fields ) {
		if ( ! is_array( $fields ) ) {
			return;
		}
		$opted_in = false;
		foreach ( $fields as $k => $v ) {
			if ( is_string( $k ) && substr( $k, -10 ) === '_newsletter' ) {
				$val = is_array( $v ) ? implode( ',', $v ) : (string) $v;
				if ( $val !== '' && strtolower( $val ) !== '0' && strtolower( $val ) !== 'no' ) {
					$opted_in = true;
					break;
				}
			}
		}
		if ( ! $opted_in ) {
			return;
		}
		$email = '';
		foreach ( [ 'email', 'sender_email', 'your_email', 'g2a_f_email' ] as $k ) {
			if ( ! empty( $fields[ $k ] ) ) {
				$email = (string) $fields[ $k ];
				break;
			}
		}
		if ( ! $email ) {
			return;
		}
		self::process( $email, 'contact-form:' . substr( (string) $form_name, 0, 40 ) );
	}

	/**
	 * Create / upgrade the subscribers table. Called from Database::install().
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			email VARCHAR(191) NOT NULL,
			source VARCHAR(64) NOT NULL DEFAULT 'footer',
			source_url VARCHAR(255) NOT NULL DEFAULT '',
			ip_address VARCHAR(64) NOT NULL DEFAULT '',
			user_agent VARCHAR(255) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			consent_at DATETIME NOT NULL,
			unsubscribed_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email),
			KEY status (status),
			KEY source (source),
			KEY consent_at (consent_at)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Register the REST mirror of the subscribe endpoint.
	 */
	public static function register_rest() {
		register_rest_route( 'formistic/v1', '/newsletter', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'rest_subscribe' ],
			// Public endpoint: gated by the wp REST nonce (X-WP-Nonce header
			// or _wpnonce param) plus per-IP throttle in process().
			'permission_callback' => [ __CLASS__, 'rest_permission' ],
			'args'                => [
				'email'  => [ 'type' => 'string', 'required' => true ],
				'source' => [ 'type' => 'string', 'required' => false ],
			],
		] );
	}

	/**
	 * Require a valid wp_rest nonce. Returning true for logged-out users is
	 * fine — wp_verify_nonce checks the token regardless of auth state.
	 */
	public static function rest_permission( $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' ) ?: $request->get_param( '_wpnonce' );
		if ( ! $nonce || ! wp_verify_nonce( (string) $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Invalid nonce.', 'formistic' ), [ 'status' => 403 ] );
		}
		return true;
	}

	/**
	 * REST adapter — same logic as the AJAX handler, just different transport.
	 */
	public static function rest_subscribe( $request ) {
		$result = self::process( (string) $request->get_param( 'email' ), (string) $request->get_param( 'source' ) );
		$status = ( 'ok' === $result['status'] ) ? 200 : ( 'duplicate' === $result['status'] ? 200 : 400 );
		return new WP_REST_Response( $result, $status );
	}

	/**
	 * AJAX handler. Form POSTs with `email`, `source`, `_wpnonce`.
	 */
	public static function handle_subscribe() {
		check_ajax_referer( self::NONCE, '_wpnonce' );
		$email  = isset( $_POST['email'] )  ? sanitize_text_field( wp_unslash( $_POST['email'] ) )  : '';
		$source = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : 'footer';
		$result = self::process( $email, $source );
		if ( 'invalid' === $result['status'] || 'throttled' === $result['status'] ) {
			wp_send_json_error( $result, 400 );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Core subscribe routine — sanitize, throttle, upsert. Returns a
	 * shape-stable result array used by both REST + AJAX paths.
	 */
	public static function process( $email, $source = 'footer' ) {
		$email  = sanitize_email( $email );
		$source = $source ? substr( $source, 0, 64 ) : 'footer';

		if ( ! $email || ! is_email( $email ) ) {
			return [
				'status'  => 'invalid',
				'message' => __( 'Please enter a valid email address.', 'formistic' ),
			];
		}

		// Per-IP throttle — stops a single visitor from flooding the form.
		$ip  = self::client_ip();
		$key = 'wpistic_formistic_nl_' . md5( $ip );
		if ( get_transient( $key ) ) {
			return [
				'status'  => 'throttled',
				'message' => __( 'Please wait a moment before trying again.', 'formistic' ),
			];
		}
		set_transient( $key, 1, self::THROTTLE );

		global $wpdb;
		$table = self::table();

		// Re-activate a previously unsubscribed email instead of erroring.
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM {$table} WHERE email = %s", $email ) );
		if ( $existing ) {
			if ( 'active' === $existing->status ) {
				return [
					'status'  => 'duplicate',
					'message' => __( "You're already subscribed — thanks!", 'formistic' ),
				];
			}
			$wpdb->update(
				$table,
				[
					'status'          => 'active',
					'consent_at'      => current_time( 'mysql' ),
					'unsubscribed_at' => null,
					'source'          => $source,
					'source_url'      => self::source_url(),
					'ip_address'      => $ip,
					'user_agent'      => self::user_agent(),
				],
				[ 'id' => (int) $existing->id ]
			);
			do_action( 'wpistic_formistic_newsletter_resubscribed', $email, $source );
		} else {
			$wpdb->insert( $table, [
				'email'      => $email,
				'source'     => $source,
				'source_url' => self::source_url(),
				'ip_address' => $ip,
				'user_agent' => self::user_agent(),
				'status'     => 'active',
				'consent_at' => current_time( 'mysql' ),
			] );
			do_action( 'wpistic_formistic_newsletter_subscribed', $email, $source, (int) $wpdb->insert_id );
		}

		return [
			'status'  => 'ok',
			'message' => __( "You're on the list. Watch your inbox.", 'formistic' ),
		];
	}

	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return substr( $ip, 0, 64 );
	}

	private static function user_agent() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		return substr( $ua, 0, 255 );
	}

	private static function source_url() {
		$ref = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		return substr( $ref, 0, 255 );
	}

	/**
	 * Register the front-end subscribe script. Enqueued on demand by the
	 * shortcode so it only loads on pages that render the form.
	 */
	public static function register_assets() {
		wp_register_script(
			'wpistic-formistic-newsletter',
			WPISTIC_FORMISTIC_URL . 'assets/newsletter.js',
			[],
			WPISTIC_FORMISTIC_VERSION,
			true
		);
		wp_localize_script( 'wpistic-formistic-newsletter', 'Wpistic_Formistic_NL', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
			'i18n'    => [
				'sending' => __( 'Subscribing…', 'formistic' ),
				'ok'      => __( "You're on the list. Watch your inbox.", 'formistic' ),
				'invalid' => __( 'Please enter a valid email address.', 'formistic' ),
				'error'   => __( 'Something went wrong. Please try again.', 'formistic' ),
			],
		] );
	}

	/**
	 * Shortcode renderer for arbitrary in-content placement.
	 */
	public static function shortcode( $atts = [] ) {
		$atts = shortcode_atts( [
			'source'      => 'shortcode',
			'placeholder' => __( 'your@email.com', 'formistic' ),
			'button'      => __( 'Subscribe', 'formistic' ),
		], $atts, 'wpistic_formistic_newsletter' );

		wp_enqueue_script( 'wpistic-formistic-newsletter' );

		ob_start();
		?>
		<form class="wpistic-formistic-newsletter" data-source="<?php echo esc_attr( $atts['source'] ); ?>">
			<input type="email" name="email" required placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>">
			<button type="submit"><?php echo esc_html( $atts['button'] ); ?></button>
			<span class="wpistic-formistic-newsletter-status" role="status" aria-live="polite"></span>
		</form>
		<?php
		return ob_get_clean();
	}

	/**
	 * Admin: register the Newsletter submenu under Formistic.
	 */
	public static function register_admin_page() {
		$hook = add_submenu_page(
			Wpistic_Formistic_Admin::PAGE,
			__( 'Newsletter', 'formistic' ),
			__( 'Newsletter', 'formistic' ),
			Wpistic_Formistic_Admin::CAP,
			self::PAGE,
			[ __CLASS__, 'render_admin_page' ]
		);
		Wpistic_Formistic_Admin::$page_hooks[] = $hook;
	}

	/**
	 * Admin: render the subscriber list + filters + export button.
	 */
	public static function render_admin_page() {
		if ( ! current_user_can( Wpistic_Formistic_Admin::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'formistic' ) );
		}
		global $wpdb;
		$table = self::table();

		$per_page = 25;
		$paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$offset   = ( $paged - 1 ) * $per_page;
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'active'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$where  = 'WHERE 1=1';
		$params = [];
		if ( in_array( $status, [ 'active', 'unsubscribed' ], true ) ) {
			$where   .= ' AND status = %s';
			$params[] = $status;
		}
		if ( $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where   .= ' AND (email LIKE %s OR source LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		$total_sql = "SELECT COUNT(*) FROM {$table} {$where}";
		$rows_sql  = "SELECT * FROM {$table} {$where} ORDER BY consent_at DESC LIMIT %d OFFSET %d";
		$params_rows = array_merge( $params, [ $per_page, $offset ] );

		$total = $params
			? (int) $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) )
			: (int) $wpdb->get_var( $total_sql );
		$rows  = $wpdb->get_results( $wpdb->prepare( $rows_sql, $params_rows ) );

		$counts = [
			'active'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='active'" ),
			'unsubscribed' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='unsubscribed'" ),
		];

		$base_url   = admin_url( 'admin.php?page=' . self::PAGE );
		$export_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=wpistic_formistic_newsletter_export' ),
			'wpistic_formistic_newsletter_export'
		);
		?>
		<div class="wrap">
			<h1 style="display:flex; align-items:center; gap:10px;">
				<?php esc_html_e( 'Newsletter', 'formistic' ); ?>
				<span class="title-count" style="background:#2271b1; color:#fff; padding:2px 10px; border-radius:10px; font-size:13px;"><?php echo (int) $counts['active']; ?></span>
				<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action"><?php esc_html_e( 'Export CSV', 'formistic' ); ?></a>
			</h1>

			<ul class="subsubsub">
				<li><a href="<?php echo esc_url( add_query_arg( 'status', 'active', $base_url ) ); ?>" class="<?php echo 'active' === $status ? 'current' : ''; ?>"><?php esc_html_e( 'Active', 'formistic' ); ?> <span class="count">(<?php echo (int) $counts['active']; ?>)</span></a> |</li>
				<li><a href="<?php echo esc_url( add_query_arg( 'status', 'unsubscribed', $base_url ) ); ?>" class="<?php echo 'unsubscribed' === $status ? 'current' : ''; ?>"><?php esc_html_e( 'Unsubscribed', 'formistic' ); ?> <span class="count">(<?php echo (int) $counts['unsubscribed']; ?>)</span></a></li>
			</ul>

			<form method="get" style="margin: 12px 0;">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
				<p class="search-box">
					<label class="screen-reader-text" for="wpistic-formistic-nl-search"><?php esc_html_e( 'Search subscribers', 'formistic' ); ?></label>
					<input type="search" id="wpistic-formistic-nl-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Email or source', 'formistic' ); ?>">
					<input type="submit" class="button" value="<?php esc_attr_e( 'Search', 'formistic' ); ?>">
				</p>
			</form>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width:34%;"><?php esc_html_e( 'Email', 'formistic' ); ?></th>
						<th style="width:12%;"><?php esc_html_e( 'Source', 'formistic' ); ?></th>
						<th><?php esc_html_e( 'Source URL', 'formistic' ); ?></th>
						<th style="width:10%;"><?php esc_html_e( 'IP', 'formistic' ); ?></th>
						<th style="width:16%;"><?php esc_html_e( 'Subscribed', 'formistic' ); ?></th>
						<th style="width:10%;"><?php esc_html_e( 'Action', 'formistic' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No subscribers yet.', 'formistic' ); ?></td></tr>
					<?php else : foreach ( $rows as $r ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $r->email ); ?></strong></td>
							<td><?php echo esc_html( $r->source ); ?></td>
							<td style="word-break:break-all;"><?php echo $r->source_url ? '<a href="' . esc_url( $r->source_url ) . '" target="_blank" rel="noopener">' . esc_html( $r->source_url ) . '</a>' : '—'; ?></td>
							<td><?php echo esc_html( $r->ip_address ?: '—' ); ?></td>
							<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $r->consent_at ) ); ?></td>
							<td>
								<?php if ( 'active' === $r->status ) : ?>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wpistic_formistic_newsletter_unsubscribe&id=' . (int) $r->id ), 'wpistic_formistic_newsletter_unsubscribe_' . (int) $r->id ) ); ?>" class="button-link delete" onclick="return confirm('<?php echo esc_js( __( 'Unsubscribe this email?', 'formistic' ) ); ?>');"><?php esc_html_e( 'Unsubscribe', 'formistic' ); ?></a>
								<?php else : ?>
									<em><?php echo esc_html( $r->unsubscribed_at ? mysql2date( get_option( 'date_format' ), $r->unsubscribed_at ) : __( 'Unsubscribed', 'formistic' ) ); ?></em>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; endif; ?>
				</tbody>
			</table>

			<?php
			$total_pages = max( 1, (int) ceil( $total / $per_page ) );
			if ( $total_pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links( [
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
					'total'     => $total_pages,
					'current'   => $paged,
				] ) . '</div></div>';
			}
			?>
		</div>
		<?php
	}

	/**
	 * Admin: stream the subscriber list as CSV.
	 */
	public static function handle_export() {
		if ( ! current_user_can( Wpistic_Formistic_Admin::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to export.', 'formistic' ) );
		}
		check_admin_referer( 'wpistic_formistic_newsletter_export' );

		global $wpdb;
		$table  = self::table();
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'active';

		$where  = '';
		$params = [];
		if ( in_array( $status, [ 'active', 'unsubscribed' ], true ) ) {
			$where    = 'WHERE status = %s';
			$params[] = $status;
		}

		$sql  = "SELECT email, source, source_url, ip_address, status, consent_at, unsubscribed_at FROM {$table} {$where} ORDER BY consent_at DESC";
		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );

		$filename = sprintf( 'newsletter-subscribers-%s-%s.csv', $status ?: 'all', gmdate( 'Y-m-d' ) );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, [ 'Email', 'Source', 'Source URL', 'IP', 'Status', 'Subscribed At (UTC)', 'Unsubscribed At (UTC)' ] );
		if ( $rows ) {
			foreach ( $rows as $r ) {
				fputcsv( $out, [
					$r['email'],
					$r['source'],
					$r['source_url'],
					$r['ip_address'],
					$r['status'],
					$r['consent_at'],
					$r['unsubscribed_at'] ?: '',
				] );
			}
		}
		fclose( $out );
		exit;
	}

	/**
	 * Admin: per-row unsubscribe.
	 */
	public static function handle_admin_unsubscribe() {
		if ( ! current_user_can( Wpistic_Formistic_Admin::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'formistic' ) );
		}
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'wpistic_formistic_newsletter_unsubscribe_' . $id );

		if ( $id > 0 ) {
			global $wpdb;
			$wpdb->update(
				self::table(),
				[ 'status' => 'unsubscribed', 'unsubscribed_at' => current_time( 'mysql' ) ],
				[ 'id' => $id ]
			);
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}
}
