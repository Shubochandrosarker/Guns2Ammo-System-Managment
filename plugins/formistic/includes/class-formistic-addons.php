<?php
/**
 * Addons — a modular on/off system for optional Formistic features.
 *
 * Each addon gates a feature module (and, where relevant, its settings tab and
 * admin submenu). Site owners enable only what they need from a card-based
 * Addons screen, keeping the dashboard and runtime lean.
 *
 * @package Wpistic_Formistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Addon registry + management screen.
 */
class Wpistic_Formistic_Addons {

	/** Option storing the slug => '1'|'0' activation map. */
	const OPTION = 'wpistic_formistic_addons';

	/** Admin page slug under the Formistic menu. */
	const PAGE = 'formistic-addons';

	/** Capability required to manage addons. */
	const CAP = 'manage_options';

	/**
	 * Addon catalog.
	 *
	 * @return array<string,array{label:string,desc:string,icon:string,default:string}>
	 */
	public static function definitions() {
		return [
			'captures'      => [
				'label'   => __( 'Form Captures', 'formistic' ),
				'desc'    => __( 'Pull submissions from Contact Form 7, WPForms, Gravity Forms, Fluent Forms, and compatible theme forms straight into your inbox.', 'formistic' ),
				'icon'    => 'dashicons-download',
				'default' => '1',
			],
			'spam'          => [
				'label'   => __( 'Spam Protection', 'formistic' ),
				'desc'    => __( 'Block junk with honeypot, rate limiting, reCAPTCHA v3, Cloudflare Turnstile, Akismet, and an IP blocklist.', 'formistic' ),
				'icon'    => 'dashicons-shield',
				'default' => '1',
			],
			'autoresponder' => [
				'label'   => __( 'Auto Responder', 'formistic' ),
				'desc'    => __( 'Send an automatic acknowledgement email to people the moment they submit a form.', 'formistic' ),
				'icon'    => 'dashicons-email-alt',
				'default' => '0',
			],
			'webhook'       => [
				'label'   => __( 'Webhooks', 'formistic' ),
				'desc'    => __( 'Forward each submission as JSON to one or more endpoints, with optional HMAC signing and replay.', 'formistic' ),
				'icon'    => 'dashicons-admin-links',
				'default' => '0',
			],
			'templates'     => [
				'label'   => __( 'Reply Templates', 'formistic' ),
				'desc'    => __( 'Save reusable reply snippets with placeholders and insert them while answering from the inbox.', 'formistic' ),
				'icon'    => 'dashicons-media-text',
				'default' => '1',
			],
			'ai'            => [
				'label'   => __( 'AI Automation', 'formistic' ),
				'desc'    => __( 'Smart reply drafts, AI spam scoring, smart tags, and a keyword rule engine — with free local or external providers.', 'formistic' ),
				'icon'    => 'dashicons-superhero',
				'default' => '0',
			],
			'newsletter'    => [
				'label'   => __( 'Newsletter', 'formistic' ),
				'desc'    => __( 'Collect newsletter sign-ups in a dedicated subscribers list (kept separate from the inbox) with CSV export.', 'formistic' ),
				'icon'    => 'dashicons-megaphone',
				'default' => '0',
			],
		];
	}

	/**
	 * The whole activation map (slug => '1'|'0'), filled with defaults.
	 *
	 * @return array<string,string>
	 */
	public static function map() {
		$saved = get_option( self::OPTION, [] );
		$saved = is_array( $saved ) ? $saved : [];
		$map   = [];
		foreach ( self::definitions() as $slug => $def ) {
			$map[ $slug ] = isset( $saved[ $slug ] ) ? ( $saved[ $slug ] ? '1' : '0' ) : $def['default'];
		}
		return $map;
	}

	/**
	 * Is a given addon active?
	 *
	 * @param string $slug Addon slug.
	 * @return bool
	 */
	public static function is_active( $slug ) {
		$map = self::map();
		return isset( $map[ $slug ] ) && '1' === $map[ $slug ];
	}

	/**
	 * Register admin hooks.
	 */
	public function register() {
		add_action( 'admin_menu', [ $this, 'menu' ], 15 );
		add_action( 'wp_ajax_wpistic_formistic_toggle_addon', [ $this, 'ajax_toggle' ] );
	}

	/**
	 * Add the Addons submenu.
	 */
	public function menu() {
		$hook = add_submenu_page(
			Wpistic_Formistic_Admin::PAGE,
			__( 'Addons', 'formistic' ),
			__( 'Addons', 'formistic' ),
			self::CAP,
			self::PAGE,
			[ $this, 'render' ]
		);
		Wpistic_Formistic_Admin::$page_hooks[] = $hook;
	}

	/**
	 * AJAX: toggle a single addon on/off.
	 */
	public function ajax_toggle() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'wpistic_formistic_admin' ) || ! current_user_can( self::CAP ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'formistic' ) ], 403 );
		}
		$slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
		$on   = isset( $_POST['active'] ) && '1' === (string) $_POST['active'];

		$defs = self::definitions();
		if ( ! isset( $defs[ $slug ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown addon.', 'formistic' ) ], 400 );
		}

		$map          = self::map();
		$map[ $slug ] = $on ? '1' : '0';
		update_option( self::OPTION, $map );

		wp_send_json_success( [
			'slug'   => $slug,
			'active' => $on,
			/* translators: %s: addon name */
			'message' => $on
				? sprintf( __( '%s enabled.', 'formistic' ), $defs[ $slug ]['label'] )
				: sprintf( __( '%s disabled.', 'formistic' ), $defs[ $slug ]['label'] ),
		] );
	}

	/**
	 * Render the card-based Addons screen.
	 */
	public function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$admin = new Wpistic_Formistic_Admin();
		$map   = self::map();
		?>
		<div class="wrap wpistic-formistic-wrap">
			<?php $admin->header( __( 'Turn features on or off — keep only what your site needs.', 'formistic' ) ); ?>

			<div class="wpistic-formistic-addons-grid">
				<?php foreach ( self::definitions() as $slug => $def ) :
					$active = '1' === $map[ $slug ];
					?>
					<div class="wpistic-formistic-addon-card<?php echo $active ? ' is-active' : ''; ?>" data-slug="<?php echo esc_attr( $slug ); ?>">
						<div class="wpistic-formistic-addon-card__icon">
							<span class="dashicons <?php echo esc_attr( $def['icon'] ); ?>"></span>
						</div>
						<div class="wpistic-formistic-addon-card__body">
							<div class="wpistic-formistic-addon-card__head">
								<h3><?php echo esc_html( $def['label'] ); ?></h3>
								<label class="wpistic-formistic-switch" title="<?php esc_attr_e( 'Toggle addon', 'formistic' ); ?>">
									<input type="checkbox" class="wpistic-formistic-addon-toggle" data-slug="<?php echo esc_attr( $slug ); ?>" <?php checked( $active ); ?>>
									<span class="wpistic-formistic-switch__track"><span class="wpistic-formistic-switch__thumb"></span></span>
								</label>
							</div>
							<p><?php echo esc_html( $def['desc'] ); ?></p>
							<span class="wpistic-formistic-addon-card__status"><?php echo $active ? esc_html__( 'Active', 'formistic' ) : esc_html__( 'Inactive', 'formistic' ); ?></span>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}
}
