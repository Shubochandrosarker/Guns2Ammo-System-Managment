<?php

namespace G2A\POS\Messaging;

use G2A\POS\Database\MessagingRepository;

/**
 * Transactional outbox for email + SMS. Email goes through wp_mail() by
 * default (so existing SMTP plugins keep working — including Formistic's
 * optional wp_mail capture, which logs a copy into its inbox when enabled).
 * SMS routes through Messageistic's SMS_Service when that plugin is active
 * — it resolves the site's actual configured provider, enforces opt-out/
 * consent/frequency compliance policy, and logs to its own conversation
 * history — falling back to a direct Twilio call (via the
 * `g2a_pos_messaging_twilio` option) only when Messageistic isn't
 * installed.
 */
final class MessagingService {

	public static function queue_email( string $to, string $subject, string $body, array $opts = array() ): int {
		return ( new MessagingRepository() )->queue(
			array(
				'channel'             => 'email',
				'template_key'        => $opts['template_key'] ?? '',
				'to_address'          => $to,
				'from_address'        => $opts['from'] ?? get_option( 'admin_email' ),
				'subject'             => $subject,
				'body'                => $body,
				'related_entity_type' => $opts['related_entity_type'] ?? '',
				'related_entity_id'   => $opts['related_entity_id'] ?? null,
				'scheduled_at'        => $opts['scheduled_at'] ?? current_time( 'mysql' ),
			)
		);
	}

	public static function queue_sms( string $to, string $body, array $opts = array() ): int {
		return ( new MessagingRepository() )->queue(
			array(
				'channel'             => 'sms',
				'template_key'        => $opts['template_key'] ?? '',
				'to_address'          => $to,
				'body'                => $body,
				'related_entity_type' => $opts['related_entity_type'] ?? '',
				'related_entity_id'   => $opts['related_entity_id'] ?? null,
				'scheduled_at'        => $opts['scheduled_at'] ?? current_time( 'mysql' ),
			)
		);
	}

	public static function queue_from_template( string $template_key, string $channel, string $to, array $vars = array(), array $opts = array() ): int {
		$repo = new MessagingRepository();
		$tpl  = $repo->find_template( $template_key, $channel );
		if ( ! $tpl ) {
			return 0;
		}
		$subject = self::render( (string) $tpl['subject'], $vars );
		$body    = self::render( (string) $tpl['body'], $vars );

		$opts['template_key'] = $template_key;
		if ( $channel === 'sms' ) {
			return self::queue_sms( $to, $body, $opts );
		}
		return self::queue_email( $to, $subject, $body, $opts );
	}

	public static function flush( int $batch_size = 25 ): array {
		$repo   = new MessagingRepository();
		$sent   = 0;
		$failed = 0;
		foreach ( $repo->due_now( $batch_size ) as $msg ) {
			$ok = false;
			try {
				if ( $msg['channel'] === 'sms' ) {
					$ok = self::send_sms( $msg );
				} else {
					$ok = self::send_email( $msg );
				}
			} catch ( \Throwable $e ) {
				$repo->mark_failed( (int) $msg['id'], $e->getMessage() );
				++$failed;
				continue;
			}
			if ( $ok ) {
				$repo->mark_sent( (int) $msg['id'] );
				++$sent;
			} else {
				$repo->mark_failed( (int) $msg['id'], 'send_returned_false' );
				++$failed;
			}
		}
		return array(
			'sent'   => $sent,
			'failed' => $failed,
		);
	}

	private static function send_email( array $msg ): bool {
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( ! empty( $msg['from_address'] ) ) {
			$headers[] = 'From: ' . $msg['from_address'];
		}
		return (bool) wp_mail( (string) $msg['to_address'], (string) $msg['subject'], (string) $msg['body'], $headers );
	}

	private static function send_sms( array $msg ): bool {
		if ( class_exists( '\Messageistic\Messaging\SMS_Service' ) ) {
			return self::send_sms_via_messageistic( $msg );
		}
		return self::send_sms_via_twilio( $msg );
	}

	/**
	 * Messageistic is the canonical SMS system on sites that have it —
	 * routing through its SMS_Service (rather than dialing a provider API
	 * directly) means opt-out/consent/frequency-cap compliance checks and
	 * conversation logging happen exactly the same way as every other SMS
	 * the business sends. A WP_Error here (including a compliance block) is
	 * a real failure and must NOT silently fall back to Twilio — that would
	 * bypass the exact policy this integration exists to respect.
	 */
	private static function send_sms_via_messageistic( array $msg ): bool {
		$service = new \Messageistic\Messaging\SMS_Service();
		$result  = $service->send(
			array(
				'phone'           => (string) $msg['to_address'],
				'body'            => (string) $msg['body'],
				'classification'  => 'transactional',
				'source'          => 'g2a_pos',
				'idempotency_key' => 'g2a_pos_msg_' . (int) $msg['id'],
			)
		);
		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( 'messageistic: ' . $result->get_error_message() );
		}
		return true;
	}

	private static function send_sms_via_twilio( array $msg ): bool {
		$cfg   = (array) get_option( 'g2a_pos_messaging_twilio', array() );
		$sid   = $cfg['account_sid'] ?? '';
		$token = $cfg['auth_token'] ?? '';
		$from  = $cfg['from_number'] ?? '';
		if ( ! $sid || ! $token || ! $from ) {
			// No driver configured — leave queued so it shows up in the UI.
			return false;
		}
		$url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode( $sid ) . '/Messages.json';
		$res = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $sid . ':' . $token ),
				),
				'body'    => array(
					'From' => $from,
					'To'   => (string) $msg['to_address'],
					'Body' => (string) $msg['body'],
				),
				'timeout' => 15,
			)
		);
		if ( is_wp_error( $res ) ) {
			throw new \RuntimeException( 'twilio: ' . $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		return $code >= 200 && $code < 300;
	}

	private static function render( string $template, array $vars ): string {
		$out = $template;
		foreach ( $vars as $k => $v ) {
			$out = str_replace( '{{' . $k . '}}', (string) $v, $out );
		}
		return $out;
	}
}
