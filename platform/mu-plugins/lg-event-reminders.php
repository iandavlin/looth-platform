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

add_action('wp_ajax_lg_event_reminder_signup', function () {
    // Same-origin guard (benign idempotent action; this keeps drive-by POSTs out).
    $src  = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($src !== '' && parse_url($src, PHP_URL_HOST) !== $host) {
        wp_send_json(['ok' => false, 'error' => 'bad_origin'], 403);
    }

    $u = wp_get_current_user();
    if (!$u || !$u->exists()) wp_send_json(['ok' => false, 'error' => 'auth'], 401);
    if (!function_exists('FluentCrmApi')) wp_send_json(['ok' => false, 'error' => 'crm_unavailable'], 500);

    try {
        $api     = FluentCrmApi('contacts');
        $already = false;
        $existing = $api->getContact($u->user_email);
        if ($existing) {
            $ids = array_map('intval', $existing->lists->pluck('id')->toArray());
            $already = in_array(LG_EVENT_REMINDER_LIST_ID, $ids, true);
        }
        $api->createOrUpdate([
            'email'      => $u->user_email,
            'first_name' => $u->first_name ?: $u->display_name,
            'status'     => 'subscribed',
            'lists'      => [LG_EVENT_REMINDER_LIST_ID],
        ]);
        wp_send_json(['ok' => true, 'already' => $already]);
    } catch (\Throwable $e) {
        error_log('[lg-event-reminders] ' . $e->getMessage());
        wp_send_json(['ok' => false, 'error' => 'crm_error'], 500);
    }
});
