<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

/**
 * One thread's messages. ⚠️ SKELETON — returns 501. Plan: social-layer §4.
 *   GET ?id=<thread_id> → { thread, messages[] }  (marks read for the viewer)
 * Asserts the viewer is a recipient. Backend: src/Messaging.php.
 */

use Looth\ProfileApp\Auth;
// use Looth\ProfileApp\Messaging;

$user = Auth::requireUser();

// TODO:
//   $id = (int)($_GET['id'] ?? 0); if (!$id) profile_app_json(400, ['error'=>'id_required']);
//   $t = Messaging::thread($user['uuid'], $id);  // 403 if not a recipient
//   profile_app_json(200, $t);

profile_app_json(501, ['error' => 'not_implemented', 'endpoint' => 'me-thread']);
