<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

/**
 * Messages endpoint (thread list + send). ⚠️ SKELETON — returns 501.
 * Plan: social-layer §4. Backend: src/Messaging.php.
 *   GET  → [ { thread_uuid, peers[], last_snippet, unread_count, last_message_at } ]
 *   POST → { thread_id?|to_uuid, body }  → sends (creates thread if none)
 */

use Looth\ProfileApp\Auth;
// use Looth\ProfileApp\Messaging;
// use Looth\ProfileApp\Connections;

$user = Auth::requireUser();
$method = $_SERVER['REQUEST_METHOD'];

// TODO:
//   GET:  profile_app_json(200, Messaging::threadsFor($user['uuid']));
//   POST: $in=json; assert Connections::canMessage for new threads;
//         Messaging::send($user['uuid'], $in['thread_id']??null, $in['to_uuid']??null, $in['body']);
//   else: 405.

profile_app_json(501, ['error' => 'not_implemented', 'endpoint' => 'me-messages', 'method' => $method]);
