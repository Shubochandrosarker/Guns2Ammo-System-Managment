<?php
/**
 * G2AB_Email_Branding — shared Guns2Ammo email design system.
 *
 * Every automated email renders through wrap(): a 600px table-based shell
 * (nested role="presentation" tables, fully inlined styles — no external
 * stylesheets, no flexbox/grid) with a dark header band, a light content
 * card, brass/orange brand accents, and a business NAP footer.
 *
 * button() produces a bulletproof, image-free CTA (table-based, minimum
 * 44px touch target) and plain_text() derives the multipart text/plain
 * fallback from the final HTML (buttons/links become "Label: URL" lines).
 *
 * @package G2AB\Modules\EmailAutomation
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class G2AB_Email_Branding {

	// Brand tokens.
	const COLOR_HEADER   = '#0F1115'; // Dark header band.
	const COLOR_GOLD     = '#C9A84C'; // Brass accent.
	const COLOR_ORANGE   = '#E8802F'; // Primary CTA.
	const COLOR_CTA_TEXT = '#1A1408'; // CTA label on orange.
	const COLOR_PAGE     = '#EDEAE2'; // Page background.
	const COLOR_CARD     = '#F4F1EA'; // Content card.
	const COLOR_TEXT     = '#26241F'; // Body copy.
	const COLOR_MUTED    = '#6F6A5E'; // Secondary copy.
	const FONT_STACK     = 'Arial,Helvetica,sans-serif';

	/**
	 * Wrap inner HTML in the shared branded shell.
	 *
	 * @param string $inner_html Rendered body fragment (already escaped/merged).
	 * @param array  $args       {
	 *     @type string $title       Document <title> (usually the subject).
	 *     @type string $footer_html Optional footer override (already kses'd).
	 *     @type string $preheader   Optional hidden preview text.
	 * }
	 * @return string Full HTML document.
	 */
	public static function wrap( $inner_html, $args = array() ) {
		$args = wp_parse_args( (array) $args, array(
			'title'       => '',
			'footer_html' => '',
			'preheader'   => '',
		) );

		$biz_name = function_exists( 'g2ab_business_name' ) ? g2ab_business_name() : get_bloginfo( 'name' );
		$biz_name = '' !== trim( (string) $biz_name ) ? trim( (string) $biz_name ) : get_bloginfo( 'name' );

		$phone = function_exists( 'g2ab_business_phone' ) ? trim( (string) g2ab_business_phone() ) : '';
		if ( '' === $phone ) {
			$phone = trim( (string) get_option( 'g2ab_business_phone', '' ) );
		}
		$addr = function_exists( 'g2ab_business_address' ) ? trim( (string) g2ab_business_address() ) : '';
		if ( '' === $addr ) {
			$addr = trim( (string) get_option( 'g2ab_business_address', '' ) );
		}

		$logo = esc_url( (string) get_option( 'g2ab_email_logo_url', '' ) );

		$logo_html = $logo
			? sprintf(
				'<img src="%s" alt="%s" width="200" style="display:block;width:200px;max-width:100%%;height:auto;border:0;" />',
				$logo,
				esc_attr( $biz_name )
			)
			: sprintf(
				'<strong style="color:%s;font-family:%s;font-size:24px;letter-spacing:.06em;text-transform:uppercase;">%s</strong>',
				self::COLOR_GOLD,
				self::FONT_STACK,
				esc_html( $biz_name )
			);

		$footer = (string) $args['footer_html'];
		if ( '' === trim( $footer ) ) {
			$nap_bits = array_filter( array( $addr, $phone ) );
			$footer   = '<p style="margin:0 0 6px;font-family:' . self::FONT_STACK . ';font-size:13px;font-weight:bold;color:' . self::COLOR_TEXT . ';">' . esc_html( $biz_name ) . '</p>';
			if ( $nap_bits ) {
				$footer .= '<p style="margin:0 0 10px;font-family:' . self::FONT_STACK . ';font-size:12px;line-height:1.6;color:' . self::COLOR_MUTED . ';">' . esc_html( implode( ' · ', $nap_bits ) ) . '</p>';
			}
			$footer .= '<p style="margin:0;font-family:' . self::FONT_STACK . ';font-size:11px;color:' . self::COLOR_MUTED . ';">&copy; ' . esc_html( gmdate( 'Y' ) ) . ' ' . esc_html( $biz_name ) . '. All rights reserved.</p>';
		}

		$preheader_html = '';
		if ( '' !== trim( (string) $args['preheader'] ) ) {
			$preheader_html = '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:' . self::COLOR_PAGE . ';">' . esc_html( (string) $args['preheader'] ) . '</div>';
		}

		$unsub_note = sprintf(
			/* translators: %s business name */
			esc_html__( 'You are receiving this transactional message because a reservation was made with %s. To make changes, use the links above, reply to this email, or contact us directly.', 'g2a-booking' ),
			esc_html( $biz_name )
		);

		return '<!doctype html><html lang="en"><head><meta charset="UTF-8">'
			. '<meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<meta http-equiv="X-UA-Compatible" content="IE=edge">'
			. '<meta name="color-scheme" content="light">'
			. '<title>' . esc_html( (string) $args['title'] ) . '</title></head>'
			. '<body style="margin:0;padding:0;background:' . self::COLOR_PAGE . ';font-family:' . self::FONT_STACK . ';font-size:15px;line-height:1.6;color:' . self::COLOR_TEXT . ';-webkit-text-size-adjust:100%;">'
			. $preheader_html
			. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:' . self::COLOR_PAGE . ';">'
			. '<tr><td align="center" style="padding:32px 16px;">'
			. '<table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="background:' . self::COLOR_CARD . ';max-width:600px;width:100%;border-collapse:collapse;border-top:4px solid ' . self::COLOR_GOLD . ';">'
			// Header — dark band, logo (or brass wordmark), brass hairline under it.
			. '<tr><td align="center" bgcolor="' . self::COLOR_HEADER . '" style="background:' . self::COLOR_HEADER . ';padding:26px 32px;">' . $logo_html . '</td></tr>'
			. '<tr><td style="font-size:0;line-height:0;height:3px;background:' . self::COLOR_GOLD . ';">&nbsp;</td></tr>'
			// Body content.
			. '<tr><td style="padding:32px;font-family:' . self::FONT_STACK . ';font-size:15px;line-height:1.6;color:' . self::COLOR_TEXT . ';">' . $inner_html . '</td></tr>'
			// Footer — business NAP on a slightly deeper neutral.
			. '<tr><td align="center" style="background:#E9E5DB;padding:22px 32px;border-top:1px solid #DDD8CC;">' . $footer . '</td></tr>'
			. '</table>'
			// Sub-footer / unsubscribe-style note outside the card.
			. '<table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="max-width:600px;width:100%;">'
			. '<tr><td align="center" style="padding:16px 12px 0;font-family:' . self::FONT_STACK . ';font-size:11px;line-height:1.6;color:' . self::COLOR_MUTED . ';">' . $unsub_note . '</td></tr>'
			. '</table>'
			. '</td></tr></table></body></html>';
	}

	/**
	 * Bulletproof, image-free CTA button.
	 *
	 * Table-based so Outlook renders it; 14px vertical padding + 16px line
	 * height keeps the touch target at 44px minimum. Primary is solid
	 * orange with near-black text; secondary is a brass outline.
	 *
	 * Merge-tag placeholder URLs ("{pay_url}") are passed through verbatim
	 * so template defaults can be assembled before tag substitution; real
	 * URLs are escaped.
	 *
	 * @param string $url   Destination URL or "{merge_tag}" placeholder.
	 * @param string $label Button label (plain text — escaped here).
	 * @param string $style 'primary' or 'secondary'.
	 * @return string HTML fragment.
	 */
	public static function button( $url, $label, $style = 'primary' ) {
		$url = (string) $url;
		$is_placeholder = (bool) preg_match( '/^\{[a-zA-Z0-9_]+\}$/', $url );
		$href = $is_placeholder ? $url : esc_url( $url );
		if ( '' === $href ) {
			return '';
		}

		if ( 'secondary' === $style ) {
			$td_style = 'border-radius:6px;border:2px solid ' . self::COLOR_GOLD . ';background:transparent;';
			$a_style  = 'display:inline-block;padding:12px 30px;font-family:' . self::FONT_STACK . ';font-size:15px;line-height:16px;font-weight:bold;color:#7A621F;text-decoration:none;border-radius:6px;';
			$bgcolor  = '';
		} else {
			$td_style = 'border-radius:6px;background:' . self::COLOR_ORANGE . ';';
			$a_style  = 'display:inline-block;padding:14px 32px;font-family:' . self::FONT_STACK . ';font-size:15px;line-height:16px;font-weight:bold;color:' . self::COLOR_CTA_TEXT . ';text-decoration:none;border-radius:6px;';
			$bgcolor  = ' bgcolor="' . self::COLOR_ORANGE . '"';
		}

		return '<table role="presentation" border="0" cellspacing="0" cellpadding="0" style="margin:20px 0 8px;">'
			. '<tr><td align="center"' . $bgcolor . ' style="' . $td_style . '">'
			. '<a href="' . $href . '" style="' . $a_style . '">' . esc_html( $label ) . '</a>'
			. '</td></tr></table>';
	}

	/**
	 * Derive the text/plain fallback body from a final HTML email.
	 *
	 * Anchors (including CTA buttons) become "Label: URL" lines, block
	 * elements become newlines, everything else is stripped and decoded.
	 *
	 * @param string $html Final HTML document or fragment.
	 * @return string Plain-text body.
	 */
	public static function plain_text( $html ) {
		$text = (string) $html;

		// Drop head + hidden preheader entirely.
		$text = preg_replace( '#<head\b.*?</head>#is', '', $text );
		$text = preg_replace( '#<div[^>]*display:none[^>]*>.*?</div>#is', '', $text );
		$text = preg_replace( '#<style\b.*?</style>#is', '', $text );

		// Anchors → "Label: URL" (skip anchors whose label IS the URL).
		$text = preg_replace_callback(
			'#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is',
			static function ( $m ) {
				$url   = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
				$label = trim( wp_strip_all_tags( $m[2] ) );
				if ( '' === $label || $label === $url ) {
					return $url;
				}
				return $label . ': ' . $url;
			},
			$text
		);

		// Structural closers → line breaks.
		$text = preg_replace( '#<br\s*/?\s*>#i', "\n", $text );
		$text = preg_replace( '#</(p|h[1-6]|li|tr|table|div)>#i', "\n", $text );

		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );

		// Collapse whitespace noise left behind by inline markup.
		$text = preg_replace( "/[ \t]+/", ' ', $text );
		$text = preg_replace( "/ *\n */", "\n", $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );

		return trim( (string) $text );
	}
}
