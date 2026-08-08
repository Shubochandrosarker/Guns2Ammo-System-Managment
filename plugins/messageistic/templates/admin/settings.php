<?php
/**
 * Settings view.
 *
 * @var string $tab
 * @var \Messageistic\Providers\Provider_Manager $providers
 * @var array<string,mixed> $general
 * @var string $active
 * @var array<string,array<string,mixed>> $all_set
 *
 * @package Messageistic
 */

defined( 'ABSPATH' ) || exit;

$tabs = [
    'general'   => __( 'General', 'messageistic' ),
    'provider'  => __( 'Provider', 'messageistic' ),
    'smsgate'   => __( 'Local Gateway App', 'messageistic' ),
    'jasmin'    => __( 'Self-hosted SMS', 'messageistic' ),
    'ottertext' => __( 'OtterText', 'messageistic' ),
    'twilio'    => __( 'Twilio', 'messageistic' ),
    'testing'   => __( 'Testing', 'messageistic' ),
];

$ottertext = $all_set['ottertext'] ?? [];
$twilio    = $all_set['twilio']    ?? [];
$jasmin    = $all_set['jasmin']    ?? [];
$smsgate   = $all_set['smsgate']   ?? [];
$testing   = $all_set['testing']   ?? [];

$get = static function ( array $bag, string $key, $default = '' ) {
    return $bag[ $key ] ?? $default;
};
?>
<div class="wrap messageistic-wrap messageistic-settings">
    <h1 class="messageistic-page-title"><?php esc_html_e( 'Messageistic Settings', 'messageistic' ); ?></h1>

    <nav class="nav-tab-wrapper messageistic-tabs">
        <?php foreach ( $tabs as $slug => $label ) :
            $url   = add_query_arg(
                [ 'page' => 'messageistic-settings', 'tab' => $slug ],
                admin_url( 'admin.php' )
            );
            $class = 'nav-tab' . ( $tab === $slug ? ' nav-tab-active' : '' );
            ?>
            <a class="<?php echo esc_attr( $class ); ?>" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
        <?php endforeach; ?>
    </nav>

    <?php settings_errors(); ?>

    <?php if ( 'general' === $tab ) : ?>
        <form method="post" action="options.php" class="messageistic-card">
            <?php settings_fields( 'messageistic_general' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="mi_business_name"><?php esc_html_e( 'Business Name', 'messageistic' ); ?></label></th>
                    <td><input id="mi_business_name" class="regular-text" type="text" name="messageistic_general_settings[business_name]" value="<?php echo esc_attr( (string) $get( $general, 'business_name' ) ); ?>"></td>
                </tr>
                <tr>
                    <th><label for="mi_business_phone"><?php esc_html_e( 'Business Phone', 'messageistic' ); ?></label></th>
                    <td><input id="mi_business_phone" class="regular-text" type="text" name="messageistic_general_settings[business_phone]" value="<?php echo esc_attr( (string) $get( $general, 'business_phone' ) ); ?>"></td>
                </tr>
                <tr>
                    <th><label for="mi_business_location"><?php esc_html_e( 'Business Location', 'messageistic' ); ?></label></th>
                    <td><input id="mi_business_location" class="regular-text" type="text" name="messageistic_general_settings[business_location]" value="<?php echo esc_attr( (string) $get( $general, 'business_location' ) ); ?>"></td>
                </tr>
                <tr>
                    <th><label for="mi_default_country_code"><?php esc_html_e( 'Default Country Code', 'messageistic' ); ?></label></th>
                    <td><input id="mi_default_country_code" type="text" name="messageistic_general_settings[default_country_code]" value="<?php echo esc_attr( (string) $get( $general, 'default_country_code', '+1' ) ); ?>"></td>
                </tr>
                <tr>
                    <th><label for="mi_default_timezone"><?php esc_html_e( 'Default Timezone', 'messageistic' ); ?></label></th>
                    <td><input id="mi_default_timezone" class="regular-text" type="text" name="messageistic_general_settings[default_timezone]" value="<?php echo esc_attr( (string) $get( $general, 'default_timezone' ) ); ?>"></td>
                </tr>
                <tr>
                    <th><label for="mi_admin_notification_email"><?php esc_html_e( 'Admin Notification Email', 'messageistic' ); ?></label></th>
                    <td><input id="mi_admin_notification_email" class="regular-text" type="email" name="messageistic_general_settings[admin_notification_email]" value="<?php echo esc_attr( (string) $get( $general, 'admin_notification_email' ) ); ?>"></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Quiet Hours', 'messageistic' ); ?></th>
                    <td>
                        <input type="text" name="messageistic_general_settings[quiet_hours_start]" value="<?php echo esc_attr( (string) $get( $general, 'quiet_hours_start', '21:00' ) ); ?>" placeholder="21:00">
                        <?php esc_html_e( 'to', 'messageistic' ); ?>
                        <input type="text" name="messageistic_general_settings[quiet_hours_end]" value="<?php echo esc_attr( (string) $get( $general, 'quiet_hours_end', '08:00' ) ); ?>" placeholder="08:00">
                    </td>
                </tr>
                <tr>
                    <th><label for="mi_daily_sending_limit"><?php esc_html_e( 'Daily Sending Limit', 'messageistic' ); ?></label></th>
                    <td><input id="mi_daily_sending_limit" type="number" min="0" name="messageistic_general_settings[daily_sending_limit]" value="<?php echo esc_attr( (string) $get( $general, 'daily_sending_limit', 1000 ) ); ?>"></td>
                </tr>
                <tr><th><label for="mi_contact_daily_limit"><?php esc_html_e( 'Per-contact Daily Limit', 'messageistic' ); ?></label></th><td><input id="mi_contact_daily_limit" type="number" min="0" name="messageistic_general_settings[contact_daily_limit]" value="<?php echo esc_attr( (string) $get( $general, 'contact_daily_limit', 3 ) ); ?>"></td></tr>
                <tr><th><?php esc_html_e( 'Industry Policy', 'messageistic' ); ?></th><td><select name="messageistic_general_settings[industry]"><option value="general" <?php selected( $get( $general, 'industry', 'general' ), 'general' ); ?>><?php esc_html_e( 'General', 'messageistic' ); ?></option><option value="firearms" <?php selected( $get( $general, 'industry' ), 'firearms' ); ?>><?php esc_html_e( 'Firearms', 'messageistic' ); ?></option></select><p class="description"><?php esc_html_e( 'Firearms mode fails closed unless age, consent, and provider approval evidence exist.', 'messageistic' ); ?></p></td></tr>
                <tr><th><?php esc_html_e( 'Approved Firearm Providers', 'messageistic' ); ?></th><td><input class="regular-text" type="text" name="messageistic_general_settings[approved_firearm_providers]" value="<?php echo esc_attr( (string) $get( $general, 'approved_firearm_providers' ) ); ?>" placeholder="ottertext, jasmin"><p class="description"><?php esc_html_e( 'Comma-separated providers with written approval. Twilio remains blocked.', 'messageistic' ); ?></p></td></tr>
                <tr><th><?php esc_html_e( 'Minimum Verified Age', 'messageistic' ); ?></th><td><input type="number" min="18" max="100" name="messageistic_general_settings[minimum_verified_age]" value="<?php echo esc_attr( (string) $get( $general, 'minimum_verified_age', 21 ) ); ?>"></td></tr>
                <tr><th><?php esc_html_e( 'Firearm Marketing', 'messageistic' ); ?></th><td><label><input type="checkbox" name="messageistic_general_settings[firearm_marketing_enabled]" value="1" <?php checked( ! empty( $general['firearm_marketing_enabled'] ) ); ?>> <?php esc_html_e( 'Enable only after provider and legal approval', 'messageistic' ); ?></label></td></tr>
                <tr><th><?php esc_html_e( 'Controlled Pilot', 'messageistic' ); ?></th><td><label><input type="checkbox" name="messageistic_general_settings[pilot_mode_enabled]" value="1" <?php checked( ! empty( $general['pilot_mode_enabled'] ) ); ?>> <?php esc_html_e( 'Enable strict Phase B pilot controls', 'messageistic' ); ?></label><p class="description"><?php esc_html_e( 'Enforces one U.S. location, one provider, one sender, transactional-only messages, reviewed templates, and 100–500 messages/day.', 'messageistic' ); ?></p></td></tr>
                <tr><th><?php esc_html_e( 'Pilot Location', 'messageistic' ); ?></th><td><input class="regular-text" type="text" name="messageistic_general_settings[pilot_location_name]" value="<?php echo esc_attr( (string) $get( $general, 'pilot_location_name' ) ); ?>" placeholder="Main Store"> <input type="text" maxlength="2" size="4" name="messageistic_general_settings[pilot_location_state]" value="<?php echo esc_attr( (string) $get( $general, 'pilot_location_state' ) ); ?>" placeholder="TX"> <input type="text" readonly size="4" name="messageistic_general_settings[pilot_country]" value="US"></td></tr>
                <tr><th><?php esc_html_e( 'Pilot Provider', 'messageistic' ); ?></th><td><select name="messageistic_general_settings[pilot_provider]"><option value=""><?php esc_html_e( '— Select approved provider —', 'messageistic' ); ?></option><?php foreach ( $providers->all() as $provider_key => $provider ) : if ( 'testing' === $provider_key ) { continue; } ?><option value="<?php echo esc_attr( $provider_key ); ?>" <?php selected( $get( $general, 'pilot_provider' ), $provider_key ); ?>><?php echo esc_html( $provider->get_label() ); ?></option><?php endforeach; ?></select></td></tr>
                <tr><th><?php esc_html_e( 'Pilot Sender', 'messageistic' ); ?></th><td><input class="regular-text" type="text" name="messageistic_general_settings[pilot_sender]" value="<?php echo esc_attr( (string) $get( $general, 'pilot_sender' ) ); ?>" placeholder="+15551234567"><p class="description"><?php esc_html_e( 'Exact approved sender number or sender ID used for every pilot message.', 'messageistic' ); ?></p></td></tr>
                <tr><th><?php esc_html_e( 'Approval Reference', 'messageistic' ); ?></th><td><input class="regular-text" type="text" name="messageistic_general_settings[pilot_approval_reference]" value="<?php echo esc_attr( (string) $get( $general, 'pilot_approval_reference' ) ); ?>" placeholder="Provider ticket / campaign approval ID"><p class="description"><?php esc_html_e( 'Record the written provider approval or registration reference for this business, use case, sender, and location.', 'messageistic' ); ?></p></td></tr>
                <tr><th><?php esc_html_e( 'Pilot Daily Limit', 'messageistic' ); ?></th><td><input type="number" min="100" max="500" name="messageistic_general_settings[pilot_daily_limit]" value="<?php echo esc_attr( (string) $get( $general, 'pilot_daily_limit', 100 ) ); ?>"><p class="description"><?php esc_html_e( 'Strictly limited to 100–500 outbound messages per site-local day.', 'messageistic' ); ?></p></td></tr>
                <tr><th><?php esc_html_e( 'Promotional Campaigns', 'messageistic' ); ?></th><td><label><input type="checkbox" name="messageistic_general_settings[promotional_campaigns_enabled]" value="1" <?php checked( ! empty( $general['promotional_campaigns_enabled'] ) ); ?>> <?php esc_html_e( 'Enable only after both approvals below are recorded', 'messageistic' ); ?></label><p class="description"><?php esc_html_e( 'This gate is fail-closed, is unavailable in pilot mode, and does not replace per-contact marketing consent.', 'messageistic' ); ?></p></td></tr>
                <tr><th><?php esc_html_e( 'Approved Promotional Provider', 'messageistic' ); ?></th><td><select name="messageistic_general_settings[promotional_approved_provider]"><option value=""><?php esc_html_e( '— Select —', 'messageistic' ); ?></option><?php foreach ( $providers->all() as $provider_key => $provider ) : if ( 'testing' === $provider_key ) { continue; } ?><option value="<?php echo esc_attr( $provider_key ); ?>" <?php selected( $get( $general, 'promotional_approved_provider' ), $provider_key ); ?>><?php echo esc_html( $provider->get_label() ); ?></option><?php endforeach; ?></select></td></tr>
                <tr><th><?php esc_html_e( 'Provider Approval Reference', 'messageistic' ); ?></th><td><input class="regular-text" type="text" name="messageistic_general_settings[promotional_provider_approval_reference]" value="<?php echo esc_attr( (string) $get( $general, 'promotional_provider_approval_reference' ) ); ?>" placeholder="Provider ticket / campaign registration ID"></td></tr>
                <tr><th><?php esc_html_e( 'Legal Review Reference', 'messageistic' ); ?></th><td><input class="regular-text" type="text" name="messageistic_general_settings[promotional_legal_review_reference]" value="<?php echo esc_attr( (string) $get( $general, 'promotional_legal_review_reference' ) ); ?>" placeholder="Counsel memo / approval ID"></td></tr>
                <tr><th><?php esc_html_e( 'Approval Date', 'messageistic' ); ?></th><td><input type="date" name="messageistic_general_settings[promotional_approved_at]" value="<?php echo esc_attr( (string) $get( $general, 'promotional_approved_at' ) ); ?>"></td></tr>
                <tr><th><?php esc_html_e( 'Retention (days)', 'messageistic' ); ?></th><td><?php esc_html_e( 'Messages', 'messageistic' ); ?> <input type="number" min="1" name="messageistic_general_settings[message_retention_days]" value="<?php echo esc_attr( (string) $get( $general, 'message_retention_days', 365 ) ); ?>"> <?php esc_html_e( 'Logs', 'messageistic' ); ?> <input type="number" min="1" name="messageistic_general_settings[log_retention_days]" value="<?php echo esc_attr( (string) $get( $general, 'log_retention_days', 90 ) ); ?>"> <?php esc_html_e( 'Consent evidence', 'messageistic' ); ?> <input type="number" min="365" name="messageistic_general_settings[consent_retention_days]" value="<?php echo esc_attr( (string) $get( $general, 'consent_retention_days', 2555 ) ); ?>"></td></tr>
            </table>
            <?php submit_button(); ?>
        </form>

    <?php elseif ( 'provider' === $tab ) : ?>
        <form method="post" action="options.php" class="messageistic-card">
            <?php settings_fields( 'messageistic_provider' ); ?>
            <p><?php esc_html_e( 'Select the active SMS provider. Switching does not delete contacts, templates, campaigns, automations, or logs.', 'messageistic' ); ?></p>
            <fieldset class="messageistic-radio-grid">
                <?php foreach ( $providers->all() as $key => $provider ) : ?>
                    <label class="messageistic-radio-card <?php echo $active === $key ? 'is-selected' : ''; ?>">
                        <input type="radio" name="messageistic_active_provider" value="<?php echo esc_attr( $key ); ?>" <?php checked( $active, $key ); ?>>
                        <strong><?php echo esc_html( $provider->get_label() ); ?></strong>
                        <span><?php echo esc_html( $key ); ?></span>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <?php submit_button( __( 'Set Active Provider', 'messageistic' ) ); ?>
        </form>

    <?php elseif ( 'ottertext' === $tab ) : ?>
        <form method="post" action="options.php" class="messageistic-card">
            <?php settings_fields( 'messageistic_provider' ); ?>
            <input type="hidden" name="messageistic_active_provider" value="<?php echo esc_attr( $active ); ?>">
            <table class="form-table" role="presentation">
                <tr><th><label><?php esc_html_e( 'API Base URL', 'messageistic' ); ?></label></th><td><input class="regular-text" type="url" name="messageistic_provider_settings[ottertext][api_base_url]" value="<?php echo esc_attr( (string) $get( $ottertext, 'api_base_url' ) ); ?>" placeholder="https://api.ottertext.example/v1"></td></tr>
                <tr><th><label><?php esc_html_e( 'API Key', 'messageistic' ); ?></label></th><td><input class="regular-text" type="password" autocomplete="new-password" name="messageistic_provider_settings[ottertext][api_key]" value="" placeholder="<?php echo esc_attr( ! empty( $ottertext['api_key'] ) ? __( 'Stored — leave blank to keep', 'messageistic' ) : '' ); ?>"></td></tr>
                <tr><th><label><?php esc_html_e( 'Account ID / Business ID', 'messageistic' ); ?></label></th><td><input class="regular-text" type="text" name="messageistic_provider_settings[ottertext][account_id]" value="<?php echo esc_attr( (string) $get( $ottertext, 'account_id' ) ); ?>"></td></tr>
                <tr><th><label><?php esc_html_e( 'Location ID / Store ID', 'messageistic' ); ?></label></th><td><input class="regular-text" type="text" name="messageistic_provider_settings[ottertext][location_id]" value="<?php echo esc_attr( (string) $get( $ottertext, 'location_id' ) ); ?>"></td></tr>
                <tr><th><label><?php esc_html_e( 'Default List ID', 'messageistic' ); ?></label></th><td><input class="regular-text" type="text" name="messageistic_provider_settings[ottertext][default_list_id]" value="<?php echo esc_attr( (string) $get( $ottertext, 'default_list_id' ) ); ?>"></td></tr>
                <tr><th><label><?php esc_html_e( 'Default Campaign ID', 'messageistic' ); ?></label></th><td><input class="regular-text" type="text" name="messageistic_provider_settings[ottertext][default_campaign_id]" value="<?php echo esc_attr( (string) $get( $ottertext, 'default_campaign_id' ) ); ?>"></td></tr>
                <tr><th><label><?php esc_html_e( 'Webhook Secret', 'messageistic' ); ?></label></th><td><input class="regular-text" type="password" autocomplete="new-password" name="messageistic_provider_settings[ottertext][webhook_secret]" value="" placeholder="<?php echo esc_attr( ! empty( $ottertext['webhook_secret'] ) ? __( 'Stored — leave blank to keep', 'messageistic' ) : '' ); ?>"><p class="description"><?php esc_html_e( 'Required to authenticate inbound and status webhooks.', 'messageistic' ); ?></p></td></tr>
                <tr><th><?php esc_html_e( 'Feature Toggles', 'messageistic' ); ?></th><td>
                    <label><input type="checkbox" name="messageistic_provider_settings[ottertext][enable_contact_sync]" value="1" <?php checked( ! empty( $ottertext['enable_contact_sync'] ) ); ?>> <?php esc_html_e( 'Enable Contact Sync', 'messageistic' ); ?></label><br>
                    <label><input type="checkbox" name="messageistic_provider_settings[ottertext][enable_message_sending]" value="1" <?php checked( ! empty( $ottertext['enable_message_sending'] ) ); ?>> <?php esc_html_e( 'Enable Message Sending', 'messageistic' ); ?></label><br>
                    <label><input type="checkbox" name="messageistic_provider_settings[ottertext][enable_inbound_sync]" value="1" <?php checked( ! empty( $ottertext['enable_inbound_sync'] ) ); ?>> <?php esc_html_e( 'Enable Inbound Sync', 'messageistic' ); ?></label><br>
                    <label><input type="checkbox" name="messageistic_provider_settings[ottertext][enable_campaign_push]" value="1" <?php checked( ! empty( $ottertext['enable_campaign_push'] ) ); ?>> <?php esc_html_e( 'Enable Campaign Push', 'messageistic' ); ?></label>
                </td></tr>
            </table>
            <p><button type="button" class="button messageistic-test-connection" data-provider="ottertext"><?php esc_html_e( 'Test Connection', 'messageistic' ); ?></button> <span class="messageistic-test-result" data-for="ottertext"></span></p>
            <?php submit_button(); ?>
        </form>

    <?php elseif ( 'twilio' === $tab ) : ?>
        <form method="post" action="options.php" class="messageistic-card">
            <?php settings_fields( 'messageistic_provider' ); ?>
            <input type="hidden" name="messageistic_active_provider" value="<?php echo esc_attr( $active ); ?>">
            <table class="form-table" role="presentation">
                <tr><th><label><?php esc_html_e( 'Account SID', 'messageistic' ); ?></label></th><td><input class="regular-text" type="text" name="messageistic_provider_settings[twilio][account_sid]" value="<?php echo esc_attr( (string) $get( $twilio, 'account_sid' ) ); ?>"></td></tr>
                <tr><th><label><?php esc_html_e( 'Auth Token', 'messageistic' ); ?></label></th><td><input class="regular-text" type="password" autocomplete="new-password" name="messageistic_provider_settings[twilio][auth_token]" value="" placeholder="<?php echo esc_attr( ! empty( $twilio['auth_token'] ) ? __( 'Stored — leave blank to keep', 'messageistic' ) : '' ); ?>"></td></tr>
                <tr><th><label><?php esc_html_e( 'Messaging Service SID', 'messageistic' ); ?></label></th><td><input class="regular-text" type="text" name="messageistic_provider_settings[twilio][messaging_service_sid]" value="<?php echo esc_attr( (string) $get( $twilio, 'messaging_service_sid' ) ); ?>"></td></tr>
                <tr><th><label><?php esc_html_e( 'From Number', 'messageistic' ); ?></label></th><td><input class="regular-text" type="text" name="messageistic_provider_settings[twilio][from_number]" value="<?php echo esc_attr( (string) $get( $twilio, 'from_number' ) ); ?>" placeholder="+15551234567"></td></tr>
                <tr><th><?php esc_html_e( 'Inbound Webhook URL', 'messageistic' ); ?></th><td><code><?php echo esc_html( rest_url( 'messageistic/v1/webhooks/twilio/inbound' ) ); ?></code></td></tr>
                <tr><th><?php esc_html_e( 'Delivery Status Webhook URL', 'messageistic' ); ?></th><td><code><?php echo esc_html( rest_url( 'messageistic/v1/webhooks/twilio/status' ) ); ?></code></td></tr>
                <tr><th><?php esc_html_e( 'Signature Validation', 'messageistic' ); ?></th><td><input type="hidden" name="messageistic_provider_settings[twilio][validate_signature]" value="1"><strong><?php esc_html_e( 'Required', 'messageistic' ); ?></strong><p class="description"><?php esc_html_e( 'Twilio webhook requests are always authenticated with the configured Auth Token.', 'messageistic' ); ?></p></td></tr>
            </table>
            <p><button type="button" class="button messageistic-test-connection" data-provider="twilio"><?php esc_html_e( 'Test Connection', 'messageistic' ); ?></button> <span class="messageistic-test-result" data-for="twilio"></span></p>
            <?php submit_button(); ?>
        </form>

    <?php elseif ( 'smsgate' === $tab ) : ?>
        <form method="post" action="options.php" class="messageistic-card">
            <?php settings_fields( 'messageistic_provider' ); ?>
            <input type="hidden" name="messageistic_active_provider" value="<?php echo esc_attr( $active ); ?>">
            <p class="description"><?php
                printf(
                    /* translators: %s: link to the SMS Gateway for Android app. */
                    esc_html__( 'Turn an Android phone with a SIM card into your SMS gateway using the open-source %s app. Local Server mode keeps every message on hardware you own; Cloud Server mode relays jobs through api.sms-gate.app when WordPress cannot reach the phone directly.', 'messageistic' ),
                    '<a href="https://sms-gate.app" target="_blank" rel="noopener">SMS Gateway for Android™</a>'
                );
            ?></p>
            <table class="form-table" role="presentation">
                <tr>
                    <th><label><?php esc_html_e( 'Gateway Base URL', 'messageistic' ); ?></label></th>
                    <td>
                        <input class="regular-text" type="url" name="messageistic_provider_settings[smsgate][base_url]" value="<?php echo esc_attr( (string) $get( $smsgate, 'base_url' ) ); ?>" placeholder="http://192.168.1.50:8080">
                        <p class="description"><?php esc_html_e( 'Local Server mode: http://<phone-ip>:8080 (shown inside the app). Cloud Server mode: https://api.sms-gate.app/3rdparty/v1.', 'messageistic' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e( 'Username', 'messageistic' ); ?></label></th>
                    <td><input class="regular-text" type="text" name="messageistic_provider_settings[smsgate][username]" value="<?php echo esc_attr( (string) $get( $smsgate, 'username' ) ); ?>"><p class="description"><?php esc_html_e( 'Copy from the app\'s Local/Cloud Server screen.', 'messageistic' ); ?></p></td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e( 'Password', 'messageistic' ); ?></label></th>
                    <td><input class="regular-text" type="password" autocomplete="new-password" name="messageistic_provider_settings[smsgate][password]" value="" placeholder="<?php echo esc_attr( ! empty( $smsgate['password'] ) ? __( 'Stored — leave blank to keep', 'messageistic' ) : '' ); ?>"></td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e( 'SIM Slot', 'messageistic' ); ?></label></th>
                    <td>
                        <?php $sim = (int) $get( $smsgate, 'sim_number', 0 ); ?>
                        <select name="messageistic_provider_settings[smsgate][sim_number]">
                            <option value="0" <?php selected( $sim, 0 ); ?>><?php esc_html_e( 'Device default', 'messageistic' ); ?></option>
                            <option value="1" <?php selected( $sim, 1 ); ?>><?php esc_html_e( 'SIM 1', 'messageistic' ); ?></option>
                            <option value="2" <?php selected( $sim, 2 ); ?>><?php esc_html_e( 'SIM 2', 'messageistic' ); ?></option>
                            <option value="3" <?php selected( $sim, 3 ); ?>><?php esc_html_e( 'SIM 3', 'messageistic' ); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Delivery Reports', 'messageistic' ); ?></th>
                    <td><label><input type="checkbox" name="messageistic_provider_settings[smsgate][with_delivery_report]" value="1" <?php checked( ! empty( $smsgate['with_delivery_report'] ) ); ?>> <?php esc_html_e( 'Request a carrier delivery report for every message (recommended).', 'messageistic' ); ?></label></td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e( 'Webhook Secret', 'messageistic' ); ?></label></th>
                    <td>
                        <input class="regular-text" type="password" autocomplete="new-password" name="messageistic_provider_settings[smsgate][webhook_secret]" value="" placeholder="<?php echo esc_attr( ! empty( $smsgate['webhook_secret'] ) ? __( 'Stored — leave blank to keep', 'messageistic' ) : '' ); ?>">
                        <p class="description"><?php esc_html_e( 'Appended to the webhook URLs as ?token=… and compared with hash_equals on every callback.', 'messageistic' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e( 'Signing Key (optional)', 'messageistic' ); ?></label></th>
                    <td>
                        <input class="regular-text" type="password" autocomplete="new-password" name="messageistic_provider_settings[smsgate][signing_key]" value="" placeholder="<?php echo esc_attr( ! empty( $smsgate['signing_key'] ) ? __( 'Stored — leave blank to keep', 'messageistic' ) : '' ); ?>">
                        <p class="description"><?php esc_html_e( 'Must match the webhook signing key configured inside the app. Verifies the HMAC-SHA256 X-Signature header (with a 5-minute replay window).', 'messageistic' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Inbound Webhook URL', 'messageistic' ); ?></th>
                    <td>
                        <?php
                        $smsgate_inbound = rest_url( 'messageistic/v1/webhooks/smsgate/inbound' );
                        if ( ! empty( $smsgate['webhook_secret'] ) ) {
                            $smsgate_inbound = add_query_arg( 'token', $smsgate['webhook_secret'], $smsgate_inbound );
                        }
                        ?>
                        <code><?php echo esc_html( $smsgate_inbound ); ?></code>
                        <p class="description"><?php esc_html_e( 'Receives sms:received events. STOP / START keywords route through the opt-out table automatically.', 'messageistic' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Delivery Status Webhook URL', 'messageistic' ); ?></th>
                    <td>
                        <?php
                        $smsgate_status = rest_url( 'messageistic/v1/webhooks/smsgate/status' );
                        if ( ! empty( $smsgate['webhook_secret'] ) ) {
                            $smsgate_status = add_query_arg( 'token', $smsgate['webhook_secret'], $smsgate_status );
                        }
                        ?>
                        <code><?php echo esc_html( $smsgate_status ); ?></code>
                        <p class="description"><?php esc_html_e( 'Receives sms:sent, sms:delivered, and sms:failed events.', 'messageistic' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Webhook Validation', 'messageistic' ); ?></th>
                    <td>
                        <label><input type="checkbox" name="messageistic_provider_settings[smsgate][validate_signature]" value="1" <?php checked( ! empty( $smsgate['validate_signature'] ) ); ?>> <?php esc_html_e( 'Require the Webhook Secret and/or Signing Key on every callback.', 'messageistic' ); ?></label>
                        <p class="description"><?php esc_html_e( 'Leave on in production. Off only for local development.', 'messageistic' ); ?></p>
                    </td>
                </tr>
            </table>
            <p>
                <button type="button" class="button messageistic-test-connection" data-provider="smsgate"><?php esc_html_e( 'Test Connection', 'messageistic' ); ?></button>
                <button type="button" class="button messageistic-register-webhooks" data-provider="smsgate"><?php esc_html_e( 'Register Webhooks on Device', 'messageistic' ); ?></button>
                <span class="messageistic-test-result" data-for="smsgate"></span>
            </p>
            <p class="description"><?php esc_html_e( 'Save the settings first, then register the webhooks — the plugin pushes all four event subscriptions (received, sent, delivered, failed) onto the device in one click. The phone must be able to reach this site\'s URL.', 'messageistic' ); ?></p>
            <?php submit_button(); ?>
        </form>

    <?php elseif ( 'jasmin' === $tab ) : ?>
        <form method="post" action="options.php" class="messageistic-card">
            <?php settings_fields( 'messageistic_provider' ); ?>
            <input type="hidden" name="messageistic_active_provider" value="<?php echo esc_attr( $active ); ?>">
            <p class="description"><?php
                printf(
                    /* translators: %s: link to Jasmin SMS Gateway docs. */
                    esc_html__( 'Point this at your own %s — open-source SMPP middleware you host yourself. Jasmin connects to any carrier or aggregator over SMPP; Messageistic submits messages over its HTTP API. No SMS traffic crosses a third-party SaaS.', 'messageistic' ),
                    '<a href="https://jasminsms.com" target="_blank" rel="noopener">Jasmin SMS Gateway</a>'
                );
            ?></p>
            <table class="form-table" role="presentation">
                <tr>
                    <th><label><?php esc_html_e( 'Gateway Base URL', 'messageistic' ); ?></label></th>
                    <td>
                        <input class="regular-text" type="url" name="messageistic_provider_settings[jasmin][base_url]" value="<?php echo esc_attr( (string) $get( $jasmin, 'base_url' ) ); ?>" placeholder="https://sms.example.com">
                        <p class="description"><?php esc_html_e( 'Base URL of the Jasmin httpapi. The plugin appends /send and /ping itself.', 'messageistic' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e( 'HTTP API Username', 'messageistic' ); ?></label></th>
                    <td><input class="regular-text" type="text" name="messageistic_provider_settings[jasmin][username]" value="<?php echo esc_attr( (string) $get( $jasmin, 'username' ) ); ?>"></td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e( 'HTTP API Password', 'messageistic' ); ?></label></th>
                    <td><input class="regular-text" type="password" autocomplete="new-password" name="messageistic_provider_settings[jasmin][password]" value="" placeholder="<?php echo esc_attr( ! empty( $jasmin['password'] ) ? __( 'Stored — leave blank to keep', 'messageistic' ) : '' ); ?>"></td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e( 'Default Sender', 'messageistic' ); ?></label></th>
                    <td>
                        <input class="regular-text" type="text" name="messageistic_provider_settings[jasmin][default_sender]" value="<?php echo esc_attr( (string) $get( $jasmin, 'default_sender' ) ); ?>" placeholder="+15551234567">
                        <p class="description"><?php esc_html_e( 'Number (E.164) or alphanumeric sender ID — depending on what your carrier allows in your country.', 'messageistic' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e( 'Character Coding', 'messageistic' ); ?></label></th>
                    <td>
                        <?php $coding = (string) $get( $jasmin, 'coding', '0' ); ?>
                        <select name="messageistic_provider_settings[jasmin][coding]">
                            <option value="0" <?php selected( $coding, '0' ); ?>><?php esc_html_e( 'GSM-7 (default, 160 chars / segment)', 'messageistic' ); ?></option>
                            <option value="8" <?php selected( $coding, '8' ); ?>><?php esc_html_e( 'UCS-2 / Unicode (70 chars / segment)', 'messageistic' ); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e( 'Use Unicode if you send non-Latin characters or emoji; segments shrink to 70 chars.', 'messageistic' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e( 'Webhook Secret', 'messageistic' ); ?></label></th>
                    <td>
                        <input class="regular-text" type="password" autocomplete="new-password" name="messageistic_provider_settings[jasmin][webhook_secret]" value="" placeholder="<?php echo esc_attr( ! empty( $jasmin['webhook_secret'] ) ? __( 'Stored — leave blank to keep', 'messageistic' ) : '' ); ?>">
                        <p class="description"><?php esc_html_e( 'Bound to the DLR / MO webhook URLs as ?token=… and validated with hash_equals on every callback.', 'messageistic' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Inbound (MO) Webhook URL', 'messageistic' ); ?></th>
                    <td>
                        <?php
                        $jasmin_inbound = rest_url( 'messageistic/v1/webhooks/jasmin/inbound' );
                        if ( ! empty( $jasmin['webhook_secret'] ) ) {
                            $jasmin_inbound = add_query_arg( 'token', $jasmin['webhook_secret'], $jasmin_inbound );
                        }
                        ?>
                        <code><?php echo esc_html( $jasmin_inbound ); ?></code>
                        <p class="description"><?php esc_html_e( 'Wire this into Jasmin\'s MO http connector. STOP / START keywords route through the opt-out table automatically.', 'messageistic' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Delivery Status (DLR) Webhook URL', 'messageistic' ); ?></th>
                    <td>
                        <?php
                        $jasmin_status = rest_url( 'messageistic/v1/webhooks/jasmin/status' );
                        if ( ! empty( $jasmin['webhook_secret'] ) ) {
                            $jasmin_status = add_query_arg( 'token', $jasmin['webhook_secret'], $jasmin_status );
                        }
                        ?>
                        <code><?php echo esc_html( $jasmin_status ); ?></code>
                        <p class="description"><?php esc_html_e( 'Bound per-message — the plugin sends this on every /send call so per-message DLR routes back here.', 'messageistic' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Webhook Signature Validation', 'messageistic' ); ?></th>
                    <td>
                        <label><input type="checkbox" name="messageistic_provider_settings[jasmin][validate_signature]" value="1" <?php checked( ! empty( $jasmin['validate_signature'] ) ); ?>> <?php esc_html_e( 'Require the Webhook Secret on every DLR / MO callback.', 'messageistic' ); ?></label>
                        <p class="description"><?php esc_html_e( 'Leave on in production. Off only for local development.', 'messageistic' ); ?></p>
                    </td>
                </tr>
            </table>
            <p><button type="button" class="button messageistic-test-connection" data-provider="jasmin"><?php esc_html_e( 'Test Connection', 'messageistic' ); ?></button> <span class="messageistic-test-result" data-for="jasmin"></span></p>
            <?php submit_button(); ?>
        </form>

    <?php elseif ( 'testing' === $tab ) : ?>
        <form method="post" action="options.php" class="messageistic-card">
            <?php settings_fields( 'messageistic_provider' ); ?>
            <input type="hidden" name="messageistic_active_provider" value="<?php echo esc_attr( $active ); ?>">
            <table class="form-table" role="presentation">
                <tr><th><?php esc_html_e( 'Fake Delivery Status', 'messageistic' ); ?></th><td><label><input type="checkbox" name="messageistic_provider_settings[testing][fake_delivery_status]" value="1" <?php checked( ! empty( $testing['fake_delivery_status'] ) ); ?>> <?php esc_html_e( 'Mark simulated messages as delivered', 'messageistic' ); ?></label></td></tr>
                <tr><th><label><?php esc_html_e( 'Fake Delivery Delay (seconds)', 'messageistic' ); ?></label></th><td><input type="number" min="0" name="messageistic_provider_settings[testing][fake_delivery_delay]" value="<?php echo esc_attr( (string) $get( $testing, 'fake_delivery_delay', 0 ) ); ?>"></td></tr>
                <tr><th><?php esc_html_e( 'Fake Reply Simulation', 'messageistic' ); ?></th><td><label><input type="checkbox" name="messageistic_provider_settings[testing][fake_reply_simulation]" value="1" <?php checked( ! empty( $testing['fake_reply_simulation'] ) ); ?>> <?php esc_html_e( 'Generate occasional simulated replies', 'messageistic' ); ?></label></td></tr>
                <tr><th><?php esc_html_e( 'Testing Banner', 'messageistic' ); ?></th><td><label><input type="checkbox" name="messageistic_provider_settings[testing][show_testing_banner]" value="1" <?php checked( ! empty( $testing['show_testing_banner'] ) ); ?>> <?php esc_html_e( 'Show banner on the dashboard when Testing Mode is active', 'messageistic' ); ?></label></td></tr>
            </table>
            <?php submit_button(); ?>
        </form>
    <?php endif; ?>
</div>
