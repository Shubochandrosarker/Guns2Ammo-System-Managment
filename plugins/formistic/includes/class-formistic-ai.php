<?php
/**
 * AI Layer for Formistic (Phase 3 / v1.6.0).
 *
 * @package Wpistic_Formistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI enrichment + automated reply orchestration.
 */
class Wpistic_Formistic_AI {

	/**
	 * Human-readable reason the last external generation attempt failed
	 * (empty when it succeeded or was never attempted). The ai_meta schema
	 * has no dedicated error column, so this is folded into the
	 * source_provider value for post-mortem visibility.
	 *
	 * @var string
	 */
	public static $last_error = '';

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'wpistic_formistic_submission_captured', [ $this, 'wpistic_formistic_handle_submission_ai' ], 40, 3 );
	}

	/**
	 * Main AI workflow on captured submissions.
	 *
	 * @param int    $submission_id Submission ID.
	 * @param string $form_name     Form name.
	 * @param array  $fields        Submission fields.
	 */
	public function wpistic_formistic_handle_submission_ai( $submission_id, $form_name, $fields ) {
		$row = Wpistic_Formistic_Database::get_submission( (int) $submission_id );
		if ( ! $row ) {
			return;
		}
		$spam_score = $this->wpistic_formistic_calculate_spam_score( $row, $fields );
		$tags       = $this->wpistic_formistic_generate_tags( $row, $fields );
		$draft      = '';

		if ( '1' === get_option( 'wpistic_formistic_ai_smart_reply_enabled', '0' ) ) {
			$draft = $this->wpistic_formistic_generate_smart_reply( $row, $fields );
		}

		// Record the provider; when the external call failed and we fell back
		// to local rules, note why. There is no dedicated error column in the
		// ai_meta schema, so the reason rides along inside source_provider
		// (VARCHAR 100 — upsert_ai_meta truncates).
		$provider = (string) get_option( 'wpistic_formistic_ai_provider', 'local_rules' );
		if ( '' !== self::$last_error ) {
			$provider .= ' [error: ' . self::$last_error . ']';
		}

		Wpistic_Formistic_Database::upsert_ai_meta(
			(int) $submission_id,
			(int) $spam_score,
			implode( ', ', $tags ),
			$draft,
			$provider
		);

		if ( '1' === get_option( 'wpistic_formistic_ai_auto_reply_enabled', '0' ) ) {
			$this->wpistic_formistic_maybe_send_automated_reply( $row, $draft, $form_name );
		}
	}

	/**
	 * Lightweight heuristic spam score (0-100).
	 *
	 * @param object $row    Submission row.
	 * @param array  $fields Fields.
	 * @return int
	 */
	protected function wpistic_formistic_calculate_spam_score( $row, $fields ) {
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
	protected function wpistic_formistic_generate_tags( $row, $fields ) {
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
	protected function wpistic_formistic_generate_smart_reply( $row, $fields ) {
		// Persona + knowledge context travel as the SYSTEM part; chat-style
		// providers (OpenRouter) get it as a system message, single-prompt
		// providers get it prepended to the prompt inside generate_text().
		$context = $this->wpistic_formistic_get_knowledge_context();
		$system  = "You are the Formistic assistant for this website. Write a concise professional reply.";
		if ( '' !== trim( $context ) ) {
			$system .= "\nKnowledge Context:\n" . $context;
		}
		$prompt  = "Sender name: " . (string) $row->sender_name . "\n";
		$prompt .= "Sender email: " . (string) $row->sender_email . "\n";
		$prompt .= "Form: " . (string) $row->form_name . "\n";
		$prompt .= "Message: " . (string) $row->message . "\n";
		$prompt .= "Fields: " . wp_json_encode( $fields ) . "\n";
		$generated = $this->wpistic_formistic_ai_generate_text( $prompt, $system );
		if ( '' !== trim( $generated ) ) {
			return $generated;
		}
		return $this->wpistic_formistic_local_fallback_reply( $row );
	}

	/**
	 * Automatic reply sender with rule-based override.
	 *
	 * @param object $row       Submission row.
	 * @param string $ai_draft  Generated draft.
	 * @param string $form_name Form name.
	 */
	protected function wpistic_formistic_maybe_send_automated_reply( $row, $ai_draft, $form_name ) {
		if ( ! is_email( (string) $row->sender_email ) ) {
			return;
		}
		$subject_tpl = (string) get_option( 'wpistic_formistic_ai_auto_reply_subject', 'Thanks for contacting {site_name}' );
		$subject     = strtr(
			$subject_tpl,
			[
				'{site_name}' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'{form}'      => (string) $form_name,
				'{name}'      => (string) $row->sender_name,
			]
		);

		$body = $this->wpistic_formistic_apply_automation_rules( $row, $ai_draft );
		if ( '' === trim( $body ) ) {
			return;
		}
		$headers = [];
		$from_email = get_option( 'wpistic_formistic_reply_from_email', get_option( 'admin_email' ) );
		$from_name  = get_option( 'wpistic_formistic_reply_from_name', get_bloginfo( 'name' ) );
		if ( is_email( $from_email ) ) {
			$headers[] = sprintf( 'From: %s <%s>', $from_name, $from_email );
		}
		$sent = Wpistic_Formistic_Capture::send_internal( (string) $row->sender_email, $subject, $body, $headers );
		if ( $sent ) {
			Wpistic_Formistic_Database::insert_reply( (int) $row->id, $subject, $body );
			Wpistic_Formistic_Database::set_status( (int) $row->id, 'replied' );
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
	protected function wpistic_formistic_apply_automation_rules( $row, $ai_draft ) {
		$rules = (string) get_option( 'wpistic_formistic_ai_auto_reply_rules', '' );
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
		return '' !== trim( $ai_draft ) ? $ai_draft : $this->wpistic_formistic_local_fallback_reply( $row );
	}

	/**
	 * Pull custom training context from FAQs/KB/Sheets/Text sources.
	 *
	 * @return string
	 */
	protected function wpistic_formistic_get_knowledge_context() {
		$chunks = [];
		$faq = (string) get_option( 'wpistic_formistic_ai_faq_text', '' );
		$kb  = (string) get_option( 'wpistic_formistic_ai_kb_text', '' );
		if ( '' !== trim( $faq ) ) {
			$chunks[] = "FAQs:\n" . $faq;
		}
		if ( '' !== trim( $kb ) ) {
			$chunks[] = "Knowledge Base:\n" . $kb;
		}
		$sheets = preg_split( '/\r\n|\r|\n/', (string) get_option( 'wpistic_formistic_ai_google_sheets_urls', '' ) );
		foreach ( (array) $sheets as $url ) {
			$url = trim( (string) $url );
			if ( '' === $url ) {
				continue;
			}
			$data = $this->wpistic_formistic_fetch_source_text( $url );
			if ( '' !== $data ) {
				$chunks[] = "Google Sheet Source:\n" . $data;
			}
		}
		$sources = preg_split( '/\r\n|\r|\n/', (string) get_option( 'wpistic_formistic_ai_text_sources', '' ) );
		foreach ( (array) $sources as $source ) {
			$source = trim( (string) $source );
			if ( '' === $source ) {
				continue;
			}
			$data = $this->wpistic_formistic_fetch_source_text( $source );
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
	protected function wpistic_formistic_fetch_source_text( $source ) {
		$source = trim( (string) $source );
		if ( '' === $source ) {
			return '';
		}

		// URL branch — HTTPS only by default, with SSRF blocks for
		// private + loopback + link-local + cloud-metadata ranges.
		// Override via the wpistic_formistic_ai_url_check filter (return WP_Error
		// to deny, true to allow) when a site genuinely needs to fetch
		// from an internal endpoint.
		if ( preg_match( '~^https?://~i', $source ) ) {
			$allowed = self::wpistic_formistic_url_is_safe( $source );
			if ( true !== $allowed ) {
				return '';
			}
			$res = wp_safe_remote_get(
				$source,
				array(
					'timeout'     => 10,
					'redirection' => 0, // refuse redirects so a 302 → http://169.254.169.254 can't slip through
					'user-agent'  => 'Formistic-AI/' . WPISTIC_FORMISTIC_VERSION . ' (+' . home_url( '/' ) . ')',
				)
			);
			if ( is_wp_error( $res ) ) {
				return '';
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			if ( $code < 200 || $code >= 400 ) {
				return '';
			}
			return sanitize_textarea_field( (string) wp_remote_retrieve_body( $res ) );
		}

		// Local-file branch — restrict to inside ABSPATH (no arbitrary
		// /etc/passwd reads, no wp-config.php exfil) and require a
		// real, canonicalised path.
		$real = realpath( $source );
		if ( false === $real ) {
			return '';
		}
		$abs = realpath( ABSPATH );
		if ( false === $abs || 0 !== strpos( $real . DIRECTORY_SEPARATOR, $abs . DIRECTORY_SEPARATOR ) ) {
			return '';
		}
		// Refuse known-sensitive files even inside ABSPATH.
		$basename = strtolower( basename( $real ) );
		$blocked  = array( 'wp-config.php', '.htaccess', '.htpasswd', '.env', 'wp-config-sample.php' );
		if ( in_array( $basename, $blocked, true ) ) {
			return '';
		}
		if ( ! is_readable( $real ) ) {
			return '';
		}
		return sanitize_textarea_field( (string) file_get_contents( $real ) );
	}

	/**
	 * SSRF + scheme guard for AI text-source URLs.
	 *
	 * Returns true when the URL is safe to fetch, or a WP_Error
	 * describing why it was rejected. Override via the
	 * wpistic_formistic_ai_url_check filter — return true to allow an
	 * otherwise-blocked URL, or a WP_Error to deny one that would
	 * otherwise pass.
	 */
	public static function wpistic_formistic_url_is_safe( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( empty( $parts['host'] ) ) {
			return new \WP_Error( 'wpistic_formistic_ai_url_invalid', 'URL has no host.' );
		}
		// Scheme: HTTPS only, with HTTP allowed only for localhost so
		// people running Ollama at 127.0.0.1:11434 still work.
		$scheme = strtolower( $parts['scheme'] ?? '' );
		$host   = strtolower( $parts['host'] );
		$is_local_host = in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );
		if ( 'https' !== $scheme && ! ( 'http' === $scheme && $is_local_host ) ) {
			$reject = new \WP_Error( 'wpistic_formistic_ai_url_scheme', 'Only https:// URLs are allowed.' );
			return apply_filters( 'wpistic_formistic_ai_url_check', $reject, $url, $parts );
		}
		// Special-case localhost/127.0.0.1/::1 — local AI providers like
		// Ollama bind here. Skip the IP-resolution + private-range check
		// because by definition these targets ARE local; the scheme guard
		// above already ensured the URL is intentional.
		if ( $is_local_host ) {
			return apply_filters( 'wpistic_formistic_ai_url_check', true, $url, $parts );
		}
		// Resolve the host to its IP(s). dns_get_record returns A+AAAA;
		// gethostbynamel returns IPv4 — combine both for IPv6 coverage.
		$ips = array();
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips[] = $host;
		} else {
			$v4 = gethostbynamel( $host );
			if ( is_array( $v4 ) ) { $ips = array_merge( $ips, $v4 ); }
			$v6 = @dns_get_record( $host, DNS_AAAA );
			if ( is_array( $v6 ) ) {
				foreach ( $v6 as $rec ) {
					if ( ! empty( $rec['ipv6'] ) ) { $ips[] = $rec['ipv6']; }
				}
			}
		}
		if ( empty( $ips ) ) {
			$reject = new \WP_Error( 'wpistic_formistic_ai_url_dns', 'Host does not resolve.' );
			return apply_filters( 'wpistic_formistic_ai_url_check', $reject, $url, $parts );
		}
		foreach ( $ips as $ip ) {
			if ( self::wpistic_formistic_ip_is_private_or_metadata( $ip ) ) {
				$reject = new \WP_Error( 'wpistic_formistic_ai_url_internal', 'Host resolves to an internal / metadata IP.' );
				return apply_filters( 'wpistic_formistic_ai_url_check', $reject, $url, $parts );
			}
		}
		return apply_filters( 'wpistic_formistic_ai_url_check', true, $url, $parts );
	}

	/**
	 * True if the IP is in a private, loopback, link-local, multicast,
	 * or cloud-metadata range. SSRF-relevant denylist.
	 */
	public static function wpistic_formistic_ip_is_private_or_metadata( $ip ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return true; // unparseable → reject
		}
		// Cloud-metadata endpoints (AWS / GCP / Azure / DigitalOcean / OpenStack).
		$metadata = array( '169.254.169.254', 'fd00:ec2::254', '100.100.100.200' );
		if ( in_array( $ip, $metadata, true ) ) {
			return true;
		}
		// PHP's FILTER_VALIDATE_IP with NO_PRIV_RANGE | NO_RES_RANGE
		// returns FALSE for any private/reserved IP — which is exactly
		// what we want to reject.
		$is_public = filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
		return false === $is_public;
	}

	/**
	 * Provider-based text generation. Request/response shapes are
	 * provider-aware:
	 *
	 * - openrouter  → OpenAI-style chat completions ({model, messages[],
	 *                 max_tokens}) with Bearer auth + HTTP-Referer/X-Title
	 *                 attribution headers; endpoint auto-defaults to
	 *                 https://openrouter.ai/api/v1/chat/completions.
	 * - huggingface → Inference API ({inputs}); parses [0].generated_text.
	 * - ollama      → /api/generate ({model, prompt, stream:false}).
	 * - custom      → generic {model, prompt}.
	 *
	 * Failures return '' (callers fall back to local rules) and leave the
	 * reason in self::$last_error.
	 *
	 * @param string $prompt Prompt text (the user-turn content).
	 * @param string $system Optional persona/knowledge context. Sent as a
	 *                       system message on chat providers, otherwise
	 *                       prepended to the prompt.
	 * @return string
	 */
	protected function wpistic_formistic_ai_generate_text( $prompt, $system = '' ) {
		self::$last_error = '';

		$provider = (string) get_option( 'wpistic_formistic_ai_provider', 'local_rules' );
		if ( 'local_rules' === $provider ) {
			return '';
		}
		$endpoint = (string) get_option( 'wpistic_formistic_ai_endpoint', '' );
		$model    = (string) get_option( 'wpistic_formistic_ai_model', '' );
		$api_key  = (string) get_option( 'wpistic_formistic_ai_api_key', '' );
		if ( '' === $endpoint && 'openrouter' === $provider ) {
			$endpoint = 'https://openrouter.ai/api/v1/chat/completions';
		}
		if ( '' === $endpoint ) {
			self::$last_error = 'no endpoint configured';
			return '';
		}
		$headers = [ 'Content-Type' => 'application/json' ];
		if ( '' !== $api_key ) {
			$headers['Authorization'] = 'Bearer ' . $api_key;
		}

		// Single-prompt providers get the system context folded in up front.
		$full_prompt = '' !== trim( $system ) ? $system . "\n\n" . $prompt : $prompt;

		switch ( $provider ) {
			case 'openrouter':
				$messages = [];
				if ( '' !== trim( $system ) ) {
					$messages[] = [ 'role' => 'system', 'content' => $system ];
				}
				$messages[] = [ 'role' => 'user', 'content' => $prompt ];
				$body = [
					'model'      => $model,
					'messages'   => $messages,
					'max_tokens' => (int) apply_filters( 'wpistic_formistic_ai_max_tokens', 600 ),
				];
				// App-attribution headers OpenRouter asks integrations to send.
				$headers['HTTP-Referer'] = home_url( '/' );
				$headers['X-Title']      = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
				break;
			case 'huggingface':
				$body = [ 'inputs' => $full_prompt ];
				break;
			case 'ollama':
				$body = [ 'model' => $model, 'prompt' => $full_prompt, 'stream' => false ];
				break;
			default: // custom endpoint — keep the legacy generic shape.
				$body = [ 'model' => $model, 'prompt' => $full_prompt ];
				break;
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
			self::$last_error = substr( $res->get_error_message(), 0, 60 );
			return '';
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( $code < 200 || $code >= 300 ) {
			$detail = '';
			if ( isset( $data['error']['message'] ) ) {
				$detail = (string) $data['error']['message'];
			} elseif ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
				$detail = $data['error'];
			}
			self::$last_error = substr( trim( 'HTTP ' . $code . ' ' . $detail ), 0, 60 );
			return '';
		}

		// Provider-specific shapes first, then the generic fallbacks.
		if ( 'huggingface' === $provider && isset( $data[0]['generated_text'] ) ) {
			return sanitize_textarea_field( (string) $data[0]['generated_text'] );
		}
		if ( isset( $data['response'] ) ) {
			return sanitize_textarea_field( (string) $data['response'] );
		}
		if ( isset( $data['choices'][0]['message']['content'] ) ) {
			return sanitize_textarea_field( (string) $data['choices'][0]['message']['content'] );
		}
		if ( isset( $data['choices'][0]['text'] ) ) {
			return sanitize_textarea_field( (string) $data['choices'][0]['text'] );
		}
		self::$last_error = 'unrecognized response shape';
		return '';
	}

	/**
	 * Fallback reply when no external AI provider is configured.
	 *
	 * @param object $row Submission row.
	 * @return string
	 */
	protected function wpistic_formistic_local_fallback_reply( $row ) {
		$name = '' !== trim( (string) $row->sender_name ) ? (string) $row->sender_name : __( 'there', 'formistic' );
		return sprintf(
			/* translators: 1: sender name, 2: site name */
			__( "Hi %1\$s,\n\nThanks for contacting %2\$s. We received your message and our team will get back to you shortly.\n\nBest regards,\nSupport Team", 'formistic' ),
			$name,
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);
	}
}
