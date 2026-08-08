<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap messageistic-wrap">
    <h1><?php esc_html_e( 'Workflow Packs', 'messageistic' ); ?></h1>
    <?php if ( $notice ) : ?><div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>
    <div class="messageistic-card">
        <h2><?php esc_html_e( 'Firearm Transaction Workflow Pack', 'messageistic' ); ?></h2>
        <p><?php esc_html_e( 'Creates six conservative transactional templates and inactive journeys for FFL status, transfer arrival, pickup appointments, documentation, training reminders, and waivers. Templates remain pending human review and automations remain inactive.', 'messageistic' ); ?></p>
        <form method="post">
            <?php wp_nonce_field( 'messageistic_install_pack' ); ?><input type="hidden" name="action" value="install_firearm">
            <label><?php esc_html_e( 'Location', 'messageistic' ); ?> <select name="location_id"><option value="0"><?php esc_html_e( 'All locations', 'messageistic' ); ?></option><?php foreach ( $locations as $location ) : ?><option value="<?php echo (int) $location['id']; ?>"><?php echo esc_html( $location['name'] ); ?></option><?php endforeach; ?></select></label>
            <?php submit_button( __( 'Install Draft Pack', 'messageistic' ), 'primary', 'submit', false ); ?>
        </form>
    </div>
</div>
