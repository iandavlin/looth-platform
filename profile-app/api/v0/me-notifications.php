<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

/**
 * Notifications endpoint (bell feed + mark-read). ⚠️ SKELETON — returns 501.
 * Plan: social-layer §4. Backend: src/Notifications.php. lg-shell's bell + modal
 * call this; profile-app owns the data. Identity via Auth::requireUser() (/whoami).
 *   GET  → { items: [ { id, type, actor{uuid,name,avatar_url,slug}, ref{kind,id},
 *                       is_read, created_at } ], unread: int }   (recent-first)
 *   POST → { action: 'read', id }  | { action: 'read_all' }      → marks read
 * Counts for the header badge come from me-social-counts (display caps at "9+").
 * Retention: 30-day prune is a cron (bin/prune-notifications), NOT this endpoint.
 */

use Looth\ProfileApp\Auth;
// use Looth\ProfileApp\Notifications;

$user = Auth::requireUser();
$method = $_SERVER['REQUEST_METHOD'];

// TODO:
//   GET:  profile_app_json(200, [
//             'items'  => Notifications::listFor($user['uuid']),
//             'unread' => Notifications::unreadCount($user['uuid']),
//         ]);
//   POST: $in = json; switch ($in['action']):
//             'read'     => Notifications::markRead($user['uuid'], (int)$in['id']);
//             'read_all' => Notifications::markAllRead($user['uuid']);
//         then 200 ['ok'=>true]; bad action → 400.
//   else: 405.

profile_app_json(501, ['error' => 'not_implemented', 'endpoint' => 'me-notifications', 'method' => $method]);
