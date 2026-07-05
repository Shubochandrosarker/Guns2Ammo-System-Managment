<?php
/**
 * Submission capture — records form submissions from a configurable set of
 * sources (theme handlers, CF7, WPForms, Gravity, Fluent, wp_mail intercept,
 * plus the plugin's own shortcode), runs them through the spam stack, and
 * normalizes attachments.
 *
 * @package Wpistic_Formistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures form submissions into the Wpistic_Formistic_Database.
 */
class Wpistic_Formistic_Capture {

	/**
	 * Flag flipped on while Wpistic_Formistic itself dispatches mail (admin notifications,
	 * auto-responder, dashboard replies) so the wp_mail intercept doesn't
	 * record our own outbound traffic.
	 *
	 * @var bool
	 */
	public static $sending_internal = false;

	/**
	 * The submission ID created by the most recent store() — used by the
	 * shortcode handler to associate uploaded files.
	 *
	 * @var int
	 */
	public static $last_submission_id = 0;

	/**
	 * Register capture hooks. Each integration is gated by its option toggle
	 * so disabled sources add zero overhead.
	 */
	public function register() {
		// Third-party / theme capture sources are gated by the Captures addon.
		// The plugin's own forms always store to the inbox regardless.
		if ( ! Wpistic_Formistic_Addons::is_active( 'captures' ) ) {
			return;
		}
		if ( '1' === get_option( 'wpistic_formistic_capture_g2a', '1' ) ) {
			foreach ( [ 'g2a_request', 'g2a_reservation' ] as $action ) {
				add_action( 'admin_post_' . $action, [ $this, 'capture_theme_form' ], 1 );
				add_action( 'admin_post_nopriv_' . $action, [ $this, 'capture_theme_form' ], 1 );
			}
		}
		if ( '1' === get_option( 'wpistic_formistic_capture_cf7', '1' ) ) {
			add_action( 'wpcf7_mail_sent', [ $this, 'capture_cf7' ], 10, 1 );
		}
		if ( '1' === get_option( 'wpistic_formistic_capture_wpforms', '1' ) ) {
			add_action( 'wpforms_process_complete', [ $this, 'capture_wpforms' ], 10, 4 );
		}
		if ( '1' === get_option( 'wpistic_formistic_capture_gform', '1' ) ) {
			add_action( 'gform_after_submission', [ $this, 'capture_gform' ], 10, 2 );
		}
		if ( '1' === get_option( 'wpistic_formistic_capture_fluent', '1' ) ) {
			add_action( 'fluentform/submission_inserted', [ $this, 'capture_fluent' ], 10, 3 );
			add_action( 'fluentform_submission_inserted', [ $this, 'capture_fluent' ], 10, 3 );
		}
		if ( '1' === get_option( 'wpistic_formistic_capture_wpmail', '0' ) ) {
			add_filter( 'wp_mail', [ $this, 'intercept_wp_mail' ], 999 );
		}
	}

	/* ==================================================================
	 * Integrations
	 * ================================================================== */

	/**
	 * Contact Form 7 — capture from wpcf7_mail_sent.
	 *
	 * @param object $contact_form WPCF7_ContactForm instance.
	 */
	public function capture_cf7( $contact_form ) {
		if ( ! class_exists( 'WPCF7_Submission' ) ) {
			return;
		}
		$submission = WPCF7_Submission::get_instance();
		if ( ! $submission ) {
			return;
		}
		$posted = $submission->get_posted_data();
		if ( ! is_array( $posted ) || ! $posted ) {
			return;
		}

		$fields = [];
		foreach ( $posted as $key => $value ) {
			if ( 0 === strpos( (string) $key, '_wpcf7' ) || 0 === strpos( (string) $key, 'g-recaptcha' ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'sanitize_text_field', $value ) );
			} else {
				$value = sanitize_textarea_field( (string) $value );
			}
			if ( '' === trim( (string) $value ) ) {
				continue;
			}
			$fields[ $this->humanize_label( $key ) ] = $value;
		}

		$form_name = method_exists( $contact_form, 'title' )
			? (string) $contact_form->title()
			: __( 'Contact Form 7', 'formistic' );

