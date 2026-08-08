<?php
/**
 * G2A — RFC 5545 .ics calendar-invite generator.
 *
 * Attached to the "your firearm is ready for pickup" email so the customer
 * can one-click block off time to visit the dealer for the 4473 + NICS
 * appointment. Generates a single VEVENT at the next business day 12:00
 * (local site timezone) with a 30-minute window, including the dealer
 * name + address as LOCATION and a description with the transfer ref +
 * pickup checklist.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Ics {

	/**
	 * Build a single-event .ics payload string.
	 * Caller writes it to a temp file and passes the path to wp_mail's
	 * attachments array.
	 */
	public static function build_pickup_invite( object $transfer ): string {
		$tz       = wp_timezone();
		$now      = new \DateTimeImmutable( 'now', $tz );

		// Next business day at noon local time. Skip Sat/Sun.
		$start = $now->modify( 'tomorrow 12:00' );
		while ( in_array( (int) $start->format( 'N' ), [ 6, 7 ], true ) ) {
			$start = $start->modify( '+1 day' );
		}
		$end = $start->modify( '+30 minutes' );

		$dealer_name = (string) ( $transfer->dealer_name ?? __( 'Your FFL Dealer', 'advanced-ffl-checkout' ) );
		$dealer_addr = trim( implode( ', ', array_filter( [
			(string) ( $transfer->dealer_street ?? '' ),
			(string) ( $transfer->dealer_city ?? '' ),
			(string) ( $transfer->dealer_state ?? '' ),
			(string) ( $transfer->dealer_zip ?? '' ),
		] ) ), ', ' );

		$site  = get_bloginfo( 'name' );
		$ref   = (string) ( $transfer->transfer_ref ?? '' );
		$phone = (string) ( $transfer->dealer_phone ?? '' );

		$summary  = sprintf(
			/* translators: %s = transfer ref */
			__( 'Pick up firearm — %s', 'advanced-ffl-checkout' ),
			$ref
		);
		$lines    = [
			__( 'Visit your FFL dealer to complete the transfer.', 'advanced-ffl-checkout' ),
			'',
			sprintf( __( 'Transfer reference: %s', 'advanced-ffl-checkout' ), $ref ),
			sprintf( __( 'Dealer: %s', 'advanced-ffl-checkout' ), $dealer_name ),
		];
		if ( $phone ) {
			$lines[] = sprintf( __( 'Dealer phone: %s', 'advanced-ffl-checkout' ), $phone );
		}
		$lines[] = '';
		$lines[] = __( 'Bring with you:', 'advanced-ffl-checkout' );
		$lines[] = __( '  - Government-issued photo ID', 'advanced-ffl-checkout' );
		$lines[] = __( '  - State firearm permit (if your state requires one)', 'advanced-ffl-checkout' );
		$lines[] = __( '  - Payment for the dealer transfer fee', 'advanced-ffl-checkout' );

		// Recommend calling first.
		$lines[] = '';
		$lines[] = __( 'Call the dealer first to confirm hours — this is a suggested time, not a confirmed appointment.', 'advanced-ffl-checkout' );

		$desc = implode( "\\n", array_map( [ self::class, 'ical_escape' ], $lines ) );
		$uid  = sprintf( '%s@%s', $ref ?: wp_generate_uuid4(), wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'localhost' );

		$body  = "BEGIN:VCALENDAR\r\n";
		$body .= "VERSION:2.0\r\n";
		$body .= 'PRODID:-//' . self::ical_escape( $site ) . "//Advanced FFL//EN\r\n";
		$body .= "CALSCALE:GREGORIAN\r\n";
		$body .= "METHOD:PUBLISH\r\n";
		$body .= "BEGIN:VEVENT\r\n";
		$body .= 'UID:' . $uid . "\r\n";
		$body .= 'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ) . "\r\n";
		$body .= 'DTSTART:' . $start->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' ) . "\r\n";
		$body .= 'DTEND:'   . $end->setTimezone(   new \DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' ) . "\r\n";
		$body .= 'SUMMARY:' . self::ical_escape( $summary ) . "\r\n";
		if ( $dealer_addr ) {
			$body .= 'LOCATION:' . self::ical_escape( $dealer_name . ', ' . $dealer_addr ) . "\r\n";
		}
		$body .= 'DESCRIPTION:' . $desc . "\r\n";
		$body .= "STATUS:TENTATIVE\r\n";
		$body .= "TRANSP:OPAQUE\r\n";
		$body .= "BEGIN:VALARM\r\nACTION:DISPLAY\r\nDESCRIPTION:" . self::ical_escape( $summary ) . "\r\nTRIGGER:-PT1H\r\nEND:VALARM\r\n";
		$body .= "END:VEVENT\r\n";
		$body .= "END:VCALENDAR\r\n";
		return $body;
	}

	/** Escape per RFC 5545 §3.3.11 — , ; \ → \\, \\;, \\\\ ; newlines → \\n */
	public static function ical_escape( string $s ): string {
		$s = str_replace( [ '\\', "\r\n", "\n", "\r", ',', ';' ], [ '\\\\', '\\n', '\\n', '\\n', '\\,', '\\;' ], $s );
		return $s;
	}

	/**
	 * Write the .ics body to a one-shot temp file usable as a wp_mail
	 * attachment. Caller should `unlink()` after send (wp_mail copies the
	 * data before returning so deletion is safe).
	 */
	public static function build_temp_file( object $transfer ): ?string {
		$body = self::build_pickup_invite( $transfer );
		$tmp  = wp_tempnam( 'wpistic-ffl-pickup-' . ( $transfer->transfer_ref ?? 'x' ) . '.ics' );
		if ( ! $tmp ) {
			return null;
		}
		// Rename to a .ics extension — wp_tempnam returns .tmp which Outlook
		// and some clients refuse to render as a calendar attachment.
		$ics_path = $tmp . '.ics';
		if ( ! rename( $tmp, $ics_path ) ) {
			$ics_path = $tmp;
		}
		file_put_contents( $ics_path, $body );
		return $ics_path;
	}
}
