<?php
/**
 * Conversations inbox view — responsive two-pane chat UI.
 *
 * Server renders the full state; assets/js/admin.js progressively enhances
 * it (AJAX reply, live polling, list search, mobile pane switching). Every
 * action also works without JavaScript through the original form posts.
 *
 * @var array<int,array<string,mixed>> $threads
 * @var array<string,mixed>|null $active_thread
 * @var array<int,array<string,mixed>> $messages
 * @var int $active_id
 * @var bool $can_inbound
 * @var string $notice
 * @var string $filter_status
 * @var int $filter_assignee
 * @var int $filter_location
 * @var array<int,array<string,mixed>> $locations
 * @var array<int,\WP_User> $staff
 * @var array<int,array<string,mixed>> $notes
 * @var array<int,array<string,mixed>> $events
 *
 * @package Messageistic
 */

defined( 'ABSPATH' ) || exit;

$mi_status_icon = static function ( string $status ): string {
    return match ( $status ) {
        'delivered'         => '✓✓',
        'sent'              => '✓',
        'failed'            => '⚠',
        'queued', 'pending' => '⏱',
        default             => '',
    };
};

$mi_initials = static function ( array $row ): string {
    $first = trim( (string) ( $row['first_name'] ?? '' ) );
    $last  = trim( (string) ( $row['last_name'] ?? '' ) );
    if ( '' !== $first || '' !== $last ) {
        return strtoupper( mb_substr( $first, 0, 1 ) . mb_substr( $last, 0, 1 ) );
    }
    $phone = (string) ( $row['normalized_phone'] ?? '' );
    return '' !== $phone ? substr( $phone, -2 ) : '–';
};

$mi_list_url = add_query_arg(
    array_filter( [
        'page'        => 'messageistic-conversations',
        'status'      => $filter_status,
        'mine'        => $filter_assignee > 0 ? 1 : null,
        'location_id' => $filter_location ?: null,
    ] ),
    admin_url( 'admin.php' )
);

