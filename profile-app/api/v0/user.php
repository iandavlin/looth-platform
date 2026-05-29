<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Db;
use Looth\ProfileApp\Profile;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') profile_app_json(405, ['error' => 'method_not_allowed']);

$uuid = $_GET['uuid'] ?? '';
if (!is_string($uuid) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
    profile_app_json(400, ['error' => 'invalid_uuid']);
}
$uuid = strtolower($uuid);

$idStmt = Db::pg()->prepare('SELECT id FROM users WHERE uuid = :u');
$idStmt->execute([':u' => $uuid]);
$id = $idStmt->fetchColumn();
if (!$id) profile_app_json(404, ['error' => 'not_found']);

$full = Profile::loadFull((int)$id);

// Determine viewer role.
$viewer = Auth::currentUser();
if (!$viewer) {
    $role = 'public';
} elseif (strtolower($viewer['uuid']) === $uuid) {
    $role = 'me';
} else {
    // 'friend' graph doesn't exist yet — friends fall through to member.
    $role = 'member';
}

profile_app_json(200, Profile::renderForViewer($full, $role, $viewer ? (int)$viewer['id'] : 0, (int)$id));
