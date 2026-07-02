<?php
/**
 * Plugin Name: Guns2Ammo Waiver Manager
 * Plugin URI: https://wordpressistic.com/
 * Description: Guns2Ammo Waiver Manager is a custom-built WordPress plugin that automates the waiver and user management process for the Guns2Ammo site. It integrates tightly with ApproveMe WP E-Signature and Paid Memberships Pro (PMPro) to manage both membership and kiosk walk-in users, ensuring that waiver forms are signed, users are registered, and access is controlled — all hands-free.
 * Version: 1.3
 * Author: Wordpressistic
 * Author URI: https://wordpressistic.com/
 */

// === CREATE CUSTOM "kiosk" ROLE ON PLUGIN ACTIVATION === //
register_activation_hook(__FILE__, function () {
    add_role('kiosk', 'Kiosk User', [
        'read' => true,
        'level_0' => true,
    ]);
});

// === REDIRECT PMPro MEMBERS TO MEMBERSHIP WAIVER === //
function pmpro_redirect_to_waiver_after_checkout($user_id) {
    $user = get_user_by('ID', $user_id);
    if (!$user) return;
    wp_redirect('https://guns2ammo.com/membership-waiver-agreement/');
    exit;
}
add_action('pmpro_after_checkout', 'pmpro_redirect_to_waiver_after_checkout');

// === STORE WAIVER SIGN DATE AFTER THANK YOU PAGE === //
add_action('template_redirect', function () {
    if (!is_user_logged_in()) return;

    $user = wp_get_current_user();
    $uri = $_SERVER['REQUEST_URI'];

    $valid_pages = [
        '/waiver-thank-you-members/',
        '/waiver-thank-you-kiosk/'
    ];

    foreach ($valid_pages as $slug) {
        if (strpos($uri, $slug) !== false) {
            update_user_meta($user->ID, 'waiver_signed_date', current_time('timestamp'));
            break;
        }
    }
});

// === CHECK IF USER HAS VALID WAIVER === //
function guns2ammo_has_valid_waiver($user_id) {
    $signed_date = get_user_meta($user_id, 'waiver_signed_date', true);
    if (!$signed_date) return false;
    $now = current_time('timestamp');
    $one_year = 365 * 24 * 60 * 60;
    return ($now - $signed_date) < $one_year;
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
            $diff = time() - $signed;
            if ($diff < 365 * 24 * 60 * 60) {
                return '<span style="color:green;"> Signed (Valid)</span>';
            } else {
                return '<span style="color:orange;"> Expired</span>';
            }
        } else {
            return '<span style="color:red;"> Not Signed</span>';
        }
    }
    return $value;
}, 10, 3);

// === CREATE WP USER WHEN KIOSK WAIVER IS SIGNED === //
add_action('esig_stand_alone_document_after_invite_fired', 'guns2ammo_create_user_from_kiosk_waiver', 10, 2);
function guns2ammo_create_user_from_kiosk_waiver($doc_id, $invitation) {
    if ((int) $doc_id !== 28) return;

    $email = sanitize_email($invitation['user_email']);
    $name  = sanitize_text_field($invitation['user_fullname']);

    if (email_exists($email)) return;

    $username = sanitize_user(strtolower(str_replace(' ', '_', $name)));
    if (username_exists($username)) {
        $username .= '_' . rand(1000, 9999);
    }

    $random_pass = wp_generate_password();
    $user_id = wp_create_user($username, $random_pass, $email);

    if (!is_wp_error($user_id)) {
        wp_update_user([
            'ID' => $user_id,
            'display_name' => $name,
        ]);

        $user = new WP_User($user_id);
        $user->set_role('kiosk');

        update_user_meta($user_id, 'waiver_signed_date', current_time('timestamp'));
        update_user_meta($user_id, 'waiver_source', 'kiosk');

        wp_mail($email, "Your Guns2Ammo Waiver Account", "Hi $name,

Thanks for signing our waiver at Guns2Ammo.

Your account has been created.
Username: $username
Password: (you can set via 'Forgot Password')

See you soon!");
    }
}

// Load waiver success popup script only on kiosk waiver page
add_action('wp_enqueue_scripts', function () {
    if (is_page('kiosk-waiver')) { // You can also use ID: is_page(123)
        wp_enqueue_script(
            'guns2ammo-waiver-popup',
            plugin_dir_url(__FILE__) . 'assets/js/waiver-success.js',
            [],
            '1.0',
            true
        );
    }
});

