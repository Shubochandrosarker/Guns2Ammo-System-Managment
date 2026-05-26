<?php
/**
 * AI Layer for WPistic Contact Form (Phase 3 / v1.6.0).
 *
 * @package WPISTIC_CF
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI enrichment + automated reply orchestration.
 */
class WPistic_CF_AI {

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'WPISTIC_CF_submission_captured', [ $this, 'wpistic_cf_handle_submission_ai' ], 40, 3 );
	}

	/**
	 * Main AI workflow on captured submissions.
	 *
	 * @param int    $submission_id Submission ID.
	 * @param string $form_name     Form name.
	 * @param array  $fields        Submission fields.
	 */
	public function wpistic_cf_handle_submission_ai( $submission_id, $form_name, $fields ) {
		$row = WPISTIC_CF_Database::get_submission( (int) $submission_id );
		if ( ! $row ) {
			return;
		}
		$spam_score = $this->wpistic_cf_calculate_spam_score( $row, $fields );
		$tags       = $this->wpistic_cf_generate_tags( $row, $fields );
		$draft      = '';

		if ( '1' === get_option( 'wpistic_cf_ai_smart_reply_enabled', '0' ) ) {
			$draft = $this->wpistic_cf_generate_smart_reply( $row, $fields );
		}

		WPISTIC_CF_Database::upsert_ai_meta(
			(int) $submission_id,
			(int) $spam_score,
			implode( ', ', $tags ),
			$draft,
			(string) get_option( 'wpistic_cf_ai_provider', 'local_rules' )
		);

		if ( '1' === get_option( 'wpistic_cf_ai_auto_reply_enabled', '0' ) ) {
			$this->wpistic_cf_maybe_send_automated_reply( $row, $draft, $form_name );
		}
	}

	/**
	 * Lightweight heuristic spam score (0-100).
	 *
	 * @param object $row    Submission row.
	 * @param array  $fields Fields.
	 * @return int
	 */
	protected function wpistic_cf_calculate_spam_score( $row, $fields ) {
		$score = 10;
		$message = strtolower( (string) $row->message );
		if ( preg_match_all( '~https?://~', $message, $m ) ) {
			$score += min( 40, count( $m[0] ) * 10 );
		}
		if ( strlen( preg_replace( '/\s+/', '', $message ) ) < 12 ) {
			$score += 20;
		}
		if ( ! empty( $row->sender_email ) && preg_match( '/\d{4,}/', (string) $row->sender_email ) ) {
			$score += 10;
		}
		if ( preg_match( '/(viagra|casino|crypto|loan|seo service|backlink)/i', $message ) ) {
			$score += 30;
		}
		return max( 0, min( 100, $score ) );
	}

	/**
	 * Smart tag generation based on content and fields.
	 *
	 * @param object $row    Submission row.
	 * @param array  $fields Fields.
	 * @return string[]
	 */
	protected function wpistic_cf_generate_tags( $row, $fields ) {
		$text = strtolower( (string) $row->subject . ' ' . (string) $row->message . ' ' . wp_json_encode( $fields ) );
		$tags = [];
		$map  = [
			'billing'     => [ 'invoice', 'payment', 'billing', 'charge' ],
			'sales lead'  => [ 'quote', 'pricing', 'service', 'project', 'hire' ],
			'support'     => [ 'issue', 'error', 'bug', 'not working', 'help' ],
			'complaint'   => [ 'bad', 'angry', 'complain', 'disappointed' ],
			'partnership' => [ 'partner', 'collaboration', 'affiliate', 'joint' ],
		];
		foreach ( $map as $tag => $needles ) {
			foreach ( $needles as $needle ) {
				if ( false !== strpos( $text, $needle ) ) {
					$tags[] = $tag;
					break;
				}
			}
		}
		if ( ! $tags ) {
			$tags[] = 'general';
		}
		return array_values( array_unique( $tags ) );
	}

	/**
	 * Generate a smart AI reply draft.
	 *
	 * @param object $row    Submission row.
	 * @param array  $fields Fields.
	 * @return string
	 */
	protected function wpistic_cf_generate_smart_reply( $row, $fields ) {
		$context = $this->wpistic_cf_get_knowledge_context();
		$prompt  = "You are an assistant for Wordpressistic. Write a concise professional reply.\n";
		$prompt .= "Sender name: " . (string) $row->sender_name . "\n";
		$prompt .= "Sender email: " . (string) $row->sender_email . "\n";
		$prompt .= "Form: " . (string) $row->form_name . "\n";
		$prompt .= "Message: " . (string) $row->message . "\n";
		$prompt .= "Fields: " . wp_json_encode( $fields ) . "\n";
		$prompt .= "Knowledge Context:\n" . $context . "\n";
		$generated = $this->wpistic_cf_ai_generate_text( $prompt );
		if ( '' !== trim( $generated ) ) {
			return $generated;
		}
		return $this->wpistic_cf_local_fallback_reply( $row );
	}

	/**
	 * Automatic reply sender with rule-based override.
	 *
	 * @param object $row       Submission row.
	 * @param string $ai_draft  Generated draft.
	 * @param string $form_name Form name.
	 */
	protected function wpistic_cf_maybe_send_automated_reply( $row, $ai_draft, $form_name ) {
		if ( ! is_email( (string) $row->sender_email ) ) {
			return;
		}
		$subject_tpl = (string) get_option( 'wpistic_cf_ai_auto_reply_subject', 'Thanks for contacting {site_name}' );
		$subject     = strtr(
			$subject_tpl,
			[
				'{site_name}' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'{form}'      => (string) $form_name,
				'{name}'      => (string) $row->sender_name,
			]
		);

		$body = $this->wpistic_cf_apply_automation_rules( $row, $ai_draft );
		if ( '' === trim( $body ) ) {
			return;
		}
		$headers = [];
		$from_email = get_option( 'WPISTIC_CF_reply_from_email', get_option( 'admin_email' ) );
		$from_name  = get_option( 'WPISTIC_CF_reply_from_name', get_bloginfo( 'name' ) );
		if ( is_email( $from_email ) ) {
			$headers[] = sprintf( 'From: %s <%s>', $from_name, $from_email );
		}
		$sent = WPISTIC_CF_Capture::send_internal( (string) $row->sender_email, $subject, $body, $headers );
		if ( $sent ) {
			WPISTIC_CF_Database::insert_reply( (int) $row->id, $subject, $body );
			WPISTIC_CF_Database::set_status( (int) $row->id, 'replied' );
		}
	}

	/**
	 * Apply easy automation rules before fallback to AI draft.
	 *
	 * Rule format per line:
	 * keyword => template text
	 *
	 * @param object $row      Submission row.
	 * @param string $ai_draft AI draft.
	 * @return string
	 */
	protected function wpistic_cf_apply_automation_rules( $row, $ai_draft ) {
		$rules = (string) get_option( 'wpistic_cf_ai_auto_reply_rules', '' );
		$text  = strtolower( (string) $row->subject . ' ' . (string) $row->message );
		$lines = preg_split( '/\r\n|\r|\n/', $rules );
		foreach ( (array) $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || false === strpos( $line, '=>' ) ) {
				continue;
			}
			list( $keyword, $template ) = array_map( 'trim', explode( '=>', $line, 2 ) );
			if ( '' !== $keyword && false !== strpos( $text, strtolower( $keyword ) ) ) {
				return strtr(
					$template,
					[
						'{name}'      => (string) $row->sender_name,
						'{site_name}' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
						'{site_url}'  => home_url( '/' ),
					]
				);
			}
		}
		return '' !== trim( $ai_draft ) ? $ai_draft : $this->wpistic_cf_local_fallback_reply( $row );
	}

	/**
	 * Pull custom training context from FAQs/KB/Sheets/Text sources.
	 *
	 * @return string
	 */
	protected function wpistic_cf_get_knowledge_context() {
		$chunks = [];
		$faq = (string) get_option( 'wpistic_cf_ai_faq_text', '' );
		$kb  = (string) get_option( 'wpistic_cf_ai_kb_text', '' );
		if ( '' !== trim( $faq ) ) {
			$chunks[] = "FAQs:\n" . $faq;
		}
		if ( '' !== trim( $kb ) ) {
			$chunks[] = "Knowledge Base:\n" . $kb;
		}
		$sheets = preg_split( '/\r\n|\r|\n/', (string) get_option( 'wpistic_cf_ai_google_sheets_urls', '' ) );
		foreach ( (array) $sheets as $url ) {
			$url = trim( (string) $url );
			if ( '' === $url ) {
				continue;
			}
			$data = $this->wpistic_cf_fetch_source_text( $url );
			if ( '' !== $data ) {
				$chunks[] = "Google Sheet Source:\n" . $data;
			}
		}
		$sources = preg_split( '/\r\n|\r|\n/', (string) get_option( 'wpistic_cf_ai_text_sources', '' ) );
		foreach ( (array) $sources as $source ) {
			$source = trim( (string) $source );
			if ( '' === $source ) {
				continue;
			}
			$data = $this->wpistic_cf_fetch_source_text( $source );
			if ( '' !== $data ) {
				$chunks[] = "Custom Text Source:\n" . $data;
			}
		}
		$context = implode( "\n\n", $chunks );
		return substr( $context, 0, 12000 );
	}

	/**
	 * Fetch text from URL/file source.
	 *
	 * @param string $source URL or file path.
	 * @return string
	 */
	protected function wpistic_cf_fetch_source_text( $source ) {
		if ( preg_match( '~^https?://~i', $source ) ) {
			$res = wp_remote_get( $source, [ 'timeout' => 10 ] );
			if ( is_wp_error( $res ) ) {
				return '';
			}
			return sanitize_textarea_field( (string) wp_remote_retrieve_body( $res ) );
		}
		if ( file_exists( $source ) && is_readable( $source ) ) {
			return sanitize_textarea_field( (string) file_get_contents( $source ) );
		}
		return '';
	}

	/**
	 * Provider-based text generation.
	 *
	 * @param string $prompt Prompt text.
	 * @return string
	 */
	protected function wpistic_cf_ai_generate_text( $prompt ) {
		$provider = (string) get_option( 'wpistic_cf_ai_provider', 'local_rules' );
		if ( 'local_rules' === $provider ) {
			return '';
		}
		$endpoint = (string) get_option( 'wpistic_cf_ai_endpoint', '' );
		$model    = (string) get_option( 'wpistic_cf_ai_model', '' );
		$api_key  = (string) get_option( 'wpistic_cf_ai_api_key', '' );
		if ( '' === $endpoint ) {
			return '';
		}
		$headers = [ 'Content-Type' => 'application/json' ];
		if ( '' !== $api_key ) {
			$headers['Authorization'] = 'Bearer ' . $api_key;
		}
		$body = [
			'model'  => $model,
			'prompt' => $prompt,
		];
		if ( 'ollama' === $provider ) {
			$body = [ 'model' => $model, 'prompt' => $prompt, 'stream' => false ];
		}
		$res = wp_remote_post(
			$endpoint,
			[
				'timeout' => 20,
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
			]
		);
		if ( is_wp_error( $res ) ) {
			return '';
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( isset( $data['response'] ) ) {
			return sanitize_textarea_field( (string) $data['response'] );
		}
		if ( isset( $data['choices'][0]['text'] ) ) {
			return sanitize_textarea_field( (string) $data['choices'][0]['text'] );
		}
		if ( isset( $data['choices'][0]['message']['content'] ) ) {
			return sanitize_textarea_field( (string) $data['choices'][0]['message']['content'] );
		}
		return '';
	}

	/**
	 * Fallback reply when no external AI provider is configured.
	 *
	 * @param object $row Submission row.
	 * @return string
	 */
	protected function wpistic_cf_local_fallback_reply( $row ) {
		$name = '' !== trim( (string) $row->sender_name ) ? (string) $row->sender_name : __( 'there', 'wpistic-contact-form' );
		return sprintf(
			/* translators: 1: sender name, 2: site name */
			__( "Hi %1\$s,\n\nThanks for contacting %2\$s. We received your message and our team will get back to you shortly.\n\nBest regards,\nSupport Team", 'wpistic-contact-form' ),
			$name,
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);
	}
}

