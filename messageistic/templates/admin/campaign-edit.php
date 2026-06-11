<?php
defined( 'ABSPATH' ) || exit;
/** @var array<string,mixed>|array $campaign */
/** @var array<int,array<string,mixed>> $templates */
$campaign = is_array( $campaign ?? null ) ? $campaign : [];
$id       = (int) ( $campaign['id'] ?? 0 );
$classification = $pilot_mode ? 'transactional' : (string) ( $campaign['classification'] ?? 'marketing' );
$audience = $campaign['audience'] ? json_decode( (string) $campaign['audience'], true ) : [];
?>
<div class="wrap messageistic-wrap">
    <h1 class="messageistic-page-title"><?php echo $id ? esc_html__( 'Edit campaign', 'messageistic' ) : esc_html__( 'New campaign', 'messageistic' ); ?></h1>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=messageistic-campaigns' ) ); ?>" class="messageistic-card">
        <?php wp_nonce_field( 'messageistic_save_campaign' ); ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>">
        <table class="form-table" role="presentation">
            <tr><th><label><?php esc_html_e( 'Location', 'messageistic' ); ?></label></th><td><select name="location_id"><option value="0"><?php esc_html_e( 'All / unassigned', 'messageistic' ); ?></option><?php foreach ( $locations as $location ) : ?><option value="<?php echo (int) $location['id']; ?>" <?php selected( (int) ( $campaign['location_id'] ?? 0 ), (int) $location['id'] ); ?>><?php echo esc_html( $location['name'] ); ?></option><?php endforeach; ?></select></td></tr>
            <tr><th><label><?php esc_html_e( 'Campaign Name', 'messageistic' ); ?></label></th><td><input class="regular-text" required type="text" name="name" value="<?php echo esc_attr( (string) ( $campaign['name'] ?? '' ) ); ?>"></td></tr>
            <tr><th><label><?php esc_html_e( 'Classification', 'messageistic' ); ?></label></th><td><select name="classification" <?php disabled( $pilot_mode ); ?>><option value="marketing" <?php selected( $classification, 'marketing' ); ?>><?php esc_html_e( 'Marketing', 'messageistic' ); ?></option><option value="transactional" <?php selected( $classification, 'transactional' ); ?>><?php esc_html_e( 'Transactional', 'messageistic' ); ?></option><option value="customer_care" <?php selected( $classification, 'customer_care' ); ?>><?php esc_html_e( 'Customer care', 'messageistic' ); ?></option></select><?php if ( $pilot_mode ) : ?><input type="hidden" name="classification" value="transactional"><?php endif; ?></td></tr>
            <tr><th><label><?php esc_html_e( 'Type', 'messageistic' ); ?></label></th><td><input type="text" name="type" value="<?php echo esc_attr( (string) ( $campaign['type'] ?? 'broadcast' ) ); ?>"></td></tr>
            <tr><th><?php esc_html_e( 'Audience', 'messageistic' ); ?></th><td>
                <label><input type="radio" name="audience_kind" value="all" <?php checked( ( $audience['kind'] ?? 'all' ), 'all' ); ?>> <?php esc_html_e( 'All consented contacts', 'messageistic' ); ?></label><br>
                <label><input type="radio" name="audience_kind" value="tag" <?php checked( ( $audience['kind'] ?? '' ), 'tag' ); ?>> <?php esc_html_e( 'Has tag:', 'messageistic' ); ?></label>
                <input type="text" name="audience_tag" value="<?php echo esc_attr( (string) ( $audience['tag'] ?? '' ) ); ?>">
            </td></tr>
            <tr><th><?php esc_html_e( 'Provider Mode', 'messageistic' ); ?></th><td>
                <label><input type="radio" name="provider_mode" value="queue" <?php checked( ( $campaign['provider_mode'] ?? 'queue' ), 'queue' ); ?>> <?php esc_html_e( 'Send via Messageistic Queue', 'messageistic' ); ?></label><br>
                <label><input type="radio" name="provider_mode" value="provider_push" <?php checked( ( $campaign['provider_mode'] ?? '' ), 'provider_push' ); ?>> <?php esc_html_e( 'Push to Provider Campaign (if supported)', 'messageistic' ); ?></label>
            </td></tr>
            <tr><th><label><?php esc_html_e( 'Template', 'messageistic' ); ?></label></th><td>
                <select name="template_id">
                    <option value="0"><?php echo esc_html( $pilot_mode ? __( '— Select approved template —', 'messageistic' ) : __( '— Use custom body —', 'messageistic' ) ); ?></option>
                    <?php foreach ( $templates as $t ) : if ( $pilot_mode && ( 'approved' !== ( $t['review_status'] ?? '' ) || 'transactional' !== ( $t['classification'] ?? '' ) ) ) { continue; } ?>
                        <option value="<?php echo (int) $t['id']; ?>" <?php selected( (int) ( $campaign['template_id'] ?? 0 ), (int) $t['id'] ); ?>><?php echo esc_html( $t['name'] ); ?></option>
                    <?php endforeach; ?>
                </select>
            </td></tr>
            <tr><th><label><?php esc_html_e( 'Body', 'messageistic' ); ?></label></th><td><textarea name="body" rows="5" class="large-text" <?php disabled( $pilot_mode ); ?>><?php echo esc_textarea( (string) ( $campaign['body'] ?? '' ) ); ?></textarea></td></tr>
            <tr><th><label><?php esc_html_e( 'Schedule (Y-m-d H:i:s)', 'messageistic' ); ?></label></th><td><input type="text" name="schedule_at" value="<?php echo esc_attr( (string) ( $campaign['schedule_at'] ?? '' ) ); ?>" placeholder="2026-05-15 14:00:00"></td></tr>
        </table>
        <?php submit_button(); ?>
    </form>
    <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=messageistic-campaigns' ) ); ?>">&larr; <?php esc_html_e( 'Back to campaigns', 'messageistic' ); ?></a></p>
</div>
