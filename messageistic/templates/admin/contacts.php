<?php
/**
 * Contacts list view.
 *
 * @var array<int,array<string,mixed>> $rows
 * @var int $total
 * @var int $current_page
 * @var int $total_pages
 * @var array<string,mixed> $args
 * @var string $notice
 *
 * @package Messageistic
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap messageistic-wrap">
    <h1 class="messageistic-page-title">
        <?php esc_html_e( 'Contacts', 'messageistic' ); ?>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=messageistic-contacts&view=new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add new', 'messageistic' ); ?></a>
    </h1>

    <?php if ( $notice ) : ?>
        <div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div>
    <?php endif; ?>

    <form method="get" class="messageistic-card">
        <input type="hidden" name="page" value="messageistic-contacts">
        <p>
            <input type="search" name="s" value="<?php echo esc_attr( (string) $args['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search by name, email, phone…', 'messageistic' ); ?>">
            <select name="opt_out">
                <option value=""><?php esc_html_e( 'Any opt-out status', 'messageistic' ); ?></option>
                <option value="0" <?php selected( $args['opt_out'], '0' ); ?>><?php esc_html_e( 'Subscribed', 'messageistic' ); ?></option>
                <option value="1" <?php selected( $args['opt_out'], '1' ); ?>><?php esc_html_e( 'Opted-out', 'messageistic' ); ?></option>
            </select>
            <select name="consent">
                <option value=""><?php esc_html_e( 'Any consent', 'messageistic' ); ?></option>
                <option value="1" <?php selected( $args['consent'], '1' ); ?>><?php esc_html_e( 'Has SMS consent', 'messageistic' ); ?></option>
                <option value="0" <?php selected( $args['consent'], '0' ); ?>><?php esc_html_e( 'No SMS consent', 'messageistic' ); ?></option>
            </select>
            <button type="submit" class="button"><?php esc_html_e( 'Filter', 'messageistic' ); ?></button>
        </p>
    </form>

    <div class="messageistic-card">
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Name', 'messageistic' ); ?></th>
                    <th><?php esc_html_e( 'Phone', 'messageistic' ); ?></th>
                    <th><?php esc_html_e( 'Email', 'messageistic' ); ?></th>
                    <th><?php esc_html_e( 'Source', 'messageistic' ); ?></th>
                    <th><?php esc_html_e( 'Provider', 'messageistic' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'messageistic' ); ?></th>
                    <th><?php esc_html_e( 'Last Activity', 'messageistic' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="7"><?php esc_html_e( 'No contacts yet.', 'messageistic' ); ?></td></tr>
                <?php else : foreach ( $rows as $row ) :
                    $edit_url = admin_url( 'admin.php?page=messageistic-contacts&view=edit&id=' . (int) $row['id'] );
                    ?>
                    <tr>
                        <td><a href="<?php echo esc_url( $edit_url ); ?>"><strong><?php echo esc_html( trim( ( $row['first_name'] ?? '' ) . ' ' . ( $row['last_name'] ?? '' ) ) ?: '—' ); ?></strong></a></td>
                        <td><?php echo esc_html( $row['normalized_phone'] ?: $row['phone'] ); ?></td>
                        <td><?php echo esc_html( $row['email'] ); ?></td>
                        <td><span class="messageistic-pill"><?php echo esc_html( $row['source'] ); ?></span></td>
                        <td><span class="messageistic-pill"><?php echo esc_html( $row['active_provider'] ?: '—' ); ?></span></td>
                        <td>
                            <?php if ( ! empty( $row['opt_out'] ) ) : ?>
                                <span class="messageistic-badge is-danger"><?php esc_html_e( 'Opted-out', 'messageistic' ); ?></span>
                            <?php elseif ( ! empty( $row['sms_consent'] ) ) : ?>
                                <span class="messageistic-badge is-ok"><?php esc_html_e( 'Consented', 'messageistic' ); ?></span>
                            <?php else : ?>
                                <span class="messageistic-badge"><?php esc_html_e( 'No consent', 'messageistic' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $row['last_message_at'] ? mysql2date( get_option( 'date_format' ), $row['last_message_at'] ) : '—' ); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

        <?php if ( $total_pages > 1 ) : ?>
            <nav class="messageistic-pagination">
                <?php
                echo paginate_links( [
                    'base'    => add_query_arg( 'paged', '%#%' ),
                    'format'  => '',
                    'current' => $current_page,
                    'total'   => $total_pages,
                ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                ?>
            </nav>
        <?php endif; ?>
    </div>
</div>
