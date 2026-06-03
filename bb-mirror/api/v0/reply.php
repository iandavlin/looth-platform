<?php
/**
 * bb-mirror/api/v0/reply.php — server-side bbPress reply-write endpoint.
 *
 * POST /bb-mirror-api/v0/reply   (runs on the WP FPM pool, cookie-authed)
 *   body (JSON): { topic_id:int, content:string(html), reply_to?:int, media_ids?:int[] }
 *
 * The ONE owned reply-write path for the stack — the /stream/ inline-reply UI and
 * the /hub/ reply forms both wire to this. It reuses BuddyBoss's reply handler
 * in-process via rest_do_request() (so media attach, reply/topic counts, BB
 * notifications, and the bb→pg sync hooks all fire exactly as in the native path),
 * but wraps it in ONE clean contract and explicitly handles the ~10s flood
 * throttle (clean 429 + retry_after) and moderation (202 pending).
 *
 * Auth: the same-origin WordPress login cookie (the browser sends it). bbPress is
 * WP, so writes need the WP user. looth_id-only (JWT, no WP cookie) auth is a
 * future enhancement — would map sub→wp_user_id and wp_set_current_user().
 *
 * Contract: docs/reply-write-endpoint.md
 */

require __DIR__ . '/../../config.php';

if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', false);
$_SERVER['HTTP_HOST']   ??= LG_BB_MIRROR_HOST;
$_SERVER['REQUEST_URI'] ??= '/';
require LG_BB_MIRROR_WP_LOAD;

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function reply_out(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    reply_out(405, ['ok' => false, 'error' => 'method', 'message' => 'POST only.']);
}

$uid = get_current_user_id();
if (!$uid) {
    reply_out(401, ['ok' => false, 'error' => 'auth', 'message' => 'Sign in to reply.']);
}

$body     = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$topic_id = (int) ($body['topic_id'] ?? 0);
$content  = trim((string) ($body['content'] ?? ''));
$reply_to = (int) ($body['reply_to'] ?? 0);
$media    = array_values(array_filter(array_map('intval', (array) ($body['media_ids'] ?? []))));

if ($topic_id <= 0) {
    reply_out(400, ['ok' => false, 'error' => 'invalid', 'message' => 'topic_id is required.']);
}
if ($content === '' && !$media) {
    reply_out(400, ['ok' => false, 'error' => 'invalid', 'message' => "Reply can't be empty."]);
}
if (!function_exists('bbp_get_topic_post_type')) {
    reply_out(500, ['ok' => false, 'error' => 'server', 'message' => 'Forum engine unavailable.']);
}

// Target must be a real, published, open discussion.
$topic = get_post($topic_id);
if (!$topic || $topic->post_type !== bbp_get_topic_post_type() || $topic->post_status !== 'publish') {
    reply_out(404, ['ok' => false, 'error' => 'not_found', 'message' => 'Discussion not found.']);
}
if (bbp_is_topic_closed($topic_id)) {
    reply_out(403, ['ok' => false, 'error' => 'closed', 'message' => 'This discussion is closed.']);
}
$forum_id = (int) bbp_get_topic_forum_id($topic_id);

// ── Flood throttle (~10s per author; keymasters/mods bypass, mirroring bbPress).
//    Pre-check so callers get a clean 429 + retry_after instead of a generic error.
$throttle = (int) get_option('_bbp_throttle_time', 10);
$bypass   = current_user_can('moderate') || current_user_can('keep_gate');
if ($throttle > 0 && !$bypass) {
    $last    = (int) get_user_meta($uid, '_bbp_last_posted', true);
    $elapsed = time() - $last;
    if ($last && $elapsed < $throttle) {
        $retry = max(1, $throttle - $elapsed);
        header('Retry-After: ' . $retry);
        reply_out(429, [
            'ok' => false, 'error' => 'flood', 'retry_after' => $retry,
            'message' => "You're posting too fast — wait {$retry}s and try again.",
        ]);
    }
}

// ── Insert via BuddyBoss REST in-process. Reuses media + counts + notifications
//    + the bb→pg sync hooks; permission_callback re-checks the viewer server-side.
$req = new WP_REST_Request('POST', '/buddyboss/v1/reply');
$req->set_param('topic_id', $topic_id);
$req->set_param('forum_id', $forum_id);
if ($content !== '') $req->set_param('content', $content);
if ($reply_to > 0)   $req->set_param('reply_to', $reply_to);
if ($media)          $req->set_param('bbp_media', $media);

$res = rest_do_request($req);

if ($res->is_error()) {
    $err  = $res->as_error();
    $code = (string) $err->get_error_code();
    $msg  = (string) $err->get_error_message();
    if (str_contains($code, 'flood') || stripos($msg, 'too quickly') !== false || stripos($msg, 'wait') !== false) {
        header('Retry-After: ' . $throttle);
        reply_out(429, ['ok' => false, 'error' => 'flood', 'retry_after' => $throttle, 'message' => $msg]);
    }
    $status = (int) $res->get_status();
    reply_out($status >= 400 ? $status : 400, ['ok' => false, 'error' => $code ?: 'failed', 'message' => $msg ?: 'Reply failed.']);
}

$data     = (array) $res->get_data();
$reply_id = (int) ($data['id'] ?? 0);
if ($reply_id <= 0) {
    reply_out(500, ['ok' => false, 'error' => 'server', 'message' => 'Reply was not created.']);
}

// Belt-and-suspenders throttle bookkeeping for our own pre-check.
update_user_meta($uid, '_bbp_last_posted', time());

$reply = get_post($reply_id);

// Moderation: held replies come back pending/spam.
if ($reply && in_array($reply->post_status, ['pending', 'spam'], true)) {
    reply_out(202, [
        'ok' => true, 'status' => 'pending', 'reply_id' => $reply_id,
        'message' => 'Your reply was submitted and is awaiting moderation.',
    ]);
}

// Published — return everything a surface needs for an optimistic insert.
$u          = wp_get_current_user();
$forum_slug = get_post_field('post_name', $forum_id);
$permalink  = LG_BB_MIRROR_PUBLIC_PATH . '/' . $forum_slug . '/' . $topic->post_name . '/#reply-' . $reply_id;

reply_out(200, [
    'ok'              => true,
    'status'          => 'published',
    'reply_id'        => $reply_id,
    'topic_id'        => $topic_id,
    'parent_reply_id' => $reply_to ?: null,
    'author'          => [
        'wp_user_id'   => (int) $uid,
        'display_name' => (string) $u->display_name,
        'slug'         => $u->user_nicename ?: null,
        'avatar_url'   => get_avatar_url($uid, ['size' => 96]) ?: null,
    ],
    'content_html'    => (string) apply_filters('bbp_get_reply_content', $reply->post_content, $reply_id),
    'created_at'      => mysql2date('c', $reply->post_date_gmt, false),
    'permalink'       => $permalink,
]);
