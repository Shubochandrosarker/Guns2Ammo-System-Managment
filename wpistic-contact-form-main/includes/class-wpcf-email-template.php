<?php
/**
 * Email Template — shared branded HTML wrapper for every outbound
 * customer-facing email (newsletter confirmation, auto-responder).
 *
 * Produces a bulletproof 600px table layout with fully inlined styles so
 * it renders consistently in Gmail, Outlook (incl. Windows desktop) and
 * Apple Mail: dark header band with the brand logo, brass accent divider,
 * white content card, and a footer carrying the business address / phone
 * plus an optional unsubscribe line.
 *
 * @package WPISTIC_CF
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static HTML email layout helpers.
 */
class WPISTIC_CF_Email_Template {

	/** Brand palette — Guns 2 Ammo tactical-luxury direction. */
	const COLOR_DARK  = '#1A191E';
	const COLOR_BRASS = '#C9A84C';
	const COLOR_EMBER = '#E8802F';

	/**
	 * Resolve the logo image URL for the email header.
	 *
	 * Order: the WPISTIC_CF_email_logo_url option, then the bundled
	 * guns2ammo theme asset when that theme is active, else empty string
	 * (the header falls back to a text brand).
	 *
	 * @return string
	 */
	public static function logo_url() {
		$logo = (string) get_option( 'WPISTIC_CF_email_logo_url', '' );
		if ( $logo ) {
			return esc_url_raw( $logo );
		}
		// Default to the theme-bundled 600px email logo, but only when the
		// guns2ammo theme is actually active — never hardcode another
		// site's asset path.
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
			. '<tr><td align="center" bgcolor="' . self::COLOR_BRASS . '" style="border-radius:3px;">'
			. '<a href="' . esc_url( $url ) . '" target="_blank" style="display:inline-block;padding:14px 32px;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:bold;letter-spacing:.08em;text-transform:uppercase;color:' . self::COLOR_DARK . ';text-decoration:none;border-radius:3px;">'
			. esc_html( $label )
			. '</a></td></tr></table>';
	}

	/**
	 * Wrap inner HTML in the branded 600px shell.
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
	public static function wrap( $inner_html, $args = [] ) {
		$args = wp_parse_args( $args, [
			'preheader'       => '',
			'unsubscribe_url' => '',
			'title'           => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		] );

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$logo      = self::logo_url();

		// Header: centered logo image, or a text brand fallback styled to match.
		$header = $logo
			? '<img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $site_name ) . '" width="220" style="display:block;width:220px;max-width:100%;height:auto;border:0;margin:0 auto;">'
			: '<strong style="color:#ffffff;font-family:Impact,Arial,sans-serif;font-size:26px;letter-spacing:.08em;text-transform:uppercase;">' . esc_html( $site_name ) . '</strong>';

		// Footer business details — reuse the Memberistic business settings
		// when present (same client install), else just the site name. The
		// option read is harmless when the plugin is absent.
		$footer_lines = [ esc_html( $site_name ) ];
		$mbr          = get_option( 'memberistic_settings', [] );
		if ( is_array( $mbr ) ) {
			if ( ! empty( $mbr['business_address'] ) ) {
				$footer_lines[] = nl2br( esc_html( (string) $mbr['business_address'] ) );
			}
			if ( ! empty( $mbr['business_phone'] ) ) {
				$footer_lines[] = esc_html( (string) $mbr['business_phone'] );
			}
		}
		$footer_lines = apply_filters( 'wpcf_email_footer_lines', $footer_lines );

		$footer_html = '<p style="margin:0 0 6px;font-size:12px;line-height:1.6;color:#8A8894;">' . implode( '<br>', $footer_lines ) . '</p>'
			. '<p style="margin:0;font-size:12px;color:#8A8894;">&copy; ' . esc_html( gmdate( 'Y' ) ) . ' ' . esc_html( $site_name ) . '. ' . esc_html__( 'All rights reserved.', 'wpistic-contact-form' ) . '</p>';

		if ( $args['unsubscribe_url'] ) {
			$footer_html .= '<p style="margin:10px 0 0;font-size:12px;color:#8A8894;">'
				. esc_html__( 'No longer want range updates?', 'wpistic-contact-form' ) . ' '
				. '<a href="' . esc_url( $args['unsubscribe_url'] ) . '" style="color:' . self::COLOR_BRASS . ';text-decoration:underline;">' . esc_html__( 'Unsubscribe', 'wpistic-contact-form' ) . '</a>'
				. '</p>';
		}

		// Hidden preheader — shows as inbox preview text without rendering.
		$preheader_html = $args['preheader']
			? '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#F4F3F0;">' . esc_html( $args['preheader'] ) . '</div>'
			: '';

		return '<!doctype html><html lang="en"><head><meta charset="UTF-8">'
			. '<meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<meta http-equiv="X-UA-Compatible" content="IE=edge">'
			. '<title>' . esc_html( $args['title'] ) . '</title></head>'
			. '<body style="margin:0;padding:0;background:#F4F3F0;font-family:Arial,Helvetica,sans-serif;color:' . self::COLOR_DARK . ';-webkit-text-size-adjust:100%;">'
			. $preheader_html
			. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#F4F3F0;">'
			. '<tr><td align="center" style="padding:32px 16px;">'
			. '<table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="background:#ffffff;max-width:600px;width:100%;border-collapse:collapse;border-top:4px solid ' . self::COLOR_BRASS . ';">'
			// Dark header band with centered logo.
			. '<tr><td align="center" bgcolor="' . self::COLOR_DARK . '" style="background:' . self::COLOR_DARK . ';padding:28px 32px;">' . $header . '</td></tr>'
			// Brass accent divider.
			. '<tr><td style="font-size:0;line-height:0;height:3px;background:' . self::COLOR_BRASS . ';">&nbsp;</td></tr>'
			// Content area.
			. '<tr><td style="padding:32px;font-size:15px;line-height:1.7;color:' . self::COLOR_DARK . ';">' . $inner_html . '</td></tr>'
			// Footer.
			. '<tr><td style="background:#F4F3F0;padding:22px 32px;border-top:1px solid #E4E2DC;" align="center">' . $footer_html . '</td></tr>'
			. '</table>'
			. '</td></tr></table></body></html>';
	}
}
