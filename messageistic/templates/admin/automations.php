<?php
defined( 'ABSPATH' ) || exit;
/** @var array<int,array<string,mixed>> $rows */
/** @var array<string,string> $triggers */
/** @var string $notice */
?>
<div class="wrap messageistic-wrap">
    <h1 class="messageistic-page-title">
        <?php esc_html_e( 'Automations', 'messageistic' ); ?>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=messageistic-automations&view=new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add new', 'messageistic' ); ?></a>
    </h1>
    <?php if ( $notice ) : ?><div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>
    <div class="messageistic-card">
        <table class="widefat striped">
            <thead><tr>
                <th><?php esc_html_e( 'Name', 'messageistic' ); ?></th>
                <th><?php esc_html_e( 'Trigger', 'messageistic' ); ?></th>
                <th><?php esc_html_e( 'Active', 'messageistic' ); ?></th>
                <th><?php esc_html_e( 'Runs', 'messageistic' ); ?></th>
                <th><?php esc_html_e( 'Last run', 'messageistic' ); ?></th>
                <th></th>
            </tr></thead>
            <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="6"><?php esc_html_e( 'No automations yet.', 'messageistic' ); ?></td></tr>
                <?php else : foreach ( $rows as $r ) :
                    $edit = admin_url( 'admin.php?page=messageistic-automations&view=edit&id=' . (int) $r['id'] );
                    $del  = wp_nonce_url( admin_url( 'admin.php?page=messageistic-automations&action=delete&id=' . (int) $r['id'] ), 'messageistic_delete_automation' );
                    ?>
                    <tr>
                        <td><a href="<?php echo esc_url( $edit ); ?>"><strong><?php echo esc_html( $r['name'] ); ?></strong></a></td>
                        <td><span class="messageistic-pill"><?php echo esc_html( $triggers[ $r['trigger_key'] ] ?? $r['trigger_key'] ); ?></span></td>
                        <td><?php echo $r['is_active'] ? '✓' : '—'; ?></td>
                        <td><?php echo (int) $r['run_count']; ?></td>
                        <td><?php echo esc_html( $r['last_run_at'] ? mysql2date( 'M j, H:i', $r['last_run_at'] ) : '—' ); ?></td>
                        <td><a href="<?php echo esc_url( $del ); ?>" onclick="return confirm('<?php esc_attr_e( 'Delete?', 'messageistic' ); ?>');"><?php esc_html_e( 'Delete', 'messageistic' ); ?></a></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
