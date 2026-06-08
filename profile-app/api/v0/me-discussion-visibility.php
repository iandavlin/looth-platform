<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

/**
 * Discussion-author posting visibility — the owner's preference for whether
 * LOGGED-OUT viewers see their real identity on DISCUSSION (forum) posts.
 * Piece #2's persistence half (docs/briefing-discussion-visibility.md).
 *
 *   GET → { discussion_visibility: 'public'|'member' }   (self)
 *   PUT → { discussion_visibility: 'public'|'member' }    sets it; default 'member'.
 *
 * Source of truth lives here; surfaced in /whoami (self) + /users (batch) so the
 * archive-poc person-sync can copy it into forums.person and the Hub mask reads it.
 * Scope = discussions only — CPT author rendering is unaffected.
 */

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Cache;
use Looth\ProfileApp\Db;

const ME_DISCUSSION_VIS_ALLOWED = ['public', 'member'];

$user   = Auth::requireUser();
$method = $_SERVER['REQUEST_METHOD'];
$pg     = Db::pg();

if ($method === 'GET') {
    $st = $pg->prepare('SELECT discussion_visibility FROM users WHERE id = :i');
    $st->execute([':i' => (int)$user['id']]);
    $vis = $st->fetchColumn();
    if ($vis === false || !in_array($vis, ME_DISCUSSION_VIS_ALLOWED, true)) $vis = 'member';
    profile_app_json(200, ['discussion_visibility' => $vis]);
}

if ($method !== 'PUT') profile_app_json(405, ['error' => 'method_not_allowed']);

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in) || !array_key_exists('discussion_visibility', $in)) {
    profile_app_json(400, ['error' => 'discussion_visibility_required']);
}
$vis = $in['discussion_visibility'];
if (!is_string($vis) || !in_array($vis, ME_DISCUSSION_VIS_ALLOWED, true)) {
    profile_app_json(400, ['error' => 'invalid_discussion_visibility', 'allowed' => ME_DISCUSSION_VIS_ALLOWED]);
}

$up = $pg->prepare('UPDATE users SET discussion_visibility = :v, updated_at = now() WHERE id = :i');
$up->execute([':v' => $vis, ':i' => (int)$user['id']]);

// discussion_visibility rides the /whoami self payload — purge so the next read is fresh.
try {
    $b = $pg->prepare('SELECT wp_user_id FROM wp_user_bridge WHERE user_id = :u');
    $b->execute([':u' => (int)$user['id']]);
    $wpId = (int)$b->fetchColumn();
    if ($wpId > 0) Cache::purgeWhoami($wpId);
} catch (Throwable $e) {
    error_log('[me-discussion-visibility] whoami purge failed: ' . $e->getMessage());
}

profile_app_json(200, ['ok' => true, 'discussion_visibility' => $vis]);
