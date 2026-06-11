<?php
defined( 'ABSPATH' ) || exit;
/** @var array<string,mixed> $template */
$template = is_array( $template ?? null ) ? $template : [];
$id = (int) ( $template['id'] ?? 0 );
?>
<div class="wrap messageistic-wrap">
    <h1 class="messageistic-page-title"><?php echo $id ? esc_html__( 'Edit template', 'messageistic' ) : esc_html__( 'New template', 'messageistic' ); ?></h1>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=messageistic-templates' ) ); ?>" class="messageistic-card">
        <?php wp_nonce_field( 'messageistic_save_template' ); ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>">
        <table class="form-table" role="presentation">
            <tr><th><label><?php esc_html_e( 'Location', 'messageistic' ); ?></label></th><td><select name="location_id"><option value="0"><?php esc_html_e( 'Global', 'messageistic' ); ?></option><?php foreach ( $locations as $location ) : ?><option value="<?php echo (int) $location['id']; ?>" <?php selected( (int) ( $template['location_id'] ?? 0 ), (int) $location['id'] ); ?>><?php echo esc_html( $location['name'] ); ?></option><?php endforeach; ?></select></td></tr>
            <tr><th><label><?php esc_html_e( 'Name', 'messageistic' ); ?></label></th><td><input class="regular-text" required type="text" name="name" value="<?php echo esc_attr( (string) ( $template['name'] ?? '' ) ); ?>"></td></tr>
            <tr><th><label><?php esc_html_e( 'Classification', 'messageistic' ); ?></label></th><td><select name="classification"><option value="transactional" <?php selected( (string) ( $template['classification'] ?? 'transactional' ), 'transactional' ); ?>><?php esc_html_e( 'Transactional', 'messageistic' ); ?></option><option value="marketing" <?php selected( (string) ( $template['classification'] ?? '' ), 'marketing' ); ?>><?php esc_html_e( 'Marketing', 'messageistic' ); ?></option><option value="customer_care" <?php selected( (string) ( $template['classification'] ?? '' ), 'customer_care' ); ?>><?php esc_html_e( 'Customer care', 'messageistic' ); ?></option><option value="security" <?php selected( (string) ( $template['classification'] ?? '' ), 'security' ); ?>><?php esc_html_e( 'Security', 'messageistic' ); ?></option></select></td></tr>
            <tr><th><label><?php esc_html_e( 'Category', 'messageistic' ); ?></label></th><td>
                <select name="category">
                    <?php foreach ( [ 'general', 'booking', 'membership', 'ffl', 'waiver', 'woocommerce', 'pos', 'review', 'promotion' ] as $c ) : ?>
                        <option value="<?php echo esc_attr( $c ); ?>" <?php selected( $template['category'] ?? 'general', $c ); ?>><?php echo esc_html( ucfirst( $c ) ); ?></option>
                    <?php endforeach; ?>
                </select>
            </td></tr>
            <tr><th><label><?php esc_html_e( 'Body', 'messageistic' ); ?></label></th><td>
                <textarea required name="body" rows="6" class="large-text"><?php echo esc_textarea( (string) ( $template['body'] ?? '' ) ); ?></textarea>
                <p class="description"><?php esc_html_e( 'Variables: {first_name} {last_name} {full_name} {business_name} {booking_date} {booking_time} {membership_name} {membership_expiry} {order_id} {ffl_status} {waiver_link} {review_link} {location} {phone} {unsubscribe_text}', 'messageistic' ); ?></p>
            </td></tr>
            <tr><th><?php esc_html_e( 'Active', 'messageistic' ); ?></th><td><label><input type="checkbox" name="is_active" value="1" <?php checked( ! isset( $template['is_active'] ) || $template['is_active'] ); ?>> <?php esc_html_e( 'Available for use', 'messageistic' ); ?></label></td></tr>
        </table>
        <?php submit_button(); ?>
    </form>
    <?php if ( $id && current_user_can( \Messageistic\Core\Permissions::MANAGE_SETTINGS ) ) : ?>
        <div class="messageistic-card">
            <h3><?php esc_html_e( 'Human Review', 'messageistic' ); ?></h3>
            <p><?php printf( esc_html__( 'Current status: %s. Any body or classification edit resets approval.', 'messageistic' ), esc_html( (string) ( $template['review_status'] ?? 'pending' ) ) ); ?></p>
            <?php if ( ! empty( $template['reviewed_at'] ) ) : ?><p><?php printf( esc_html__( 'Last reviewed at %1$s by user #%2$d.', 'messageistic' ), esc_html( (string) $template['reviewed_at'] ), (int) $template['reviewed_by'] ); ?></p><?php endif; ?>
            <?php if ( ! empty( $template['review_note'] ) ) : ?><p><strong><?php esc_html_e( 'Review note:', 'messageistic' ); ?></strong> <?php echo esc_html( (string) $template['review_note'] ); ?></p><?php endif; ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=messageistic-templates' ) ); ?>">
                <?php wp_nonce_field( 'messageistic_review_template' ); ?>
                <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
                <textarea name="review_note" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'Review note', 'messageistic' ); ?>"></textarea>
                <p><button class="button button-primary" name="action" value="approve"><?php esc_html_e( 'Approve template', 'messageistic' ); ?></button> <button class="button" name="action" value="reject"><?php esc_html_e( 'Reject template', 'messageistic' ); ?></button></p>
            </form>
        </div>
    <?php endif; ?>
    <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=messageistic-templates' ) ); ?>">&larr; <?php esc_html_e( 'Back to templates', 'messageistic' ); ?></a></p>
</div>