		$id = $this->store( $form_name, $fields );

		// Attachments — CF7 keeps temp file paths in uploaded_files().
		if ( $id && method_exists( $submission, 'uploaded_files' ) && class_exists( 'Wpistic_Formistic_Attachments' ) ) {
			$files = (array) $submission->uploaded_files();
			foreach ( $files as $field_name => $paths ) {
				foreach ( (array) $paths as $path ) {
					if ( $path && is_string( $path ) && is_file( $path ) ) {
						// CF7 deletes temp files after mail-send; copy now.
						$this->copy_local_file_to_attachments( $id, $path );
					}
				}
			}
		}
	}

	/**
	 * WPForms — capture from wpforms_process_complete.
	 *
	 * @param array $fields    Sanitized field values keyed by field ID.
	 * @param array $entry     Raw entry data.
	 * @param array $form_data Form settings/structure.
	 * @param int   $entry_id  Entry ID.
	 */
	public function capture_wpforms( $fields, $entry, $form_data, $entry_id ) {
		if ( ! is_array( $fields ) ) {
			return;
		}

		$normalized   = [];
		$file_entries = [];
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$type  = $field['type'] ?? '';
			$label = ! empty( $field['name'] ) ? (string) $field['name'] : ( $type ?: 'Field' );

			if ( 'file-upload' === $type ) {
				$urls = [];
				if ( ! empty( $field['value_raw'] ) && is_array( $field['value_raw'] ) ) {
					foreach ( $field['value_raw'] as $f ) {
						if ( ! empty( $f['value'] ) ) {
							$urls[] = [ 'url' => (string) $f['value'], 'name' => (string) ( $f['file_user_name'] ?? '' ) ];
						}
					}
				} elseif ( ! empty( $field['value'] ) ) {
					foreach ( preg_split( '/[\r\n]+/', (string) $field['value'] ) as $url ) {
						$url = trim( $url );
						if ( $url ) {
							$file_entries[] = [ 'url' => $url, 'name' => '' ];
						}
					}
				}
				foreach ( $urls as $u ) {
					$file_entries[] = $u;
				}
				$normalized[ $label ] = sprintf( /* translators: %d: count */ _n( '%d file', '%d files', max( 1, count( $urls ) ), 'formistic' ), max( 1, count( $urls ) ) );
				continue;
			}

			$value = $field['value'] ?? '';
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', $value ) );
			}
			$value = sanitize_textarea_field( (string) $value );
			if ( '' === trim( $value ) ) {
				continue;
			}
			$normalized[ $label ] = $value;
		}

		$form_name = $form_data['settings']['form_title']
			?? $form_data['name']
			?? __( 'WPForms Form', 'formistic' );

		$id = $this->store( (string) $form_name, $normalized );

		if ( $id && $file_entries && class_exists( 'Wpistic_Formistic_Attachments' ) ) {
			foreach ( $file_entries as $f ) {
				Wpistic_Formistic_Attachments::ingest_external_url( $id, $f['url'], $f['name'] );
			}
		}
	}

	/**
	 * Gravity Forms — capture from gform_after_submission.
	 *
	 * @param array $entry Entry record.
	 * @param array $form  Form definition.
	 */
	public function capture_gform( $entry, $form ) {
		if ( ! is_array( $entry ) || ! is_array( $form ) ) {
			return;
		}

		$fields  = [];
		$f_urls  = [];
		foreach ( (array) ( $form['fields'] ?? [] ) as $field ) {
			$id    = isset( $field->id ) ? (string) $field->id : '';
			$label = isset( $field->label ) ? (string) $field->label : '';
			$type  = isset( $field->type ) ? (string) $field->type : '';
			if ( '' === $id ) {
				continue;
			}

			if ( in_array( $type, [ 'fileupload', 'post_image' ], true ) ) {
				$raw  = isset( $entry[ $id ] ) ? (string) $entry[ $id ] : '';
				$urls = [];
				if ( $raw ) {
					$decoded = json_decode( $raw, true );
					if ( is_array( $decoded ) ) {
						$urls = $decoded;
					} else {
						$urls = [ $raw ];
					}
				}
				foreach ( $urls as $u ) {
					if ( is_string( $u ) && $u ) {
						$f_urls[] = $u;
					}
				}
				if ( $urls ) {
					$fields[ $label ?: ( 'Field ' . $id ) ] = sprintf( _n( '%d file', '%d files', count( $urls ), 'formistic' ), count( $urls ) );
				}
				continue;
			}

			if ( isset( $entry[ $id ] ) && '' !== (string) $entry[ $id ] ) {
				$value = $entry[ $id ];
			} else {
				$parts = [];
				foreach ( $entry as $k => $v ) {
					if ( 0 === strpos( (string) $k, $id . '.' ) && '' !== (string) $v ) {
						$parts[] = $v;
					}
				}
				$value = $parts ? implode( ' ', $parts ) : '';
			}
			if ( is_array( $value ) ) {
				$value = implode( ', ', $value );
			}
			$value = sanitize_textarea_field( (string) $value );
			if ( '' === trim( $value ) ) {
				continue;
			}
			$fields[ $label !== '' ? $label : ( 'Field ' . $id ) ] = $value;
		}

		$form_name = $form['title'] ?? __( 'Gravity Form', 'formistic' );
		$id        = $this->store( (string) $form_name, $fields );

		if ( $id && $f_urls && class_exists( 'Wpistic_Formistic_Attachments' ) ) {
			foreach ( $f_urls as $url ) {
				Wpistic_Formistic_Attachments::ingest_external_url( $id, $url );
			}
		}
	}

	/**
	 * Fluent Forms — capture from fluentform/submission_inserted.
	 *
	 * @param int    $entry_id  Inserted entry ID.
	 * @param array  $form_data Posted form data (field name => value).
	 * @param object $form      Form object.
	 */
	public function capture_fluent( $entry_id, $form_data, $form ) {
		if ( ! is_array( $form_data ) ) {
			return;
		}

		// field-name => label map from the form definition (when available).
		$labels      = [];
		$file_fields = [];
		if ( is_object( $form ) && ! empty( $form->form_fields ) ) {
			$decoded = is_string( $form->form_fields ) ? json_decode( $form->form_fields, true ) : (array) $form->form_fields;
			if ( is_array( $decoded ) && ! empty( $decoded['fields'] ) ) {
				foreach ( (array) $decoded['fields'] as $f ) {
					$name    = $f['attributes']['name'] ?? '';
					$lbl     = $f['settings']['label']    ?? $name;
					$element = $f['element']              ?? '';
					if ( $name ) {
						$labels[ $name ] = $lbl;
						if ( in_array( $element, [ 'input_file', 'input_image' ], true ) ) {
							$file_fields[] = $name;
						}
					}
				}
			}
		}

		$fields    = [];
		$file_urls = [];
		foreach ( $form_data as $key => $value ) {
			$k = (string) $key;
			if ( 0 === strpos( $k, '_' ) || 0 === strpos( $k, '__' ) || 0 === strpos( $k, 'g-recaptcha' ) ) {
				continue;
			}

			if ( in_array( $k, $file_fields, true ) ) {
				foreach ( (array) $value as $url ) {
					$url = (string) $url;
					if ( $url ) {
						$file_urls[] = $url;
					}
				}
				$fields[ $labels[ $k ] ?? $this->humanize_label( $k ) ] = sprintf( _n( '%d file', '%d files', max( 1, count( (array) $value ) ), 'formistic' ), max( 1, count( (array) $value ) ) );
				continue;
			}

			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', $value ) );
			}
			$value = sanitize_textarea_field( (string) $value );
			if ( '' === trim( $value ) ) {
				continue;
			}
			$fields[ $labels[ $k ] ?? $this->humanize_label( $k ) ] = $value;
		}

		$form_name = ( is_object( $form ) && isset( $form->title ) )
			? (string) $form->title
			: __( 'Fluent Form', 'formistic' );

		$id = $this->store( $form_name, $fields );

		if ( $id && $file_urls && class_exists( 'Wpistic_Formistic_Attachments' ) ) {
			foreach ( $file_urls as $url ) {
				Wpistic_Formistic_Attachments::ingest_external_url( $id, $url );
			}
		}
	}

	/**
	 * wp_mail intercept-all mode — records a snapshot of any outgoing mail
	 * that wasn't sent by Formistic itself. Use sparingly.
	 *
	 * @param array $atts wp_mail() args (to, subject, message, headers, attachments).
	 * @return array Unchanged.
	 */
	public function intercept_wp_mail( $atts ) {
		if ( self::$sending_internal ) {
			return $atts;
		}
		if ( ! is_array( $atts ) ) {
			return $atts;
		}

		$to      = $atts['to'] ?? '';
		$subject = (string) ( $atts['subject'] ?? '' );
		$message = (string) ( $atts['message'] ?? '' );
		if ( is_array( $to ) ) {
			$to = implode( ', ', $to );
		}

		$fields = [
			__( 'To',      'formistic' ) => $to,
			__( 'Subject', 'formistic' ) => $subject,
			__( 'Body',    'formistic' ) => $message,
		];
		$this->store( __( 'wp_mail intercept', 'formistic' ), $fields );
		return $atts;
	}

	/* ==================================================================
	 * Legacy theme form
	 * ================================================================== */

	/**
	 * Capture a G2A Theme form before its handler redirects away.
	 */
	public function capture_theme_form() {
		if ( ! empty( $_POST['g2a_hp'] ) ) {
			return;
		}

		$nonce = isset( $_POST['g2a_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['g2a_nonce'] ) ) : '';
		$is_valid = wp_verify_nonce( $nonce, 'g2a_request' ) || wp_verify_nonce( $nonce, 'g2a_reservation' );
		if ( ! $is_valid ) {
			return;
		}

		$form_name = isset( $_POST['g2a_subject'] )
			? sanitize_text_field( wp_unslash( $_POST['g2a_subject'] ) )
			: __( 'Website Form', 'formistic' );

		$fields = [];
		$labels = [
			'g2a_name'       => __( 'Name', 'formistic' ),
			'g2a_email'      => __( 'Email', 'formistic' ),
			'g2a_phone'      => __( 'Phone', 'formistic' ),
			'g2a_date'       => __( 'Preferred Date', 'formistic' ),
			'g2a_count'      => __( 'Participants', 'formistic' ),
			'g2a_experience' => __( 'Experience Level', 'formistic' ),
			'g2a_notes'      => __( 'Notes', 'formistic' ),
		];
		foreach ( $labels as $key => $label ) {
			if ( isset( $_POST[ $key ] ) && '' !== trim( (string) $_POST[ $key ] ) ) {
				$fields[ $label ] = sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) );
			}
		}

		foreach ( $_POST as $key => $value ) {
			if ( 0 !== strpos( $key, 'g2a_f_' ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'sanitize_text_field', wp_unslash( $value ) ) );
			} else {
				$value = sanitize_textarea_field( wp_unslash( $value ) );
			}
			if ( '' === trim( (string) $value ) ) {
				continue;
			}
			$label            = ucwords( str_replace( [ 'g2a_f_', '_', '-' ], [ '', ' ', ' ' ], $key ) );
			$fields[ $label ] = $value;
		}

		$this->store( $form_name, $fields );
	}

	/* ==================================================================
	 * Core store + helpers
	 * ================================================================== */

	/**
	 * Normalize a label/value field map into a submission and persist it.
	 *
	 * Runs the spam stack (IP blocklist, rate limit, Akismet) before insert.
	 *
	 * @param string $form_name    Human-readable form name.
	 * @param array  $fields       Label => value map.
	 * @param bool   $notify_admin Whether to send the global admin notification.
	 * @return int Submission ID, or 0 if blocked / failed.
	 */
	public function store( $form_name, array $fields, $notify_admin = true ) {
		$name  = '';
		$email = '';
		$phone = '';
		$msg   = '';
		$aliases = apply_filters(
			'wpistic_formistic_field_aliases',
			[
				'name'    => [ 'name' ],
				'phone'   => [ 'phone', 'mobile' ],
				'message' => [ 'message', 'note', 'help' ],
			]
		);

		foreach ( $fields as $label => $value ) {
			$l = strtolower( (string) $label );
			$name_aliases = (array) ( $aliases['name'] ?? [] );
			if ( '' === $name && $this->contains_any_alias( $l, $name_aliases ) ) {
				$name = $value;
			}
			if ( '' === $email && is_email( $value ) ) {
				$email = $value;
			}
			$phone_aliases = (array) ( $aliases['phone'] ?? [] );
			if ( '' === $phone && $this->contains_any_alias( $l, $phone_aliases ) ) {
				$phone = $value;
			}
			$message_aliases = (array) ( $aliases['message'] ?? [] );
			if ( '' === $msg && $this->contains_any_alias( $l, $message_aliases ) ) {
				$msg = $value;
			}
		}

		$ip = class_exists( 'Wpistic_Formistic_Spam' ) ? Wpistic_Formistic_Spam::client_ip() : $this->client_ip_fallback();

		// De-duplication — drop an identical submission captured moments ago
		// (double-clicks, page refresh, or the same payload arriving via two
		// hooks). Returns the original submission ID so the caller still sees
		// success but no second row is written. Window is filterable.
		$window = (int) apply_filters( 'wpistic_formistic_dedupe_seconds', 60, $form_name, $fields );
		$dedupe_key = '';
		if ( $window > 0 ) {
			$dedupe_key = 'wpf_dedupe_' . md5( $form_name . '|' . (string) $ip . '|' . wp_json_encode( $fields ) );
			$prior = get_transient( $dedupe_key );
			if ( false !== $prior ) {
				return (int) $prior;
			}
			// Reserve the key immediately so concurrent requests can't both pass.
			set_transient( $dedupe_key, 0, $window );
		}

		// Spam gate.
		if ( class_exists( 'Wpistic_Formistic_Spam' ) ) {
			$check = Wpistic_Formistic_Spam::pre_store_check( $form_name, $fields, $ip, $email );
			if ( is_wp_error( $check ) ) {
				/**
				 * Fires when a submission is blocked at the spam gate.
				 *
				 * @param WP_Error $check     The block reason.
				 * @param string   $form_name Form name.
				 * @param array    $fields    Captured fields.
				 */
				do_action( 'wpistic_formistic_submission_blocked', $check, $form_name, $fields );
				if ( '' !== $dedupe_key ) {
					delete_transient( $dedupe_key );
				}
				return 0;
			}
		}

		$id = Wpistic_Formistic_Database::insert_submission( [
			'form_name'    => $form_name,
			'sender_name'  => $name,
			'sender_email' => $email,
			'sender_phone' => $phone,
			'subject'      => $form_name,
			'message'      => $msg,
			'fields'       => $fields,
			'source_url'   => esc_url_raw( (string) wp_get_referer() ),
			'ip_address'   => $ip,
		] );

		if ( $id ) {
			self::$last_submission_id = $id;
			// Record the real ID against the dedupe key so an identical retry
			// within the window resolves to this submission instead of storing
			// a duplicate.
			if ( '' !== $dedupe_key ) {
				set_transient( $dedupe_key, (int) $id, $window );
			}
			/**
			 * Fires after a submission is captured.
			 *
			 * @param int    $id        New submission ID.
			 * @param string $form_name Form name.
			 * @param array  $fields    Captured fields.
			 */
			do_action( 'wpistic_formistic_submission_captured', $id, $form_name, $fields );

			if ( $notify_admin ) {
				$this->notify_admin( $id, $form_name, $fields );
			}
		} elseif ( '' !== $dedupe_key ) {
			// Insert failed — release the reservation so a retry isn't blocked.
			delete_transient( $dedupe_key );
		}

		return $id;
	}

	/**
	 * Check whether a label contains any configured alias.
	 *
	 * @param string   $label   Lower-cased label text.
	 * @param string[] $aliases Alias list.
	 * @return bool
	 */
	protected function contains_any_alias( $label, array $aliases ) {
		foreach ( $aliases as $alias ) {
			$alias = strtolower( trim( (string) $alias ) );
			if ( '' !== $alias && false !== strpos( $label, $alias ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Email the site admin that a new submission arrived.
	 *
	 * @param int    $id        Submission ID.
	 * @param string $form_name Form name.
	 * @param array  $fields    Fields.
	 */
	protected function notify_admin( $id, $form_name, array $fields ) {
		if ( '1' !== get_option( 'wpistic_formistic_notify_admin', '1' ) ) {
			return;
		}
		$to    = get_option( 'wpistic_formistic_notify_email', get_option( 'admin_email' ) );
		$lines = [
			/* translators: %s: form name */
			sprintf( __( 'New submission from the "%s" form on your website.', 'formistic' ), $form_name ),
			'',
		];
		foreach ( $fields as $label => $value ) {
			$lines[] = $label . ': ' . $value;
		}
		$lines[] = '';
		$lines[] = __( 'View & reply in the dashboard:', 'formistic' ) . ' ' . admin_url( 'admin.php?page=formistic&view=' . $id );

		self::send_internal(
			$to,
			/* translators: %s: form name */
			sprintf( __( '[%s] New Form Submission', 'formistic' ), get_bloginfo( 'name' ) ) . ' — ' . $form_name,
			implode( "\n", $lines )
		);
	}

	/**
	 * wp_mail wrapper that flags the call as wpistic-formistic-internal so the intercept
	 * mode doesn't loop on our own outbound mail.
	 *
	 * @param string|array $to      Recipient(s).
	 * @param string       $subject Subject.
	 * @param string       $body    Body.
	 * @param array|string $headers Extra headers.
	 * @param array        $attach  Attachments.
	 * @return bool
	 */
	public static function send_internal( $to, $subject, $body, $headers = [], $attach = [] ) {
		$prev = self::$sending_internal;
		self::$sending_internal = true;
		$ok = wp_mail( $to, $subject, $body, $headers, $attach );
		self::$sending_internal = $prev;
		return (bool) $ok;
	}

	/**
	 * Fallback IP detection if Wpistic_Formistic_Spam is unavailable.
	 *
	 * @return string
	 */
	protected function client_ip_fallback() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return ( $ip && filter_var( $ip, FILTER_VALIDATE_IP ) ) ? $ip : '';
	}

	/**
	 * Turn a machine field name into a human label ("first_name" → "First Name").
	 *
	 * @param string $key Raw field key.
	 * @return string
	 */
	protected function humanize_label( $key ) {
		return ucwords( str_replace( [ '_', '-' ], ' ', (string) $key ) );
	}

	/**
	 * Copy a CF7 temp file into our protected storage and create a row.
	 *
	 * @param int    $submission_id Submission ID.
	 * @param string $path          Absolute path to the temp file.
	 */
	protected function copy_local_file_to_attachments( $submission_id, $path ) {
		if ( ! class_exists( 'Wpistic_Formistic_Attachments' ) ) {
			return;
		}
		$original = basename( $path );
		$ext      = strtolower( pathinfo( $original, PATHINFO_EXTENSION ) );
		$allowed  = Wpistic_Formistic_Attachments::allowed_extensions();
		if ( $allowed && ! in_array( $ext, $allowed, true ) ) {
			return;
		}
		$dir         = Wpistic_Formistic_Attachments::submission_dir( (int) $submission_id );
		$stored_name = wp_generate_password( 16, false, false ) . ( $ext ? '.' . $ext : '' );
		$target      = trailingslashit( $dir ) . $stored_name;
		if ( copy( $path, $target ) ) {
			chmod( $target, 0640 );
			$check = wp_check_filetype_and_ext( $target, $original );
			Wpistic_Formistic_Database::insert_attachment( [
				'submission_id' => (int) $submission_id,
				'original_name' => sanitize_file_name( $original ),
				'stored_name'   => $stored_name,
				'mime_type'     => ! empty( $check['type'] ) ? $check['type'] : '',
				'size_bytes'    => (int) filesize( $target ),
				'source'        => 'local',
				'external_url'  => '',
			] );
		}
	}
}
