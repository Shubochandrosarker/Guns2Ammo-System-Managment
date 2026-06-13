<?php
/**
 * AJAX endpoints — view a submission, send a reply, change status, delete.
 *
 * @package WPISTIC_CF
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles all admin-side AJAX for the WPistic Contact dashboard.
 */
class WPISTIC_CF_Ajax {

	/** Capability required. */
	const CAP = 'manage_options';

	/**
	 * Register AJAX hooks.
	 */
	public function register() {
		add_action( 'wp_ajax_WPISTIC_CF_get_submission', [ $this, 'get_submission' ] );
		add_action( 'wp_ajax_WPISTIC_CF_send_reply', [ $this, 'send_reply' ] );
		add_action( 'wp_ajax_WPISTIC_CF_delete', [ $this, 'delete' ] );
		add_action( 'wp_ajax_WPISTIC_CF_add_note', [ $this, 'add_note' ] );
		add_action( 'wp_ajax_WPISTIC_CF_replay_submission', [ $this, 'replay_submission' ] );
	}

	/**
	 * Shared guard: verify nonce + capability.
	 */
	protected function guard() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wpistic-contact-form' ) ], 403 );
		}
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'WPISTIC_CF_admin' ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed. Please reload the page.', 'wpistic-contact-form' ) ], 403 );
		}
	}

	/**
	 * Return a submission's full detail as rendered HTML + meta.
	 */
	public function get_submission() {
		$this->guard();

		$id  = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$row = WPISTIC_CF_Database::get_submission( $id );
		if ( ! $row ) {
			wp_send_json_error( [ 'message' => __( 'Submission not found.', 'wpistic-contact-form' ) ], 404 );
		}

		// First view marks a "new" submission as "read".
		if ( 'new' === $row->status ) {
			WPISTIC_CF_Database::set_status( $id, 'read' );
			$row->status = 'read';
		}

		wp_send_json_success( [
			'id'        => (int) $row->id,
			'email'     => $row->sender_email,
			'name'      => $row->sender_name,
			'form'      => $row->form_name,
			'status'    => $row->status,
			'subject'   => $this->reply_subject( $row ),
			'html'      => $this->render_detail( $row ),
			'original'  => (string) $row->message,
			'createdAt' => date_i18n( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), strtotime( (string) $row->created_at ) ),
		] );
	}

	/**
	 * Send an email reply to the submitter and log it.
	 */
	public function send_reply() {
		$this->guard();

		$id  = isset( $_POST['submission_id'] ) ? (int) $_POST['submission_id'] : 0;
		$row = WPISTIC_CF_Database::get_submission( $id );
		if ( ! $row ) {
			wp_send_json_error( [ 'message' => __( 'Submission not found.', 'wpistic-contact-form' ) ], 404 );
		}

		$to        = sanitize_email( $row->sender_email );
		$subject   = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		$html_mode = ! empty( $_POST['html_mode'] );
		$body      = isset( $_POST['body'] )
			? ( $html_mode ? wp_kses_post( wp_unslash( $_POST['body'] ) ) : sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) )
			: '';
		$cc_raw    = isset( $_POST['cc'] )  ? sanitize_text_field( wp_unslash( $_POST['cc'] ) )  : '';
		$bcc_raw   = isset( $_POST['bcc'] ) ? sanitize_text_field( wp_unslash( $_POST['bcc'] ) ) : '';

		if ( ! is_email( $to ) ) {
			wp_send_json_error( [ 'message' => __( 'This submission has no valid email address.', 'wpistic-contact-form' ) ], 400 );
		}
		if ( '' === $subject || '' === trim( $body ) ) {
			wp_send_json_error( [ 'message' => __( 'Please fill in both the subject and the reply message.', 'wpistic-contact-form' ) ], 400 );
		}

		$signature = (string) get_option( 'WPISTIC_CF_reply_signature', '' );
		$full_body = $body;
		if ( '' !== trim( $signature ) ) {
			$separator = $html_mode ? '<br><br>--<br>' : "\n\n--\n";
			$full_body .= $separator . ( $html_mode ? nl2br( esc_html( $signature ) ) : $signature );
		}

		$from_name  = get_option( 'WPISTIC_CF_reply_from_name', get_bloginfo( 'name' ) );
		$from_email = get_option( 'WPISTIC_CF_reply_from_email', get_option( 'admin_email' ) );
		$headers    = [];
		if ( is_email( $from_email ) ) {
			$headers[] = sprintf( 'From: %s <%s>', $from_name, $from_email );
			$headers[] = 'Reply-To: ' . $from_email;
		}
		if ( $html_mode ) {
			$headers[] = 'Content-Type: text/html; charset=UTF-8';
		}
		foreach ( $this->parse_address_list( $cc_raw ) as $addr ) {
			$headers[] = 'Cc: ' . $addr;
		}
		foreach ( $this->parse_address_list( $bcc_raw ) as $addr ) {
			$headers[] = 'Bcc: ' . $addr;
		}

		$sent = WPISTIC_CF_Capture::send_internal( $to, $subject, $full_body, $headers );
		if ( ! $sent ) {
			wp_send_json_error( [ 'message' => __( 'The email could not be sent. Check your site mail configuration.', 'wpistic-contact-form' ) ], 500 );
		}

		WPISTIC_CF_Database::insert_reply( $id, $subject, $full_body );
		WPISTIC_CF_Database::set_status( $id, 'replied' );

		wp_send_json_success( [
			'message' => __( 'Reply sent successfully.', 'wpistic-contact-form' ),
			'status'  => 'replied',
		] );
	}

	/**
	 * Delete a submission.
	 */
	public function delete() {
		$this->guard();
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( ! $id || ! WPISTIC_CF_Database::delete_submission( $id ) ) {
			wp_send_json_error( [ 'message' => __( 'Could not delete the submission.', 'wpistic-contact-form' ) ], 400 );
		}
		wp_send_json_success( [ 'message' => __( 'Submission deleted.', 'wpistic-contact-form' ) ] );
	}

	/**
	 * Add an internal note + tags to a submission.
	 */
	public function add_note() {
		$this->guard();
		$id   = isset( $_POST['submission_id'] ) ? (int) $_POST['submission_id'] : 0;
		$row  = WPISTIC_CF_Database::get_submission( $id );
		$note = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		$tags = isset( $_POST['tags'] ) ? sanitize_text_field( wp_unslash( $_POST['tags'] ) ) : '';
		if ( ! $row || '' === trim( $note ) ) {
			wp_send_json_error( [ 'message' => __( 'Please provide a valid note.', 'wpistic-contact-form' ) ], 400 );
		}
		WPISTIC_CF_Database::insert_note( $id, $note, $tags );
		wp_send_json_success( [
			'message' => __( 'Note added.', 'wpistic-contact-form' ),
			'html'    => $this->render_detail( $row ),
		] );
	}

	/**
	 * Replay webhook/autoresponder delivery for a submission.
	 */
	public function replay_submission() {
		$this->guard();
		$id   = isset( $_POST['submission_id'] ) ? (int) $_POST['submission_id'] : 0;
		$type = isset( $_POST['replay_type'] ) ? sanitize_key( wp_unslash( $_POST['replay_type'] ) ) : 'both';
		$row  = WPISTIC_CF_Database::get_submission( $id );
		if ( ! $row ) {
			wp_send_json_error( [ 'message' => __( 'Submission not found.', 'wpistic-contact-form' ) ], 404 );
		}
		$fields = json_decode( (string) $row->fields, true );
		$fields = is_array( $fields ) ? $fields : [];
		if ( in_array( $type, [ 'both', 'webhook' ], true ) && class_exists( 'WPISTIC_CF_Webhooks' ) ) {
			WPISTIC_CF_Webhooks::dispatch_submission( (int) $row->id, (string) $row->form_name, $fields );
		}
		if ( in_array( $type, [ 'both', 'autoresponder' ], true ) && class_exists( 'WPISTIC_CF_Autoresponder' ) ) {
			WPISTIC_CF_Autoresponder::replay_for_submission( (int) $row->id );
		}
		wp_send_json_success( [ 'message' => __( 'Replay dispatched.', 'wpistic-contact-form' ) ] );
	}

	/**
	 * Parse a comma-separated list of emails, returning valid ones.
	 *
	 * @param string $raw Raw input.
	 * @return string[]
	 */
	protected function parse_address_list( $raw ) {
		$out = [];
		foreach ( explode( ',', (string) $raw ) as $part ) {
			$part = trim( $part );
			if ( '' !== $part && is_email( $part ) ) {
				$out[] = $part;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Suggested reply subject line.
	 *
	 * @param object $row Submission row.
	 * @return string
	 */
	protected function reply_subject( $row ) {
		$base = $row->subject ?: $row->form_name;
		/* translators: %s: original submission subject */
		return sprintf( __( 'Re: %s', 'wpistic-contact-form' ), $base );
	}

	/**
	 * Build the submission detail HTML for the View modal.
	 *
	 * @param object $row Submission row.
	 * @return string
	 */
	protected function render_detail( $row ) {
		$fields      = json_decode( (string) $row->fields, true );
		$fields      = is_array( $fields ) ? $fields : [];
		$replies     = WPISTIC_CF_Database::get_replies( $row->id );
		$attachments = WPISTIC_CF_Database::get_attachments( $row->id );
		$notes       = WPISTIC_CF_Database::get_notes( $row->id );
		$sender_rows = $row->sender_email ? WPISTIC_CF_Database::sender_activity( $row->sender_email ) : [];
		$ai_meta     = WPISTIC_CF_Database::get_ai_meta( $row->id );

		ob_start();
		?>
		<div class="WPISTIC_CF-detail">
			<div class="WPISTIC_CF-detail__meta">
				<span class="WPISTIC_CF-formtag"><?php echo esc_html( $row->form_name ?: __( 'Website Form', 'wpistic-contact-form' ) ); ?></span>
				<span class="WPISTIC_CF-detail__date">
					<?php echo esc_html( date_i18n( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), strtotime( (string) $row->created_at ) ) ); ?>
				</span>
			</div>

			<table class="WPISTIC_CF-detail__table">
				<tbody>
					<?php if ( $row->sender_name ) : ?>
						<tr><th><?php esc_html_e( 'Name', 'wpistic-contact-form' ); ?></th><td><?php echo esc_html( $row->sender_name ); ?></td></tr>
					<?php endif; ?>
					<?php if ( $row->sender_email ) : ?>
						<tr><th><?php esc_html_e( 'Email', 'wpistic-contact-form' ); ?></th><td><a href="mailto:<?php echo esc_attr( $row->sender_email ); ?>"><?php echo esc_html( $row->sender_email ); ?></a> · <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'wpistic-contact', 'sender' => (string) $row->sender_email ], admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Open unified sender view', 'wpistic-contact-form' ); ?></a></td></tr>
					<?php endif; ?>
					<?php if ( $row->sender_phone ) : ?>
						<tr><th><?php esc_html_e( 'Phone', 'wpistic-contact-form' ); ?></th><td><?php echo esc_html( $row->sender_phone ); ?></td></tr>
					<?php endif; ?>
					<?php
					foreach ( $fields as $label => $value ) :
						$skip = [ 'name', 'email', 'phone' ];
						if ( in_array( strtolower( (string) $label ), $skip, true ) ) {
							continue;
						}
						?>
						<tr>
							<th><?php echo esc_html( $label ); ?></th>
							<td><?php echo nl2br( esc_html( (string) $value ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( $row->source_url ) : ?>
						<tr><th><?php esc_html_e( 'Submitted from', 'wpistic-contact-form' ); ?></th><td><a href="<?php echo esc_url( $row->source_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $row->source_url ); ?></a></td></tr>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $row->message ) : ?>
				<div class="WPISTIC_CF-detail__message">
					<h3><?php esc_html_e( 'Message', 'wpistic-contact-form' ); ?></h3>
					<div class="WPISTIC_CF-detail__msgbody"><?php echo nl2br( esc_html( (string) $row->message ) ); ?></div>
				</div>
			<?php endif; ?>

			<?php if ( $attachments && class_exists( 'WPISTIC_CF_Attachments' ) ) : ?>
				<div class="WPISTIC_CF-detail__attachments">
					<h3><?php esc_html_e( 'Attachments', 'wpistic-contact-form' ); ?></h3>
					<ul class="WPISTIC_CF-attachments">
						<?php foreach ( $attachments as $att ) :
							$url = WPISTIC_CF_Attachments::download_url( $att );
							?>
							<li class="WPISTIC_CF-attachment">
								<a class="WPISTIC_CF-attachment__link" href="<?php echo esc_url( $url ); ?>"<?php echo 'external' === $att->source ? ' target="_blank" rel="noopener"' : ''; ?>>
									<span class="dashicons dashicons-paperclip" aria-hidden="true"></span>
									<span class="WPISTIC_CF-attachment__name"><?php echo esc_html( $att->original_name ?: __( '(file)', 'wpistic-contact-form' ) ); ?></span>
								</a>
								<span class="WPISTIC_CF-attachment__meta">
									<?php
									if ( 'local' === $att->source ) {
										echo esc_html( WPISTIC_CF_Attachments::format_size( (int) $att->size_bytes ) );
									} else {
										esc_html_e( 'External link', 'wpistic-contact-form' );
									}
									?>
								</span>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php if ( $replies ) : ?>
				<div class="WPISTIC_CF-detail__replies">
					<h3><?php esc_html_e( 'Reply History', 'wpistic-contact-form' ); ?></h3>
					<?php foreach ( $replies as $reply ) : ?>
						<div class="WPISTIC_CF-reply-item">
							<div class="WPISTIC_CF-reply-item__head">
								<strong><?php echo esc_html( $reply->reply_subject ); ?></strong>
								<span><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), strtotime( (string) $reply->sent_at ) ) ); ?></span>
							</div>
							<div class="WPISTIC_CF-reply-item__body"><?php echo nl2br( esc_html( (string) $reply->reply_body ) ); ?></div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( $sender_rows ) : ?>
				<div class="WPISTIC_CF-detail__replies">
					<h3><?php esc_html_e( 'Conversation Thread (Sender)', 'wpistic-contact-form' ); ?></h3>
					<?php foreach ( $sender_rows as $srow ) : ?>
						<div class="WPISTIC_CF-reply-item">
							<div class="WPISTIC_CF-reply-item__head">
								<strong><?php echo esc_html( $srow->form_name ?: __( 'Website Form', 'wpistic-contact-form' ) ); ?></strong>
								<span><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), strtotime( (string) $srow->created_at ) ) ); ?></span>
							</div>
							<div class="WPISTIC_CF-reply-item__body"><?php echo nl2br( esc_html( wp_trim_words( (string) $srow->message, 28, '…' ) ) ); ?></div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<div class="WPISTIC_CF-reply-tools" style="margin-top:14px;">
				<button type="button" class="button button-small WPISTIC_CF-replay" data-type="webhook" data-submission="<?php echo esc_attr( (int) $row->id ); ?>"><?php esc_html_e( 'Re-fire Webhooks', 'wpistic-contact-form' ); ?></button>
				<button type="button" class="button button-small WPISTIC_CF-replay" data-type="autoresponder" data-submission="<?php echo esc_attr( (int) $row->id ); ?>"><?php esc_html_e( 'Re-send Auto-Responder', 'wpistic-contact-form' ); ?></button>
				<button type="button" class="button button-small WPISTIC_CF-replay" data-type="both" data-submission="<?php echo esc_attr( (int) $row->id ); ?>"><?php esc_html_e( 'Replay Both', 'wpistic-contact-form' ); ?></button>
			</div>

			<div class="WPISTIC_CF-detail__replies">
				<h3><?php esc_html_e( 'Internal Notes & Tags', 'wpistic-contact-form' ); ?></h3>
				<div class="WPISTIC_CF-note-form" data-submission="<?php echo esc_attr( (int) $row->id ); ?>">
					<textarea rows="3" name="WPISTIC_CF_note_body" placeholder="<?php esc_attr_e( 'Add internal note for your team…', 'wpistic-contact-form' ); ?>"></textarea>
					<input type="text" name="WPISTIC_CF_note_tags" placeholder="<?php esc_attr_e( 'tags: vip, follow-up, support', 'wpistic-contact-form' ); ?>">
					<button type="button" class="button button-small WPISTIC_CF-note-add"><?php esc_html_e( 'Add Note', 'wpistic-contact-form' ); ?></button>
				</div>
				<?php if ( $notes ) : foreach ( $notes as $note ) : ?>
					<div class="WPISTIC_CF-reply-item">
						<div class="WPISTIC_CF-reply-item__head">
							<strong><?php echo esc_html( $note->display_name ?: __( 'Admin', 'wpistic-contact-form' ) ); ?></strong>
							<span><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), strtotime( (string) $note->created_at ) ) ); ?></span>
						</div>
						<?php if ( $note->tags ) : ?><div style="font-size:11px;color:#6B7088;margin-bottom:6px;"><?php echo esc_html( $note->tags ); ?></div><?php endif; ?>
						<div class="WPISTIC_CF-reply-item__body"><?php echo nl2br( esc_html( (string) $note->note_body ) ); ?></div>
					</div>
				<?php endforeach; else : ?>
					<p style="color:#6B7088;"><?php esc_html_e( 'No internal notes yet.', 'wpistic-contact-form' ); ?></p>
				<?php endif; ?>
			</div>
			<?php if ( $ai_meta ) : ?>
				<div class="WPISTIC_CF-detail__replies">
					<h3><?php esc_html_e( 'AI Insights', 'wpistic-contact-form' ); ?></h3>
					<p><strong><?php esc_html_e( 'Spam Score:', 'wpistic-contact-form' ); ?></strong> <?php echo esc_html( (int) $ai_meta->spam_score ); ?>/100</p>
					<?php if ( $ai_meta->ai_tags ) : ?><p><strong><?php esc_html_e( 'Smart Tags:', 'wpistic-contact-form' ); ?></strong> <?php echo esc_html( $ai_meta->ai_tags ); ?></p><?php endif; ?>
					<?php if ( $ai_meta->ai_reply ) : ?><div class="WPISTIC_CF-reply-item__body"><?php echo nl2br( esc_html( (string) $ai_meta->ai_reply ) ); ?></div><?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
