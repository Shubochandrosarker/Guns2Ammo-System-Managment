<?php
defined( 'ABSPATH' ) || exit;
/** @var array<string,array<string,int>> $matrix */
?>
<div class="wrap messageistic-wrap">
    <h1 class="messageistic-page-title"><?php esc_html_e( 'Reports', 'messageistic' ); ?></h1>
    <?php if ( ! empty( $pilot_settings['pilot_mode_enabled'] ) ) : ?>
        <div class="messageistic-card">
            <h3><?php esc_html_e( 'Controlled Pilot — Last 24 Hours', 'messageistic' ); ?></h3>
            <form method="post" style="margin-bottom:12px;"><?php wp_nonce_field( 'messageistic_run_pilot_monitor' ); ?><input type="hidden" name="action" value="run_pilot_monitor"><?php submit_button( __( 'Refresh Pilot Report Now', 'messageistic' ), 'secondary', 'submit', false ); ?></form>
            <?php if ( empty( $pilot_snapshot ) ) : ?>
                <p><?php esc_html_e( 'No pilot monitoring snapshot exists yet. It is generated daily.', 'messageistic' ); ?></p>
            <?php else : ?>
                <table class="widefat striped"><tbody>
                    <tr><th><?php esc_html_e( 'Business / location', 'messageistic' ); ?></th><td><?php echo esc_html( (string) ( $pilot_snapshot['business_name'] ?? '' ) . ' — ' . (string) ( $pilot_snapshot['location'] ?? '' ) ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Provider / sender', 'messageistic' ); ?></th><td><?php echo esc_html( (string) ( $pilot_snapshot['provider'] ?? '' ) . ' — ' . (string) ( $pilot_snapshot['sender'] ?? '' ) ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Generated', 'messageistic' ); ?></th><td><?php echo esc_html( (string) ( $pilot_snapshot['generated_at'] ?? '' ) ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Outbound', 'messageistic' ); ?></th><td><?php echo (int) ( $pilot_snapshot['sent'] ?? 0 ); ?> / <?php echo (int) ( $pilot_settings['pilot_daily_limit'] ?? 100 ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Delivered', 'messageistic' ); ?></th><td><?php echo (int) ( $pilot_snapshot['delivered'] ?? 0 ); ?> (<?php echo esc_html( (string) ( $pilot_snapshot['delivery_rate'] ?? 0 ) ); ?>%)</td></tr>
                    <tr><th><?php esc_html_e( 'Failed', 'messageistic' ); ?></th><td><?php echo (int) ( $pilot_snapshot['failed'] ?? 0 ); ?> (<?php echo esc_html( (string) ( $pilot_snapshot['failure_rate'] ?? 0 ) ); ?>%)</td></tr>
                    <tr><th><?php esc_html_e( 'Queued', 'messageistic' ); ?></th><td><?php echo (int) ( $pilot_snapshot['queued'] ?? 0 ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Awaiting final status', 'messageistic' ); ?></th><td><?php echo (int) ( $pilot_snapshot['awaiting_delivery'] ?? 0 ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Opt-outs', 'messageistic' ); ?></th><td><?php echo (int) ( $pilot_snapshot['optouts'] ?? 0 ); ?> (<?php echo esc_html( (string) ( $pilot_snapshot['optout_rate'] ?? 0 ) ); ?>%)</td></tr>
                    <?php if ( ! empty( $pilot_snapshot['alerts'] ) ) : ?><tr><th><?php esc_html_e( 'Alerts', 'messageistic' ); ?></th><td><ul><?php foreach ( (array) $pilot_snapshot['alerts'] as $alert ) : ?><li><strong><?php echo esc_html( $alert ); ?></strong></li><?php endforeach; ?></ul></td></tr><?php endif; ?>
                    <tr><th><?php esc_html_e( 'Review', 'messageistic' ); ?></th><td><?php echo empty( $pilot_snapshot['requires_review'] ) ? esc_html__( 'Acknowledged', 'messageistic' ) : esc_html__( 'Required', 'messageistic' ); ?></td></tr>
                </tbody></table>
                <?php if ( ! empty( $pilot_snapshot['requires_review'] ) && current_user_can( \Messageistic\Core\Permissions::MANAGE ) ) : ?>
                    <form method="post">
                        <?php wp_nonce_field( 'messageistic_acknowledge_pilot' ); ?>
                        <input type="hidden" name="action" value="acknowledge_pilot">
                        <?php submit_button( __( 'Acknowledge Daily Review', 'messageistic' ), 'primary', 'submit', false ); ?>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <div class="messageistic-card">
        <h3><?php esc_html_e( 'Attributed Conversions', 'messageistic' ); ?></h3>
        <?php if ( empty( $conversion_summary ) ) : ?><p class="messageistic-empty"><?php esc_html_e( 'No conversion events recorded yet.', 'messageistic' ); ?></p><?php else : ?><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Type', 'messageistic' ); ?></th><th><?php esc_html_e( 'Conversions', 'messageistic' ); ?></th><th><?php esc_html_e( 'Attributed value', 'messageistic' ); ?></th></tr></thead><tbody><?php foreach ( $conversion_summary as $row ) : ?><tr><td><?php echo esc_html( $row['conversion_type'] ); ?></td><td><?php echo (int) $row['conversions']; ?></td><td><?php echo esc_html( number_format_i18n( (float) $row['revenue'], 2 ) . ' ' . $row['currency'] ); ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
        <h4><?php esc_html_e( 'By location', 'messageistic' ); ?></h4>
        <?php if ( ! empty( $conversion_by_location ) ) : ?><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Location', 'messageistic' ); ?></th><th><?php esc_html_e( 'Conversions', 'messageistic' ); ?></th><th><?php esc_html_e( 'Value', 'messageistic' ); ?></th></tr></thead><tbody><?php foreach ( $conversion_by_location as $row ) : ?><tr><td><?php echo esc_html( $row['location_name'] ); ?></td><td><?php echo (int) $row['conversions']; ?></td><td><?php echo esc_html( number_format_i18n( (float) $row['revenue'], 2 ) . ' ' . $row['currency'] ); ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
    </div>

    <div class="messageistic-card">
        <h3><?php esc_html_e( 'Messages by provider and status', 'messageistic' ); ?></h3>
        <?php if ( empty( $matrix ) ) : ?>
            <p class="messageistic-empty"><?php esc_html_e( 'No message data yet.', 'messageistic' ); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e( 'Provider', 'messageistic' ); ?></th>
                    <th><?php esc_html_e( 'Queued', 'messageistic' ); ?></th>
                    <th><?php esc_html_e( 'Sent', 'messageistic' ); ?></th>
                    <th><?php esc_html_e( 'Delivered', 'messageistic' ); ?></th>
                    <th><?php esc_html_e( 'Failed', 'messageistic' ); ?></th>
                    <th><?php esc_html_e( 'Received', 'messageistic' ); ?></th>
                    <th><?php esc_html_e( 'Test sent', 'messageistic' ); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ( $matrix as $provider => $by_status ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $provider ); ?></strong></td>
                            <td><?php echo (int) ( $by_status['queued'] ?? 0 ); ?></td>
                            <td><?php echo (int) ( $by_status['sent'] ?? 0 ); ?></td>
                            <td><?php echo (int) ( $by_status['delivered'] ?? 0 ); ?></td>
                            <td><?php echo (int) ( $by_status['failed'] ?? 0 ); ?></td>
                            <td><?php echo (int) ( $by_status['received'] ?? 0 ); ?></td>
                            <td><?php echo (int) ( $by_status['test_sent'] ?? 0 ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
