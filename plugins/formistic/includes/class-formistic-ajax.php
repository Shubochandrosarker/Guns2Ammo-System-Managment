<?php
/**
 * AJAX endpoints — view a submission, send a reply, change status, delete.
 *
 * @package Wpistic_Formistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles all admin-side AJAX for the Formistic dashboard.
 */
class Wpistic_Formistic_Ajax {

	/** Capability required. */
	const CAP = 'manage_options';

	/**
	 * Register AJAX hooks.
	 */
	public function register() {
		add_action( 'wp_ajax_wpistic_formistic_get_submission', [ $this, 'get_submission' ] );
		add_action( 'wp_ajax_wpistic_formistic_send_reply', [ $this, 'send_reply' ] );
		add_action( 'wp_ajax_wpistic_formistic_delete', [ $this, 'delete' ] );
		add_action( 'wp_ajax_wpistic_formistic_add_note', [ $this, 'add_note' ] );
		add_action( 'wp_ajax_wpistic_formistic_replay_submission', [ $this, 'replay_submission' ] );
	}

	/**
	 * Shared guard: verify nonce + capability.
	 */
	protected function guard() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'formistic' ) ], 403 );
		}
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'wpistic_formistic_admin' ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed. Please reload the page.', 'formistic' ) ], 403 );
		}
	}

	/**
	 * Return a submission's full detail as rendered HTML + meta.
	 */
	public function get_submission() {
		$this->guard();

		$id  = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$row = Wpistic_Formistic_Database::get_submission( $id );
		if ( ! $row ) {
			wp_send_json_error( [ 'message' => __( 'Submission not found.', 'formistic' ) ], 404 );
		}

		// First view marks a "new" submission as "read".
		if ( 'new' === $row->status ) {
			Wpistic_Formistic_Database::set_status( $id, 'read' );
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
		$row = Wpistic_Formistic_Database::get_submission( $id );
		if ( ! $row ) {
			wp_send_json_error( [ 'message' => __( 'Submission not found.', 'formistic' ) ], 404 );
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
			wp_send_json_error( [ 'message' => __( 'This submission has no valid email address.', 'formistic' ) ], 400 );
		}
		if ( '' === $subject || '' === trim( $body ) ) {
			wp_send_json_error( [ 'message' => __( 'Please fill in both the subject and the reply message.', 'formistic' ) ], 400 );
		}

		$signature = (string) get_option( 'wpistic_formistic_reply_signature', '' );
		$full_body = $body;
		if ( '' !== trim( $signature ) ) {
			$separator = $html_mode ? '<br><br>--<br>' : "\n\n--\n";
			$full_body .= $separator . ( $html_mode ? nl2br( esc_html( $signature ) ) : $signature );
		}

		$from_name  = get_option( 'wpistic_formistic_reply_from_name', get_bloginfo( 'name' ) );
		$from_email = get_option( 'wpistic_formistic_reply_from_email', get_option( 'admin_email' ) );
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

		$sent = Wpistic_Formistic_Capture::send_internal( $to, $subject, $full_body, $headers );
		if ( ! $sent ) {
			wp_send_json_error( [ 'message' => __( 'The email could not be sent. Check your site mail configuration.', 'formistic' ) ], 500 );
		}

		Wpistic_Formistic_Database::insert_reply( $id, $subject, $full_body );
		Wpistic_Formistic_Database::set_status( $id, 'replied' );

		wp_send_json_success( [
			'message' => __( 'Reply sent successfully.', 'formistic' ),
			'status'  => 'replied',
		] );
	}

	/**
	 * Delete a submission.
	 */
	public function delete() {
		$this->guard();
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( ! $id || ! Wpistic_Formistic_Database::delete_submission( $id ) ) {
			wp_send_json_error( [ 'message' => __( 'Could not delete the submission.', 'formistic' ) ], 400 );
		}
		wp_send_json_success( [ 'message' => __( 'Submission deleted.', 'formistic' ) ] );
	}

	/**
	 * Add an internal note + tags to a submission.
	 */
	public function add_note() {
		$this->guard();
		$id   = isset( $_POST['submission_id'] ) ? (int) $_POST['submission_id'] : 0;
		$row  = Wpistic_Formistic_Database::get_submission( $id );
		$note = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		$tags = isset( $_POST['tags'] ) ? sanitize_text_field( wp_unslash( $_POST['tags'] ) ) : '';
		if ( ! $row || '' === trim( $note ) ) {
			wp_send_json_error( [ 'message' => __( 'Please provide a valid note.', 'formistic' ) ], 400 );
		}
		Wpistic_Formistic_Database::insert_note( $id, $note, $tags );
		wp_send_json_success( [
			'message' => __( 'Note added.', 'formistic' ),
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
		$row  = Wpistic_Formistic_Database::get_submission( $id );
		if ( ! $row ) {
			wp_send_json_error( [ 'message' => __( 'Submission not found.', 'formistic' ) ], 404 );
		}
		$fields = json_decode( (string) $row->fields, true );
		$fields = is_array( $fields ) ? $fields : [];
		if ( in_array( $type, [ 'both', 'webhook' ], true ) && class_exists( 'Wpistic_Formistic_Webhooks' ) ) {
			Wpistic_Formistic_Webhooks::dispatch_submission( (int) $row->id, (string) $row->form_name, $fields );
		}
		if ( in_array( $type, [ 'both', 'autoresponder' ], true ) && class_exists( 'Wpistic_Formistic_Autoresponder' ) ) {
			Wpistic_Formistic_Autoresponder::replay_for_submission( (int) $row->id );
		}
		wp_send_json_success( [ 'message' => __( 'Replay dispatched.', 'formistic' ) ] );
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
		return sprintf( __( 'Re: %s', 'formistic' ), $base );
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
		$replies     = Wpistic_Formistic_Database::get_replies( $row->id );
		$attachments = Wpistic_Formistic_Database::get_attachments( $row->id );
		$notes       = Wpistic_Formistic_Database::get_notes( $row->id );
		$sender_rows = $row->sender_email ? Wpistic_Formistic_Database::sender_activity( $row->sender_email ) : [];
		$ai_meta     = Wpistic_Formistic_Database::get_ai_meta( $row->id );

		ob_start();
		?>
		<div class="wpistic-formistic-detail">
			<div class="wpistic-formistic-detail__meta">
				<span class="wpistic-formistic-formtag"><?php echo esc_html( $row->form_name ?: __( 'Website Form', 'formistic' ) ); ?></span>
				<span class="wpistic-formistic-detail__date">
					<?php echo esc_html( date_i18n( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), strtotime( (string) $row->created_at ) ) ); ?>
				</span>
			</div>

			<table class="wpistic-formistic-detail__table">
				<tbody>
					<?php if ( $row->sender_name ) : ?>
						<tr><th><?php esc_html_e( 'Name', 'formistic' ); ?></th><td><?php echo esc_html( $row->sender_name ); ?></td></tr>
					<?php endif; ?>
					<?php if ( $row->sender_email ) : ?>
						<tr><th><?php esc_html_e( 'Email', 'formistic' ); ?></th><td><a href="mailto:<?php echo esc_attr( $row->sender_email ); ?>"><?php echo esc_html( $row->sender_email ); ?></a> · <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'formistic', 'sender' => (string) $row->sender_email ], admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Open unified sender view', 'formistic' ); ?></a></td></tr>
					<?php endif; ?>
					<?php if ( $row->sender_phone ) : ?>
						<tr><th><?php esc_html_e( 'Phone', 'formistic' ); ?></th><td><?php echo esc_html( $row->sender_phone ); ?></td></tr>
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
						<tr><th><?php esc_html_e( 'Submitted from', 'formistic' ); ?></th><td><a href="<?php echo esc_url( $row->source_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $row->source_url ); ?></a></td></tr>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $row->message ) : ?>
				<div class="wpistic-formistic-detail__message">
					<h3><?php esc_html_e( 'Message', 'formistic' ); ?></h3>
					<div class="wpistic-formistic-detail__msgbody"><?php echo nl2br( esc_html( (string) $row->message ) ); ?></div>
				</div>
			<?php endif; ?>

			<?php if ( $attachments && class_exists( 'Wpistic_Formistic_Attachments' ) ) : ?>
				<div class="wpistic-formistic-detail__attachments">
					<h3><?php esc_html_e( 'Attachments', 'formistic' ); ?></h3>
					<ul class="wpistic-formistic-attachments">
						<?php foreach ( $attachments as $att ) :
							$url = Wpistic_Formistic_Attachments::download_url( $att );
							?>
							<li class="wpistic-formistic-attachment">
								<a class="wpistic-formistic-attachment__link" href="<?php echo esc_url( $url ); ?>"<?php echo 'external' === $att->source ? ' target="_blank" rel="noopener"' : ''; ?>>
									<span class="dashicons dashicons-paperclip" aria-hidden="true"></span>
									<span class="wpistic-formistic-attachment__name"><?php echo esc_html( $att->original_name ?: __( '(file)', 'formistic' ) ); ?></span>
								</a>
								<span class="wpistic-formistic-attachment__meta">
									<?php
									if ( 'local' === $att->source ) {
										echo esc_html( Wpistic_Formistic_Attachments::format_size( (int) $att->size_bytes ) );
									} else {
										esc_html_e( 'External link', 'formistic' );
									}
									?>
								</span>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php if ( $replies ) : ?>
				<div class="wpistic-formistic-detail__replies">
					<h3><?php esc_html_e( 'Reply History', 'formistic' ); ?></h3>
					<?php foreach ( $replies as $reply ) : ?>
						<div class="wpistic-formistic-reply-item">
							<div class="wpistic-formistic-reply-item__head">
								<strong><?php echo esc_html( $reply->reply_subject ); ?></strong>
								<span><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), strtotime( (string) $reply->sent_at ) ) ); ?></span>
							</div>
							<div class="wpistic-formistic-reply-item__body"><?php echo nl2br( esc_html( (string) $reply->reply_body ) ); ?></div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( $sender_rows ) : ?>
				<div class="wpistic-formistic-detail__replies">
					<h3><?php esc_html_e( 'Conversation Thread (Sender)', 'formistic' ); ?></h3>
					<?php foreach ( $sender_rows as $srow ) : ?>
						<div class="wpistic-formistic-reply-item">
							<div class="wpistic-formistic-reply-item__head">
								<strong><?php echo esc_html( $srow->form_name ?: __( 'Website Form', 'formistic' ) ); ?></strong>
								<span><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), strtotime( (string) $srow->created_at ) ) ); ?></span>
							</div>
							<div class="wpistic-formistic-reply-item__body"><?php echo nl2br( esc_html( wp_trim_words( (string) $srow->message, 28, '…' ) ) ); ?></div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<div class="wpistic-formistic-reply-tools" style="margin-top:14px;">
				<button type="button" class="button button-small wpistic-formistic-replay" data-type="webhook" data-submission="<?php echo esc_attr( (int) $row->id ); ?>"><?php esc_html_e( 'Re-fire Webhooks', 'formistic' ); ?></button>
				<button type="button" class="button button-small wpistic-formistic-replay" data-type="autoresponder" data-submission="<?php echo esc_attr( (int) $row->id ); ?>"><?php esc_html_e( 'Re-send Auto-Responder', 'formistic' ); ?></button>
				<button type="button" class="button button-small wpistic-formistic-replay" data-type="both" data-submission="<?php echo esc_attr( (int) $row->id ); ?>"><?php esc_html_e( 'Replay Both', 'formistic' ); ?></button>
			</div>

			<div class="wpistic-formistic-detail__replies">
				<h3><?php esc_html_e( 'Internal Notes & Tags', 'formistic' ); ?></h3>
				<div class="wpistic-formistic-note-form" data-submission="<?php echo esc_attr( (int) $row->id ); ?>">
					<textarea rows="3" name="wpistic_formistic_note_body" placeholder="<?php esc_attr_e( 'Add internal note for your team…', 'formistic' ); ?>"></textarea>
					<input type="text" name="wpistic_formistic_note_tags" placeholder="<?php esc_attr_e( 'tags: vip, follow-up, support', 'formistic' ); ?>">
					<button type="button" class="button button-small wpistic-formistic-note-add"><?php esc_html_e( 'Add Note', 'formistic' ); ?></button>
				</div>
				<?php if ( $notes ) : foreach ( $notes as $note ) : ?>
					<div class="wpistic-formistic-reply-item">
						<div class="wpistic-formistic-reply-item__head">
							<strong><?php echo esc_html( $note->display_name ?: __( 'Admin', 'formistic' ) ); ?></strong>
							<span><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), strtotime( (string) $note->created_at ) ) ); ?></span>
						</div>
						<?php if ( $note->tags ) : ?><div style="font-size:11px;color:#6B7088;margin-bottom:6px;"><?php echo esc_html( $note->tags ); ?></div><?php endif; ?>
						<div class="wpistic-formistic-reply-item__body"><?php echo nl2br( esc_html( (string) $note->note_body ) ); ?></div>
					</div>
				<?php endforeach; else : ?>
					<p style="color:#6B7088;"><?php esc_html_e( 'No internal notes yet.', 'formistic' ); ?></p>
				<?php endif; ?>
			</div>
			<?php if ( $ai_meta ) : ?>
				<div class="wpistic-formistic-detail__replies">
					<h3><?php esc_html_e( 'AI Insights', 'formistic' ); ?></h3>
					<p><strong><?php esc_html_e( 'Spam Score:', 'formistic' ); ?></strong> <?php echo esc_html( (int) $ai_meta->spam_score ); ?>/100</p>
					<?php if ( $ai_meta->ai_tags ) : ?><p><strong><?php esc_html_e( 'Smart Tags:', 'formistic' ); ?></strong> <?php echo esc_html( $ai_meta->ai_tags ); ?></p><?php endif; ?>
					<?php if ( $ai_meta->ai_reply ) : ?><div class="wpistic-formistic-reply-item__body"><?php echo nl2br( esc_html( (string) $ai_meta->ai_reply ) ); ?></div><?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
