<?php
/**
 * Logs view.
 *
 * @var array<int,array<string,string>> $rows
 * @var int $page
 * @var int $total
 * @var int $total_pages
 *
 * @package Messageistic
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap messageistic-wrap">
    <h1 class="messageistic-page-title"><?php esc_html_e( 'Logs', 'messageistic' ); ?></h1>
    <div class="messageistic-card">
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'When', 'messageistic' ); ?></th>
                    <th><?php esc_html_e( 'Event', 'messageistic' ); ?></th>
                    <th><?php esc_html_e( 'Level', 'messageistic' ); ?></th>
                    <th><?php esc_html_e( 'Provider', 'messageistic' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'messageistic' ); ?></th>
                    <th><?php esc_html_e( 'Error', 'messageistic' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="6"><?php esc_html_e( 'No log entries yet.', 'messageistic' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $rows as $row ) : ?>
                        <tr>
                            <td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $row['created_at'] ) ); ?></td>
                            <td><code><?php echo esc_html( $row['event_type'] ); ?></code></td>
                            <td><span class="messageistic-activity__level is-<?php echo esc_attr( $row['level'] ); ?>"><?php echo esc_html( $row['level'] ); ?></span></td>
                            <td><?php echo esc_html( $row['provider_key'] ?: '—' ); ?></td>
                            <td><?php echo esc_html( $row['status'] ?: '—' ); ?></td>
                            <td><?php echo esc_html( (string) $row['error_message'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ( $total_pages > 1 ) : ?>
            <nav class="messageistic-pagination">
                <?php
                echo paginate_links(
                    [
                        'base'      => add_query_arg( 'paged', '%#%' ),
                        'format'    => '',
                        'current'   => $page,
                        'total'     => $total_pages,
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                    ]
                ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                ?>
            </nav>
        <?php endif; ?>
    </div>
</div>
