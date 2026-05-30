<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

/**
 * Connections endpoint. ⚠️ SKELETON — returns 501. Plan: social-layer §4.
 *   GET  → { accepted[], pending_in[], pending_out[], following[] }
 *   POST → { action: request|accept|decline|block|unfollow, target_uuid }
 * Identity via Auth::requireUser() (/whoami). Backend: src/Connections.php.
 */

use Looth\ProfileApp\Auth;
// use Looth\ProfileApp\Connections;

$user = Auth::requireUser();
$method = $_SERVER['REQUEST_METHOD'];

// TODO:
//   if GET:  profile_app_json(200, Connections::listFor($user['uuid']));
//   if POST: dispatch action → Connections::{request|accept|decline|block|unfollow}(...)
//   else:    405.

profile_app_json(501, ['error' => 'not_implemented', 'endpoint' => 'me-connections', 'method' => $method]);
