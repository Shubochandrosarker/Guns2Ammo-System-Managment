<?php
/**
 * Emails — shared branded HTML wrapper for every outbound customer-facing
 * email (auto-responder, newsletter confirmation).
 *
 * Table-based layout with inline styles throughout (required for Outlook/
 * older email-client compatibility — no external stylesheet, no flexbox/
 * grid). Matches the modernized shell used sitewide for the booking-engine's
 * transactional emails (rounded card, void header with a brand underline,
 * warm neutral tones, Inter font stack) so every automated email on the site
 * shares one visual language.
 *
 * @package Wpistic_Formistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static HTML email layout helpers.
 */
class Wpistic_Formistic_Emails {

	const COLOR_VOID  = '#1A191E';
	const COLOR_BRASS = '#C9A84C';
	const COLOR_EMBER = '#E8802F';

	/**
	 * Live business NAP, sourced from the theme's single source of truth so
	 * the email footer never drifts from the real business facts.
	 *
	 * @return array{name:string,addr:string,phone:string}
	 */
	protected static function biz() {
		$name  = function_exists( 'g2a_biz' ) ? (string) ( g2a_biz()['name'] ?? '' ) : '';
		$addr  = function_exists( 'g2a_biz_addr_line' ) ? (string) g2a_biz_addr_line() : '';
		$phone = function_exists( 'g2a_biz_phone' ) ? (string) g2a_biz_phone() : '';

		return array(
			'name'  => '' !== $name ? $name : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'addr'  => $addr,
			'phone' => $phone,
		);
	}

	/**
	 * Resolve the logo image URL for the email header.
	 *
	 * Order: the wpistic_formistic_email_logo_url option, then the bundled
	 * guns2ammo theme asset when that theme is active, else empty string
	 * (the header falls back to a text brand).
	 *
	 * @return string
	 */
	public static function logo_url() {
		$logo = (string) get_option( 'wpistic_formistic_email_logo_url', '' );
		if ( $logo ) {
			return esc_url_raw( $logo );
		}
		if ( function_exists( 'get_template' ) && function_exists( 'get_template_directory_uri' )
			&& 'guns2ammo' === get_template() ) {
			return esc_url_raw( get_template_directory_uri() . '/assets/img/guns2ammo-logo-email.png' );
		}
		return '';
	}

	/**
	 * Render an on-brand CTA button (table-based, Outlook-safe).
	 *
	 * @param string $label Button label.
	 * @param string $url   Target URL.
	 * @return string
	 */
	public static function button( $label, $url ) {
		return '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:24px 0;">'
			. '<tr><td align="center" bgcolor="' . self::COLOR_EMBER . '" style="border-radius:8px;">'
			. '<a href="' . esc_url( $url ) . '" target="_blank" style="display:inline-block;padding:13px 26px;font-family:\'Inter\',\'Segoe UI\',Arial,sans-serif;font-size:14px;font-weight:600;color:#fff;text-decoration:none;border-radius:8px;">'
			. esc_html( $label )
			. '</a></td></tr></table>';
	}

	/**
	 * Wrap inner HTML in the branded, rounded-card shell.
	 *
	 * @param string $inner_html Pre-escaped inner HTML for the content area.
	 * @param array  $args       {
	 *     Optional layout arguments.
	 *
	 *     @type string $preheader       Hidden inbox preview text.
	 *     @type string $unsubscribe_url When set, a footer unsubscribe line is added.
	 *     @type string $title           <title> tag text (defaults to site name).
	 * }
	 * @return string Full HTML document.
	 */
	public static function wrap( $inner_html, $args = array() ) {
		$args = wp_parse_args( $args, array(
			'preheader'       => '',
			'unsubscribe_url' => '',
			'title'           => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		) );

		$biz  = self::biz();
		$logo = self::logo_url();

		$header = $logo
			? '<img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $biz['name'] ) . '" width="220" style="display:block;width:220px;max-width:100%;height:auto;border:0;">'
			: '<strong style="color:#F7F7F9;font-family:\'Inter\',\'Segoe UI\',Arial,sans-serif;font-size:22px;letter-spacing:.04em;">' . esc_html( $biz['name'] ) . '</strong>';

		$nap_line     = trim( implode( ' · ', array_filter( array( $biz['addr'], $biz['phone'] ) ) ) );
		$footer_lines = array( '<p style="margin:0 0 6px;font-size:13px;font-weight:600;color:#2E2D33;">' . esc_html( $biz['name'] ) . '</p>' );
		if ( $nap_line ) {
			$footer_lines[] = '<p style="margin:0 0 10px;font-size:12px;color:#6E6C74;">' . esc_html( $nap_line ) . '</p>';
		}
		$footer_lines[] = '<p style="margin:0;font-size:11px;color:#9A98A0;">&copy; ' . esc_html( gmdate( 'Y' ) ) . ' ' . esc_html( $biz['name'] ) . '. ' . esc_html__( 'All rights reserved.', 'formistic' ) . '</p>';

		if ( $args['unsubscribe_url'] ) {
			$footer_lines[] = '<p style="margin:10px 0 0;font-size:12px;color:#6E6C74;">'
				. esc_html__( 'No longer want these emails?', 'formistic' ) . ' '
				. '<a href="' . esc_url( $args['unsubscribe_url'] ) . '" style="color:' . self::COLOR_BRASS . ';text-decoration:underline;">' . esc_html__( 'Unsubscribe', 'formistic' ) . '</a>'
				. '</p>';
		}
		$footer_html = apply_filters( 'wpistic_formistic_email_footer_html', implode( '', $footer_lines ), $args );

		$preheader_html = $args['preheader']
			? '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#EEEDE7;">' . esc_html( $args['preheader'] ) . '</div>'
			: '';

		return '<!doctype html><html><head><meta charset="UTF-8">'
			. '<meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<meta name="color-scheme" content="light">'
			. '<title>' . esc_html( $args['title'] ) . '</title></head>'
			. '<body style="margin:0;padding:0;background:#EEEDE7;font-family:\'Inter\',\'Segoe UI\',Roboto,Arial,Helvetica,sans-serif;color:' . self::COLOR_VOID . ';-webkit-text-size-adjust:100%;">'
			. $preheader_html
			. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#EEEDE7;padding:40px 16px;">'
			. '<tr><td align="center">'
			. '<table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="background:#FFFFFF;max-width:600px;width:100%;border-collapse:separate;border-spacing:0;border-radius:16px;overflow:hidden;box-shadow:0 8px 32px rgba(26,25,30,0.10);">'
			// Header — void background, brass-accent underline, logo/name.
			. '<tr><td style="background:' . self::COLOR_VOID . ';padding:28px 32px;border-bottom:3px solid ' . self::COLOR_BRASS . ';">' . $header . '</td></tr>'
			// Body — generous padding, comfortable line-height, modern type.
			. '<tr><td style="padding:36px 32px;font-size:15px;line-height:1.65;color:' . self::COLOR_VOID . ';">' . $inner_html . '</td></tr>'
			// Footer — full NAP, muted, on a soft neutral tint with a hairline divider.
			. '<tr><td style="background:#F7F6F2;padding:24px 32px;border-top:1px solid #E7E5DE;">' . $footer_html . '</td></tr>'
			. '</table>'
			. '</td></tr></table></body></html>';
	}
}
