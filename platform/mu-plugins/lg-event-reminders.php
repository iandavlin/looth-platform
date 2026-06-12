<?php
/**
 * Plugin Name: LG Event Reminders — one-click signup
 * Description: Adds the LOGGED-IN member to the FluentCRM "Event Reminder
 * Email List" in one click (front-page bento button, Ian 6/12). admin-ajax
 * (cookie auth — works from the standalone pages where REST nonces aren't
 * available), same-origin checked, idempotent: re-clicks just re-confirm.
 */

if (!defined('ABSPATH')) exit;

const LG_EVENT_REMINDER_LIST_ID = 4;   // wp_fc_lists: "Event Reminder Email List"

/** Shared guards → current user or JSON-error exit. */
function lg_evr_user(): WP_User {
    $src  = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($src !== '' && parse_url($src, PHP_URL_HOST) !== $host) {
        wp_send_json(['ok' => false, 'error' => 'bad_origin'], 403);
    }
    $u = wp_get_current_user();
    if (!$u || !$u->exists()) wp_send_json(['ok' => false, 'error' => 'auth'], 401);
    if (!function_exists('FluentCrmApi')) wp_send_json(['ok' => false, 'error' => 'crm_unavailable'], 500);
    return $u;
}

function lg_evr_is_on(string $email): bool {
    $c = FluentCrmApi('contacts')->getContact($email);
    if (!$c) return false;
    $ids = array_map('intval', $c->lists->pluck('id')->toArray());
    return in_array(LG_EVENT_REMINDER_LIST_ID, $ids, true);
}

/** GET state — the button renders its real CRM state on page load. */
add_action('wp_ajax_lg_event_reminder_state', function () {
    $u = lg_evr_user();
    try { wp_send_json(['ok' => true, 'on' => lg_evr_is_on($u->user_email)]); }
    catch (\Throwable $e) { wp_send_json(['ok' => false, 'error' => 'crm_error'], 500); }
});

/** TOGGLE — on adds to the list, off detaches from it (Ian 6/12: both ways). */
add_action('wp_ajax_lg_event_reminder_signup', function () {
    $u    = lg_evr_user();
    $want = (string)($_POST['on'] ?? '1') === '1';
    try {
        $api = FluentCrmApi('contacts');
        if ($want) {
            $api->createOrUpdate([
                'email'      => $u->user_email,
                'first_name' => $u->first_name ?: $u->display_name,
                'status'     => 'subscribed',
                'lists'      => [LG_EVENT_REMINDER_LIST_ID],
            ]);
        } else {
            $c = $api->getContact($u->user_email);
            if ($c) $c->detachLists([LG_EVENT_REMINDER_LIST_ID]);
        }
        wp_send_json(['ok' => true, 'on' => lg_evr_is_on($u->user_email)]);
    } catch (\Throwable $e) {
        error_log('[lg-event-reminders] ' . $e->getMessage());
        wp_send_json(['ok' => false, 'error' => 'crm_error'], 500);
    }
});
