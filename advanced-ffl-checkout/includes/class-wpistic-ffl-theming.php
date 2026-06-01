<?php
/**
 * Portal theming engine.
 *
 * Renders CSS custom properties (variables) from admin-saved settings so the
 * dealer portal can be re-skinned without rebuilding stylesheets.
 *
 * Ships with three free presets (default / dark / minimal) and a Guns2Ammo
 * theme. Pro tier unlocks every individual color, font, layout and custom-CSS
 * field via the License gate (free tier sees them disabled in admin UI).
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class Theming {

	const SETTINGS_KEY = 'wpistic_ffl_theme_settings';

	public function __construct() {
		// No hooks needed — theming is rendered inline by Portal::render_page().
	}

	/**
	 * Default theme settings used on first activation.
	 * Currently set to the Guns2Ammo brand palette.
	 */
	public static function default_theme_settings(): array {
		return [
			'preset'              => 'guns2ammo',
			'business_name'       => get_bloginfo( 'name' ),
			'logo_url'            => '',
			'favicon_url'         => '',
			'support_email'       => get_option( 'admin_email' ),
			'support_phone'       => '',

			// Colors — Guns2Ammo tactical red/black palette
			'color_primary'       => '#C8102E',  // brand red
			'color_primary_hover' => '#A00C24',
			'color_success'       => '#16A34A',
			'color_danger'        => '#DC2626',
			'color_warning'       => '#F59E0B',
			'color_bg'            => '#0F1115',
			'color_surface'       => '#1A1D24',
			'color_text'          => '#F4F4F5',
			'color_text_muted'    => '#A1A1AA',
			'color_border'        => '#2A2F3A',

			// Typography
			'font_family'         => 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
			'heading_weight'      => '700',
			'body_size'           => '16',

			// Layout
			'max_width'           => '640',
			'card_style'          => 'shadow',  // flat | shadow | bordered
			'button_style'        => 'rounded', // rounded | square | pill
			'header_style'        => 'logo-center',

			// Content
			'welcome_heading'     => __( 'Confirm Firearm Transfer Receipt', 'advanced-ffl-checkout' ),
			'welcome_subtext'     => __( 'You are receiving this firearm shipment from {store_name}. Please confirm receipt to update the transfer record.', 'advanced-ffl-checkout' ),
			'btn_received_label'  => __( '✓ Item Received', 'advanced-ffl-checkout' ),
			'btn_issue_label'     => __( '⚠ Report Issue', 'advanced-ffl-checkout' ),
			'btn_not_mine_label'  => __( '✗ Not My Shipment', 'advanced-ffl-checkout' ),
			'footer_text'         => __( 'For questions about this transfer, contact {store_name} at {store_support_email}.', 'advanced-ffl-checkout' ),
			'privacy_url'         => '',
			'terms_url'           => '',
			'custom_css'          => '',
			'show_branding'       => 1,
		];
	}

	/**
	 * Get merged settings (defaults + admin-saved overrides).
	 */
	public static function settings(): array {
		$saved = get_option( self::SETTINGS_KEY, [] );
		return array_merge( self::default_theme_settings(), is_array( $saved ) ? $saved : [] );
	}

	/**
	 * Apply a named preset and return its color/typography overrides.
	 */
	public static function preset( string $name ): array {
		$presets = [
			'default' => [
				'color_primary'       => '#7C5CF6',
				'color_primary_hover' => '#6440EB',
				'color_bg'            => '#F5F7FB',
				'color_surface'       => '#FFFFFF',
				'color_text'          => '#1F2937',
				'color_text_muted'    => '#6B7280',
				'color_border'        => '#E5E7EB',
			],
			'dark' => [
				'color_primary'       => '#A78BFA',
				'color_primary_hover' => '#8B6FF1',
				'color_bg'            => '#0B0F19',
				'color_surface'       => '#161B27',
				'color_text'          => '#E5E7EB',
				'color_text_muted'    => '#9CA3AF',
				'color_border'        => '#23293A',
			],
			'minimal' => [
				'color_primary'       => '#111827',
				'color_primary_hover' => '#000000',
				'color_bg'            => '#FFFFFF',
				'color_surface'       => '#F9FAFB',
				'color_text'          => '#111827',
				'color_text_muted'    => '#6B7280',
				'color_border'        => '#E5E7EB',
			],
			'guns2ammo' => [
				'color_primary'       => '#C8102E',
				'color_primary_hover' => '#A00C24',
				'color_bg'            => '#0F1115',
				'color_surface'       => '#1A1D24',
				'color_text'          => '#F4F4F5',
				'color_text_muted'    => '#A1A1AA',
				'color_border'        => '#2A2F3A',
			],
		];
		return $presets[ $name ] ?? $presets['guns2ammo'];
	}

	/**
	 * Render the inline <style> block for the portal page.
	 * Outputs CSS custom properties scoped to .wpistic-ffl-portal.
	 */
	public static function render_inline_css(): string {
		$s = self::settings();

		$radius = match ( $s['button_style'] ) {
			'square' => '4px',
			'pill'   => '999px',
			default  => '10px',
		};

		$shadow = match ( $s['card_style'] ) {
			'flat'     => 'none',
			'bordered' => 'none',
			default    => '0 12px 48px -12px rgba(0,0,0,0.45)',
		};

		$border = 'bordered' === $s['card_style']
			? '1px solid var(--ffl-border)'
			: 'none';

		ob_start();
		?>
.wpistic-ffl-portal {
	--ffl-primary:        <?php echo esc_html( $s['color_primary'] ); ?>;
	--ffl-primary-hover:  <?php echo esc_html( $s['color_primary_hover'] ); ?>;
	--ffl-success:        <?php echo esc_html( $s['color_success'] ); ?>;
	--ffl-danger:         <?php echo esc_html( $s['color_danger'] ); ?>;
	--ffl-warning:        <?php echo esc_html( $s['color_warning'] ); ?>;
	--ffl-bg:             <?php echo esc_html( $s['color_bg'] ); ?>;
	--ffl-surface:        <?php echo esc_html( $s['color_surface'] ); ?>;
	--ffl-text:           <?php echo esc_html( $s['color_text'] ); ?>;
	--ffl-text-muted:     <?php echo esc_html( $s['color_text_muted'] ); ?>;
	--ffl-border:         <?php echo esc_html( $s['color_border'] ); ?>;
	--ffl-radius:         <?php echo esc_html( $radius ); ?>;
	--ffl-shadow:         <?php echo esc_html( $shadow ); ?>;
	--ffl-card-border:    <?php echo esc_html( $border ); ?>;
	--ffl-font:           <?php echo esc_html( $s['font_family'] ); ?>;
	--ffl-heading-weight: <?php echo esc_html( $s['heading_weight'] ); ?>;
	--ffl-body-size:      <?php echo (int) $s['body_size']; ?>px;
	--ffl-max-width:      <?php echo (int) $s['max_width']; ?>px;
}
		<?php
		$base = ob_get_clean();

		// Custom CSS injection (Pro feature)
		$custom = License::can( 'custom_css' ) && ! empty( $s['custom_css'] )
			? "\n/* Custom CSS */\n" . wp_strip_all_tags( $s['custom_css'] )
			: '';

		return $base . $custom;
	}

	/**
	 * Replace merge tags in template strings with actual data.
	 *
	 * Supported: {store_name}, {store_logo_url}, {store_support_email}, {store_phone},
	 *            {dealer_name}, {dealer_business}, {dealer_license}, {dealer_city},
	 *            {customer_name_masked}, {transfer_id}, {transfer_ref}, {tracking_number},
	 *            {carrier}, {confirm_url}, {expiry_date}, {days_until_expiry}.
	 */
	public static function replace_tags( string $template, array $context = [] ): string {
		$theme = self::settings();

		$store = [
			'{store_name}'          => $theme['business_name'],
			'{store_logo_url}'      => $theme['logo_url'],
			'{store_support_email}' => $theme['support_email'],
			'{store_phone}'         => $theme['support_phone'],
		];

		$out = strtr( $template, $store + $context );
		return $out;
	}

	/**
	 * Mask "John Smith" → "John S."
	 */
	public static function mask_name( string $name ): string {
		$parts = preg_split( '/\s+/', trim( $name ) );
		if ( count( $parts ) < 2 ) {
			return $parts[0] ?? '';
		}
		$last = array_pop( $parts );
		return implode( ' ', $parts ) . ' ' . strtoupper( substr( $last, 0, 1 ) ) . '.';
	}
}
