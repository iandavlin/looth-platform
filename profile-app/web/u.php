<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_render_public.php';

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Db;
use Looth\ProfileApp\Profile;

$slug = $_GET['slug'] ?? '';
if (!is_string($slug) || $slug === '') { http_response_code(404); echo 'not found'; exit; }

$pg = Db::pg();
$row = null;
$s = $pg->prepare('SELECT id, uuid FROM users WHERE slug = :s');
$s->execute([':s' => $slug]);
$row = $s->fetch();
if (!$row && ctype_digit($slug)) {
    $s = $pg->prepare('SELECT id, uuid FROM users WHERE id = :i');
    $s->execute([':i' => (int)$slug]);
    $row = $s->fetch();
}
if (!$row) { http_response_code(404); echo 'not found'; exit; }

// Self looking at own public page → bounce to editor.
$viewer = Auth::currentUser();
if ($viewer && strtolower($viewer['uuid']) === strtolower($row['uuid'])) {
    header('Location: /profile/edit'); exit;
}

$full = Profile::loadFull((int)$row['id']);
$role = $viewer ? 'member' : 'public';
$filtered = Profile::renderForViewer($full, $role, $viewer ? (int)$viewer['id'] : 0, (int)$row['id']);

looth_render_public($filtered, $role, (int)$row['id']);
