<?php
declare(strict_types=1);

/**
 * /profile-media/<class>/<uuid>/<file> — auth front controller (Ian 6/12).
 *
 * Closes THE standing hole: the media store used to be a bare nginx alias that
 * served everything (gallery photos + resumes included) to anyone past the dev
 * cookie. Now every request is decided by Visibility::fileVisible:
 *
 *   avatars/banners → public (identity chrome — bylines, comments, messages)
 *   gallery         → the owner's gallery-section visibility (+ master switch)
 *   resumes         → users.resume_visibility (+ master switch)
 *   anything else   → fails closed
 *
 * PHP never streams bytes: on allow we hand nginx an X-Accel-Redirect to the
 * internal alias and it serves the file itself. Denials answer 404 (not 403)
 * so a gated file's existence can't be probed.
 */

require_once __DIR__ . '/../config.php';

use Looth\ProfileApp\Visibility;

$path = (string)($_GET['path'] ?? '');
if (!preg_match('#^(avatars|banners|gallery|resumes)/([0-9a-fA-F-]{36})/([A-Za-z0-9][A-Za-z0-9._ -]*)$#', $path, $m)
    || str_contains($m[3], '..')) {
    http_response_code(404);
    exit;
}
[, $class, $uuid, $file] = $m;

if (!Visibility::fileVisible(Visibility::viewer(), strtolower($class), $uuid)) {
    http_response_code(404);
    exit;
}

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$types = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
    'webp' => 'image/webp', 'gif' => 'image/gif', 'avif' => 'image/avif',
    'svg' => 'image/svg+xml', 'pdf' => 'application/pdf',
];
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));

// Public chrome stays long-cacheable (URLs carry ?v= versions). Gated classes
// must never land in a shared cache and must revalidate per viewer.
if ($class === 'avatars' || $class === 'banners') {
    header('Cache-Control: public, max-age=2592000');
} else {
    header('Cache-Control: private, no-store');
}

header('X-Accel-Redirect: /profile-media-internal/' . $class . '/' . strtolower($uuid) . '/' . rawurlencode($file));
