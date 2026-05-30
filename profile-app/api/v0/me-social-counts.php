<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

/**
 * Lightweight badge counts for the shared header. ⚠️ SKELETON — returns 501.
 * Feeds the header's lazy-loaded messages / friends badges (data-lg-msg-count,
 * pending-requests). Cheap; cache ~30s. Plan: social-layer §4.
 *   GET → { messages_unread: int, requests_pending: int }
 */

use Looth\ProfileApp\Auth;
// use Looth\ProfileApp\Messaging;
// use Looth\ProfileApp\Connections;

$user = Auth::requireUser();

// TODO:
//   profile_app_json(200, [
//     'messages_unread'  => Messaging::unreadCount($user['uuid']),
//     'requests_pending' => Connections::pendingCount($user['uuid']),
//   ]);

profile_app_json(501, ['error' => 'not_implemented', 'endpoint' => 'me-social-counts']);
