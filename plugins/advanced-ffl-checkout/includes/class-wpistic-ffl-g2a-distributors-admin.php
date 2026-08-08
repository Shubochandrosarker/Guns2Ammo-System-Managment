<?php
/**
 * G2A — Shared "📦 Distributors" admin page.
 *
 * One submenu page for every distributor client, tab-switched server-side
 * via `?tab=`, rather than either a submenu entry per distributor (which
 * would clutter the admin menu once there's more than one or two) or a
 * client-side JS tab switcher rendering every panel's markup on every
 * load. Mirrors how G2A_Carrier_Providers already handles multiple
 * carriers behind one page with a provider selector.
 *
 * Each distributor class owns its own `render_panel()` -- this class only
 * owns the tab bar and picks which one to call.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Distributors_Admin {

	const PAGE_SLUG = 'wpistic-ffl-distributors';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_admin_page' ], 49 );
		add_action( 'admin_init', [ $this, 'redirect_legacy_lipseys_page' ] );
	}

	/**
	 * Before this page existed, Lipsey's had its own submenu at
	 * ?page=wpistic-ffl-lipseys -- redirect anyone with that URL
	 * bookmarked or linked straight to the Lipsey's tab here instead of
	 * letting it 404/access-deny silently.
	 */
	public function redirect_legacy_lipseys_page(): void {
		if ( ! isset( $_GET['page'] ) || 'wpistic-ffl-lipseys' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE_SLUG, 'tab' => 'lipseys' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public function register_admin_page(): void {
		add_submenu_page(
			'wpistic-ffl',
			__( 'Distributors', 'advanced-ffl-checkout' ),
			__( '📦 Distributors', 'advanced-ffl-checkout' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			[ $this, 'render_admin_page' ]
		);
	}

	public function render_admin_page(): void {
		$distributors = G2A_Distributor_Registry::enabled_distributors();
		if ( ! $distributors ) {
			?>
			<div class="wrap wpistic-ffl-admin">
				<h1 style="display:flex;align-items:center;gap:12px;">
					<span style="font-size:24px;">📦</span>
					<?php esc_html_e( 'Distributors', 'advanced-ffl-checkout' ); ?>
				</h1>
				<p class="description" style="max-width:800px;">
					<?php
					printf(
						/* translators: %s: link to the Add-ons admin page */
						esc_html__( 'No distributors are enabled yet. Turn on the ones this store uses from the %s page.', 'advanced-ffl-checkout' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=' . G2A_Addons_Admin::PAGE_SLUG ) ) . '">' . esc_html__( '🧩 Add-ons', 'advanced-ffl-checkout' ) . '</a>'
					);
					?>
				</p>
			</div>
			<?php
			return;
		}

		$slugs       = array_keys( $distributors );
		$active_slug = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : $slugs[0]; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $distributors[ $active_slug ] ) ) {
			$active_slug = $slugs[0];
		}
		$active = $distributors[ $active_slug ];
		?>
		<div class="wrap wpistic-ffl-admin">
			<h1 style="display:flex;align-items:center;gap:12px;">
				<span style="font-size:24px;">📦</span>
				<?php esc_html_e( 'Distributors', 'advanced-ffl-checkout' ); ?>
			</h1>
			<p class="description" style="max-width:800px;">
				<?php esc_html_e( 'Real distributor drop-ship clients. Catalog sync is always free to run any time; submitting a wholesale order is always an explicit click on the distributor\'s own tab below, never triggered automatically by any transfer event. See each tab\'s own note for exactly what was confirmed against a real reference and what wasn\'t.', 'advanced-ffl-checkout' ); ?>
			</p>

			<h2 class="nav-tab-wrapper" style="margin-top:16px;">
				<?php foreach ( $distributors as $slug => $meta ) : ?>
					<a href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE_SLUG, 'tab' => $slug ], admin_url( 'admin.php' ) ) ); ?>"
					   class="nav-tab <?php echo $slug === $active_slug ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $meta['label'] ); ?>
						<span style="font-size:11px;color:#888;font-weight:400;">(<?php echo esc_html( $meta['protocol'] ); ?>)</span>
					</a>
				<?php endforeach; ?>
			</h2>

			<div style="margin-top:8px;">
				<?php
				$class = $active['class'] ?? null;
				if ( $class && class_exists( $class ) && method_exists( $class, 'render_panel' ) ) {
					call_user_func( [ $class, 'render_panel' ] );
				} else {
					echo '<p>' . esc_html__( 'This distributor has no admin panel registered.', 'advanced-ffl-checkout' ) . '</p>';
				}
				?>
			</div>
		</div>
		<?php
	}
}
