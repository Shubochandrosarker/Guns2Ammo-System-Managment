<?php
defined( 'ABSPATH' ) || exit;
/** @var array<int,array<string,mixed>> $rows */
/** @var string $notice */
?>
<div class="wrap messageistic-wrap">
    <h1 class="messageistic-page-title">
        <?php esc_html_e( 'Campaigns', 'messageistic' ); ?>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=messageistic-campaigns&view=new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add new', 'messageistic' ); ?></a>
    </h1>
    <?php if ( $notice ) : ?><div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>
    <div class="messageistic-card">
        <table class="widefat striped">
            <thead><tr>
                <th><?php esc_html_e( 'Name', 'messageistic' ); ?></th>
                <th><?php esc_html_e( 'Status', 'messageistic' ); ?></th>
                <th><?php esc_html_e( 'Mode', 'messageistic' ); ?></th>
                <th><?php esc_html_e( 'Recipients', 'messageistic' ); ?></th>
                <th><?php esc_html_e( 'Sent', 'messageistic' ); ?></th>
                <th><?php esc_html_e( 'Failed', 'messageistic' ); ?></th>
                <th><?php esc_html_e( 'Schedule', 'messageistic' ); ?></th>
                <th></th>
            </tr></thead>
            <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="8"><?php esc_html_e( 'No campaigns yet.', 'messageistic' ); ?></td></tr>
                <?php else : foreach ( $rows as $r ) :
                    $edit  = admin_url( 'admin.php?page=messageistic-campaigns&view=edit&id=' . (int) $r['id'] );
                    $base  = admin_url( 'admin.php?page=messageistic-campaigns' );
                    $queue = wp_nonce_url( $base . '&action=queue&id=' . (int) $r['id'], 'messageistic_campaign_action' );
                    $pause = wp_nonce_url( $base . '&action=pause&id=' . (int) $r['id'], 'messageistic_campaign_action' );
                    $resume = wp_nonce_url( $base . '&action=resume&id=' . (int) $r['id'], 'messageistic_campaign_action' );
                    $cancel = wp_nonce_url( $base . '&action=cancel&id=' . (int) $r['id'], 'messageistic_campaign_action' );
                    ?>
                    <tr>
                        <td><a href="<?php echo esc_url( $edit ); ?>"><strong><?php echo esc_html( $r['name'] ); ?></strong></a></td>
                        <td><span class="messageistic-pill"><?php echo esc_html( $r['status'] ); ?></span></td>
                        <td><?php echo esc_html( $r['provider_mode'] ); ?></td>
                        <td><?php echo (int) $r['total_recipients']; ?></td>
                        <td><?php echo (int) $r['sent_count']; ?></td>
                        <td><?php echo (int) $r['failed_count']; ?></td>
                        <td><?php echo esc_html( $r['schedule_at'] ? mysql2date( 'M j, H:i', $r['schedule_at'] ) : '—' ); ?></td>
                        <td>
                            <?php if ( in_array( $r['status'], [ 'draft', 'scheduled' ], true ) ) : ?>
                                <a href="<?php echo esc_url( $queue ); ?>"><?php esc_html_e( 'Send now', 'messageistic' ); ?></a>
                            <?php elseif ( 'sending' === $r['status'] ) : ?>
                                <a href="<?php echo esc_url( $pause ); ?>"><?php esc_html_e( 'Pause', 'messageistic' ); ?></a>
                            <?php elseif ( 'paused' === $r['status'] ) : ?>
                                <a href="<?php echo esc_url( $resume ); ?>"><?php esc_html_e( 'Resume', 'messageistic' ); ?></a>
                            <?php endif; ?>
                            <?php if ( ! in_array( $r['status'], [ 'sent', 'cancelled' ], true ) ) : ?>
                                | <a href="<?php echo esc_url( $cancel ); ?>"><?php esc_html_e( 'Cancel', 'messageistic' ); ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
