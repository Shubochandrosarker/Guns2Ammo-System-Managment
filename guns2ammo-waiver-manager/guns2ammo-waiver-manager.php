<?php
/**
 * Plugin Name: Guns2Ammo Waiver Manager
 * Plugin URI: https://wordpressistic.com/
 * Description: RETIRED — superseded by Memberistic's built-in waiver system. This plugin is kept for archival reference only and registers no runtime behaviour; it self-disables after showing a retirement notice. Deactivate and delete it.
 * Version: 1.5.1
 * Author: Wordpressistic
 * Author URI: https://wordpressistic.com/
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'G2A_WAIVER_VERSION', '1.5.1' );

// === RETIRED: superseded by Memberistic's built-in waiver system === //
// Memberistic >= 1.17 covers everything this plugin did — e-sign (member,
// guest, and station-token kiosk modes), minors, generated PDFs, versioned
// waiver text, expiry + renewal reminders — without ApproveMe or any
// third-party membership plugin.
//
// The plugin now HARD-STOPS below: it shows the retirement notice and then
// returns, so none of the legacy integration hooks are ever registered even
// if the plugin is left active on a site. Everything after the `return` is
// dead code kept purely for archival reference — do not re-enable it. The
// legacy membership-checkout redirect and the third-party dependency notice
// were removed outright, since Memberistic owns both flows now.
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p><strong>Guns2Ammo Waiver Manager is retired and inactive.</strong> '
		. 'Waivers now run on Memberistic (Memberistic &rarr; Waivers: e-sign, kiosk stations, minors, PDFs, versioning, renewal reminders). '
		. 'This plugin registers no functionality &mdash; please deactivate and delete it.</p></div>';
} );

// Nothing below this line runs. See the note above.
return;

// === CREATE CUSTOM "kiosk" ROLE ON PLUGIN ACTIVATION === //
register_activation_hook(__FILE__, function () {
    add_role('kiosk', 'Kiosk User', [
        'read' => true,
        'level_0' => true,
    ]);
});

/**
 * The ApproveMe standalone document that kiosk walk-ins sign.
 * Configurable via option/filter so an edited/recreated document doesn't
 * silently kill kiosk user provisioning (the old hardcoded ID did).
 */
function guns2ammo_waiver_kiosk_doc_id() {
    $doc_id = (int) get_option( 'g2a_waiver_kiosk_document_id', 28 );
    return (int) apply_filters( 'g2a_waiver_kiosk_document_id', $doc_id );
}

// === STORE WAIVER SIGN DATE FROM APPROVEME SIGNING EVENTS === //
// The signed-document event is the trustworthy source for ALL documents
// (membership and kiosk). Stamps the WP user matching the signer's email.
add_action('esig_document_signed', 'guns2ammo_stamp_waiver_from_esig', 10, 1);
add_action('esig_signature_saved', 'guns2ammo_stamp_waiver_from_esig', 10, 1);
function guns2ammo_stamp_waiver_from_esig($args) {
    $email = '';
    if (is_array($args)) {
        foreach (['user_email', 'recipient_email', 'signer_email', 'email'] as $key) {
            if (!empty($args[$key]) && is_email($args[$key])) {
                $email = $args[$key];
                break;
            }
        }
        // Some ApproveMe events pass an invitation/signature object instead.
        if ('' === $email && !empty($args['invitation']) && is_array($args['invitation']) && !empty($args['invitation']['user_email'])) {
            $email = $args['invitation']['user_email'];
        }
    } elseif (is_object($args) && !empty($args->user_email)) {
        $email = $args->user_email;
    }

    if ('' === $email || !is_email($email)) {
        // No email in the event payload; fall back to the logged-in signer.
        if (is_user_logged_in()) {
            update_user_meta(get_current_user_id(), 'waiver_signed_date', time());
        }
        return;
    }

    $user_id = email_exists(sanitize_email($email));
    if ($user_id) {
        update_user_meta((int) $user_id, 'waiver_signed_date', time());
    }
}

