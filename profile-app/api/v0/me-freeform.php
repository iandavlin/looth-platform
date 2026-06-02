<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

/**
 * Freeform titled block CRUD — user-set title + free-text body, multi-instance.
 *
 *   POST   /profile-api/v0/me/freeform                    create a new block;
 *                                                         body: { title?: string }
 *                                                         returns: { ok, key, freeform }
 *   PUT    /profile-api/v0/me/freeform?key=freeform:abc   update title / body / visibility;
 *                                                         body: { title?, body?, visibility? }
 *                                                         null/omitted = keep existing
 *   DELETE /profile-api/v0/me/freeform?key=freeform:abc   delete the row + strip from layout
 *
 * On POST, the new key is APPENDED to the owner's profile_layout (so the block
 * shows up at the end of their page, ready for inline editing). PUT/DELETE
 * leave the layout untouched (delete strips its own entry via Block::deleteFreeform).
 *
 * NOTE TO COORDINATOR (nginx — strangler-profile-app.conf at merge):
 *   1. rewrite "^/profile-api/v0/me/freeform/?$" /profile-api/v0/me-freeform.php last;
 *   2. add `me-freeform` to the auth-gated /me/*\.php allowlist regex.
 */

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Block;

$user   = Auth::requireUser();
$uid    = (int) $user['id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $in    = json_decode(file_get_contents('php://input') ?: '', true);
    $title = is_array($in) && array_key_exists('title', $in) ? (string)$in['title'] : '';
    $key   = Block::createFreeform($uid, $title);
    if ($key === null) {
        profile_app_json(400, ['error' => 'cap_reached', 'max' => Block::FREEFORM_MAX_PER_USER]);
    }
    // Append to layout so the new block appears on the profile immediately.
    $layout   = Block::profileLayout($uid);
    $layout[] = $key;
    Block::saveProfileLayout($uid, $layout);

    profile_app_json(200, [
        'ok'       => true,
        'key'      => $key,
        'freeform' => Block::loadFreeform($uid, $key),
    ]);
}

if ($method === 'PUT' || $method === 'DELETE') {
    $key = (string)($_GET['key'] ?? '');
    if (!Block::isFreeformKey($key)) profile_app_json(400, ['error' => 'invalid_key']);

    if ($method === 'DELETE') {
        $ok = Block::deleteFreeform($uid, $key);
        profile_app_json($ok ? 200 : 404, ['ok' => $ok]);
    }

    $in = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($in)) profile_app_json(400, ['error' => 'invalid_json']);
    $title = array_key_exists('title', $in)      ? (string)$in['title']      : null;
    $body  = array_key_exists('body', $in)       ? (string)$in['body']       : null;
    $vis   = array_key_exists('visibility', $in) ? (string)$in['visibility'] : null;
    $shape = Block::saveFreeform($uid, $key, $title, $body, $vis);
    if ($shape === null) profile_app_json(404, ['error' => 'not_found']);
    profile_app_json(200, ['ok' => true, 'freeform' => $shape]);
}

profile_app_json(405, ['error' => 'method_not_allowed']);
