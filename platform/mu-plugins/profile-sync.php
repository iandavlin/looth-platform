<?php
/**
 * Plugin Name: Profile-app Sync
 * Description: On user_register, fires non-blocking POST to /profile-api/v0/hooks/user-created.
 *              On profile_update where the email changed, fires /profile-api/v0/hooks/email-changed
 *              so profile-app reconciles the alias while keeping the stored uuid stable
 *              (identity survives an email change — fixes the silent logout-as-stranger bug).
 *              Reads shared secret from wp_options['profile_hook_secret'].
 *              Loopback-only target; sslverify off (self-signed dev cert).
 * Version:     0.2.0
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('profile_sync_dispatch_user_created')) {
function profile_sync_dispatch_user_created(int $user_id): void {
    if ($user_id <= 0) return;
    $u = get_userdata($user_id);
    if (!$u) return;
    $secret = (string) get_option('profile_hook_secret', '');
    if ($secret === '') return; // refuse to send without secret

    $payload = wp_json_encode([
        'wp_user_id'   => $user_id,
        'email'        => $u->user_email,
        'display_name' => $u->display_name,
    ]);

    wp_remote_post('https://127.0.0.1/profile-api/v0/hooks/user-created', [
        'method'    => 'POST',
        'timeout'   => 1,
        'blocking'  => false,
        'sslverify' => false,
        'headers'   => [
            'Host'           => $_SERVER['HTTP_HOST'] ?? 'dev.loothgroup.com',
            'Content-Type'   => 'application/json',
            'X-Hook-Secret'  => $secret,
        ],
        'body' => $payload,
    ]);
}
}

add_action('user_register', function ($user_id) {
    profile_sync_dispatch_user_created((int)$user_id);
}, 99, 1);

// Email change: keep profile-app's identity stable. profile_update passes the
// pre-update WP_User as $old; only dispatch when the email actually changed
// (this hook also fires for unrelated profile edits).
add_action('profile_update', function ($user_id, $old) {
    $user_id = (int) $user_id;
    if ($user_id <= 0) return;
    $u = get_userdata($user_id);
    if (!$u || !is_object($old)) return;

    $new_email = strtolower(trim((string) $u->user_email));
    $old_email = strtolower(trim((string) ($old->user_email ?? '')));
    if ($new_email === '' || $new_email === $old_email) return; // no email change

    $secret = (string) get_option('profile_hook_secret', '');
    if ($secret === '') return;

    wp_remote_post('https://127.0.0.1/profile-api/v0/hooks/email-changed', [
        'method'    => 'POST',
        'timeout'   => 1,
        'blocking'  => false,
        'sslverify' => false,
        'headers'   => [
            'Host'          => $_SERVER['HTTP_HOST'] ?? 'dev.loothgroup.com',
            'Content-Type'  => 'application/json',
            'X-Hook-Secret' => $secret,
        ],
        'body' => wp_json_encode([
            'wp_user_id' => $user_id,
            'email'      => $u->user_email,
        ]),
    ]);
}, 99, 2);