// === LEGACY THANK-YOU-PAGE STAMP — DISABLED BY DEFAULT (SECURITY) === //
// Stamping on a thank-you page VISIT marked any logged-in user who opened
// the URL as signed, with no evidence a document was ever signed. It is now
// an explicit opt-in fallback: enable only temporarily (option
// g2a_waiver_url_stamp_fallback = 1) if the signing events above turn out
// not to fire for the membership document on production, and investigate.
add_action('template_redirect', function () {
    if ( ! apply_filters( 'g2a_waiver_url_stamp_fallback', (bool) get_option( 'g2a_waiver_url_stamp_fallback', false ) ) ) {
        return;
    }
    if (!is_user_logged_in()) return;

    $user = wp_get_current_user();
    $uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

    $valid_pages = [
        '/waiver-thank-you-members/',
        '/waiver-thank-you-kiosk/'
    ];

    foreach ($valid_pages as $slug) {
        if (strpos($uri, $slug) !== false) {
            update_user_meta($user->ID, 'waiver_signed_date', time());
            error_log('Guns2Ammo Waiver Manager: waiver stamped via legacy URL fallback for user #' . $user->ID . ' — the fallback should be disabled once ApproveMe signing events are confirmed.');
            break;
        }
    }
});

// === CHECK IF USER HAS VALID WAIVER === //
function guns2ammo_has_valid_waiver($user_id) {
    $signed_date = get_user_meta($user_id, 'waiver_signed_date', true);
    if (!$signed_date) return false;
    // Compare on the same clock the stamp was written with (UTC epoch).
    // v1.3 stamped with current_time('timestamp') but compared with time(),
    // drifting by the site's UTC offset; both sides now use time().
    $one_year = 365 * 24 * 60 * 60;
    return (time() - (int) $signed_date) < $one_year;
}

// === ADMIN COLUMN FOR WAIVER STATUS === //
add_filter('manage_users_columns', function($columns) {
    $columns['waiver_status'] = 'Waiver Status';
    return $columns;
});

add_filter('manage_users_custom_column', function($value, $column_name, $user_id) {
    if ($column_name === 'waiver_status') {
        $signed = get_user_meta($user_id, 'waiver_signed_date', true);
        if ($signed) {
            if ( ( time() - (int) $signed ) < 365 * 24 * 60 * 60 ) {
                return '<span style="color:green;"> Signed (Valid)</span>';
            }
            return '<span style="color:orange;"> Expired</span>';
        }
        return '<span style="color:red;"> Not Signed</span>';
    }
    return $value;
}, 10, 3);

// === CREATE WP USER WHEN KIOSK WAIVER IS SIGNED === //
add_action('esig_stand_alone_document_after_invite_fired', 'guns2ammo_create_user_from_kiosk_waiver', 10, 2);
function guns2ammo_create_user_from_kiosk_waiver($doc_id, $invitation) {
    if ((int) $doc_id !== guns2ammo_waiver_kiosk_doc_id()) return;

    $email = sanitize_email($invitation['user_email'] ?? '');
    $name  = sanitize_text_field($invitation['user_fullname'] ?? '');
    if ( ! is_email( $email ) ) {
        return;
    }

    // Returning signer: refresh their waiver date instead of doing nothing
    // (v1.3 silently ignored a repeat kiosk signature).
    $existing_id = email_exists($email);
    if ($existing_id) {
        update_user_meta($existing_id, 'waiver_signed_date', time());
        return;
    }

    $base = sanitize_user(strtolower(str_replace(' ', '_', $name)), true);
    if ('' === $base) {
        $base = sanitize_user(strtolower(strtok($email, '@')), true);
    }
    $username = $base;
    $suffix   = 1;
    while (username_exists($username)) {
        $username = $base . '_' . $suffix;
        $suffix++;
    }

    $random_pass = wp_generate_password();
    $user_id = wp_create_user($username, $random_pass, $email);

    if (is_wp_error($user_id)) {
        error_log('Guns2Ammo Waiver Manager: kiosk user creation failed for ' . $email . ': ' . $user_id->get_error_message());
        return;
    }

    wp_update_user([
        'ID' => $user_id,
        'display_name' => $name,
    ]);

    $user = new WP_User($user_id);
    $user->set_role('kiosk');

    update_user_meta($user_id, 'waiver_signed_date', time());
    update_user_meta($user_id, 'waiver_source', 'kiosk');

    wp_mail($email, "Your Guns2Ammo Waiver Account", "Hi $name,

Thanks for signing our waiver at Guns2Ammo.

Your account has been created.
Username: $username
Password: (you can set via 'Forgot Password')

See you soon!");
}

// Load waiver success popup script only on kiosk waiver page
add_action('wp_enqueue_scripts', function () {
    if (is_page('kiosk-waiver')) { // You can also use ID: is_page(123)
        wp_enqueue_script(
            'guns2ammo-waiver-popup',
            plugin_dir_url(__FILE__) . 'assets/js/waiver-success.js',
            [],
            G2A_WAIVER_VERSION,
            true
        );
    }
});
