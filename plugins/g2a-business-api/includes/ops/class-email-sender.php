<?php
/**
 * Email sender for approved BridGistic drafts.
 *
 * WordPress' `wp_mail` is the send primitive — it's the standard path every
 * WP mailer plugin (Messageistic, WP Mail SMTP, Postmark, …) hooks into, so
 * using it means we automatically pick up whatever transport the site has
 * configured.
 *
 * When Messageistic is installed we ALSO fire its `messageistic_trigger`
 * hook so its analytics + compliance log records the send alongside its
 * own campaigns. That's advisory — if Messageistic is absent, wp_mail
 * still runs.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Ops;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Email_Sender {
	/**
	 * Attempt to send a draft. Returns the updated draft record (with
	 * status = sent | failed and an error message on failure).
	 */
	public function send( array $draft ): array {
		$store = new Email_Draft_Store();
		$audit = new Audit_Log();
		$id    = (string) ( $draft['id'] ?? '' );

		$to      = trim( (string) ( $draft['to'] ?? '' ) );
		$subject = (string) ( $draft['subject'] ?? '' );
		$body    = (string) ( $draft['body'] ?? '' );

		if ( '' === $to || ! self::is_valid_email( $to ) ) {
			$updated = $store->transition( $id, Email_Draft_Store::STATUS_FAILED, 'Missing or invalid recipient' );
			$audit->record( 'email.send_failed', 'Missing/invalid recipient for draft ' . $id, array( 'draftId' => $id ) );
			return $updated ?? $draft;
		}

		// Compliance category — 'internal' bypasses the opt-out gate + the
		// unsubscribe footer entirely (internal staff notifications don't
		// carry the same expectation).
		$category   = (string) ( $draft['category'] ?? Opt_Out_Store::CATEGORY_ALL );
		$is_internal = ( Opt_Out_Store::CATEGORY_INTERNAL === $category );

		// Compliance gate: recipient opt-outs are honoured for every send
		// path. This runs BEFORE any transport is touched.
		if ( ! $is_internal && ( new Opt_Out_Store() )->opted_out( $to, $category ) ) {
			$updated = $store->transition( $id, Email_Draft_Store::STATUS_FAILED, sprintf( 'Recipient has opted out (%s)', $category ) );
			$audit->record(
				'email.suppressed_opt_out',
				sprintf( 'Suppressed draft %s: %s is opted out of %s', $id, $to, $category ),
				array( 'draftId' => $id, 'to' => $to, 'category' => $category )
			);
			return $updated ?? $draft;
		}

		// Append a plain-text unsubscribe footer + a List-Unsubscribe header
		// so mailbox providers can honour one-click opt-outs. Internal mail
		// skips this — those emails go to staff and shouldn't carry an
		// unsubscribe control that would confuse the recipient.
		$body_final = $body;
		$headers    = array( 'Content-Type: text/plain; charset=UTF-8' );
		if ( ! $is_internal ) {
			$link = Opt_Out_Signer::make_link( $to, $category );
			if ( '' !== $link ) {
				$body_final .= "\n\n---\nTo stop receiving these Guns2Ammo emails, follow this link:\n" . $link;
				$headers[]   = 'List-Unsubscribe: <' . $link . '>';
				$headers[]   = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
			}
		}

		// Fire the Messageistic trigger before wp_mail so the record exists
		// even if wp_mail hangs on a broken SMTP config.
		if ( self::messageistic_present() ) {
			do_action(
				'messageistic_trigger',
				'g2aba.email_draft_send',
				array(
					'draft_id' => $id,
					'to'       => $to,
					'subject'  => $subject,
				)
			);
		}

		$ok = wp_mail( $to, $subject, $body_final, $headers );

		if ( $ok ) {
			$updated = $store->transition( $id, Email_Draft_Store::STATUS_SENT, null );
			$audit->record( 'email.sent', sprintf( 'Sent draft %s to %s', $id, $to ), array( 'draftId' => $id, 'to' => $to ) );
			return $updated ?? $draft;
		}

		$updated = $store->transition( $id, Email_Draft_Store::STATUS_FAILED, 'wp_mail returned false' );
		$audit->record( 'email.send_failed', 'wp_mail returned false for draft ' . $id, array( 'draftId' => $id, 'to' => $to ) );
		return $updated ?? $draft;
	}

	/**
	 * @internal Exposed for tests. Detects a Messageistic install.
	 */
	public static function messageistic_present(): bool {
		return defined( 'MESSAGEISTIC_VERSION' ) || class_exists( '\\Messageistic\\Core\\Plugin' );
	}

	/**
	 * @internal Exposed for tests. Loose email validation — final call is
	 * wp_mail's, this just gates obviously-bad values.
	 */
	public static function is_valid_email( string $addr ): bool {
		return (bool) preg_match( '/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $addr );
	}
}
