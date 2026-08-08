<?php
/**
 * Contact add/edit + manual SMS panel.
 *
 * @var array<string,mixed>|null $contact
 * @var array<int,array<string,mixed>> $messages
 * @var string $notice
 *
 * @package Messageistic
 */

defined( 'ABSPATH' ) || exit;

$contact = is_array( $contact ?? null ) ? $contact : [];
$id      = (int) ( $contact['id'] ?? 0 );
$action_url = admin_url( 'admin.php?page=messageistic-contacts' );
?>
<div class="wrap messageistic-wrap">
    <h1 class="messageistic-page-title"><?php echo $id ? esc_html__( 'Edit contact', 'messageistic' ) : esc_html__( 'New contact', 'messageistic' ); ?></h1>

    <?php if ( ! empty( $notice ) ) : ?>
        <div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div>
    <?php endif; ?>

    <div class="messageistic-grid messageistic-grid--split">
        <form method="post" action="<?php echo esc_url( $action_url ); ?>" class="messageistic-card">
            <?php wp_nonce_field( 'messageistic_save_contact' ); ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>">
            <table class="form-table" role="presentation">
                <tr><th><label><?php esc_html_e( 'Location', 'messageistic' ); ?></label></th><td><select name="location_id"><option value="0"><?php esc_html_e( '— Unassigned —', 'messageistic' ); ?></option><?php foreach ( $locations as $location ) : ?><option value="<?php echo (int) $location['id']; ?>" <?php selected( (int) ( $contact['location_id'] ?? 0 ), (int) $location['id'] ); ?>><?php echo esc_html( $location['name'] ); ?></option><?php endforeach; ?></select></td></tr>
                <tr><th><label><?php esc_html_e( 'First Name', 'messageistic' ); ?></label></th><td><input class="regular-text" type="text" name="first_name" value="<?php echo esc_attr( (string) ( $contact['first_name'] ?? '' ) ); ?>"></td></tr>
                <tr><th><label><?php esc_html_e( 'Last Name', 'messageistic' ); ?></label></th><td><input class="regular-text" type="text" name="last_name" value="<?php echo esc_attr( (string) ( $contact['last_name'] ?? '' ) ); ?>"></td></tr>
                <tr><th><label><?php esc_html_e( 'Email', 'messageistic' ); ?></label></th><td><input class="regular-text" type="email" name="email" value="<?php echo esc_attr( (string) ( $contact['email'] ?? '' ) ); ?>"></td></tr>
                <tr><th><label><?php esc_html_e( 'Phone', 'messageistic' ); ?></label></th><td><input class="regular-text" type="text" name="phone" value="<?php echo esc_attr( (string) ( $contact['phone'] ?? '' ) ); ?>"></td></tr>
                <tr><th><label><?php esc_html_e( 'Source', 'messageistic' ); ?></label></th><td><input class="regular-text" type="text" name="source" value="<?php echo esc_attr( (string) ( $contact['source'] ?? 'manual' ) ); ?>"></td></tr>
                <tr><th><label><?php esc_html_e( 'Customer Type', 'messageistic' ); ?></label></th><td><input class="regular-text" type="text" name="customer_type" value="<?php echo esc_attr( (string) ( $contact['customer_type'] ?? '' ) ); ?>"></td></tr>
                <tr><th><?php esc_html_e( 'Consent', 'messageistic' ); ?></th><td>
                    <label><input type="checkbox" name="sms_consent" value="1" <?php checked( ! empty( $contact['sms_consent'] ) ); ?>> <?php esc_html_e( 'Has SMS consent', 'messageistic' ); ?></label><br>
                    <label><?php esc_html_e( 'Consent source', 'messageistic' ); ?>: <input type="text" name="consent_source" value="<?php echo esc_attr( (string) ( $contact['consent_source'] ?? '' ) ); ?>"></label><br>
                    <label><?php esc_html_e( 'Consent ledger event', 'messageistic' ); ?>: <select name="consent_event"><option value=""><?php esc_html_e( 'No new event', 'messageistic' ); ?></option><option value="grant_transactional"><?php esc_html_e( 'Grant transactional consent', 'messageistic' ); ?></option><option value="grant_marketing"><?php esc_html_e( 'Grant marketing consent', 'messageistic' ); ?></option><option value="revoke_all"><?php esc_html_e( 'Revoke all SMS consent', 'messageistic' ); ?></option></select></label><br>
                    <label><?php esc_html_e( 'Disclosure version (required to grant)', 'messageistic' ); ?>: <input type="text" name="disclosure_version"></label><br>
                    <label><?php esc_html_e( 'Disclosure text', 'messageistic' ); ?>:<br><textarea name="disclosure_text" rows="3" class="large-text"></textarea></label><br>
                    <label><input type="checkbox" name="opt_out" value="1" <?php checked( ! empty( $contact['opt_out'] ) ); ?>> <?php esc_html_e( 'Opted out', 'messageistic' ); ?></label>
                </td></tr>
                <tr><th><?php esc_html_e( 'Age verification', 'messageistic' ); ?></th><td><label><input type="checkbox" name="age_verified" value="1" <?php checked( ! empty( $contact['age_verified'] ) ); ?>> <?php esc_html_e( 'Age independently verified', 'messageistic' ); ?></label><br><label><?php esc_html_e( 'Date of birth', 'messageistic' ); ?>: <input type="date" name="date_of_birth" value="<?php echo esc_attr( (string) ( $contact['date_of_birth'] ?? '' ) ); ?>"></label></td></tr>
            </table>
            <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'messageistic' ); ?></button></p>
        </form>

        <?php if ( $id ) : ?>
            <div class="messageistic-card">
                <h3><?php esc_html_e( 'Send manual SMS', 'messageistic' ); ?></h3>
                <form method="post" action="<?php echo esc_url( $action_url ); ?>">
                    <?php wp_nonce_field( 'messageistic_manual_send' ); ?>
                    <input type="hidden" name="action" value="send">
                    <input type="hidden" name="contact_id" value="<?php echo esc_attr( (string) $id ); ?>">
                    <p><label><?php esc_html_e( 'Classification', 'messageistic' ); ?> <select name="classification" <?php disabled( $pilot_mode ); ?>><option value="transactional"><?php esc_html_e( 'Transactional', 'messageistic' ); ?></option><?php if ( ! $pilot_mode ) : ?><option value="customer_care"><?php esc_html_e( 'Customer care', 'messageistic' ); ?></option><option value="security"><?php esc_html_e( 'Security', 'messageistic' ); ?></option><option value="marketing"><?php esc_html_e( 'Marketing', 'messageistic' ); ?></option><?php endif; ?></select></label><?php if ( $pilot_mode ) : ?><input type="hidden" name="classification" value="transactional"><?php endif; ?></p>
                    <p><label><?php esc_html_e( 'Approved template', 'messageistic' ); ?> <select name="template_id"><option value="0"><?php esc_html_e( '— Select —', 'messageistic' ); ?></option><?php foreach ( $templates as $item ) : if ( 'approved' !== ( $item['review_status'] ?? '' ) || 'transactional' !== ( $item['classification'] ?? '' ) ) { continue; } ?><option value="<?php echo (int) $item['id']; ?>"><?php echo esc_html( $item['name'] ); ?></option><?php endforeach; ?></select></label></p>
                    <?php if ( $pilot_mode ) : ?><p class="description"><?php esc_html_e( 'Pilot sends are rendered from the selected reviewed template; free-form text is ignored.', 'messageistic' ); ?></p><?php else : ?><textarea name="body" rows="4" class="large-text" placeholder="<?php esc_attr_e( 'Type a message…', 'messageistic' ); ?>"></textarea><?php endif; ?>
                    <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Send', 'messageistic' ); ?></button></p>
                </form>

                <h3 style="margin-top:24px;"><?php esc_html_e( 'Recent messages', 'messageistic' ); ?></h3>
                <?php if ( empty( $messages ) ) : ?>
                    <p class="messageistic-empty"><?php esc_html_e( 'No messages yet.', 'messageistic' ); ?></p>
                <?php else : ?>
                    <ul class="messageistic-activity">
                        <?php foreach ( $messages as $m ) : ?>
                            <li>
                                <span class="messageistic-activity__time"><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' H:i', $m['created_at'] ) ); ?></span>
                                <span class="messageistic-activity__event"><?php echo esc_html( ( 'inbound' === $m['direction'] ? '↓ ' : '↑ ' ) . wp_trim_words( $m['body'], 12 ) ); ?></span>
                                <span class="messageistic-activity__provider"><?php echo esc_html( $m['provider_key'] ?: '—' ); ?></span>
                                <span class="messageistic-activity__level is-info"><?php echo esc_html( $m['status'] ); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=messageistic-contacts' ) ); ?>">&larr; <?php esc_html_e( 'Back to contacts', 'messageistic' ); ?></a></p>
</div>