$mi_notice_is_error = '' !== $notice && false !== stripos( $notice, __( 'failed', 'messageistic' ) );
?>
<div class="wrap messageistic-wrap messageistic-wrap--inbox">
    <div class="messageistic-header">
        <div>
            <h1 class="messageistic-page-title"><?php esc_html_e( 'Conversations', 'messageistic' ); ?></h1>
            <p class="messageistic-subtitle"><?php esc_html_e( 'Two-way SMS inbox. New messages appear automatically.', 'messageistic' ); ?></p>
        </div>
        <form method="get" class="mi-inbox-filters">
            <input type="hidden" name="page" value="messageistic-conversations">
            <select name="status" aria-label="<?php esc_attr_e( 'Status filter', 'messageistic' ); ?>">
                <option value=""><?php esc_html_e( 'All statuses', 'messageistic' ); ?></option>
                <?php foreach ( [ 'open', 'pending', 'closed' ] as $status ) : ?>
                    <option value="<?php echo esc_attr( $status ); ?>" <?php selected( $filter_status, $status ); ?>><?php echo esc_html( ucfirst( $status ) ); ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ( ! empty( $locations ) ) : ?>
                <select name="location_id" aria-label="<?php esc_attr_e( 'Location filter', 'messageistic' ); ?>">
                    <option value="0"><?php esc_html_e( 'All locations', 'messageistic' ); ?></option>
                    <?php foreach ( $locations as $location ) : ?>
                        <option value="<?php echo (int) $location['id']; ?>" <?php selected( $filter_location, (int) $location['id'] ); ?>><?php echo esc_html( $location['name'] ); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <label class="mi-inbox-filters__mine"><input type="checkbox" name="mine" value="1" <?php checked( $filter_assignee > 0 ); ?>> <?php esc_html_e( 'Mine', 'messageistic' ); ?></label>
            <button class="button"><?php esc_html_e( 'Filter', 'messageistic' ); ?></button>
        </form>
    </div>

    <?php if ( '' !== $notice ) : ?>
        <div class="mi-alert <?php echo $mi_notice_is_error ? 'mi-alert--danger' : 'mi-alert--ok'; ?>"><p style="margin:0;"><?php echo esc_html( $notice ); ?></p></div>
    <?php endif; ?>

    <?php if ( ! $can_inbound ) : ?>
        <div class="messageistic-banner messageistic-banner--testing">
            <strong><?php esc_html_e( 'Inbound limited', 'messageistic' ); ?></strong>
            <span><?php esc_html_e( 'Inbound replies are managed inside the active provider unless inbound sync is enabled.', 'messageistic' ); ?></span>
        </div>
    <?php endif; ?>

    <div class="messageistic-inbox <?php echo $active_thread ? 'has-active' : ''; ?>">
        <aside class="messageistic-inbox__list">
            <div class="mi-inbox-search">
                <input type="search" class="mi-inbox-search__input" placeholder="<?php esc_attr_e( 'Search conversations…', 'messageistic' ); ?>" aria-label="<?php esc_attr_e( 'Search conversations', 'messageistic' ); ?>">
            </div>
            <div class="mi-inbox-threads">
                <?php if ( empty( $threads ) ) : ?>
                    <div class="mi-inbox-empty">
                        <span class="mi-inbox-empty__icon">💬</span>
                        <p><?php esc_html_e( 'No conversations yet.', 'messageistic' ); ?></p>
                        <p class="messageistic-subtitle"><?php esc_html_e( 'Inbound texts to your business number will appear here.', 'messageistic' ); ?></p>
                    </div>
                <?php else : foreach ( $threads as $t ) :
                    $url     = add_query_arg( [ 'conversation' => (int) $t['id'] ], $mi_list_url );
                    $name    = trim( ( $t['first_name'] ?? '' ) . ' ' . ( $t['last_name'] ?? '' ) ) ?: (string) $t['normalized_phone'];
                    $is_act  = (int) $t['id'] === $active_id;
                    $preview = trim( (string) ( $t['last_body'] ?? '' ) );
                    if ( '' !== $preview && 'outbound' === ( $t['last_direction'] ?? '' ) ) {
                        $preview = __( 'You:', 'messageistic' ) . ' ' . $preview;
                    }
                    $when = ! empty( $t['last_message_at'] ) ? human_time_diff( strtotime( (string) $t['last_message_at'] ), current_time( 'timestamp' ) ) : '';
                    ?>
                    <a class="mi-thread <?php echo $is_act ? 'is-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>" data-search="<?php echo esc_attr( strtolower( $name . ' ' . $t['normalized_phone'] . ' ' . $preview ) ); ?>">
                        <span class="mi-thread__avatar" aria-hidden="true"><?php echo esc_html( $mi_initials( $t ) ); ?></span>
                        <span class="mi-thread__main">
                            <span class="mi-thread__top">
                                <span class="mi-thread__name"><?php echo esc_html( $name ?: __( '(no name)', 'messageistic' ) ); ?></span>
                                <?php if ( $when ) : ?><span class="mi-thread__time"><?php echo esc_html( $when ); ?></span><?php endif; ?>
                            </span>
                            <span class="mi-thread__bottom">
                                <span class="mi-thread__preview"><?php echo esc_html( $preview ?: $t['normalized_phone'] ); ?></span>
                                <?php if ( ! empty( $t['unread_count'] ) ) : ?><span class="mi-thread__unread"><?php echo (int) $t['unread_count']; ?></span><?php endif; ?>
                            </span>
                            <span class="mi-thread__meta">
                                <span class="mi-dot mi-dot--<?php echo esc_attr( $t['status'] ); ?>"></span><?php echo esc_html( $t['status'] ); ?>
                                <?php if ( ! empty( $t['priority'] ) && 'normal' !== $t['priority'] ) : ?> · <span class="mi-thread__priority is-<?php echo esc_attr( $t['priority'] ); ?>"><?php echo esc_html( $t['priority'] ); ?></span><?php endif; ?>
                            </span>
                        </span>
                    </a>
                <?php endforeach; endif; ?>
            </div>
        </aside>

        <section class="messageistic-inbox__panel" <?php echo $active_thread ? 'data-contact-id="' . (int) $active_thread['contact_id'] . '"' : ''; ?>>
            <?php if ( ! $active_thread ) : ?>
                <div class="mi-inbox-empty mi-inbox-empty--panel">
                    <span class="mi-inbox-empty__icon">👈</span>
                    <p><?php esc_html_e( 'Select a conversation to read and reply.', 'messageistic' ); ?></p>
                </div>
            <?php else :
                $active_name = trim( ( $active_thread['first_name'] ?? '' ) . ' ' . ( $active_thread['last_name'] ?? '' ) ) ?: (string) $active_thread['normalized_phone'];
                ?>
                <header class="messageistic-inbox__header">
                    <a class="mi-inbox-back button" href="<?php echo esc_url( $mi_list_url ); ?>" aria-label="<?php esc_attr_e( 'Back to conversation list', 'messageistic' ); ?>">←</a>
                    <span class="mi-thread__avatar mi-thread__avatar--lg" aria-hidden="true"><?php echo esc_html( $mi_initials( $active_thread ) ); ?></span>
                    <div class="mi-inbox-peer">
                        <strong><?php echo esc_html( $active_name ); ?></strong>
                        <div class="mi-inbox-peer__sub">
                            <?php echo esc_html( $active_thread['normalized_phone'] ); ?><?php if ( ! empty( $active_thread['email'] ) ) : ?> · <?php echo esc_html( $active_thread['email'] ); ?><?php endif; ?>
                        </div>
                    </div>
                    <div class="mi-inbox-header-actions">
                        <span class="messageistic-pill"><?php echo esc_html( $active_thread['status'] ); ?></span>
                        <button type="button" class="button mi-inbox-details-toggle" aria-expanded="false"><?php esc_html_e( 'Details', 'messageistic' ); ?></button>
                    </div>
                </header>

                <div class="mi-inbox-details" hidden>
                    <?php if ( current_user_can( \Messageistic\Core\Permissions::MANAGE_INBOX ) ) : ?>
                    <form method="post" class="mi-inbox-workflow">
                        <?php wp_nonce_field( 'messageistic_inbox_workflow' ); ?>
                        <input type="hidden" name="action" value="workflow">
                        <input type="hidden" name="conversation_id" value="<?php echo (int) $active_id; ?>">
                        <label><?php esc_html_e( 'Status', 'messageistic' ); ?>
                            <select name="status"><?php foreach ( [ 'open', 'pending', 'closed' ] as $status ) : ?><option <?php selected( $active_thread['status'], $status ); ?>><?php echo esc_html( $status ); ?></option><?php endforeach; ?></select>
                        </label>
                        <label><?php esc_html_e( 'Priority', 'messageistic' ); ?>
                            <select name="priority"><?php foreach ( [ 'normal', 'high', 'urgent' ] as $priority ) : ?><option <?php selected( $active_thread['priority'] ?? 'normal', $priority ); ?>><?php echo esc_html( $priority ); ?></option><?php endforeach; ?></select>
                        </label>
                        <label><?php esc_html_e( 'Assignee', 'messageistic' ); ?>
                            <select name="assigned_user_id"><option value="0"><?php esc_html_e( 'Unassigned', 'messageistic' ); ?></option><?php foreach ( $staff as $user ) : ?><option value="<?php echo (int) $user->ID; ?>" <?php selected( (int) $active_thread['assigned_user_id'], (int) $user->ID ); ?>><?php echo esc_html( $user->display_name ); ?></option><?php endforeach; ?></select>
                        </label>
                        <label><?php esc_html_e( 'Location', 'messageistic' ); ?>
                            <select name="location_id"><option value="0">—</option><?php foreach ( $locations as $location ) : ?><option value="<?php echo (int) $location['id']; ?>" <?php selected( (int) $active_thread['location_id'], (int) $location['id'] ); ?>><?php echo esc_html( $location['name'] ); ?></option><?php endforeach; ?></select>
                        </label>
                        <button class="button button-primary"><?php esc_html_e( 'Update', 'messageistic' ); ?></button>
                    </form>
                    <?php endif; ?>

                    <details class="mi-inbox-notes">
                        <summary><?php esc_html_e( 'Internal notes & activity', 'messageistic' ); ?> (<?php echo count( $notes ) + count( $events ); ?>)</summary>
                        <?php if ( current_user_can( \Messageistic\Core\Permissions::MANAGE_INBOX ) ) : ?>
                        <form method="post" class="mi-inbox-note-form">
                            <?php wp_nonce_field( 'messageistic_inbox_note' ); ?>
                            <input type="hidden" name="action" value="note">
                            <input type="hidden" name="conversation_id" value="<?php echo (int) $active_id; ?>">
                            <textarea name="note" rows="2" required placeholder="<?php esc_attr_e( 'Private note for staff only…', 'messageistic' ); ?>"></textarea>
                            <button class="button"><?php esc_html_e( 'Add note', 'messageistic' ); ?></button>
                        </form>
                        <?php endif; ?>
                        <ul class="mi-inbox-notes__list">
                            <?php foreach ( $notes as $note ) : ?>
                                <li><strong><?php echo esc_html( $note['display_name'] ?: __( 'Staff', 'messageistic' ) ); ?>:</strong> <?php echo esc_html( $note['note'] ); ?> <small><?php echo esc_html( $note['created_at'] ); ?></small></li>
                            <?php endforeach; ?>
                            <?php foreach ( $events as $event ) : ?>
                                <li class="is-event"><em><?php echo esc_html( $event['event_type'] . ': ' . $event['old_value'] . ' → ' . $event['new_value'] ); ?></em></li>
                            <?php endforeach; ?>
                            <?php if ( empty( $notes ) && empty( $events ) ) : ?>
                                <li class="is-event"><em><?php esc_html_e( 'No notes or activity yet.', 'messageistic' ); ?></em></li>
                            <?php endif; ?>
                        </ul>
                    </details>
                </div>

                <div class="messageistic-inbox__thread" data-last-message-id="<?php echo (int) ( $messages ? max( array_column( $messages, 'id' ) ) : 0 ); ?>">
                    <?php if ( empty( $messages ) ) : ?>
                        <div class="mi-inbox-empty"><p><?php esc_html_e( 'No messages in this conversation yet.', 'messageistic' ); ?></p></div>
                    <?php endif; ?>
                    <?php foreach ( $messages as $m ) :
                        $is_out = 'inbound' !== $m['direction'];
                        $status = (string) $m['status'];
                        ?>
                        <div class="messageistic-bubble <?php echo $is_out ? 'is-out' : 'is-in'; ?> is-status-<?php echo esc_attr( $status ); ?>" data-message-id="<?php echo (int) $m['id']; ?>">
                            <div class="messageistic-bubble__body"><?php echo esc_html( $m['body'] ); ?></div>
                            <small>
                                <span class="messageistic-bubble__time"><?php echo esc_html( mysql2date( 'M j, H:i', $m['created_at'] ) ); ?></span>
                                <?php if ( $is_out ) : ?>
                                    · <span class="messageistic-bubble__status"><?php echo esc_html( $mi_status_icon( $status ) ); ?> <?php echo esc_html( $status ); ?></span>
                                <?php endif; ?>
                            </small>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ( current_user_can( \Messageistic\Core\Permissions::SEND_SMS ) ) : ?>
                <form class="messageistic-inbox__compose" method="post" data-contact-id="<?php echo (int) $active_thread['contact_id']; ?>">
                    <?php wp_nonce_field( 'messageistic_reply' ); ?>
                    <input type="hidden" name="action" value="reply">
                    <input type="hidden" name="contact_id" value="<?php echo (int) $active_thread['contact_id']; ?>">
                    <div class="mi-compose-error" hidden></div>
                    <div class="mi-compose-row">
                        <textarea name="body" rows="1" placeholder="<?php esc_attr_e( 'Type a reply… (Enter to send, Shift+Enter for a new line)', 'messageistic' ); ?>" required></textarea>
                        <button type="submit" class="button button-primary mi-compose-send"><?php esc_html_e( 'Send', 'messageistic' ); ?></button>
                    </div>
                    <div class="mi-compose-meta"><span class="mi-compose-counter"></span></div>
                </form>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>
</div>
