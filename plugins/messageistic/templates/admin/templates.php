<?php
defined( 'ABSPATH' ) || exit;
/** @var array<int,array<string,mixed>> $rows */
/** @var string $notice */
?>
<div class="wrap messageistic-wrap">
    <h1 class="messageistic-page-title">
        <?php esc_html_e( 'Templates', 'messageistic' ); ?>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=messageistic-templates&view=new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add new', 'messageistic' ); ?></a>
    </h1>
    <?php if ( $notice ) : ?><div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>
    <div class="messageistic-card">
        <table class="widefat striped">
            <thead><tr>
                <th><?php esc_html_e( 'Name', 'messageistic' ); ?></th>
                <th><?php esc_html_e( 'Category', 'messageistic' ); ?></th>
                <th><?php esc_html_e( 'Classification', 'messageistic' ); ?></th>
                <th><?php esc_html_e( 'Review', 'messageistic' ); ?></th>
                <th><?php esc_html_e( 'Active', 'messageistic' ); ?></th>
                <th><?php esc_html_e( 'Body', 'messageistic' ); ?></th>
                <th></th>
            </tr></thead>
            <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="7"><?php esc_html_e( 'No templates yet.', 'messageistic' ); ?></td></tr>
                <?php else : foreach ( $rows as $r ) :
                    $edit = admin_url( 'admin.php?page=messageistic-templates&view=edit&id=' . (int) $r['id'] );
                    $del  = wp_nonce_url( admin_url( 'admin.php?page=messageistic-templates&action=delete&id=' . (int) $r['id'] ), 'messageistic_delete_template' );
                    ?>
                    <tr>
                        <td><a href="<?php echo esc_url( $edit ); ?>"><strong><?php echo esc_html( $r['name'] ); ?></strong></a></td>
                        <td><span class="messageistic-pill"><?php echo esc_html( $r['category'] ); ?></span></td>
                        <td><?php echo esc_html( $r['classification'] ?? 'transactional' ); ?></td>
                        <td><span class="messageistic-pill"><?php echo esc_html( $r['review_status'] ?? 'pending' ); ?></span></td>
                        <td><?php echo $r['is_active'] ? '✓' : '—'; ?></td>
                        <td><?php echo esc_html( wp_trim_words( $r['body'], 16 ) ); ?></td>
                        <td><a href="<?php echo esc_url( $del ); ?>" onclick="return confirm('<?php esc_attr_e( 'Delete this template?', 'messageistic' ); ?>');"><?php esc_html_e( 'Delete', 'messageistic' ); ?></a></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
